<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\FtpCredentialException;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpCredentialService;
use App\Support\FtpInboxDirectory;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
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

    private ?int $expectedSftpGoTransactionLevel = null;

    private int $deleteResponseStatus = 200;

    private int $provisionResponseStatus = 201;

    protected function setUp(): void
    {
        parent::setUp();

        $this->passwordSentToSftpGo = null;
        $this->expectedSftpGoTransactionLevel = null;
        $this->deleteResponseStatus = 200;
        $this->provisionResponseStatus = 201;

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

    public function test_slug_replacement_uses_the_same_lock_as_self_change(): void
    {
        $photographer = $this->activePhotographer('old-slug');
        $service = app(FtpCredentialService::class);
        $held = false;
        $nestedFailure = null;
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->twice()
            ->with(10, \Mockery::type(Closure::class))
            ->andReturnUsing(function (int $seconds, Closure $callback) use (&$held): mixed {
                if ($held) {
                    throw new LockTimeoutException;
                }

                $held = true;

                try {
                    return $callback();
                } finally {
                    $held = false;
                }
            });
        Cache::shouldReceive('lock')
            ->twice()
            ->with(FtpCredentialService::rotationLockKey((string) $photographer->getKey()), 120)
            ->andReturn($lock);

        Http::fake([
            'sftpgo.test:8080/api/v2/users*' => function (Request $request) use ($photographer, $service, &$nestedFailure) {
                if ($request->method() === 'DELETE') {
                    try {
                        $service->changeAndShow($photographer, 'current-camera-password');
                    } catch (FtpCredentialException $exception) {
                        $nestedFailure = $exception;
                    }

                    return Http::response(['message' => 'user deleted'], 200);
                }

                $this->passwordSentToSftpGo = (string) ($request->data()['password'] ?? '');

                return Http::response([
                    'id' => 7,
                    'username' => 'new-slug',
                    'status' => 1,
                    'home_dir' => $this->inboxRoot.'/new-slug',
                    'permissions' => ['/' => ['*']],
                ], 201);
            },
        ]);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($photographer),
        ])->putJson('/api/auth/profile', [
            'name' => 'Max Mustermann',
            'ftp_slug' => 'new-slug',
        ]);

        $response->assertOk()->assertJsonPath('ftp_password', $this->passwordSentToSftpGo);
        $this->assertInstanceOf(FtpCredentialException::class, $nestedFailure);
        $this->assertSame(FtpCredentialException::REASON_ROTATION_IN_PROGRESS, $nestedFailure->reason);
        Http::assertSentCount(2);
    }

    public function test_slug_change_runs_both_sftpgo_calls_outside_its_profile_transaction(): void
    {
        $photographer = $this->activePhotographer('old-slug');
        $this->expectedSftpGoTransactionLevel = DB::transactionLevel();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($photographer),
        ])->putJson('/api/auth/profile', [
            'name' => 'Max Mustermann',
            'ftp_slug' => 'new-slug',
        ]);

        $response->assertOk();
        $this->assertNotNull($this->passwordSentToSftpGo);
        Http::assertSentCount(2);
    }

    public function test_slug_change_lock_timeout_returns_conflict_without_mutating_the_profile(): void
    {
        $photographer = $this->photographer();
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(10, \Mockery::type(Closure::class))
            ->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')
            ->once()
            ->with(FtpCredentialService::rotationLockKey((string) $photographer->getKey()), 120)
            ->andReturn($lock);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($photographer),
        ])->putJson('/api/auth/profile', [
            'name' => 'Changed Name',
            'ftp_slug' => 'new-slug',
        ]);

        $response->assertStatus(409)->assertJsonStructure(['error']);
        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'name' => 'Max Mustermann',
            'ftp_slug' => $photographer->ftp_slug,
        ]);
        Http::assertNothingSent();
    }

    public function test_slug_change_rechecks_photographer_authorization_after_waiting_for_the_lock(): void
    {
        $photographer = $this->photographer();
        $role = Role::query()->where('name', UserRole::PHOTOGRAPHER->value)->sole();
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(10, \Mockery::type(Closure::class))
            ->andReturnUsing(function (int $seconds, Closure $callback) use ($photographer, $role): mixed {
                $photographer->roles()->detach($role);

                return $callback();
            });
        Cache::shouldReceive('lock')
            ->once()
            ->with(FtpCredentialService::rotationLockKey((string) $photographer->getKey()), 120)
            ->andReturn($lock);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($photographer),
        ])->putJson('/api/auth/profile', [
            'name' => 'Changed Name',
            'ftp_slug' => 'new-slug',
        ]);

        $response->assertStatus(403);
        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'name' => 'Max Mustermann',
            'ftp_slug' => $photographer->ftp_slug,
        ]);
        Http::assertNothingSent();
    }

    public function test_failed_delete_does_not_persist_the_new_slug_or_other_profile_fields(): void
    {
        $photographer = $this->activePhotographer('old-slug');
        $this->deleteResponseStatus = 503;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($photographer),
        ])->putJson('/api/auth/profile', [
            'name' => 'Changed Name',
            'ftp_slug' => 'new-slug',
        ]);

        $response->assertStatus(500)->assertJsonMissingPath('ftp_password');
        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'name' => 'Max Mustermann',
            'ftp_slug' => 'old-slug',
            'ftp_account_status' => FtpCredentialService::STATUS_ERROR,
        ]);
        $this->assertNotEmpty($photographer->fresh()->ftp_account_error);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
    }

    public function test_slug_replacement_retries_an_ambiguous_delete_from_error_status(): void
    {
        $photographer = $this->activePhotographer('old-slug');
        $this->expectedSftpGoTransactionLevel = DB::transactionLevel();
        $this->deleteResponseStatus = 503;

        $firstResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($photographer),
        ])->putJson('/api/auth/profile', [
            'name' => 'Changed Name',
            'ftp_slug' => 'new-slug',
        ]);

        $firstResponse->assertStatus(500)->assertJsonMissingPath('ftp_password');
        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'name' => 'Max Mustermann',
            'ftp_slug' => 'old-slug',
            'ftp_account_status' => FtpCredentialService::STATUS_ERROR,
        ]);
        Http::assertSentCount(1);

        // A 404 proves that the first ambiguous delete actually removed the old
        // account. It must not prevent the replacement from completing.
        $this->deleteResponseStatus = 404;
        $secondResponse = $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($photographer),
        ])->putJson('/api/auth/profile', [
            'name' => 'Changed Name',
            'ftp_slug' => 'new-slug',
        ]);

        $secondResponse->assertOk()->assertJsonStructure(['success', 'ftp_password', 'ftp_password_note']);
        Http::assertSentCount(3);
        $this->assertSame(2, Http::recorded()->filter(fn (array $pair): bool => $pair[0]->method() === 'DELETE')->count());
        $this->assertSame(1, Http::recorded()->filter(fn (array $pair): bool => $pair[0]->method() === 'POST')->count());
        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'name' => 'Changed Name',
            'ftp_slug' => 'new-slug',
            'ftp_account_status' => FtpCredentialService::STATUS_ACTIVE,
        ]);
    }

    public function test_a_revoked_photographer_cannot_reprovision_by_changing_the_slug(): void
    {
        $photographer = $this->activePhotographer('old-slug');
        $photographer->forceFill([
            'ftp_account_status' => FtpCredentialService::STATUS_REVOKED,
            'ftp_revoked_at' => now(),
        ])->save();

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($photographer),
        ])->putJson('/api/auth/profile', [
            'name' => 'Changed Name',
            'ftp_slug' => 'new-slug',
        ]);

        $response->assertStatus(409)
            ->assertJsonMissingPath('ftp_password')
            ->assertJsonMissingPath('ftp_password_note');
        Http::assertNothingSent();
        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'name' => 'Max Mustermann',
            'ftp_slug' => 'old-slug',
            'ftp_account_status' => FtpCredentialService::STATUS_REVOKED,
        ]);
    }

    public function test_failed_provisioning_keeps_the_new_slug_in_an_explicit_error_state_and_shows_no_password(): void
    {
        $photographer = $this->activePhotographer('old-slug');
        $this->provisionResponseStatus = 503;

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($photographer),
        ])->putJson('/api/auth/profile', [
            'name' => 'Changed Name',
            'ftp_slug' => 'new-slug',
        ]);

        $response->assertStatus(500)->assertJsonMissingPath('ftp_password');
        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'name' => 'Changed Name',
            'ftp_slug' => 'new-slug',
            'ftp_account_status' => FtpCredentialService::STATUS_ERROR,
        ]);
        $this->assertNotEmpty($photographer->fresh()->ftp_account_error);
        $this->assertNotNull($this->passwordSentToSftpGo);
        Http::assertSentCount(2);
    }

    public function test_password_is_not_shown_when_the_active_status_cannot_be_persisted(): void
    {
        $photographer = $this->activePhotographer('old-slug');
        User::saving(static fn (User $user): bool => $user->ftp_account_status !== FtpCredentialService::STATUS_ACTIVE);

        $response = $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($photographer),
        ])->putJson('/api/auth/profile', [
            'name' => 'Changed Name',
            'ftp_slug' => 'new-slug',
        ]);

        $response->assertStatus(500)->assertJsonMissingPath('ftp_password');
        $this->assertNotNull($this->passwordSentToSftpGo);
        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'ftp_slug' => 'new-slug',
            'ftp_account_status' => FtpCredentialService::STATUS_ERROR,
        ]);
        $this->assertNotEmpty($photographer->fresh()->ftp_account_error);
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

    private function activePhotographer(string $slug): User
    {
        $user = $this->photographer();
        $user->forceFill([
            'ftp_slug' => $slug,
            'ftp_account_status' => FtpCredentialService::STATUS_ACTIVE,
        ])->save();
        mkdir($this->inboxRoot.'/'.$slug, FtpInboxDirectory::MODE);

        return $user->fresh();
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
                if ($this->expectedSftpGoTransactionLevel !== null) {
                    $this->assertSame(
                        $this->expectedSftpGoTransactionLevel,
                        DB::transactionLevel(),
                        'SFTPGo HTTP calls must not run inside the profile DB transaction.',
                    );
                }

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
                    ], $this->provisionResponseStatus)
                    : Http::response(['message' => 'user deleted'], $this->deleteResponseStatus);
            },
        ]);
    }
}
