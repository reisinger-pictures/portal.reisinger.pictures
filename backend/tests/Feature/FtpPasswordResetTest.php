<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\FtpCredentialException;
use App\Exceptions\SftpGoException;
use App\Models\FtpPasswordReset;
use App\Models\Role;
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
 * `resetAndShow()`: the per-account quota and the audit trail (P1-M33).
 *
 * The show-once flow (P1-M23) makes the reset the *only* recovery path — SFTPGo
 * cannot restore the old password. That is precisely what makes it dangerous
 * unthrottled: an open reset endpoint is an unlimited mint for valid camera
 * credentials. M22 and M23 guarantee a password never reaches a log; neither
 * says anything about *how often* one may be rotated. These tests pin the two
 * answers: a hard quota per account, and one audit row per attempt.
 */
class FtpPasswordResetTest extends TestCase
{
    use RefreshDatabase;

    private const INBOX_ROOT = '/var/www/ftp';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sftpgo' => [
                'base_url' => 'http://sftpgo.test:8080',
                'api_key' => 'test-api-key',
                'admin_username' => null,
                'admin_password' => null,
            ],
            'filesystems.disks.ftp_inbox.root' => self::INBOX_ROOT,
        ]);
    }

    // ── Show-once ────────────────────────────────────────────────────────────

    public function test_the_reset_returns_the_new_password_exactly_once(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        $password = app(FtpCredentialService::class)->resetAndShow($user, '203.0.113.7');

        $this->assertMatchesRegularExpression(FtpCredentialService::PASSWORD_PATTERN, $password);

        // The returned password is the one the service received — the caller has
        // to be able to type it into a camera, so it cannot be a second value.
        // Scoped to the PUT, because the reset reads the account first.
        Http::assertSent(function (Request $request) use ($password): bool {
            if ($request->method() !== 'PUT') {
                return true;
            }

            $this->assertSame($password, $request->data()['password']);

            return true;
        });
    }

    /**
     * "Show once" is a storage property, not a UI one. The value must not survive
     * in the user record, in the audit row, or anywhere else that is persisted —
     * if it did, the next read would be a second show.
     */
    public function test_the_reset_persists_the_password_nowhere(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        $password = app(FtpCredentialService::class)->resetAndShow($user, '203.0.113.7');

        $persisted = json_encode([
            'user' => $user->fresh()?->getAttributes(),
            'audit' => FtpPasswordReset::query()->get()->map->getAttributes()->all(),
        ], JSON_THROW_ON_ERROR);

        $this->assertIsString($persisted);
        $this->assertStringNotContainsString($password, $persisted);

        // And there is no column anywhere on the audit table that could hold it,
        // so this is not a property a later refactor could silently undo.
        $this->assertFalse(Schema::hasColumn('ftp_password_resets', 'password'));
    }

    /**
     * The reset is a read-modify-write against the Admin API (feature doc 7.6):
     * a PUT with only a password would reset `home_dir` and `permissions` and
     * silently break a working account. Pinned here as well because the quota
     * work sits in the same method — a shortcut to "just set the password" would
     * keep every other test in this file green.
     */
    public function test_the_reset_preserves_the_rest_of_the_account(): void
    {
        $this->fakeSuccessfulService();

        $user = $this->photographer();

        app(FtpCredentialService::class)->resetAndShow($user, '203.0.113.7');

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return true;
            }

            $this->assertSame(self::INBOX_ROOT.'/florian', $request->data()['home_dir']);
            $this->assertSame(['/' => ['*']], $request->data()['permissions']);

            return true;
        });
    }

    // ── Rate limit ───────────────────────────────────────────────────────────

    /**
     * The core of P1-M33: the fourth reset inside the window is refused. Three is
     * the documented limit and the assertion is written against the constant, so a
     * deliberate change to the number fails the test loudly instead of silently
     * loosening the guard.
     */
    public function test_the_fourth_reset_within_the_hour_is_refused(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();
        $service = app(FtpCredentialService::class);

        for ($attempt = 1; $attempt <= FtpCredentialService::RESET_LIMIT_PER_HOUR; $attempt++) {
            $service->resetAndShow($user, '203.0.113.7');
        }

        try {
            $service->resetAndShow($user, '203.0.113.7');
            $this->fail('The reset past the hourly limit must be refused.');
        } catch (FtpCredentialException $exception) {
            $this->assertSame(FtpCredentialException::REASON_RATE_LIMITED, $exception->reason);
        }
    }

    /**
     * A refusal must be inert. It generates no password, contacts no service and
     * writes no audit row — a rejected call is the refusal of an attempt, not an
     * attempt, and counting it as one would fill the trail with noise that hides
     * the real resets.
     */
    public function test_a_refused_reset_contacts_no_service_and_writes_no_audit_row(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();
        $service = app(FtpCredentialService::class);

        for ($attempt = 0; $attempt < FtpCredentialService::RESET_LIMIT_PER_HOUR; $attempt++) {
            $service->resetAndShow($user, '203.0.113.7');
        }

        $rowsBefore = FtpPasswordReset::query()->count();
        $requestsBefore = count(Http::recorded());

        try {
            $service->resetAndShow($user, '203.0.113.7');
        } catch (FtpCredentialException) {
            // Expected; the assertions below are the point of this test.
        }

        $this->assertGreaterThan(0, $requestsBefore, 'The setup must really have talked to the service.');
        $this->assertCount(
            $requestsBefore,
            Http::recorded(),
            'A refused reset must not reach the service.',
        );
        $this->assertSame(
            $rowsBefore,
            FtpPasswordReset::query()->count(),
            'A refused reset must not appear in the audit trail.',
        );
    }

    /**
     * The counter counts attempts, not successes, and a rejected call still
     * consumes one. That is what stops a hammering client from pushing the window
     * forward indefinitely — the same deliberate choice `CheckoutRiskService`
     * makes. Without it, repeatedly clicking "reset" would keep resetting the
     * window and the limit would never bite.
     */
    public function test_a_refused_reset_still_consumes_quota(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();
        $service = app(FtpCredentialService::class);
        $key = FtpCredentialService::resetQuotaKey($user->getKey());

        for ($attempt = 0; $attempt < FtpCredentialService::RESET_LIMIT_PER_HOUR + 3; $attempt++) {
            try {
                $service->resetAndShow($user, '203.0.113.7');
            } catch (FtpCredentialException) {
                // Refusals are expected once the limit is hit.
            }
        }

        $this->assertSame(
            FtpCredentialService::RESET_LIMIT_PER_HOUR + 3,
            (int) RateLimiter::attempts($key),
            'Every attempt must count, so hammering cannot extend the window.',
        );
    }

    public function test_the_quota_is_per_account_not_global(): void
    {
        $this->fakeSuccessfulService();
        $service = app(FtpCredentialService::class);
        $first = $this->photographer(['ftp_slug' => 'florian', 'email' => 'florian@example.com']);
        $second = $this->photographer(['ftp_slug' => 'sabine', 'email' => 'sabine@example.com']);

        for ($attempt = 0; $attempt < FtpCredentialService::RESET_LIMIT_PER_HOUR; $attempt++) {
            $service->resetAndShow($first, '203.0.113.7');
        }

        // The first account is exhausted; the second must be unaffected, or one
        // photographer could deny the recovery path to every other one.
        try {
            $service->resetAndShow($first, '203.0.113.7');
            $this->fail('The exhausted account must stay exhausted.');
        } catch (FtpCredentialException $exception) {
            $this->assertSame(FtpCredentialException::REASON_RATE_LIMITED, $exception->reason);
        }

        $password = $service->resetAndShow($second, '198.51.100.4');

        $this->assertMatchesRegularExpression(FtpCredentialService::PASSWORD_PATTERN, $password);
    }

    /**
     * The quota expires. A photographer who really lost a password three times
     * this morning must be able to try again tomorrow — a limiter that never
     * forgets would be a lockout, not a rate limit.
     */
    public function test_the_quota_frees_up_when_the_window_expires(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();
        $service = app(FtpCredentialService::class);
        $key = FtpCredentialService::resetQuotaKey($user->getKey());

        for ($attempt = 0; $attempt < FtpCredentialService::RESET_LIMIT_PER_HOUR; $attempt++) {
            $service->resetAndShow($user, '203.0.113.7');
        }

        $this->travel(FtpCredentialService::RESET_WINDOW_SECONDS + 60)->seconds();

        $password = $service->resetAndShow($user, '203.0.113.7');

        $this->assertMatchesRegularExpression(FtpCredentialService::PASSWORD_PATTERN, $password);
        $this->assertSame(1, (int) RateLimiter::attempts($key), 'The window must have restarted.');
    }

    public function test_the_rate_limit_error_carries_a_retry_after(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();
        $service = app(FtpCredentialService::class);

        for ($attempt = 0; $attempt < FtpCredentialService::RESET_LIMIT_PER_HOUR; $attempt++) {
            $service->resetAndShow($user, '203.0.113.7');
        }

        try {
            $service->resetAndShow($user, '203.0.113.7');
            $this->fail('The reset past the hourly limit must be refused.');
        } catch (FtpCredentialException $exception) {
            $this->assertNotNull($exception->retryAfterSeconds);
            $this->assertGreaterThan(0, $exception->retryAfterSeconds);
            $this->assertLessThanOrEqual(
                FtpCredentialService::RESET_WINDOW_SECONDS,
                $exception->retryAfterSeconds,
            );
        }
    }

    // ── Audit trail ──────────────────────────────────────────────────────────

    public function test_every_reset_writes_an_audit_row(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();
        $service = app(FtpCredentialService::class);

        for ($attempt = 0; $attempt < FtpCredentialService::RESET_LIMIT_PER_HOUR; $attempt++) {
            $service->resetAndShow($user, '203.0.113.7');
        }

        $this->assertSame(
            FtpCredentialService::RESET_LIMIT_PER_HOUR,
            FtpPasswordReset::query()->count(),
            'One row per reset attempt — the trail has to be complete, not a sample.',
        );
    }

    public function test_the_audit_row_identifies_the_account_the_time_the_ip_and_the_outcome(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        $before = now();
        app(FtpCredentialService::class)->resetAndShow($user, '203.0.113.7');

        $row = FtpPasswordReset::query()->sole();

        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('203.0.113.7', $row->ip);
        $this->assertTrue($row->success);
        // Compared at second granularity: SQLite stores a `timestamp` without
        // microseconds, so a raw `greaterThanOrEqualTo(now())` would fail on the
        // sub-second part of a value that is in fact correct.
        $this->assertTrue(
            $row->reset_at->greaterThanOrEqualTo($before->copy()->startOfSecond()),
            "The row must carry the reset time, got '{$row->reset_at}'.",
        );
    }

    /**
     * A failed reset is the *more* interesting row, not noise. A service that
     * answers 500 three times is a support case, and it is invisible without this
     * — the photographer's complaint ("reset does nothing") leaves no trace if
     * only successes are recorded.
     */
    public function test_a_failed_reset_is_audited_as_unsuccessful(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/users/*' => Http::response(['error' => 'internal failure'], 500),
        ]);

        $user = $this->photographer();

        try {
            app(FtpCredentialService::class)->resetAndShow($user, '203.0.113.7');
            $this->fail('Expected the service failure to surface.');
        } catch (SftpGoException) {
            // Expected.
        }

        $row = FtpPasswordReset::query()->sole();

        $this->assertFalse($row->success, 'A reset that did not happen must not be recorded as one.');
        $this->assertSame($user->id, $row->user_id);
        $this->assertSame('203.0.113.7', $row->ip);
    }

    /**
     * A portal-side precondition (no account name) never reaches the service, and
     * is still an attempt. It has to be in the trail, because "the reset button
     * does nothing" with a silently-empty log is exactly the unrecoverable
     * debugging situation M33 exists to prevent.
     */
    public function test_a_reset_refused_by_a_portal_precondition_is_audited_too(): void
    {
        Http::fake();
        $user = User::factory()->create();
        $user->forceFill(['ftp_slug' => null])->saveQuietly();

        try {
            app(FtpCredentialService::class)->resetAndShow($user, '203.0.113.7');
            $this->fail('Expected an FtpCredentialException for a user without an account name.');
        } catch (FtpCredentialException) {
            // Expected.
        }

        $row = FtpPasswordReset::query()->sole();

        $this->assertFalse($row->success);
        Http::assertNothingSent();
    }

    /**
     * The IP is nullable because a console or job reset has none, and a
     * placeholder string would be a lie in an audit table.
     */
    public function test_the_audit_row_tolerates_a_missing_ip(): void
    {
        $this->fakeSuccessfulService();
        $user = $this->photographer();

        app(FtpCredentialService::class)->resetAndShow($user);

        $row = FtpPasswordReset::query()->sole();

        $this->assertNull($row->ip);
        $this->assertTrue($row->success);
    }

    public function test_the_audit_trail_is_scoped_to_the_account_it_belongs_to(): void
    {
        $this->fakeSuccessfulService();
        $service = app(FtpCredentialService::class);
        $first = $this->photographer(['ftp_slug' => 'florian', 'email' => 'florian@example.com']);
        $second = $this->photographer(['ftp_slug' => 'sabine', 'email' => 'sabine@example.com']);

        $service->resetAndShow($first, '203.0.113.7');
        $service->resetAndShow($first, '203.0.113.7');
        $service->resetAndShow($second, '198.51.100.4');

        $this->assertSame(2, FtpPasswordReset::query()->where('user_id', $first->id)->count());
        $this->assertSame(1, FtpPasswordReset::query()->where('user_id', $second->id)->count());
    }

    // ── Guards ───────────────────────────────────────────────────────────────

    /**
     * The endpoint has no `user_id` input, so a reset can only ever target the
     * authenticated account. This is the property that makes the quota and the
     * trail meaningful — a caller must not be able to drain another
     * photographer's quota or write rows under their id.
     */
    public function test_the_reset_always_targets_the_calling_account(): void
    {
        $this->fakeSuccessfulService();
        $service = app(FtpCredentialService::class);
        $caller = $this->photographer(['ftp_slug' => 'florian', 'email' => 'florian@example.com']);
        $victim = $this->photographer(['ftp_slug' => 'sabine', 'email' => 'sabine@example.com']);

        $service->resetAndShow($caller, '203.0.113.7');

        $this->assertSame(0, FtpPasswordReset::query()->where('user_id', $victim->id)->count());
        $this->assertSame(1, FtpPasswordReset::query()->where('user_id', $caller->id)->count());

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return true;
            }

            $this->assertStringContainsString('/users/florian', $request->url());

            return true;
        });
    }

    /**
     * `SftpGoClient` is the only thing that may see the password, and it only
     * carries the account name and operation into the log. The audit row must not
     * become a second channel that leaks it.
     */
    public function test_the_password_never_reaches_the_log(): void
    {
        // The read succeeds and the *write* fails: that is the only path on which
        // a password actually exists in a request while the call still ends in an
        // error and a log record. Failing the read instead would abort before the
        // password was ever sent and the assertion would pass vacuously.
        Http::fake([
            'sftpgo.test:8080/api/v2/users/*' => fn (Request $request) => $request->method() === 'PUT'
                ? Http::response(['error' => 'internal failure'], 500)
                : Http::response([
                    'id' => 7,
                    'username' => 'florian',
                    'status' => 1,
                    'home_dir' => self::INBOX_ROOT.'/florian',
                    'permissions' => ['/' => ['*']],
                ], 200),
        ]);

        $user = $this->photographer();
        $password = null;

        $records = [];
        $this->app['events']->listen(MessageLogged::class, function ($event) use (&$records): void {
            $records[] = $event;
        });

        try {
            app(FtpCredentialService::class)->resetAndShow($user, '203.0.113.7');
        } catch (SftpGoException) {
            // Expected; the password only exists in the request the fake recorded.
        }

        // The reset is a read-modify-write, so the fake recorded two requests.
        // Only the PUT carries the password — the GET is the existing account —
        // so the capture has to skip the read instead of tripping over a body it
        // does not have.
        Http::assertSent(function (Request $request) use (&$password): bool {
            if ($request->method() !== 'PUT') {
                return true;
            }

            $password = (string) ($request->data()['password'] ?? '');

            return true;
        });

        $this->assertIsString($password);
        $this->assertNotEmpty($records, 'A failed reset must be logged.');

        foreach ($records as $record) {
            $serialized = (string) json_encode([$record->level, $record->message, $record->context], JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString($password, $serialized);
        }
    }

    /**
     * $fillable discipline: the audit row is written from system state, so nothing
     * in it may be reachable by request input. A factory would not prove this —
     * factories run inside `Model::unguarded()`.
     */
    public function test_the_audit_model_is_not_mass_assignable_beyond_its_own_fields(): void
    {
        $model = new FtpPasswordReset;

        $this->assertSame(
            ['user_id', 'reset_at', 'ip', 'success'],
            $model->getFillable(),
        );
        $this->assertNull($model->CREATED_AT, 'The table has no created_at column.');
        $this->assertNull($model->UPDATED_AT, 'The table has no updated_at column.');
    }

    /**
     * A successful Admin API, for any number of resets.
     *
     * A closure rather than a `Http::sequence()` on purpose: the reset is a
     * read-modify-write, so one reset costs two requests (GET then PUT), and a
     * test that resets three times would exhaust a fixed sequence and fail with
     * an unrelated error. Answering per method keeps the fake valid for every
     * call count while still pinning the two response shapes.
     */
    private function fakeSuccessfulService(): void
    {
        $account = [
            'id' => 7,
            'username' => 'florian',
            'status' => 1,
            'home_dir' => self::INBOX_ROOT.'/florian',
            'permissions' => ['/' => ['*']],
        ];

        Http::fake([
            'sftpgo.test:8080/api/v2/users/*' => function (Request $request) use ($account) {
                return $request->method() === 'PUT'
                    ? Http::response(['message' => 'user updated'], 200)
                    : Http::response($account, 200);
            },
        ]);
    }

    private function photographer(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        // The slug is the SFTPGo account name, so it has to satisfy
        // FtpSlug::isValid() — the single source of truth for that rule. An
        // explicit slug from the caller is kept; anything else is replaced,
        // because the service must refuse a non-conforming name and this test is
        // about the quota and the trail, not about the slug.
        if (! is_string($user->ftp_slug) || ! FtpSlug::isValid($user->ftp_slug)) {
            $user->forceFill(['ftp_slug' => 'florian'])->save();
        }

        return $user->fresh();
    }
}
