<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\SftpGoException;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Bus;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class ProfileUpdateScoutTest extends TestCase
{
    use RefreshDatabase;

    private const SFTPGO_BASE_URL = 'http://sftpgo.test:8080';

    private const INBOX_ROOT = '/var/www/ftp';

    protected function setUp(): void
    {
        parent::setUp();

        // The slug half of this endpoint is a reset (P1-M34) and therefore runs
        // through the SFTPGo Admin API. Without a configured, faked service the
        // slug test fails on "SFTPGo is not configured" — which says nothing
        // about either the format rule or the Scout index this file is about.
        // Same setup as FtpPasswordResetTest and FtpSlugValidationTest.
        $this->configureSftpGo();
        $this->fakeSftpGo();
    }

    public function test_updating_metadata_copyright_updates_scout_index_for_photos()
    {
        $photographer = User::factory()->create(['name' => 'Old Name', 'metadata_copyright' => 'Old Copyright']);
        $photographer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $gallery = Gallery::factory()->create();
        $photographer->galleries()->attach($gallery);

        $photo = Photo::factory()->create(['user_id' => $photographer->id, 'gallery_id' => $gallery->id]);

        // Mock für Scout
        Bus::fake();

        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'New Name',
                'metadata_copyright' => 'New Awesome Copyright',
            ]);

        $response->assertStatus(200);

        // Sicherstellen, dass das Profil aktualisiert wurde
        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'metadata_copyright' => 'New Awesome Copyright',
        ]);

        // Prüfen, ob der Accessor den neuen Wert auswirft
        $photo->refresh();
        $this->assertEquals('New Awesome Copyright', $photo->artist);
    }

    public function test_updating_ftp_slug_validates_uniqueness_and_formats_it()
    {
        $user1 = User::factory()->create(['ftp_slug' => 'florian']);
        $user2 = User::factory()->create(['ftp_slug' => 'max']);
        $token = auth('api')->login($user2);

        // 1. Conflict Test (422)
        $responseConflict = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max',
                'ftp_slug' => 'florian', // Gehört schon user1
            ]);
        $responseConflict->assertStatus(422);

        // 2. Format & Success Test (200)
        $responseSuccess = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max',
                'ftp_slug' => 'Max NeÚ', // Sollte zu max-neu werden
            ]);
        $responseSuccess->assertStatus(200);

        $this->assertDatabaseHas('users', [
            'id' => $user2->id,
            'ftp_slug' => 'max-neu',
        ]);
    }

    /**
     * The fail-closed decision reaches further than the slug: a profile update
     * is one transaction, so a refused slug change discards the rest of the
     * request as well.
     *
     * A photographer who changes the name and the camera login in one form
     * expects one answer, and a half-applied update is the worst of both: the
     * portal would show a new name with the old account, or a new account with
     * the old name, and neither matches what was sent. Pinning it here also
     * documents that the transaction — not the call order — is what makes the
     * update atomic: `$user->update()` runs after the service call, so a future
     * reordering would still be caught by the rollback this test relies on.
     */
    public function test_a_refused_slug_change_also_discards_the_name_change_from_the_same_request(): void
    {
        $user = User::factory()->create(['ftp_slug' => 'max', 'name' => 'Max']);
        $token = auth('api')->login($user);

        $this->configureSftpGo(['api_key' => null, 'admin_username' => null, 'admin_password' => null]);
        Http::fake();

        // Caught, not rendered: no handler turns this into a 500 that a caller
        // could mistake for a stored update.
        $this->withoutExceptionHandling();

        try {
            $this->withHeaders(['Authorization' => "Bearer $token"])
                ->putJson('/api/auth/profile', [
                    'name' => 'Maximilian Mustermann',
                    'ftp_slug' => 'max-neu',
                ]);

            $this->fail('A slug change must be refused while SFTPGo is unconfigured.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_NOT_CONFIGURED, $exception->reason);
        }

        Http::assertNothingSent();

        $this->assertDatabaseHas('users', [
            'id' => $user->id,
            'name' => 'Max',
            'ftp_slug' => 'max',
        ]);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * @param  array<string, mixed>  $overrides
     */
    private function configureSftpGo(array $overrides = []): void
    {
        config([
            'services.sftpgo' => array_merge([
                'base_url' => self::SFTPGO_BASE_URL,
                'api_key' => 'test-api-key',
                'admin_username' => null,
                'admin_password' => null,
            ], $overrides),
            'filesystems.disks.ftp_inbox.root' => self::INBOX_ROOT,
        ]);
    }

    /**
     * `POST /users` 201 with the created user, `DELETE /users/{name}` 200 with a
     * message — the two calls a slug change makes, in the shapes
     * `SftpGoClient` documents. The pattern covers the collection endpoint as
     * well: an unmatched stub falls through to the real HTTP handler.
     */
    private function fakeSftpGo(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/users*' => fn ($request) => $request->method() === 'POST'
                ? Http::response([
                    'id' => 7,
                    'username' => $request->data()['username'] ?? 'unknown',
                    'status' => 1,
                    'home_dir' => self::INBOX_ROOT.'/'.($request->data()['username'] ?? 'unknown'),
                    'permissions' => ['/' => ['*']],
                ], 201)
                : Http::response(['message' => 'user deleted'], 200),
        ]);
    }
}
