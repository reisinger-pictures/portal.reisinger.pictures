<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Exceptions\FtpCredentialException;
use App\Exceptions\SftpGoException;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpCredentialService;
use App\Values\FtpAccountReconciliation;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The explicit reconciliation of the cached FTP account state (P1-M30).
 *
 * `users.ftp_account_status` is a cache (feature doc 7.4): the read path may
 * not repair it, because a live query in the inbox endpoint would make the UI
 * depend on a service that may be down (7.5). `FtpCredentialService::
 * reconcileAccount()` is the repair, and it is called by the command and by
 * nothing else.
 *
 * The properties this suite holds are the ones a test can hold and a reader
 * cannot guess:
 *
 * 1. **The truth wins.** What SFTPGo answers decides the state: an account it
 *    knows is `active`, one it does not know (or has disabled) is withdrawn.
 * 2. **An unknown outcome changes nothing.** If SFTPGo cannot be reached, the
 *    status stays exactly as it was — a cache repair must not take a working
 *    camera away because a service had an outage. The reason is recorded and
 *    the exception is thrown.
 * 3. **The deliberate states are not touched.** `pending` is the state in
 *    which a photographer may ask for credentials (P1-M58) and `revoked` is a
 *    decision with an audit trail (§7.16); a cache correction may not undo
 *    either of them.
 *
 * SFTPGo is faked; no test here needs a running instance.
 */
class FtpAccountReconciliationTest extends TestCase
{
    use RefreshDatabase;

