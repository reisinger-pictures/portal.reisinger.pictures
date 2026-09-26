<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `FtpController::status()` reports the provisioning state (P1-M26).
 *
 * Two properties are load-bearing here, and the second one is the reason the
 * feature exists:
 *
 * 1. The photographer can see "not provisioned" / "provisioning failed" instead
 *    of an empty inbox folder, which is indistinguishable from "no photos yet".
 * 2. The read path never contacts SFTPGo (feature doc 7.4/7.5). The columns are
 *    a cache and this endpoint reads them; a live query would make the UI depend
 *    on a service that may be down and would need a credential for a read. The
 *    test therefore asserts not just the values but `assertNothingSent()` — a
 *    value-correct response that was fetched from the service would still be the
 *    wrong design.
 */
class FtpProvisioningStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ftp_inbox');
    }

    public function test_an_unprovisioned_account_reports_pending(): void
    {
        $response = $this->statusFor($this->photographer());

        $response->assertOk();
        $response->assertJsonPath('ftp_account_status', 'pending');
        // A pending account has never been provisioned, so there is no timestamp
        // and no error text. Asserting the nulls explicitly pins that the reader
        // does not invent a placeholder date.
        $response->assertJsonPath('ftp_provisioned_at', null);
        $response->assertJsonPath('ftp_account_error', null);
    }

    public function test_a_provisioned_account_reports_active_with_its_timestamp(): void
    {
        $user = $this->photographer();
        $provisionedAt = '2026-09-26 10:11:12';
        $this->setProvisioningState($user, 'active', $provisionedAt);

        $response = $this->statusFor($user);

        $response->assertOk();
        $response->assertJsonPath('ftp_account_status', 'active');

        // The wire format is the model's `datetime` cast: ISO-8601. What the
        // photographer needs is the *instant*, so the assertion compares parsed
        // values — pinning the string format instead would fail on a
        // timezone-handling change with no behavioural difference for anyone.
        $reported = $response->json('ftp_provisioned_at');
        $this->assertIsString($reported);
        $this->assertTrue(
            Carbon::parse($reported)->equalTo(Carbon::parse($provisionedAt)),
            "The response must carry the stored instant, got '{$reported}'.",
        );

        $response->assertJsonPath('ftp_account_error', null);
    }

    public function test_a_failed_provisioning_reports_error_with_its_reason(): void
    {
        $user = $this->photographer();
        $this->setProvisioningState($user, 'error', null, 'Home-Verzeichnis fehlt auf dem Host.');

        $response = $this->statusFor($user);

        $response->assertOk();
        $response->assertJsonPath('ftp_account_status', 'error');
        $response->assertJsonPath('ftp_account_error', 'Home-Verzeichnis fehlt auf dem Host.');
        $response->assertJsonPath('ftp_provisioned_at', null);
    }

    /**
     * The three states are the whole contract of the V041 column, so the read
     * path has to be able to distinguish all of them — a reader that collapsed
     * `error` into `pending` would tell a photographer to wait for something that
     * will never happen.
     */
    public function test_all_three_states_are_reported_distinctly(): void
    {
        $user = $this->photographer();
        $reported = [];

        foreach (['pending', 'active', 'error'] as $state) {
            $this->setProvisioningState($user, $state);
            $reported[$state] = $this->statusFor($user)->json('ftp_account_status');
        }

        $this->assertSame(
            ['pending' => 'pending', 'active' => 'active', 'error' => 'error'],
            $reported,
        );
    }

    /**
     * Feature doc 7.5: the import must not hang on the service, and 7.4 fixes the
     * column as the source of truth. This is the regression guard for both — it
     * fails if a live query is ever added to the read path, which is the exact
     * regression the design forbids.
     */
    public function test_the_status_endpoint_never_contacts_sftpgo(): void
    {
        // A fake that would answer happily: if the read path ever queries the
        // service, this test would start passing through that data and
        // assertNothingSent() would fail — the design error, not a missing
        // fixture.
        Http::fake([
            'sftpgo.test:8080/*' => Http::response([
                'username' => 'florian',
                'status' => 1,
                'home_dir' => '/var/www/ftp/florian',
            ], 200),
        ]);

        $this->configureSftpGo();
        $user = $this->photographer();
        $this->setProvisioningState($user, 'active', '2026-09-26 10:11:12');

        $response = $this->statusFor($user);

        $response->assertOk();
        $response->assertJsonPath('ftp_account_status', 'active');

        Http::assertNothingSent();
    }

    /**
     * A service outage must not turn into a 500 on the read path, and must not
     * hide the known state either. Because the columns are a cache, a dead
     * service is indistinguishable from a healthy one here — which is the point:
     * the photographer still sees what the system knows.
     */
    public function test_status_answers_from_the_cache_while_sftpgo_is_unreachable(): void
    {
        Http::fake([
            'sftpgo.test:8080/*' => fn () => throw new ConnectionException('connection refused'),
        ]);

        $this->configureSftpGo();
        $user = $this->photographer();
        $this->setProvisioningState($user, 'error', null, 'Dienst nicht erreichbar.');

        $response = $this->statusFor($user);

        $response->assertOk();
        $response->assertJsonPath('ftp_account_status', 'error');
        $response->assertJsonPath('ftp_account_error', 'Dienst nicht erreichbar.');

        Http::assertNothingSent();
    }

    /**
     * The provisioning fields are additive: the three fields `status()` already
     * returned are consumed by the inbox UI, and a response that dropped them
     * would break the import view. Pinned so adding the status cannot quietly
     * replace the existing contract.
     */
    public function test_the_existing_status_fields_are_preserved(): void
    {
        $user = $this->photographer(['ftp_slug' => 'florian']);
        $this->setProvisioningState($user, 'active', '2026-09-26 10:11:12');

        $response = $this->statusFor($user);

        $response->assertOk();
        $response->assertJsonStructure([
            'ftp_folder',
            'file_count',
            'current_target_gallery',
            'ftp_account_status',
            'ftp_provisioned_at',
            'ftp_account_error',
        ]);
        $this->assertSame('/florian', $response->json('ftp_folder'));
        $this->assertSame(0, $response->json('file_count'));
        $this->assertNull($response->json('current_target_gallery'));
    }

    public function test_a_client_cannot_read_the_provisioning_status(): void
    {
        $token = auth('api')->login(User::factory()->create());

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/ftp/status');

        $response->assertStatus(403);
    }

    private function statusFor(User $user)
    {
        $token = auth('api')->login($user);

        return $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/management/ftp/status');
    }

    private function photographer(array $attributes = []): User
    {
        $user = User::factory()->create($attributes);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        return $user->fresh();
    }

    /**
     * The provisioning columns are deliberately not mass-assignable, so the test
     * writes them the way the provisioning path does — explicitly.
     */
    private function setProvisioningState(User $user, string $status, ?string $provisionedAt = null, ?string $error = null): void
    {
        $user->forceFill([
            'ftp_account_status' => $status,
            'ftp_provisioned_at' => $provisionedAt,
            'ftp_account_error' => $error,
        ])->save();
    }

    private function configureSftpGo(): void
    {
        config([
            'services.sftpgo' => [
                'base_url' => 'http://sftpgo.test:8080',
                'api_key' => 'test-api-key',
                'admin_username' => null,
                'admin_password' => null,
            ],
        ]);
    }
}
