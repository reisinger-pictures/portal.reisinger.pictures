<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Exceptions\FtpCredentialException;
use App\Exceptions\SftpGoException;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpCredentialService;
use Closure;
use Illuminate\Contracts\Cache\Lock;
use Illuminate\Contracts\Cache\LockTimeoutException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * §7.16: losing the photographer role — by role change or account deletion —
 * ends the SFTPGo camera account before the local role or user row changes.
 *
 * The invariant these tests pin: an SFTPGo account exists exactly when
 * `users.ftp_account_status === 'active'`. So the interesting cases are not
 * only "a role drops" but also the directions that must *not* fire — gaining
 * the role, or moving between two unrelated roles — and the outage case, where
 * fail-closed means the role change is refused and the database is untouched.
 *
 * SFTPGo is faked with the pattern `.../api/v2/users*` (no slash before the
 * wildcard): `Factory::stubUrl()` matches with `Str::is()`, and a stub that does
 * not match falls through to the real handler, which would send a genuine
 * request instead of failing the test. Same fixture contract as
 * `FtpSlugValidationTest`.
 */
class FtpCredentialRevocationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * A real, writable inbox root: `provisionAndShow()` creates `ftp/<slug>` on
     * disk before it creates the account (D-2).
     */
    private string $inboxRoot;

    private ?int $expectedSftpGoTransactionLevel = null;

    private int $deleteResponseStatus = 200;

    private const SFTPGO_BASE_URL = 'http://sftpgo.test:8080';

    protected function setUp(): void
    {
        parent::setUp();

        $this->expectedSftpGoTransactionLevel = null;
        $this->deleteResponseStatus = 200;

        $this->inboxRoot = $this->useTemporaryFtpInboxRoot();

        $this->configureSftpGo();
        $this->fakeSftpGo();
    }

    // ── Wiring: role change ──────────────────────────────────────────────────

    public function test_losing_the_photographer_role_revokes_the_camera_account(): void
    {
        $admin = $this->admin();
        $photographer = $this->photographer('max');
        $this->expectedSftpGoTransactionLevel = DB::transactionLevel();

        $response = $this->asAdmin($admin)->putJson(
            "/api/management/users/{$photographer->id}",
            ['role_ids' => [$this->roleId(UserRole::CLIENT)], 'brand' => Brand::B2B->value],
        );

        $response->assertOk();

        // The account is gone in SFTPGo, and the row records *when* it ended.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/api/v2/users/max'));

        $revoked = $photographer->fresh();
        $this->assertSame(FtpCredentialService::STATUS_REVOKED, $revoked->ftp_account_status);
        $this->assertNotNull($revoked->ftp_revoked_at);
        $this->assertTrue(
            $revoked->roles->pluck('name')->doesntContain(UserRole::PHOTOGRAPHER->value),
            'The role change itself must still have happened.',
        );
    }

    /**
     * The other direction. Without this the suite would also pass if the
     * controller revoked on *every* role update, which is the failure mode a
     * single happy-path test cannot see.
     */
    public function test_gaining_the_photographer_role_does_not_revoke(): void
    {
        $admin = $this->admin();
        $user = $this->client('max');

        $response = $this->asAdmin($admin)->putJson(
            "/api/management/users/{$user->id}",
            [
                'role_ids' => [$this->roleId(UserRole::CLIENT), $this->roleId(UserRole::PHOTOGRAPHER)],
                'brand' => Brand::B2B->value,
            ],
        );

        $response->assertOk();
        Http::assertNothingSent();
        $this->assertSame('pending', $user->fresh()->ftp_account_status);
        $this->assertNull($user->fresh()->ftp_revoked_at);
    }

    /**
     * A transition that has nothing to do with the camera must not reach the
     * service at all — the same assertion as above, for the role pair that
     * would sit closest to a wrong "photographer leaves" predicate.
     */
    public function test_changing_between_two_other_roles_does_not_contact_sftpgo(): void
    {
        $admin = $this->admin();
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach($this->roleId(UserRole::POWER_USER));

        $response = $this->asAdmin($admin)->putJson(
            "/api/management/users/{$user->id}",
            ['role_ids' => [$this->roleId(UserRole::CLIENT)], 'brand' => Brand::B2B->value],
        );

        $response->assertOk();
        Http::assertNothingSent();
    }

    // ── Wiring: account deletion ─────────────────────────────────────────────

    public function test_deleting_a_photographer_revokes_before_the_delete(): void
    {
        $admin = $this->admin();
        $photographer = $this->photographer('max');
        $this->expectedSftpGoTransactionLevel = DB::transactionLevel();

        $response = $this->asAdmin($admin)
            ->deleteJson("/api/management/users/{$photographer->id}");

        $response->assertOk();

        // The external delete is the observable proof that revocation ran; it
        // occurs before the row is deleted, but outside the database transaction.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/api/v2/users/max'));
        $this->assertDatabaseMissing('users', ['id' => $photographer->id]);
    }

    public function test_deleting_a_revoked_photographer_does_not_recreate_the_remote_account(): void
    {
        $admin = $this->admin();
        $photographer = $this->photographer('max', FtpCredentialService::STATUS_REVOKED);

        $this->asAdmin($admin)
            ->deleteJson("/api/management/users/{$photographer->id}")
            ->assertOk();

        Http::assertNothingSent();
        $this->assertDatabaseMissing('users', ['id' => $photographer->id]);
    }

    public function test_deletion_holds_the_shared_rotation_lock_through_revocation_and_row_delete(): void
    {
        $admin = $this->admin();
        $photographer = $this->photographer('max');
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
        User::deleting(function (User $deletingUser) use ($photographer, $service, &$nestedFailure): void {
            if (! $deletingUser->is($photographer)) {
                return;
            }

            try {
                $service->changeAndShow($deletingUser, 'current-camera-password');
            } catch (FtpCredentialException $exception) {
                $nestedFailure = $exception;
            }
        });

        $response = $this->asAdmin($admin)->deleteJson("/api/management/users/{$photographer->id}");

        $response->assertOk();
        $this->assertInstanceOf(FtpCredentialException::class, $nestedFailure);
        $this->assertSame(FtpCredentialException::REASON_ROTATION_IN_PROGRESS, $nestedFailure->reason);
        $this->assertDatabaseMissing('users', ['id' => $photographer->id]);
        Http::assertSentCount(1);
    }

    public function test_user_deletion_lock_timeout_returns_conflict_and_keeps_the_user(): void
    {
        $admin = $this->admin();
        $photographer = $this->photographer('max');
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(10, \Mockery::type(Closure::class))
            ->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')
            ->once()
            ->with(FtpCredentialService::rotationLockKey((string) $photographer->getKey()), 120)
            ->andReturn($lock);

        $response = $this->asAdmin($admin)->deleteJson("/api/management/users/{$photographer->id}");

        $response->assertStatus(409)->assertJsonStructure(['error']);
        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'ftp_account_status' => FtpCredentialService::STATUS_ACTIVE,
        ]);
        Http::assertNothingSent();
    }

    public function test_remote_revoke_failure_keeps_the_user_with_error_status(): void
    {
        $admin = $this->admin();
        $photographer = $this->photographer('max');
        $this->deleteResponseStatus = 503;

        $response = $this->asAdmin($admin)->deleteJson("/api/management/users/{$photographer->id}");

        $response->assertStatus(500);
        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'ftp_slug' => 'max',
            'ftp_account_status' => FtpCredentialService::STATUS_ERROR,
        ]);
        $this->assertNotEmpty($photographer->fresh()->ftp_account_error);
        $this->assertNull($photographer->fresh()->ftp_revoked_at);
        Http::assertSentCount(1);
    }

    public function test_retrying_a_failed_role_revocation_deletes_again_before_detaching_the_role(): void
    {
        $admin = $this->admin();
        $photographer = $this->photographer('max');
        $this->deleteResponseStatus = 503;

        $firstResponse = $this->asAdmin($admin)->putJson(
            "/api/management/users/{$photographer->id}",
            ['role_ids' => [$this->roleId(UserRole::CLIENT)], 'brand' => Brand::B2B->value],
        );

        $firstResponse->assertStatus(500);
        $afterFailure = $photographer->fresh();
        $this->assertSame(FtpCredentialService::STATUS_ERROR, $afterFailure->ftp_account_status);
        $this->assertTrue($afterFailure->roles->contains('name', UserRole::PHOTOGRAPHER->value));
        Http::assertSentCount(1);

        // The first 503 may have arrived after SFTPGo deleted the account. The
        // retry must still issue DELETE; 404 is the verified absent state.
        $this->deleteResponseStatus = 404;
        $this->asAdmin($admin)->putJson(
            "/api/management/users/{$photographer->id}",
            ['role_ids' => [$this->roleId(UserRole::CLIENT)], 'brand' => Brand::B2B->value],
        )->assertOk();

        Http::assertSentCount(2);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/api/v2/users/max'));
        $afterRetry = $photographer->fresh();
        $this->assertSame(FtpCredentialService::STATUS_REVOKED, $afterRetry->ftp_account_status);
        $this->assertTrue($afterRetry->roles->pluck('name')->doesntContain(UserRole::PHOTOGRAPHER->value));
    }

    public function test_retrying_user_deletion_accepts_an_already_missing_remote_account(): void
    {
        $admin = $this->admin();
        $photographer = $this->photographer('max');
        $this->deleteResponseStatus = 503;

        $this->asAdmin($admin)
            ->deleteJson("/api/management/users/{$photographer->id}")
            ->assertStatus(500);

        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'ftp_account_status' => FtpCredentialService::STATUS_ERROR,
        ]);
        Http::assertSentCount(1);

        // The remote delete may have completed despite the failed response.
        $this->deleteResponseStatus = 404;
        $this->asAdmin($admin)
            ->deleteJson("/api/management/users/{$photographer->id}")
            ->assertOk();

        Http::assertSentCount(2);
        $this->assertDatabaseMissing('users', ['id' => $photographer->id]);
    }

    public function test_admin_role_restoration_provisions_a_revoked_photographer_under_the_shared_lock(): void
    {
        $admin = $this->admin();
        $user = $this->client('max');
        $user->forceFill([
            'ftp_account_status' => FtpCredentialService::STATUS_REVOKED,
            'ftp_revoked_at' => now(),
        ])->save();
        $this->expectedSftpGoTransactionLevel = DB::transactionLevel();

        $response = $this->asAdmin($admin)->putJson(
            "/api/management/users/{$user->id}",
            [
                'role_ids' => [$this->roleId(UserRole::CLIENT), $this->roleId(UserRole::PHOTOGRAPHER)],
                'brand' => Brand::B2B->value,
            ],
        );

        $response->assertOk()->assertJsonMissingPath('password');
        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && $request->url() === self::SFTPGO_BASE_URL.'/api/v2/users'
            && ($request->data()['username'] ?? null) === 'max');

        $restored = $user->fresh();
        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $restored->ftp_account_status);
        $this->assertNull($restored->ftp_account_error);
        $this->assertTrue($restored->roles->contains('name', UserRole::PHOTOGRAPHER->value));
    }

    // ── Fail-closed ──────────────────────────────────────────────────────────

    /**
     * If SFTPGo is unreachable, the role change is refused and the user remains
     * present with an explicit `error` status for reconciliation. A live
     * credential must not outlive the role change, and a stale `active` status
     * must not claim the outcome is known.
     */
    public function test_a_role_change_is_refused_when_sftpgo_is_unreachable(): void
    {
        $admin = $this->admin();
        $photographer = $this->photographer('max');

        Http::fake([
            'sftpgo.test:8080/api/v2/users*' => fn () => throw new ConnectionException('connection refused'),
        ]);

        $this->withoutExceptionHandling();

        try {
            $this->asAdmin($admin)->putJson(
                "/api/management/users/{$photographer->id}",
                ['role_ids' => [$this->roleId(UserRole::CLIENT)], 'brand' => Brand::B2B->value],
            );

            $this->fail('The role change must be refused while SFTPGo is unreachable.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_UNREACHABLE, $exception->reason);
        }

        $unchanged = $photographer->fresh();
        $this->assertSame(FtpCredentialService::STATUS_ERROR, $unchanged->ftp_account_status);
        $this->assertNotEmpty($unchanged->ftp_account_error);
        $this->assertNull($unchanged->ftp_revoked_at);
        $this->assertTrue(
            $unchanged->roles->pluck('name')->contains(UserRole::PHOTOGRAPHER->value),
            'The role must not have been synced when the revocation failed.',
        );
    }

    // ── Service: idempotency ─────────────────────────────────────────────────

    public function test_a_second_revocation_is_a_no_op(): void
    {
        $photographer = $this->photographer('max');
        $service = app(FtpCredentialService::class);

        $service->revoke($photographer);
        $firstRevocation = $photographer->fresh()->ftp_revoked_at;
        $this->assertNotNull($firstRevocation);
        Http::assertSentCount(1);

        // Second call: no throw, no second SFTPGo request, timestamp preserved.
        $service->revoke($photographer->fresh());

        Http::assertSentCount(1);
        $this->assertTrue($firstRevocation->equalTo($photographer->fresh()->ftp_revoked_at));
        $this->assertSame(FtpCredentialService::STATUS_REVOKED, $photographer->fresh()->ftp_account_status);
    }

    /**
     * A photographer who never requested credentials has no account, so there
     * is nothing to delete and no way for the service to fail — the state still
     * has to move, because the role that could request it is gone.
     */
    public function test_revoking_a_never_provisioned_user_does_not_contact_sftpgo(): void
    {
        $photographer = $this->photographer('max', FtpCredentialService::STATUS_ACTIVE);
        $photographer->forceFill([
            'ftp_account_status' => 'pending',
            'ftp_provisioned_at' => null,
        ])->save();

        app(FtpCredentialService::class)->revoke($photographer->fresh());

        Http::assertNothingSent();
        $this->assertSame(FtpCredentialService::STATUS_REVOKED, $photographer->fresh()->ftp_account_status);
        $this->assertNotNull($photographer->fresh()->ftp_revoked_at);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function admin(): User
    {
        $admin = User::factory()->create(['brand' => Brand::B2B]);
        $admin->roles()->attach($this->roleId(UserRole::ADMIN));

        return $admin;
    }

    private function asAdmin(User $admin)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($admin)]);
    }

    private function photographer(string $slug, string $status = FtpCredentialService::STATUS_ACTIVE): User
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach($this->roleId(UserRole::PHOTOGRAPHER));
        $user->forceFill([
            'ftp_slug' => $slug,
            'ftp_account_status' => $status,
            'ftp_provisioned_at' => now(),
        ])->save();

        return $user->fresh();
    }

    private function client(string $slug): User
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach($this->roleId(UserRole::CLIENT));
        $user->forceFill([
            'ftp_slug' => $slug,
            'ftp_account_status' => 'pending',
        ])->save();

        return $user->fresh();
    }

    private function roleId(UserRole $role): string
    {
        return Role::firstOrCreate(['name' => $role->value])->id;
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

    private function fakeSftpGo(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/users*' => function (Request $request) {
                if ($this->expectedSftpGoTransactionLevel !== null) {
                    $this->assertSame(
                        $this->expectedSftpGoTransactionLevel,
                        DB::transactionLevel(),
                        'SFTPGo HTTP calls must not run inside a database transaction.',
                    );
                }

                $account = $this->accountIn($request);

                return match ($request->method()) {
                    'POST' => Http::response($this->sftpGoUser($account), 201),
                    'DELETE' => Http::response(['message' => 'user deleted'], $this->deleteResponseStatus),
                    default => Http::response($this->sftpGoUser($account), 200),
                };
            },
        ]);
    }

    private function accountIn(Request $request): string
    {
        if ($request->method() === 'POST') {
            return (string) ($request->data()['username'] ?? 'unknown');
        }

        return rawurldecode(basename((string) parse_url($request->url(), PHP_URL_PATH)));
    }

    /**
     * @return array<string, mixed>
     */
    private function sftpGoUser(string $account): array
    {
        return [
            'id' => 7,
            'username' => $account,
            'status' => 1,
            'home_dir' => $this->inboxRoot.'/'.$account,
            'description' => 'Portal FTP-Kamera-Zugang',
            'permissions' => ['/' => ['*']],
            'virtual_folders' => [],
        ];
    }
}
