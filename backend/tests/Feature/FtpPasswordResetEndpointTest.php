<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\FtpPasswordReset;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `POST /api/management/ftp/reset-password` (P1-M33).
 *
 * The service-level guarantees (quota, audit row, show-once) are covered in
 * `FtpPasswordResetTest`. This file covers what only the HTTP layer decides:
 * who may call it, what a caller is told, and — above all — that the endpoint
 * cannot be pointed at another account.
 */
class FtpPasswordResetEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A real, writable inbox root: `provisionAndShow()` creates `ftp/<slug>` on
     * disk before it creates the account (D-2).
     */
    private string $inboxRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inboxRoot = $this->useTemporaryFtpInboxRoot();

        config([
            'services.sftpgo' => [
                'base_url' => 'http://sftpgo.test:8080',
                'api_key' => 'test-api-key',
                'admin_username' => null,
                'admin_password' => null,
            ],
            'filesystems.disks.ftp_inbox.root' => $this->inboxRoot,
        ]);
    }

    public function test_a_photographer_receives_the_new_password(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        $response = $this->resetFor($user);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $this->assertMatchesRegularExpression(
            FtpCredentialService::PASSWORD_PATTERN,
            (string) $response->json('password'),
        );
        // The notice is not decoration: show-once is the whole security property
        // of the flow, and a client that renders nothing would leave a
        // photographer with an unusable account and no hint why.
        $response->assertJsonStructure(['success', 'password', 'password_notice']);
    }

    /**
     * The recovery path must not be able to lock a photographer out. As many
     * resets as `RESET_LIMIT_PER_HOUR` allows are served, the next one is
     * answered with 429, a German message and a `Retry-After` — a client needs
     * the header to back off correctly instead of hammering the button, which
     * would keep extending the window.
     */
    public function test_the_call_past_the_hourly_limit_is_answered_with_429(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();
        $token = auth('api')->login($user);

        for ($attempt = 1; $attempt <= FtpCredentialService::RESET_LIMIT_PER_HOUR; $attempt++) {
            $this->withHeaders(['Authorization' => "Bearer $token"])
                ->postJson('/api/management/ftp/reset-password')
                ->assertOk();
        }

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/reset-password');

        $response->assertStatus(429);
        $this->assertNotEmpty($response->headers->get('Retry-After'));
        $this->assertMatchesRegularExpression(
            '/\p{L}/u',
            (string) $response->json('error'),
            'The message must be human-readable text, not a code.',
        );
    }

    /**
     * A 429 must be a refusal, not a partial success: no second password, no
     * service call, no audit row. Otherwise a throttled client would still
     * invalidate the password the camera is using right now.
     */
    public function test_a_throttled_call_does_not_change_anything(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();
        $token = auth('api')->login($user);

        for ($attempt = 0; $attempt < FtpCredentialService::RESET_LIMIT_PER_HOUR; $attempt++) {
            $this->withHeaders(['Authorization' => "Bearer $token"])
                ->postJson('/api/management/ftp/reset-password')
                ->assertOk();
        }

        $requestsBefore = count(Http::recorded());
        $rowsBefore = FtpPasswordReset::query()->count();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/reset-password');

        $response->assertStatus(429);
        $this->assertNull($response->json('password'));
        $this->assertCount($requestsBefore, Http::recorded());
        $this->assertSame($rowsBefore, FtpPasswordReset::query()->count());
    }

    /**
     * The audit trail has to attribute an HTTP-triggered reset too, including the
     * caller's address — that is the only place the IP comes from in this flow.
     */
    public function test_the_endpoint_records_the_caller_ip_in_the_audit_trail(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        $this->resetFor($user)->assertOk();

        $row = FtpPasswordReset::query()->sole();

        $this->assertSame($user->id, $row->user_id);
        $this->assertTrue($row->success);
        $this->assertNotNull($row->ip, 'An HTTP-triggered reset always has a request address.');
    }

    /**
     * There is no `user_id` in the contract. A caller that tries to name another
     * account gets their own reset, so the surface for draining somebody else's
     * quota — or writing rows under their id — is not merely guarded, it is not
     * offered.
     */
    public function test_the_endpoint_cannot_be_pointed_at_another_account(): void
    {
        $this->fakeSuccessfulService();
        $caller = $this->photographer(['ftp_slug' => 'florian', 'email' => 'florian@example.com']);
        $victim = $this->photographer(['ftp_slug' => 'sabine', 'email' => 'sabine@example.com']);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($caller)])
            ->postJson('/api/management/ftp/reset-password', [
                'user_id' => $victim->id,
                'ftp_slug' => 'sabine',
            ]);

        $response->assertOk();
        $this->assertSame(1, FtpPasswordReset::query()->where('user_id', $caller->id)->count());
        $this->assertSame(0, FtpPasswordReset::query()->where('user_id', $victim->id)->count());

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return true;
            }

            $this->assertStringContainsString('/users/florian', $request->url());

            return true;
        });
    }

    public function test_a_client_cannot_reset_a_camera_password(): void
    {
        $this->fakeSuccessfulService();
        $client = User::factory()->create();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($client)])
            ->postJson('/api/management/ftp/reset-password');

        $response->assertStatus(403);
        $this->assertSame(0, FtpPasswordReset::query()->count());
        Http::assertNothingSent();
    }

    public function test_an_unauthenticated_caller_is_rejected(): void
    {
        $this->fakeSuccessfulService();

        $this->postJson('/api/management/ftp/reset-password')->assertStatus(401);
    }

    /**
     * A dead service is a 503, not a 500. The photographer needs to know that
     * retrying later is the right move — and that the password did not change,
     * which matters because there is no restore (feature doc 7.3).
     */
    public function test_an_unreachable_service_is_reported_as_unavailable(): void
    {
        Http::fake([
            'sftpgo.test:8080/*' => fn () => throw new ConnectionException('connection refused'),
        ]);

        $response = $this->resetFor($this->photographer());

        $response->assertStatus(503);
        $this->assertNull($response->json('password'));

        // The failed attempt is still recorded: a photographer reporting "reset
        // does nothing" has to leave a trace somewhere.
        $this->assertFalse(FtpPasswordReset::query()->sole()->success);
    }

    /**
     * The cached column can say `active` while the service has never heard of the
     * account — a hand-deleted user, which P1-M30 calls out as the reason the
     * column is a cache. That mismatch is a real 404 to the caller, and it is
     * also audited as a failure, which is the signal that reconciliation is due.
     */
    public function test_an_account_missing_in_the_service_is_reported_as_not_found(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/users/*' => Http::response(['message' => 'not found'], 404),
        ]);

        $response = $this->resetFor($this->photographer());

        $response->assertStatus(404);
        $this->assertNull($response->json('password'));
        $this->assertFalse(FtpPasswordReset::query()->sole()->success);
    }

    /**
     * A portal-side precondition is a 422, distinct from a service failure. The
     * photographer has to be able to tell "fix your profile" apart from "the
     * service is down" — and, for an unprovisioned account, the answer is a
     * provisioning call, not a reset.
     */
    public function test_a_missing_account_name_is_reported_as_an_unprocessable_request(): void
    {
        Http::fake();
        $user = $this->photographer();
        $user->forceFill(['ftp_slug' => null])->saveQuietly();

        $response = $this->resetFor($user);

        $response->assertStatus(422);
        $this->assertNull($response->json('password'));
        Http::assertNothingSent();
    }

    /**
     * A failed audit write must not be swallowed. On the success path that means
     * a broken database answers with an error instead of a password the trail
     * never recorded — the harder failure, chosen on purpose.
     */
    public function test_a_failing_audit_write_does_not_hand_out_a_password(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        // A `creating` listener that vetoes the insert is the shape of failure
        // that does NOT throw — Eloquent returns an unsaved model instead. A
        // `create()` whose result is not checked would therefore audit nothing
        // and still hand out a password.
        FtpPasswordReset::creating(static fn (): bool => false);

        $response = $this->resetFor($user);

        $response->assertStatus(500);
        $this->assertNull(
            $response->json('password'),
            'A password whose rotation cannot be audited must not leave the system.',
        );
        $this->assertSame(0, FtpPasswordReset::query()->count());
    }

    private function resetFor(User $user)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($user)])
            ->postJson('/api/management/ftp/reset-password');
    }

    private function fakeSuccessfulService(): void
    {
        $account = [
            'id' => 7,
            'username' => 'florian',
            'status' => 1,
            'home_dir' => $this->inboxRoot.'/florian',
            'permissions' => ['/' => ['*']],
        ];

        Http::fake([
            'sftpgo.test:8080/api/v2/users/*' => fn (Request $request) => $request->method() === 'PUT'
                ? Http::response(['message' => 'user updated'], 200)
                : Http::response($account, 200),
        ]);
    }

    private function photographer(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        // This file describes the rotation of a *live* account, so the state is
        // named instead of left to the column default. Since P1-M58 a `pending`
        // account is provisioned by the same endpoint instead of rotated, which
        // is covered in `FtpFirstCameraAccountTest`; without this line these
        // tests would silently have changed what they assert. The column is not
        // mass-assignable and is written the way the service writes it.
        $user->forceFill(['ftp_account_status' => FtpCredentialService::STATUS_ACTIVE])->save();

        return $user->fresh();
    }
}
