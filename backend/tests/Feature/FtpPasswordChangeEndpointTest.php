<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\FtpPasswordReset;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpCredentialService;
use App\Support\FtpSlug;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Tests\TestCase;

/**
 * `POST /api/management/ftp/change-password` (D-51).
 *
 * The photographer replaces their own camera password and confirms it with the
 * current one. The service-level guarantees (quota, audit row, show-once) are
 * covered in `FtpPasswordChangeTest`; this file covers what only the HTTP layer
 * decides: who may call it, what a caller is told, and — above all — that the
 * endpoint cannot be pointed at another account.
 */
class FtpPasswordChangeEndpointTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The password the camera account currently holds. It is the confirmation
     * the service verifies against SFTPGo, so it has to survive the request
     * unchanged — the camera alphabet has no characters that a transport would
     * rewrite.
     */
    private const CURRENT_PASSWORD = 'kamerapasswort01';

    /**
     * A real, writable inbox root: the account of a photographer is a live one,
     * so the inbox directory of the slug has to exist on disk.
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

        $response = $this->changeFor($user);

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
     * The new password is the one the service received. A photographer has to
     * be able to type it into a camera, so it cannot be a second value.
     */
    public function test_the_returned_password_is_the_one_written_to_the_service(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        $response = $this->changeFor($user);

        $password = (string) $response->json('password');

        Http::assertSent(function (Request $request) use ($password): bool {
            if ($request->method() !== 'PUT') {
                return true;
            }

            $this->assertSame($password, $request->data()['password']);

            return true;
        });
    }

    /**
     * A wrong current password is the case the whole endpoint exists for: it
     * must be refused with a message the photographer can act on, and it must
     * not touch the credential the camera is holding right now. The service
     * write is the thing to prove here — a failed confirmation that still
     * rotated the password would invalidate a working camera.
     */
    public function test_a_wrong_current_password_is_refused_and_writes_nothing(): void
    {
        $this->fakeServiceRejectingTheCurrentPassword();
        $user = $this->photographer();

        $response = $this->changeFor($user, 'falschesPasswort01');

        $response->assertStatus(422);
        $this->assertNull($response->json('password'));
        $this->assertMatchesRegularExpression(
            '/\p{L}/u',
            (string) $response->json('error'),
            'The message must be human-readable text, not a code.',
        );
        $this->assertStringContainsString(
            'Kamera-Passwort',
            (string) $response->json('error'),
            'The message has to name the camera password, not the portal password.',
        );

        Http::assertNotSent(
            fn (Request $request): bool => $request->method() === 'PUT',
            'A refused confirmation must not rotate the credential.',
        );

        // The refused attempt is still an attempt: it belongs in the trail.
        $this->assertFalse(FtpPasswordReset::query()->sole()->success);
    }

    /**
     * The confirmation is checked *before* the rotation, and the rotation
     * happens exactly once. Two writes would mean two passwords, of which only
     * the last one is the live credential.
     */
    public function test_the_correct_current_password_rotates_the_account_exactly_once(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        $response = $this->changeFor($user);

        $response->assertOk();

        $writes = 0;
        Http::assertSent(function (Request $request) use (&$writes): bool {
            if ($request->method() === 'PUT') {
                $writes++;
            }

            return true;
        });

        $this->assertSame(1, $writes, 'A change replaces one credential, it does not mint a second one.');

        $row = FtpPasswordReset::query()->sole();

        $this->assertTrue($row->success);
        $this->assertSame($user->id, $row->user_id);
    }

    public function test_a_failing_audit_write_does_not_hand_out_a_password_after_a_successful_change(): void
    {
        $this->fakeSuccessfulChangeService();
        $user = $this->photographer();

        // Eloquent returns an unsaved model when a creating listener vetoes the
        // insert instead of throwing, so the service must turn this into a 500.
        FtpPasswordReset::creating(static fn (): bool => false);

        $response = $this->changeFor($user);

        $response->assertStatus(500);
        $this->assertNull(
            $response->json('password'),
            'A password whose rotation cannot be audited must not leave the system.',
        );
        $this->assertSame(0, FtpPasswordReset::query()->count());

        $verification = Http::recorded()->first(
            static fn (array $pair): bool => str_contains($pair[0]->url(), '/api/v2/user/token'),
        );
        $this->assertNotNull($verification, 'The current password must be checked before the write.');
        $this->assertSame(200, $verification[1]->status(), 'The fake accepts the current password.');

        $writes = Http::recorded()->filter(
            static fn (array $pair): bool => $pair[0]->method() === 'PUT',
        );
        $this->assertCount(1, $writes, 'The SFTPGo password write must have happened.');
        $writtenPassword = $writes->sole()[0]->data()['password'];
        $this->assertMatchesRegularExpression(FtpCredentialService::PASSWORD_PATTERN, $writtenPassword);
        $this->assertStringNotContainsString($writtenPassword, $response->getContent());
    }

    public function test_a_change_lock_timeout_returns_conflict_without_starting_an_attempt(): void
    {
        $user = $this->photographer();
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(10, \Mockery::type(Closure::class))
            ->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')
            ->once()
            ->with(FtpCredentialService::rotationLockKey((string) $user->getKey()), 120)
            ->andReturn($lock);

        $response = $this->changeFor($user);

        $response->assertStatus(409);
        $this->assertNull($response->json('password'));
        $this->assertSame(0, FtpPasswordReset::query()->count());
        $this->assertSame(
            0,
            RateLimiter::attempts(FtpCredentialService::resetQuotaKey((string) $user->getKey())),
            'A timeout before lock acquisition is not a credential-change attempt.',
        );
        Http::assertNothingSent();
    }

    /**
     * The confirmation has to travel to the service as the account's own
     * credentials — and in the header, not in the URL. SFTPGo's HTTP access log
     * contains the full request URL, so a password in the query string would be
     * written into a log file the portal neither owns nor can redact.
     */
    public function test_the_current_password_travels_in_the_header_and_not_in_the_url(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        $this->changeFor($user)->assertOk();

        $slug = (string) $user->ftp_slug;

        Http::assertSent(function (Request $request) use ($slug): bool {
            if (! str_contains($request->url(), '/api/v2/user/token')) {
                return true;
            }

            $this->assertStringNotContainsString(self::CURRENT_PASSWORD, $request->url());
            $this->assertSame(
                'Basic '.base64_encode($slug.':'.self::CURRENT_PASSWORD),
                $request->header('Authorization')[0],
            );

            return true;
        });
    }

    /**
     * The recovery path must not be able to lock a photographer out. As many
     * changes as `RESET_LIMIT_PER_HOUR` allows are served, the next one is
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
                ->postJson('/api/management/ftp/change-password', [
                    'current_password' => self::CURRENT_PASSWORD,
                ])
                ->assertOk();
        }

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/change-password', [
                'current_password' => self::CURRENT_PASSWORD,
            ]);

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
                ->postJson('/api/management/ftp/change-password', [
                    'current_password' => self::CURRENT_PASSWORD,
                ])
                ->assertOk();
        }

        $requestsBefore = count(Http::recorded());
        $rowsBefore = FtpPasswordReset::query()->count();

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/change-password', [
                'current_password' => self::CURRENT_PASSWORD,
            ]);

        $response->assertStatus(429);
        $this->assertNull($response->json('password'));
        $this->assertCount($requestsBefore, Http::recorded());
        $this->assertSame($rowsBefore, FtpPasswordReset::query()->count());
    }

    public function test_an_unauthenticated_caller_is_rejected(): void
    {
        $this->fakeSuccessfulService();

        $this->postJson('/api/management/ftp/change-password', [
            'current_password' => self::CURRENT_PASSWORD,
        ])->assertStatus(401);
    }

    /**
     * A client without the `management/ftp*` permission must not be able to
     * reach the endpoint at all.
     */
    public function test_a_client_cannot_change_a_camera_password(): void
    {
        $this->fakeSuccessfulService();
        $client = User::factory()->create();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($client)])
            ->postJson('/api/management/ftp/change-password', [
                'current_password' => self::CURRENT_PASSWORD,
            ]);

        $response->assertStatus(403);
        $this->assertSame(0, FtpPasswordReset::query()->count());
        Http::assertNothingSent();
    }

    /**
     * The confirmation is a required field. A missing or empty one is a 422
     * with the same error shape as every other refusal of this endpoint — the
     * frontend renders one message and does not have to know two formats.
     */
    public function test_a_missing_current_password_is_refused(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        $response = $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($user)])
            ->postJson('/api/management/ftp/change-password');

        $response->assertStatus(422);
        $this->assertNotEmpty($response->json('error'));
        $this->assertNull($response->json('password'));
        Http::assertNothingSent();
    }

    /**
     * A `pending` account has never been provisioned, so it has no current
     * password that could be confirmed — the credential comes from the
     * access-data request instead. Nothing may be written for it.
     */
    public function test_a_pending_account_is_refused(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer(status: FtpCredentialService::STATUS_PENDING);

        $response = $this->changeFor($user);

        $response->assertStatus(409);
        $response->assertJsonMissingPath('password')->assertJsonMissingPath('password_notice');
        Http::assertNothingSent();
        $this->assertFalse(FtpPasswordReset::query()->sole()->success);
        $this->assertTrue($user->fresh()->roles->contains('name', UserRole::PHOTOGRAPHER->value));
        $this->assertSame(FtpCredentialService::STATUS_PENDING, $user->fresh()->ftp_account_status);
    }

    /**
     * A `revoked` account was withdrawn deliberately (§7.16). A click must not
     * hand it a working SFTPGo access again — neither by provisioning nor by
     * rotating.
     */
    public function test_a_revoked_account_is_refused(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer(status: FtpCredentialService::STATUS_REVOKED);

        $response = $this->changeFor($user);

        $response->assertStatus(409);
        $response->assertJsonMissingPath('password')->assertJsonMissingPath('password_notice');
        Http::assertNothingSent();
        $this->assertFalse(FtpPasswordReset::query()->sole()->success);
        $this->assertTrue($user->fresh()->roles->contains('name', UserRole::PHOTOGRAPHER->value));
        $this->assertSame(FtpCredentialService::STATUS_REVOKED, $user->fresh()->ftp_account_status);
    }

    /**
     * There is no `user_id` in the contract. A caller that tries to name another
     * account changes their own, so the surface for draining somebody else's
     * quota — or writing rows under their id — is not merely guarded, it is not
     * offered.
     */
    public function test_the_endpoint_cannot_be_pointed_at_another_account(): void
    {
        $this->fakeSuccessfulService();
        $caller = $this->photographer(['ftp_slug' => 'florian', 'email' => 'florian@example.com']);
        $victim = $this->photographer(['ftp_slug' => 'sabine', 'email' => 'sabine@example.com']);

        $response = $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($caller)])
            ->postJson('/api/management/ftp/change-password', [
                'current_password' => self::CURRENT_PASSWORD,
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

        $response = $this->changeFor($this->photographer());

        $response->assertStatus(503);
        $this->assertNull($response->json('password'));

        // The failed attempt is still recorded: a photographer reporting "the
        // change does nothing" has to leave a trace somewhere.
        $this->assertFalse(FtpPasswordReset::query()->sole()->success);
    }

    /**
     * A service that answers something unusable is a 502: the service answered,
     * and the answer was not usable. It must not be mistaken for a wrong
     * password, which would tell the photographer to retype a password that is
     * perfectly fine.
     */
    public function test_an_unusable_service_answer_is_reported_as_a_bad_gateway(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/user/token' => Http::response(['error' => 'boom'], 500),
        ]);

        $response = $this->changeFor($this->photographer());

        $response->assertStatus(502);
        $this->assertNull($response->json('password'));
        $this->assertFalse(FtpPasswordReset::query()->sole()->success);
    }

    /**
     * The cached column can say `active` while the service does not know the
     * account — a hand-deleted user, which P1-M30 calls out as the reason the
     * column is a cache. That mismatch is a real 404 to the caller, and it is
     * audited as a failure, which is the signal that reconciliation is due.
     *
     * Both halves of the fake are needed for that state, and the order is the
     * one the endpoint works in: the confirmation is answered first (the
     * service accepts the typed password) and the account turns out to be
     * unknown only when the rotation reads it. A service that rejected the
     * confirmation would be a 422 — a wrong password, not an unknown account.
     */
    public function test_an_account_missing_in_the_service_is_reported_as_not_found(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/user/token' => Http::response([
                'access_token' => 'user-token',
                'expires_at' => '2099-01-01T00:00:00Z',
            ], 200),
            'sftpgo.test:8080/api/v2/users/*' => Http::response(['message' => 'not found'], 404),
        ]);

        $response = $this->changeFor($this->photographer());

        $response->assertStatus(404);
        $this->assertNull($response->json('password'));

        // The failed attempt is still recorded: a photographer reporting "the
        // change does nothing" has to leave a trace somewhere.
        $this->assertFalse(FtpPasswordReset::query()->sole()->success);
    }

    /**
     * A portal-side precondition is a 422, distinct from a service failure. The
     * photographer has to be able to tell "fix your profile" apart from "the
     * service is down" — and without an account name there is nothing to
     * confirm, so the request never leaves the portal.
     */
    public function test_a_missing_account_name_is_reported_as_an_unprocessable_request(): void
    {
        Http::fake();
        $user = $this->photographer();
        $user->forceFill(['ftp_slug' => null])->saveQuietly();

        $response = $this->changeFor($user);

        $response->assertStatus(422);
        $this->assertNull($response->json('password'));
        Http::assertNothingSent();
    }

    private function changeFor(User $user, string $currentPassword = self::CURRENT_PASSWORD)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($user)])
            ->postJson('/api/management/ftp/change-password', [
                'current_password' => $currentPassword,
            ]);
    }

    /**
     * The service as it answers a correct confirmation: the user endpoint
     * accepts the credentials, the account can be read, and the account can be
     * written.
     *
     * A closure rather than a `Http::sequence()` on purpose: a change costs
     * three requests (verification, read, write), and a test that changes the
     * password several times would exhaust a fixed sequence and fail with an
     * unrelated error. Answering per URL keeps the fake valid for every call
     * count while still pinning the three response shapes.
     */
    private function fakeSuccessfulService(): void
    {
        Http::fake($this->fakeService());
    }

    /**
     * The same service, but the user endpoint rejects the credentials: the
     * account exists, the password is simply not the right one.
     */
    private function fakeServiceRejectingTheCurrentPassword(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/user/token' => Http::response(['error' => 'invalid credentials'], 401),
            'sftpgo.test:8080/api/v2/users/*' => Http::response($this->account(), 200),
        ]);
    }

    private function fakeSuccessfulChangeService(): void
    {
        $account = $this->account();

        Http::fake(function (Request $request) use ($account) {
            if (str_contains($request->url(), '/api/v2/user/token')) {
                return Http::response([
                    'access_token' => 'user-token',
                    'expires_at' => '2099-01-01T00:00:00Z',
                ], 200);
            }

            if ($request->method() === 'PUT') {
                return Http::response(['message' => 'user updated'], 200);
            }

            return Http::response($account, 200);
        });
    }

    private function fakeService(): callable
    {
        $account = $this->account();

        return function (Request $request) use ($account) {
            if (str_contains($request->url(), '/api/v2/user/token')) {
                return Http::response([
                    'access_token' => 'user-token',
                    'expires_at' => '2099-01-01T00:00:00Z',
                ], 200);
            }

            if ($request->method() === 'PUT') {
                return Http::response(['message' => 'user updated'], 200);
            }

            return Http::response($account, 200);
        };
    }

    private function account(): array
    {
        return [
            'id' => 7,
            'username' => 'florian',
            'status' => 1,
            'home_dir' => $this->inboxRoot.'/florian',
            'permissions' => ['/' => ['*']],
        ];
    }

    private function photographer(array $attributes = [], string $status = FtpCredentialService::STATUS_ACTIVE): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        // The slug is the SFTPGo account name, so it has to satisfy
        // FtpSlug::isValid() — the single source of truth for that rule. An
        // explicit slug from the caller is kept; anything else is replaced,
        // because the service must refuse a non-conforming name and this test
        // is about the confirmation, not about the slug.
        if (! is_string($user->ftp_slug) || ! FtpSlug::isValid($user->ftp_slug)) {
            $user->forceFill(['ftp_slug' => 'florian'])->save();
        }

        // Everything in this file rotates a *live* account, so the state is named
        // instead of left to the column default. A `pending` account is
        // provisioned by the reset endpoint instead of rotated — a different
        // transition with a different audit contract. The column is not
        // mass-assignable and is written the way the service writes it.
        $user->forceFill(['ftp_account_status' => $status])->save();

        return $user->fresh();
    }
}
