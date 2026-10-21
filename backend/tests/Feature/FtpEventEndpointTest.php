<?php

namespace Tests\Feature;

use App\Console\Commands\ProvisionFtpEvents;
use App\Enums\Brand;
use App\Enums\UserRole;
use App\Jobs\ImportFtpBatch;
use App\Models\Gallery;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Storage;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The internal SFTPGo upload-event endpoint (D-36).
 *
 * The endpoint is the trigger of the automatic import, so the tests assert
 * *behaviour*, not status codes: which requests cause a job to exist afterwards
 * and which leave the inbox untouched. A 200 that enqueues nothing and a 401
 * that enqueues nothing are different outcomes for the same status-code-shaped
 * assertion, and only the job list tells them apart.
 *
 * The endpoint is not a user-facing API: there is no JWT and no `management`
 * middleware, so the tests call it unauthenticated — that is the real caller
 * (SFTPGo inside the compose network), and the secret is the only guard.
 */
class FtpEventEndpointTest extends TestCase
{
    use RefreshDatabase;

    private const SECRET = 'test-event-secret';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('ftp_inbox');
        Storage::fake('photos');
        Queue::fake();

        config([
            'ftp.events.url' => 'http://portal.test/api/internal/ftp/upload-event',
            'ftp.events.secret' => self::SECRET,
            'ftp.events.secret_header' => 'X-FTP-Event-Secret',
            'ftp.events.action_name' => 'portal-ftp-upload',
        ]);
    }

    public function test_a_missing_secret_is_rejected_with_401_and_enqueues_nothing(): void
    {
        [$user] = $this->photographerWithTarget();

        $this->postJson('/api/internal/ftp/upload-event', [
            'action' => 'upload',
            'username' => $user->ftp_slug,
        ])->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_a_wrong_secret_is_rejected_with_401_and_enqueues_nothing(): void
    {
        [$user] = $this->photographerWithTarget();

        $this->withHeaders(['X-FTP-Event-Secret' => 'not-the-secret'])
            ->postJson('/api/internal/ftp/upload-event', [
                'action' => 'upload',
                'username' => $user->ftp_slug,
            ])
            ->assertStatus(401);

        Queue::assertNothingPushed();
    }

    /**
     * Fail closed: an unconfigured portal must not accept events it cannot
     * authenticate. The empty-secret case is the dangerous one, because a
     * `hash_equals('', '')` comparison would accept everything.
     */
    public function test_an_unconfigured_secret_rejects_every_event(): void
    {
        [$user] = $this->photographerWithTarget();
        config(['ftp.events.secret' => '']);

        $this->withHeaders(['X-FTP-Event-Secret' => ''])
            ->postJson('/api/internal/ftp/upload-event', [
                'action' => 'upload',
                'username' => $user->ftp_slug,
            ])
            ->assertStatus(401);

        Queue::assertNothingPushed();
    }

    public function test_an_unknown_slug_has_no_effect(): void
    {
        $this->photographerWithTarget();

        $this->withHeaders(['X-FTP-Event-Secret' => self::SECRET])
            ->postJson('/api/internal/ftp/upload-event', [
                'action' => 'upload',
                'username' => 'nobody-knows-me',
            ])
            ->assertStatus(200)
            ->assertJson(['accepted' => false, 'reason' => 'unknown_account']);

        Queue::assertNothingPushed();
    }

    /**
     * D-36's explicit boundary: without a target gallery the import is not
     * started and the files stay where they are. Guessing a gallery would file a
     * shooting into the wrong delivery.
     */
    public function test_a_missing_target_gallery_enqueues_nothing_and_leaves_the_files(): void
    {
        [$user, $sourcePath] = $this->photographerWithTarget();
        $user->update(['current_ftp_gallery_id' => null]);

        $this->withHeaders(['X-FTP-Event-Secret' => self::SECRET])
            ->postJson('/api/internal/ftp/upload-event', [
                'action' => 'upload',
                'username' => $user->ftp_slug,
            ])
            ->assertStatus(200)
            ->assertJson(['accepted' => false, 'reason' => 'no_target_gallery']);

        Queue::assertNothingPushed();
        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
    }

    #[Group('smoke')]
    public function test_a_valid_upload_event_enqueues_exactly_one_job_for_that_account(): void
    {
        [$user] = $this->photographerWithTarget();

        $this->withHeaders(['X-FTP-Event-Secret' => self::SECRET])
            ->postJson('/api/internal/ftp/upload-event', [
                'action' => 'upload',
                'username' => $user->ftp_slug,
                'fs_path' => '/'.$user->ftp_slug.'/IMG_0001.JPG',
                'file_size' => 1234,
            ])
            ->assertStatus(200)
            ->assertJson(['accepted' => true, 'dispatched' => true]);

        Queue::assertPushed(ImportFtpBatch::class, 1);
        Queue::assertPushed(
            ImportFtpBatch::class,
            static fn (ImportFtpBatch $job): bool => $job->userId === $user->id
                && $job->batchNumber === 1
                && $job->uploadEvent,
        );
    }

    /**
     * The rule subscribes to `upload`; a delete or a rename must not enqueue an
     * import. This is the endpoint's own defence in depth, so a mis-provisioned
     * rule cannot turn housekeeping into import runs.
     */
    public function test_a_non_upload_event_is_ignored(): void
    {
        [$user] = $this->photographerWithTarget();

        foreach (['delete', 'rename', 'mkdir', 'download'] as $action) {
            $this->withHeaders(['X-FTP-Event-Secret' => self::SECRET])
                ->postJson('/api/internal/ftp/upload-event', [
                    'action' => $action,
                    'username' => $user->ftp_slug,
                ])
                ->assertStatus(200)
                ->assertJson(['accepted' => false, 'reason' => 'ignored_event']);
        }

        Queue::assertNothingPushed();
    }

    public function test_a_payload_without_a_username_is_rejected_as_malformed(): void
    {
        $this->withHeaders(['X-FTP-Event-Secret' => self::SECRET])
            ->postJson('/api/internal/ftp/upload-event', ['action' => 'upload'])
            ->assertStatus(422);

        Queue::assertNothingPushed();
    }

    /**
     * The **provisioned action itself** must pass the endpoint, end to end.
     *
     * The payload is taken from `ProvisionFtpEvents::actionPayload()` — the same
     * method `ftp:provision-events` posts to the Admin API — and the endpoint,
     * the headers and the body are read out of it. Only the placeholder
     * substitution is simulated, because that is the one step SFTPGo performs
     * after the action is stored; everything else is the provisioned object.
     * So this is not "the constant looks like the parser": a renamed field, a
     * body that is not valid JSON, a wrong endpoint, or a missing secret header
     * in the provisioned shape all turn red here, and a green run proves that
     * exactly the provisioned form produces a job.
     */
    public function test_the_provisioned_action_reaches_the_endpoint_and_enqueues_a_job(): void
    {
        [$user] = $this->photographerWithTarget();

        $action = (new ProvisionFtpEvents)->actionPayload(
            (string) config('ftp.events.action_name'),
            (string) config('ftp.events.url'),
            (string) config('ftp.events.secret_header'),
            self::SECRET,
        );

        $httpConfig = $action['options']['http_config'];

        $endpoint = parse_url($httpConfig['endpoint'], PHP_URL_PATH);
        $this->assertIsString($endpoint, 'The provisioned endpoint must be a URL with a path.');

        // SFTPGo substitutes its placeholders in the stored body; this is the
        // only step the test performs on SFTPGo's behalf.
        $body = strtr($httpConfig['body'], [
            '{{.Name}}' => $user->ftp_slug,
            '{{.Event}}' => 'upload',
            '{{.VirtualPath}}' => '/'.$user->ftp_slug.'/IMG_0001.JPG',
            '{{.FileSize}}' => '1234',
        ]);

        // The action's `headers` list (`{key, value}`) becomes the request's
        // server variables, so the secret and the content type are the ones the
        // provisioned action carries rather than literals in the test.
        $server = [];
        foreach ($httpConfig['headers'] as $header) {
            $name = strtoupper(str_replace('-', '_', $header['key']));
            $server[$name === 'CONTENT_TYPE' ? 'CONTENT_TYPE' : 'HTTP_'.$name] = $header['value'];
        }

        $response = $this->call('POST', $endpoint, [], [], [], $server, $body);

        $response->assertOk()->assertJson(['accepted' => true, 'dispatched' => true]);
        Queue::assertPushed(ImportFtpBatch::class, 1);
    }

    /**
     * The throttle is part of the contract, not an implementation detail: an
     * ordinary shooting produces one event per file, so a limiter sized for
     * humans would drop exactly the events the feature exists for.
     *
     * Asserted on the **fully resolved** middleware, not on
     * `Route::gatherMiddleware()`: the latter returns the group *name* `api`, so
     * a future `throttleApi()` in `bootstrap/app.php` would add `throttle:api`
     * to the group and leave this test green. `gatherRouteMiddleware()` expands
     * the group and the aliases, which is what actually runs.
     */
    public function test_the_event_endpoint_is_not_throttled(): void
    {
        $route = app('router')->getRoutes()->getByName('api.internal.ftp.upload-event');

        $this->assertNotNull($route);
        $middleware = app('router')->gatherRouteMiddleware($route);

        $this->assertNotContains('throttle:api', $middleware);

        foreach ($middleware as $entry) {
            $this->assertStringNotContainsString('throttle', (string) $entry);
        }
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function photographerWithTarget(): array
    {
        $user = User::factory()->create(['brand' => Brand::B2B, 'ftp_slug' => 'florian']);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'type' => 'delivery',
            'apply_metadata_to_photos' => false,
            'restricted_photographers' => true,
        ]);
        $user->galleries()->attach($gallery->id);
        $user->update(['current_ftp_gallery_id' => $gallery->id]);

        $sourcePath = $user->ftp_slug.'/source.jpg';
        Storage::disk('ftp_inbox')->put($sourcePath, 'binary');

        return [$user->fresh(), $sourcePath];
    }
}
