<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The show-once password has to actually arrive (P1-M23 + P1-M34).
 *
 * A slug change is a reset: the old account is deleted, a new one is
 * provisioned, and the fresh camera password is handed to the photographer
 * exactly once. "Exactly once" has two halves, and only the first is obvious —
 * the password must not be stored anywhere, and it must be *shown* somewhere.
 *
 * This file is the regression guard for the second half. `updateProfile()`
 * returned its credential response from inside the `DB::transaction()`
 * closure, and `DB::transaction()` returns the closure's value to its caller —
 * which the method then discarded. The endpoint answered `{"success": true}`
 * while the account in SFTPGo had a password nobody had ever seen. Nothing was
 * broken in the database, the slug was correct, the account worked: the only
 * symptom was a photographer who could not log their camera in, and the only
 * way out was the reset endpoint (the hourly quota of
 * `FtpCredentialService::RESET_LIMIT_PER_HOUR`, P1-M33). A green suite and a
 * working inbox, with an unreachable account.
 *
 * So the assertion that matters is not "the response has a password" but "the
 * password in the response is the one SFTPGo was given". Anything weaker would
 * pass against a second, freshly generated password that the camera does not
 * know.
 */
class FtpSlugChangePasswordTest extends TestCase
{
    use RefreshDatabase;

    private const SFTPGO_BASE_URL = 'http://sftpgo.test:8080';

    /**
     * A real, writable inbox root: the slug write path creates `ftp/<slug>`
     * before it commits (D-2).
     */
    private string $inboxRoot;

    /**
     * The password the fake saw in the provisioning call.
     *
     * Captured rather than generated: the portal generates the password itself,
     * so a test cannot know it in advance. Reading it back out of the request
     * the fake recorded is what turns "some non-empty string" into "the same
     * value on both sides of the call".
     */
    private ?string $passwordSentToSftpGo = null;

    protected function setUp(): void
    {
        parent::setUp();

        $this->passwordSentToSftpGo = null;

        $this->inboxRoot = $this->useTemporaryFtpInboxRoot();

        $this->configureSftpGo();
        $this->fakeSftpGo();
    }

    public function test_a_slug_change_hands_the_new_camera_password_to_the_photographer(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'j-doe',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);
        $response->assertJsonStructure(['success', 'ftp_password', 'ftp_password_note']);

        $shown = $response->json('ftp_password');

        $this->assertIsString($shown);
        $this->assertNotEmpty($shown);
        // Camera-typable by construction (P1-M23): no special characters, and a
        // de-ambiguated alphanumeric alphabet, so a keyboard layout cannot turn
        // this into a support case.
        $this->assertMatchesRegularExpression(FtpCredentialService::PASSWORD_PATTERN, $shown);

        // The password the account was actually created with, not a second one.
        $this->assertNotNull(
            $this->passwordSentToSftpGo,
            'The slug change must have provisioned the new account.',
        );
        $this->assertSame(
            $this->passwordSentToSftpGo,
            $shown,
            'The password handed to the photographer must be the one SFTPGo stores.',
        );

        // The note is the contract with the UI, so it has to be there: a
        // password shown without "save it now" is one that gets lost.
        $note = $response->json('ftp_password_note');
        $this->assertIsString($note);
        $this->assertNotEmpty($note);

        // Show-once means show-once *and* nowhere else. Now that the value is in
        // the response it is worth pinning that the response is the only place
        // it exists — a copy in a column or a cache entry would be a second one.
        $this->assertDatabaseMissing('users', ['id' => $photographer->id, 'ftp_password' => $shown]);
        $this->assertStringNotContainsString(
            $shown,
            (string) json_encode($photographer->fresh()?->getAttributes(), JSON_THROW_ON_ERROR),
        );
    }

    /**
     * The refusal must not leak anything either. It is not enough that the slug
     * stays unchanged: a partial response carrying a password would invite the
     * UI to show a credential for an account that was never created.
     *
     * The exception is deliberately *not* caught here, so the response can be
     * inspected: the fail-closed behaviour itself (unchanged slug, no request
     * to SFTPGo) is pinned in `FtpSlugValidationTest`, this test covers what the
     * caller receives.
     */
    public function test_a_refused_slug_change_returns_no_password_at_all(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);
        $slugBefore = $photographer->ftp_slug;

        $this->configureSftpGo([
            'api_key' => null,
            'admin_username' => null,
            'admin_password' => null,
        ]);
        // A catch-all recorder rather than the stub map: the assertion is that
        // this stays empty.
        Http::fake();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'j-doe',
            ]);

        $response->assertStatus(500);
        $response->assertJsonMissingPath('ftp_password');
        $response->assertJsonMissingPath('ftp_password_note');

        Http::assertNothingSent();
        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'ftp_slug' => $slugBefore,
        ]);
    }

    /**
     * The other half of the fix: an ordinary profile update still answers
     * `{"success": true}` and nothing more.
     *
     * The new return path is `return $credentialResponse ?? ...`, so a change
     * that returned something in the non-slug branch would silently add a
     * password field to every profile save — a value that was never generated
     * for that request. Asserted exhaustively rather than with a missing-key
     * check, so an *added* key fails here too.
     */
    public function test_a_profile_update_without_a_slug_change_returns_no_password(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'metadata_copyright' => 'Max Mustermann',
            ]);

        $response->assertOk();
        $this->assertSame(
            ['success' => true],
            $response->json(),
            'A profile update without a slug change must answer exactly {"success": true}.',
        );

        // And it must not go near the account system either: no slug change, no
        // account to touch.
        Http::assertNothingSent();
        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'name' => 'Max Mustermann',
        ]);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function photographer(string $email = 'max@example.com'): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => 'Max Mustermann']);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        return $user;
    }

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
            'filesystems.disks.ftp_inbox.root' => $this->inboxRoot,
        ]);
    }

    /**
     * `POST /users` 201 with the created user, `DELETE /users/{name}` 200 with a
     * message — the two calls a slug change makes, in the shapes `SftpGoClient`
     * documents.
     *
     * The pattern has no trailing slash on purpose: `Factory::stubUrl()` matches
     * with `Str::is('*'.$pattern, $url)`, and a stub that does not match falls
     * through to the *real* HTTP handler. A pattern covering only
     * `/users/{name}` would send a genuine request for the collection endpoint
     * instead of failing the test.
     */
    private function fakeSftpGo(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/users*' => function (Request $request) {
                $account = $request->method() === 'POST'
                    ? (string) ($request->data()['username'] ?? 'unknown')
                    : rawurldecode(basename((string) parse_url($request->url(), PHP_URL_PATH)));

                if ($request->method() === 'POST') {
                    $this->passwordSentToSftpGo = (string) ($request->data()['password'] ?? '');
                }

                return $request->method() === 'POST'
                    ? Http::response([
                        'id' => 7,
                        'username' => $account,
                        'status' => 1,
                        'home_dir' => $this->inboxRoot.'/'.$account,
                        'permissions' => ['/' => ['*']],
                    ], 201)
                    : Http::response(['message' => 'user deleted'], 200);
            },
        ]);
    }
}
