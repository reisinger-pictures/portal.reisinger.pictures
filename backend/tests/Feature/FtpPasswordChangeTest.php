<?php

namespace Tests\Feature;

use App\Exceptions\FtpCredentialException;
use App\Exceptions\SftpGoException;
use App\Models\FtpPasswordReset;
use App\Models\User;
use App\Services\FtpCredentialService;
use App\Support\FtpSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * `changeAndShow()`: the confirmation, the order of the steps and the audit
 * trail (D-51).
 *
 * The show-once flow (P1-M23) makes the portal a system that hands out camera
 * passwords without ever keeping one. That is exactly what makes a *change*
 * different from a reset: the portal cannot check a password it does not have,
 * so the check has to happen against the service — and the order in which the
 * portal does its own work decides whether a failed confirmation can still
 * invalidate a working camera. These tests pin that order, the trail it
 * leaves and the storage property that makes the whole flow safe.
 */
class FtpPasswordChangeTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The password the camera account currently holds. It is the confirmation
     * that is verified against SFTPGo.
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

    // ── Show-once ────────────────────────────────────────────────────────────

    public function test_the_change_returns_a_camera_usable_password(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        $password = app(FtpCredentialService::class)->changeAndShow($user, self::CURRENT_PASSWORD, '203.0.113.7');

        $this->assertMatchesRegularExpression(FtpCredentialService::PASSWORD_PATTERN, $password);

        // The returned password is the one the service received — the camera has
        // to be able to type it in, so it cannot be a second value.
        Http::assertSent(function (Request $request) use ($password): bool {
            if ($request->method() !== 'PUT') {
                return true;
            }

            $this->assertSame($password, $request->data()['password']);

            return true;
        });
    }

    /**
     * "Show once" is a storage property, not a UI one. The new password must
     * not survive in the user record, in the audit row, or anywhere else that
     * is persisted — if it did, the next read would be a second show. The
     * *current* password has the stronger property still: it must not survive
     * anywhere at all, because the portal never owned it.
     */
    public function test_neither_the_current_nor_the_new_password_is_persisted(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        $password = app(FtpCredentialService::class)->changeAndShow($user, self::CURRENT_PASSWORD, '203.0.113.7');

        $persisted = json_encode([
            'user' => $user->fresh()?->getAttributes(),
            'audit' => FtpPasswordReset::query()->get()->map->getAttributes()->all(),
        ], JSON_THROW_ON_ERROR);

        $this->assertIsString($persisted);
        $this->assertStringNotContainsString($password, $persisted);
        $this->assertStringNotContainsString(self::CURRENT_PASSWORD, $persisted);

        // And there is no column anywhere on the audit table that could hold it,
        // so this is not a property a later refactor could silently undo.
        $this->assertFalse(Schema::hasColumn('ftp_password_resets', 'password'));
    }

    /**
     * The current password is a secret of the photographer, and it passes
     * through this class on its way to the service. It must not reach a log
     * line — neither the new password (which the write carries) nor the
     * current one (which the verification carries).
     */
    public function test_no_password_reaches_the_log(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        $records = [];
        $this->app['events']->listen(MessageLogged::class, function ($event) use (&$records): void {
            $records[] = $event;
        });

        $password = app(FtpCredentialService::class)->changeAndShow($user, self::CURRENT_PASSWORD, '203.0.113.7');

        $this->assertNotEmpty($records, 'A change must be logged.');

        foreach ($records as $record) {
            $serialized = (string) json_encode([$record->level, $record->message, $record->context], JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString($password, $serialized);
            $this->assertStringNotContainsString(self::CURRENT_PASSWORD, $serialized);
        }
    }

    // ── Order ────────────────────────────────────────────────────────────────

    /**
     * The confirmation is checked before the account is written. That order is
     * the whole point of the flow: a rejected confirmation must not invalidate
     * the credential the camera is holding right now.
     */
    public function test_the_current_password_is_verified_before_the_account_is_written(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        app(FtpCredentialService::class)->changeAndShow($user, self::CURRENT_PASSWORD, '203.0.113.7');

        $requests = Http::recorded()->map(fn (array $pair): Request => $pair[0])->values();

        $verification = $requests->search(fn (Request $request): bool => str_contains($request->url(), '/api/v2/user/token'));
        $write = $requests->search(fn (Request $request): bool => $request->method() === 'PUT');

        $this->assertNotFalse($verification, 'The confirmation has to reach the service.');
        $this->assertNotFalse($write, 'The account has to be written.');
        $this->assertLessThan(
            $write,
            $verification,
            'The confirmation has to be checked before the rotation.',
        );
    }

    /**
     * A wrong current password writes nothing to the service. The rotation is
     * the only write of this flow, and a refused confirmation may not reach it.
     */
    public function test_a_wrong_current_password_writes_nothing_to_the_service(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/user/token' => Http::response(['error' => 'invalid credentials'], 401),
            'sftpgo.test:8080/api/v2/users/*' => Http::response($this->account(), 200),
        ]);

        $user = $this->photographer();

        try {
            app(FtpCredentialService::class)->changeAndShow($user, 'falschesPasswort01', '203.0.113.7');
            $this->fail('A wrong current password must be refused.');
        } catch (FtpCredentialException $exception) {
            $this->assertSame(FtpCredentialException::REASON_INVALID_CURRENT_PASSWORD, $exception->reason);
        }

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    /**
     * The quota is consumed before the service is contacted at all. A refused
     * call performs no request — not even the confirmation — because a
     * throttled call must not ask the service a question it is not allowed to
     * act on.
     */
    public function test_a_quota_rejected_change_performs_no_service_call_at_all(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();
        $service = app(FtpCredentialService::class);

        for ($attempt = 0; $attempt < FtpCredentialService::RESET_LIMIT_PER_HOUR; $attempt++) {
            $service->changeAndShow($user, self::CURRENT_PASSWORD, '203.0.113.7');
        }

        $requestsBefore = count(Http::recorded());
        $rowsBefore = FtpPasswordReset::query()->count();

        $this->assertGreaterThan(0, $requestsBefore, 'The setup must really have talked to the service.');

        try {
            $service->changeAndShow($user, self::CURRENT_PASSWORD, '203.0.113.7');
            $this->fail('The change past the hourly limit must be refused.');
        } catch (FtpCredentialException $exception) {
            $this->assertSame(FtpCredentialException::REASON_RATE_LIMITED, $exception->reason);
        }

        $this->assertCount(
            $requestsBefore,
            Http::recorded(),
            'A refused change must not reach the service — not even to verify the password.',
        );
        $this->assertSame(
            $rowsBefore,
            FtpPasswordReset::query()->count(),
            'A refused change must not appear in the audit trail.',
        );
    }

    /**
     * The quota is the one of the reset: a change is the same act on the same
     * credential, so it shares the bucket. The key is the same, which is what
     * makes the two paths unable to exceed the limit together.
     */
    public function test_the_change_uses_the_quota_of_the_reset(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();
        $service = app(FtpCredentialService::class);

        for ($attempt = 0; $attempt < FtpCredentialService::RESET_LIMIT_PER_HOUR; $attempt++) {
            $service->changeAndShow($user, self::CURRENT_PASSWORD, '203.0.113.7');
        }

        $this->assertSame(
            FtpCredentialService::RESET_LIMIT_PER_HOUR,
            (int) RateLimiter::attempts(FtpCredentialService::resetQuotaKey($user->getKey())),
            'The change has to consume the quota of the reset, not a bucket of its own.',
        );
    }

    // ── Audit trail ──────────────────────────────────────────────────────────

    public function test_a_successful_change_writes_an_audit_row(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        $before = now();
        app(FtpCredentialService::class)->changeAndShow($user, self::CURRENT_PASSWORD, '203.0.113.7');

        $row = FtpPasswordReset::query()->sole();

        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('203.0.113.7', $row->ip);
        $this->assertTrue($row->success);
        // Compared at second granularity: SQLite stores a `timestamp` without
        // microseconds, so a raw `greaterThanOrEqualTo(now())` would fail on the
        // sub-second part of a value that is in fact correct.
        $this->assertTrue(
            $row->reset_at->greaterThanOrEqualTo($before->copy()->startOfSecond()),
            "The row must carry the time of the attempt, got '{$row->reset_at}'.",
        );
    }

    /**
     * A refused confirmation is the *more* interesting row, not noise: it is
     * what a photographer who cannot change their password looks like from the
     * outside.
     */
    public function test_a_refused_confirmation_is_audited_as_unsuccessful(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/user/token' => Http::response(['error' => 'invalid credentials'], 401),
            'sftpgo.test:8080/api/v2/users/*' => Http::response($this->account(), 200),
        ]);

        $user = $this->photographer();

        try {
            app(FtpCredentialService::class)->changeAndShow($user, 'falschesPasswort01', '203.0.113.7');
            $this->fail('A wrong current password must be refused.');
        } catch (FtpCredentialException) {
            // Expected; the row is the point of this test.
        }

        $row = FtpPasswordReset::query()->sole();

        $this->assertFalse($row->success, 'A change that did not happen must not be recorded as one.');
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('203.0.113.7', $row->ip);
    }

    /**
     * A portal-side precondition (an account state that may not be re-issued)
     * never reaches the service and is still an attempt. It has to be in the
     * trail, because "the change button does nothing" with a silently empty
     * log is exactly the unrecoverable debugging situation the trail exists to
     * prevent.
     */
    public function test_a_change_refused_by_a_portal_precondition_is_audited_too(): void
    {
        Http::fake();
        $user = $this->photographer(status: FtpCredentialService::STATUS_REVOKED);

        try {
            app(FtpCredentialService::class)->changeAndShow($user, self::CURRENT_PASSWORD, '203.0.113.7');
            $this->fail('Expected an FtpCredentialException for a revoked account.');
        } catch (FtpCredentialException $exception) {
            $this->assertSame(FtpCredentialException::REASON_NOT_RESETTABLE, $exception->reason);
        }

        $row = FtpPasswordReset::query()->sole();

        $this->assertFalse($row->success);
        Http::assertNothingSent();
    }

    /**
     * A service failure is a failed attempt as well — and the password that was
     * never written is not handed out.
     */
    public function test_a_service_failure_is_audited_as_unsuccessful(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/user/token' => Http::response(['error' => 'internal failure'], 500),
        ]);

        $user = $this->photographer();

        try {
            app(FtpCredentialService::class)->changeAndShow($user, self::CURRENT_PASSWORD, '203.0.113.7');
            $this->fail('Expected the service failure to surface.');
        } catch (SftpGoException) {
            // Expected.
        }

        $this->assertFalse(FtpPasswordReset::query()->sole()->success);
    }

    /**
     * The IP is nullable because a console or job change has none, and a
     * placeholder string would be a lie in an audit table.
     */
    public function test_the_audit_row_tolerates_a_missing_ip(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        app(FtpCredentialService::class)->changeAndShow($user, self::CURRENT_PASSWORD);

        $row = FtpPasswordReset::query()->sole();

        $this->assertNull($row->ip);
        $this->assertTrue($row->success);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * The service as it answers a correct confirmation: the user endpoint
     * accepts the credentials, the account can be read, and the account can be
     * written.
     *
     * A closure rather than a `Http::sequence()` on purpose: a change costs
     * three requests (verification, read, write), and a test that changes the
     * password several times would exhaust a fixed sequence and fail with an
     * unrelated error.
     */
    private function fakeSuccessfulService(): void
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

        // The slug is the SFTPGo account name, so it has to satisfy
        // FtpSlug::isValid() — the single source of truth for that rule. An
        // explicit slug from the caller is kept; anything else is replaced,
        // because the service must refuse a non-conforming name and this test
        // is about the confirmation, not about the slug.
        if (! is_string($user->ftp_slug) || ! FtpSlug::isValid($user->ftp_slug)) {
            $user->forceFill(['ftp_slug' => 'florian'])->save();
        }

        // Everything in this file rotates a *live* account, so the state is named
        // instead of left to the column default. The column is not
        // mass-assignable and is written the way the service writes it.
        $user->forceFill(['ftp_account_status' => $status])->save();

        return $user->fresh();
    }
}
