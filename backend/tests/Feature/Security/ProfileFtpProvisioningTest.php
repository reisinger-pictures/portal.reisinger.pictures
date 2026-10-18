<?php

namespace Tests\Feature\Security;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * SEC-20261018-04: only a photographer may change the SFTP account name.
 *
 * `PUT /api/auth/profile` used to accept `ftp_slug` from any authenticated
 * account and called `FtpCredentialService::provisionAndShow()` for it. That
 * handed an ordinary client — or an admin who is not a photographer — a camera
 * account, contradicting §7.16, where the account exists exactly as long as the
 * photographer role does.
 *
 * Two halves matter and both are pinned here:
 *
 * - a rejected change runs *nothing*: no directory, no SFTPGo call, and the
 *   rest of the same request (the name) is not persisted either,
 * - an unchanged, already-assigned slug is not a change. That path provisions
 *   nothing, so it must keep working for every account instead of making the
 *   profile form unsavable for clients.
 */
class ProfileFtpProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const SFTPGO_BASE_URL = 'http://sftpgo.test:8080';

    private string $inboxRoot;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inboxRoot = $this->useTemporaryFtpInboxRoot();

        config([
            'services.sftpgo' => [
                'base_url' => self::SFTPGO_BASE_URL,
                'api_key' => 'test-api-key',
                'admin_username' => null,
                'admin_password' => null,
            ],
        ]);

        Http::fake([
            'sftpgo.test:8080/api/v2/users*' => function (Request $request) {
                $account = $request->method() === 'POST'
                    ? (string) ($request->data()['username'] ?? 'unknown')
                    : rawurldecode(basename((string) parse_url($request->url(), PHP_URL_PATH)));

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

    /**
     * @param  array<int, UserRole>  $roles
     */
    private function account(string $slug, array $roles): User
    {
        $user = User::factory()->create(['name' => 'Max Mustermann']);

        foreach ($roles as $role) {
            $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));
        }

        $user->forceFill([
            'ftp_slug' => $slug,
            'ftp_account_status' => FtpCredentialService::STATUS_PENDING,
        ])->save();

        return $user->fresh();
    }

    private function as(User $user)
    {
        return $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($user)]);
    }

    public function test_a_client_cannot_change_the_ftp_slug(): void
    {
        $client = $this->account('max', [UserRole::CLIENT]);

        $this->as($client)
            ->putJson('/api/auth/profile', [
                'name' => 'Neuer Name',
                'ftp_slug' => 'j-doe',
            ])
            ->assertForbidden()
            ->assertJsonPath('error', 'Der FTP-Login kann nur von Fotografen geändert werden.');

        $fresh = $client->fresh();
        $this->assertSame('max', $fresh->ftp_slug);
        $this->assertSame('Max Mustermann', $fresh->name, 'The same request must not persist the name change either.');
        $this->assertSame(FtpCredentialService::STATUS_PENDING, $fresh->ftp_account_status);
        $this->assertNull($fresh->ftp_provisioned_at);

        Http::assertNothingSent();
        $this->assertDirectoryDoesNotExist($this->inboxRoot.'/j-doe');
    }

    public function test_a_power_user_cannot_change_the_ftp_slug(): void
    {
        $powerUser = $this->account('max', [UserRole::POWER_USER]);

        $this->as($powerUser)
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'j-doe',
            ])
            ->assertForbidden();

        $this->assertSame('max', $powerUser->fresh()->ftp_slug);
        Http::assertNothingSent();
    }

    public function test_an_org_admin_cannot_change_the_ftp_slug(): void
    {
        $orgAdmin = $this->account('max', [UserRole::ORG_ADMIN]);

        $this->as($orgAdmin)
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'j-doe',
            ])
            ->assertForbidden();

        $this->assertSame('max', $orgAdmin->fresh()->ftp_slug);
        Http::assertNothingSent();
    }

    public function test_an_admin_without_the_photographer_role_cannot_change_the_ftp_slug(): void
    {
        $admin = $this->account('max', [UserRole::ADMIN]);

        $this->as($admin)
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'j-doe',
            ])
            ->assertForbidden();

        $this->assertSame('max', $admin->fresh()->ftp_slug);
        Http::assertNothingSent();
    }

    public function test_a_client_may_submit_the_already_assigned_slug_unchanged(): void
    {
        $client = $this->account('max', [UserRole::CLIENT]);

        // The slug is untouched: no provisioning happens on this branch, so the
        // role check must not turn the ordinary profile form into a dead end.
        $this->as($client)
            ->putJson('/api/auth/profile', [
                'name' => 'Maximilian Mustermann',
                'ftp_slug' => 'max',
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertSame('max', $client->fresh()->ftp_slug);
        $this->assertSame('Maximilian Mustermann', $client->fresh()->name);
        Http::assertNothingSent();
    }

    public function test_a_client_may_still_update_the_profile_without_a_slug(): void
    {
        $client = $this->account('max', [UserRole::CLIENT]);

        $this->as($client)
            ->putJson('/api/auth/profile', [
                'name' => 'Maximilian Mustermann',
                'metadata_copyright' => 'Max Mustermann',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $client->id,
            'name' => 'Maximilian Mustermann',
            'ftp_slug' => 'max',
        ]);
        Http::assertNothingSent();
    }

    public function test_a_photographer_can_still_change_the_ftp_slug(): void
    {
        $photographer = $this->account('max', [UserRole::PHOTOGRAPHER]);

        $response = $this->as($photographer)
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'j-doe',
            ]);

        $response->assertOk();
        $this->assertIsString($response->json('ftp_password'));
        $this->assertMatchesRegularExpression(
            FtpCredentialService::PASSWORD_PATTERN,
            (string) $response->json('ftp_password'),
        );

        $fresh = $photographer->fresh();
        $this->assertSame('j-doe', $fresh->ftp_slug);
        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $fresh->ftp_account_status);
        $this->assertDirectoryExists($this->inboxRoot.'/j-doe');

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && (string) ($request->data()['username'] ?? '') === 'j-doe');
    }

    public function test_an_admin_with_the_photographer_role_can_change_the_ftp_slug(): void
    {
        $adminPhotographer = $this->account('max', [UserRole::ADMIN, UserRole::PHOTOGRAPHER]);

        $this->as($adminPhotographer)
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'j-doe',
            ])
            ->assertOk();

        $this->assertSame('j-doe', $adminPhotographer->fresh()->ftp_slug);
    }

    public function test_an_invalid_slug_from_a_client_is_still_refused(): void
    {
        $client = $this->account('max', [UserRole::CLIENT]);

        // Authorization is evaluated before validation, so a non-photographer is
        // told the rule that applies to them (403), not a format error that
        // suggests a compliant value would work.
        $this->as($client)
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'a/b',
            ])
            ->assertForbidden();

        $this->assertSame('max', $client->fresh()->ftp_slug);
        Http::assertNothingSent();
    }
}
