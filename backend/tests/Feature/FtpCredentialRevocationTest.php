<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Exceptions\SftpGoException;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * §7.16: losing the photographer role — by role change or account deletion —
 * ends the SFTPGo camera account, and nothing that writes happens before it.
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

    private const INBOX_ROOT = '/var/www/ftp';

    private const SFTPGO_BASE_URL = 'http://sftpgo.test:8080';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureSftpGo();
        $this->fakeSftpGo();
    }

    // ── Wiring: role change ──────────────────────────────────────────────────

    public function test_losing_the_photographer_role_revokes_the_camera_account(): void
    {
        $admin = $this->admin();
        $photographer = $this->photographer('max');

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

        $response = $this->asAdmin($admin)
            ->deleteJson("/api/management/users/{$photographer->id}");

        $response->assertOk();

        // The external delete is the observable proof that revocation ran; the
        // row is gone afterwards, so the ordering is "account first, row second"
        // inside one transaction.
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && str_ends_with($request->url(), '/api/v2/users/max'));
        $this->assertDatabaseMissing('users', ['id' => $photographer->id]);
    }

    // ── Fail-closed ──────────────────────────────────────────────────────────

    /**
     * The deliberate decision, not an accident: if SFTPGo is unreachable, the
     * role change is refused and *nothing* changes.
     *
     * Without the fail-closed wiring the role would be synced first (or the
     * update would proceed) and a live credential would outlive the role that
     * justified it. The test catches the exception rather than rendering it,
     * because there is no handler for it — and that absence is part of what is
     * pinned: no "saved anyway" path hides behind a 500.
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
        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $unchanged->ftp_account_status);
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
            'filesystems.disks.ftp_inbox.root' => self::INBOX_ROOT,
        ]);
    }

    private function fakeSftpGo(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/users*' => function (Request $request) {
                $account = $this->accountIn($request);

                return match ($request->method()) {
                    'POST' => Http::response($this->sftpGoUser($account), 201),
                    'DELETE' => Http::response(['message' => 'user deleted'], 200),
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
            'home_dir' => self::INBOX_ROOT.'/'.$account,
            'description' => 'Portal FTP-Kamera-Zugang',
            'permissions' => ['/' => ['*']],
            'virtual_folders' => [],
        ];
    }
}