    private const SFTPGO_BASE_URL = 'http://sftpgo.test:8080';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureSftpGo();
    }

    public function test_an_account_that_sftpgo_knows_becomes_active(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_PENDING);
        $this->fakeSftpGo(['florian' => 1]);

        $result = app(FtpCredentialService::class)->reconcileAccount($user);

        $this->assertSame(FtpAccountReconciliation::OUTCOME_ACTIVE, $result->outcome);
        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $result->status);

        $fresh = $user->fresh();
        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $fresh->ftp_account_status);
        $this->assertNotNull($fresh->ftp_provisioned_at, 'A usable account has to be recorded as provisioned.');
        $this->assertNull($fresh->ftp_account_error);
    }

    public function test_an_account_that_sftpgo_does_not_know_becomes_revoked(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_ACTIVE);
        $provisionedAt = '2026-09-26 10:11:12';
        $user->forceFill(['ftp_provisioned_at' => $provisionedAt])->save();

        $this->fakeSftpGo([]);

        $result = app(FtpCredentialService::class)->reconcileAccount($user->fresh());

        $this->assertSame(FtpAccountReconciliation::OUTCOME_WITHDRAWN, $result->outcome);
        $this->assertSame(FtpCredentialService::STATUS_REVOKED, $result->status);

        $fresh = $user->fresh();
        $this->assertSame(FtpCredentialService::STATUS_REVOKED, $fresh->ftp_account_status);
        $this->assertNotNull($fresh->ftp_revoked_at, 'A withdrawn account has to say when it ended.');
        $this->assertNull($fresh->ftp_account_error);

        // The provisioning instant is history: it describes the past
        // provisioning, and the revocation instant describes the end. Both
        // together read "provisioned at X, revoked at Y".
        $this->assertTrue(
            Carbon::parse($fresh->ftp_provisioned_at)->equalTo(Carbon::parse($provisionedAt)),
            'The reconciliation must not overwrite the provisioning instant.',
        );
    }

    /**
     * The fail-closed case, and the reason the whole method exists in this
     * shape: an outage is not evidence that the account is gone.
     */
    public function test_an_unreachable_sftpgo_changes_nothing_and_records_the_reason(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_ACTIVE);
        $user->forceFill(['ftp_provisioned_at' => '2026-09-26 10:11:12'])->save();

        Http::fake([
            'sftpgo.test:8080/api/v2/users*' => fn () => throw new ConnectionException('connection refused'),
        ]);

        try {
            app(FtpCredentialService::class)->reconcileAccount($user->fresh());
            $this->fail('An unreachable SFTPGo must not be reported as a reconciled state.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_UNREACHABLE, $exception->reason);
            $this->assertStringNotContainsString('test-api-key', $exception->getMessage());
        }

        $fresh = $user->fresh();
        $this->assertSame(
            FtpCredentialService::STATUS_ACTIVE,
            $fresh->ftp_account_status,
            'An unknown outcome must not change the status.',
        );
        $this->assertNull($fresh->ftp_revoked_at);
        $this->assertNotNull($fresh->ftp_account_error, 'The reason has to be recorded.');
        $this->assertStringContainsString('nicht erreichbar', $fresh->ftp_account_error);
        $this->assertStringNotContainsString('test-api-key', $fresh->ftp_account_error);
    }

    public function test_an_unconfigured_sftpgo_changes_nothing(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_ACTIVE);

        config([
            'services.sftpgo.api_key' => null,
            'services.sftpgo.admin_username' => null,
            'services.sftpgo.admin_password' => null,
        ]);
        Http::fake();

        try {
            app(FtpCredentialService::class)->reconcileAccount($user->fresh());
            $this->fail('An unconfigured SFTPGo must not be reported as a reconciled state.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_NOT_CONFIGURED, $exception->reason);
        }

        $fresh = $user->fresh();
        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $fresh->ftp_account_status);
        $this->assertNull($fresh->ftp_revoked_at);
        $this->assertStringContainsString('nicht konfiguriert', (string) $fresh->ftp_account_error);
    }

    /**
     * A disabled account exists but cannot be used: the camera will not
     * authenticate with it. SFTPGo's own `status` is the answer to "may this
     * account be used?", so a withdrawn access is reported as withdrawn.
     */
    public function test_a_disabled_account_counts_as_withdrawn(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_ACTIVE);
        $this->fakeSftpGo(['florian' => 0]);

        $result = app(FtpCredentialService::class)->reconcileAccount($user->fresh());

        $this->assertSame(FtpAccountReconciliation::OUTCOME_WITHDRAWN, $result->outcome);
        $this->assertSame(FtpAccountReconciliation::REASON_ACCOUNT_DISABLED, $result->reason);

        $fresh = $user->fresh();
        $this->assertSame(FtpCredentialService::STATUS_REVOKED, $fresh->ftp_account_status);
        $this->assertNotNull($fresh->ftp_revoked_at);
    }

    /**
     * `pending` means "never provisioned" — and it is the state in which the
     * photographer may ask for credentials (P1-M58). A reconciliation that
     * turned it into `revoked` would take that door away from somebody who
     * never had an account.
     */
    public function test_an_never_provisioned_account_stays_pending(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_PENDING);
        $this->fakeSftpGo([]);

        $result = app(FtpCredentialService::class)->reconcileAccount($user->fresh());

        $this->assertSame(FtpAccountReconciliation::OUTCOME_UNCHANGED, $result->outcome);

        $fresh = $user->fresh();
        $this->assertSame(FtpCredentialService::STATUS_PENDING, $fresh->ftp_account_status);
        $this->assertNull($fresh->ftp_revoked_at, 'A never provisioned account was never revoked.');
    }

    /**
     * An `error` state means "the last provisioning attempt failed, the
     * outcome is unknown". The reconciliation resolves that unknown: there is
     * no account. That is not a revocation (the portal never had one to
     * withdraw) — it is the state in which a credential may be requested again.
     */
    public function test_an_unknown_error_state_is_resolved_when_no_account_exists(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_ERROR);
        $user->forceFill(['ftp_account_error' => 'Home-Verzeichnis fehlt auf dem Host.'])->save();

        $this->fakeSftpGo([]);

        $result = app(FtpCredentialService::class)->reconcileAccount($user->fresh());

        $this->assertSame(FtpAccountReconciliation::OUTCOME_WITHDRAWN, $result->outcome);
        $this->assertSame(FtpCredentialService::STATUS_PENDING, $result->status);

        $fresh = $user->fresh();
        $this->assertSame(FtpCredentialService::STATUS_PENDING, $fresh->ftp_account_status);
        $this->assertNull($fresh->ftp_account_error, 'The stale error text has to go with the state it described.');
    }

    /**
     * The one direction the reconciliation does not resolve on its own: the
     * portal revoked the account deliberately (role loss, §7.16) and somebody
     * recreated it in SFTPGo by hand. The status stays `revoked` — undoing a
     * deliberate revocation is an operator's decision, not a side effect of a
     * cache repair — but the divergence is reported.
     */
    public function test_a_revoked_account_is_not_resurrected(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_REVOKED);
        $user->forceFill(['ftp_revoked_at' => '2026-09-26 10:11:12'])->save();

        $this->fakeSftpGo(['florian' => 1]);

        $result = app(FtpCredentialService::class)->reconcileAccount($user->fresh());

        $this->assertSame(FtpAccountReconciliation::OUTCOME_DIVERGENT, $result->outcome);
        $this->assertSame(FtpAccountReconciliation::REASON_REVOKED_ACCOUNT_FOUND, $result->reason);

        $fresh = $user->fresh();
        $this->assertSame(FtpCredentialService::STATUS_REVOKED, $fresh->ftp_account_status);
        $this->assertTrue(
            Carbon::parse($fresh->ftp_revoked_at)->equalTo(Carbon::parse('2026-09-26 10:11:12')),
            'The revocation instant must not be moved.',
        );
    }

    public function test_reconciliation_waits_for_the_shared_lock_and_uses_the_fresh_slug_and_status(): void
    {
        $user = $this->photographer('old-slug', FtpCredentialService::STATUS_ACTIVE);
        $expectedTransactionLevel = DB::transactionLevel();
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(10, \Mockery::type(Closure::class))
            ->andReturnUsing(function (int $seconds, Closure $callback) use ($user): mixed {
                // A slug replacement has completed while reconciliation was
                // waiting: its failed provisioning left the new slug in error.
                DB::table('users')->where('id', $user->getKey())->update([
                    'ftp_slug' => 'new-slug',
                    'ftp_account_status' => FtpCredentialService::STATUS_ERROR,
                    'ftp_account_error' => 'Provisioning outcome is unknown.',
                ]);

                return $callback();
            });
        Cache::shouldReceive('lock')
            ->once()
            ->with(FtpCredentialService::rotationLockKey((string) $user->getKey()), 120)
            ->andReturn($lock);
        Http::fake(function (Request $request) use ($expectedTransactionLevel) {
            $this->assertSame($expectedTransactionLevel, DB::transactionLevel());
            $this->assertSame(self::SFTPGO_BASE_URL.'/api/v2/users/new-slug', $request->url());

            return Http::response(['message' => 'user not found'], 404);
        });

        $result = app(FtpCredentialService::class)->reconcileAccount($user);

        $this->assertSame(FtpAccountReconciliation::OUTCOME_WITHDRAWN, $result->outcome);
        $this->assertSame(FtpAccountReconciliation::REASON_ACCOUNT_MISSING, $result->reason);
        $this->assertSame(FtpCredentialService::STATUS_PENDING, $result->status);
        $this->assertTrue($result->changed);
        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'ftp_slug' => 'new-slug',
            'ftp_account_status' => FtpCredentialService::STATUS_PENDING,
            'ftp_account_error' => null,
        ]);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => str_ends_with($request->url(), '/api/v2/users/old-slug'));
    }

    public function test_lock_timeout_returns_retryable_without_sftpgo_or_a_status_write(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_ACTIVE);
        DB::table('users')->where('id', $user->getKey())->update([
            'ftp_account_status' => FtpCredentialService::STATUS_ERROR,
            'ftp_account_error' => 'Another operation is in progress.',
        ]);
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(10, \Mockery::type(Closure::class))
            ->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')
            ->once()
            ->with(FtpCredentialService::rotationLockKey((string) $user->getKey()), 120)
            ->andReturn($lock);
        Http::fake();

        $result = app(FtpCredentialService::class)->reconcileAccount($user);

        $this->assertSame(FtpAccountReconciliation::OUTCOME_RETRYABLE, $result->outcome);
        $this->assertSame(FtpAccountReconciliation::REASON_ROTATION_IN_PROGRESS, $result->reason);
        $this->assertSame(FtpCredentialService::STATUS_ERROR, $result->status);
        $this->assertFalse($result->changed);
        $this->assertDatabaseHas('users', [
            'id' => $user->getKey(),
            'ftp_account_status' => FtpCredentialService::STATUS_ERROR,
            'ftp_account_error' => 'Another operation is in progress.',
        ]);
        Http::assertNothingSent();
    }

    public function test_deleted_user_is_skipped_after_lock_acquisition_without_sftpgo(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_ACTIVE);
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(10, \Mockery::type(Closure::class))
            ->andReturnUsing(function (int $seconds, Closure $callback) use ($user): mixed {
                DB::table('users')->where('id', $user->getKey())->delete();

                return $callback();
            });
        Cache::shouldReceive('lock')
            ->once()
            ->with(FtpCredentialService::rotationLockKey((string) $user->getKey()), 120)
            ->andReturn($lock);
        Http::fake();

        $result = app(FtpCredentialService::class)->reconcileAccount($user);

        $this->assertSame(FtpAccountReconciliation::OUTCOME_SKIPPED, $result->outcome);
        $this->assertSame(FtpAccountReconciliation::REASON_USER_DELETED, $result->reason);
        $this->assertSame('deleted', $result->status);
        $this->assertFalse($result->changed);
        $this->assertDatabaseMissing('users', ['id' => $user->getKey()]);
        Http::assertNothingSent();
    }

    /**
     * The reconciliation only ever reads. A write would be a second way to
     * change a camera account behind the photographer's back — and it would
     * put a camera password on the wire.
     */
    public function test_the_reconciliation_only_reads_from_sftpgo(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_ACTIVE);
        $this->fakeSftpGo(['florian' => 1]);

        app(FtpCredentialService::class)->reconcileAccount($user->fresh());

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'GET'
            && str_ends_with($request->url(), '/api/v2/users/florian'));
    }

    public function test_an_account_without_a_usable_name_is_refused_before_any_request(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_ACTIVE);
        $user->forceFill(['ftp_slug' => 'j.doe'])->save();

        Http::fake();

        $this->expectException(FtpCredentialException::class);

        try {
            app(FtpCredentialService::class)->reconcileAccount($user->fresh());
        } finally {
            Http::assertNothingSent();
        }
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function photographer(string $slug, string $status): User
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value])->id);
        $user->forceFill([
            'ftp_slug' => $slug,
            'ftp_account_status' => $status,
        ])->save();

        return $user->fresh();
    }

    private function configureSftpGo(): void
    {
        config([
            'services.sftpgo' => [
                'base_url' => self::SFTPGO_BASE_URL,
                'api_key' => 'test-api-key',
                'admin_username' => null,
                'admin_password' => null,
            ],
        ]);
    }

    /**
     * SFTPGo knows exactly the accounts named here; every other name answers
     * 404. The value is the account's `status` (1 = enabled, 0 = disabled).
     *
     * @param  array<string, int>  $known
     */
    private function fakeSftpGo(array $known = []): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/users*' => function (Request $request) use ($known) {
                $account = rawurldecode(basename((string) parse_url($request->url(), PHP_URL_PATH)));

                if (! array_key_exists($account, $known)) {
                    return Http::response(['message' => 'user not found'], 404);
                }

                return Http::response([
                    'id' => 7,
                    'username' => $account,
                    'status' => $known[$account],
                    'home_dir' => '/var/www/ftp/'.$account,
                    'description' => 'Portal FTP-Kamera-Zugang',
                    'permissions' => ['/' => ['*']],
                    'virtual_folders' => [],
                ], 200);
            },
        ]);
    }
}
