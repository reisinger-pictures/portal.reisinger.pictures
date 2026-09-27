<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * `GET /api/management/ftp/status` carries the camera configuration (feature doc 7.13).
 *
 * The endpoint is where a photographer copies the SFTP host and ports into a
 * camera, so the payload has two properties that only a test can hold:
 *
 * 1. It may not name a port nothing listens on. The values are read from the
 *    same variables the compose publishes, so a mismatch with the running
 *    container is visible here as a changed number — the failure the first
 *    cutover had, with the compose publishing `2222:2222` while SFTPGo listened
 *    on 2022.
 * 2. A value that is missing must arrive as "not configured" and `null`, never
 *    as a blank field the photographer would paste into a camera anyway. The
 *    config reads its five inputs with no defaults for exactly this reason.
 *
 * The third property is that this is additive: the six fields the inbox UI
 * already consumed are asserted alongside `connection`, so adding the camera
 * configuration cannot quietly replace the existing contract.
 *
 * Verification rule: the request goes to the test's own in-process router, and
 * nothing leaves the machine — no HTTP client, no `ssh`/`scp`, no `docker exec`,
 * no `proc_open`. The single `Http::fake()` below is a recorder, and
 * `assertNothingSent()` is the assertion that keeps the read path that way.
 * Hosts are fixtures on the reserved `.invalid` TLD (RFC 2606, guaranteed
 * unresolvable) plus the pre-existing `sftpgo.test` fake of the sibling test —
 * never a real deployment name. The SFTPGo service is not required here: the
 * value object reads configuration, it does not talk to the server.
 */
class FtpConnectionStatusTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('ftp_inbox');

        // The shipped config has no defaults, but a developer's local `.env` can
        // fill these in. A complete baseline is pinned here so a test can only
        // see the values it sets itself.
        $this->configureTransport();
    }

    public function test_the_status_reports_the_connection_details_of_the_calling_account(): void
    {
        $this->configureTransport();
        $user = $this->photographer(['ftp_slug' => 'florian']);

        $response = $this->statusFor($user);

        $response->assertOk();
        $response->assertJsonPath('connection.configured', true);
        $response->assertJsonPath('connection.host', 'ftp.example.invalid');
        $response->assertJsonPath('connection.username', 'florian');
        $response->assertJsonPath('connection.path', '/');
        $response->assertJsonPath('connection.sftp_port', 2222);
        $response->assertJsonPath('connection.ftps_port', 989);
        $response->assertJsonPath('connection.pasv_port_start', 50000);
        $response->assertJsonPath('connection.pasv_port_end', 50100);
        // Translated, not passed through: the camera says "explicit", SFTPGo
        // says 1, and the translation lives in the backend so the UI has no
        // second source of truth for it.
        $response->assertJsonPath('connection.ftps_tls_mode', 'explicit');
    }

    /**
     * The account name is per photographer while the transport is shared, so it
     * is read from the user and not from the config. One shared deployment, two
     * accounts: the payload has to differ exactly in this one field.
     */
    public function test_each_account_reports_its_own_user_name_on_the_same_transport(): void
    {
        $this->configureTransport();
        $first = $this->photographer(['ftp_slug' => 'florian', 'email' => 'florian@example.com']);
        $second = $this->photographer(['ftp_slug' => 'sabine', 'email' => 'sabine@example.com']);

        $this->assertSame('florian', $this->statusFor($first)->json('connection.username'));
        $this->assertSame('sabine', $this->statusFor($second)->json('connection.username'));
    }

    /**
     * The absence case, which is the reason this is a value object. A missing
     * `FTP_PUBLIC_HOST` has to be reported as "not configured" with a `null`
     * host, and the other fields stay — the photographer then sees exactly which
     * value an operator still has to set instead of a panel that looks complete.
     *
     * All three shapes of "unset" are covered: the variable absent, set to an
     * empty string, and set to whitespace by a careless copy/paste into `.env`.
     */
    public function test_a_missing_public_host_is_reported_as_not_configured(): void
    {
        $user = $this->photographer(['ftp_slug' => 'florian']);
        $reported = [];

        foreach ([null, '', '   '] as $publicHost) {
            $this->configureTransport(['public_host' => $publicHost]);

            $response = $this->statusFor($user);

            $response->assertOk();
            $response->assertJsonPath('connection.configured', false);
            $this->assertNull(
                $response->json('connection.host'),
                'An unset public host must be null, not an empty field to paste into a camera.',
            );

            // The known half of the configuration is still reported, so the UI
            // can show what is set without hiding the whole block.
            $response->assertJsonPath('connection.username', 'florian');
            $response->assertJsonPath('connection.sftp_port', 2222);
            $response->assertJsonPath('connection.ftps_port', 989);
            $response->assertJsonPath('connection.path', '/');

            $reported[var_export($publicHost, true)] = $response->json('connection.configured');
        }

        $this->assertSame(
            ['NULL' => false, "''" => false, "'   '" => false],
            $reported,
            'Every form of an unset host has to read as not configured.',
        );
    }

    /**
     * A port that is not a real TCP port must not travel to the UI. `0` is the
     * most likely shape in practice, because an unset compose variable usually
     * arrives as an empty string and an unset numeric one as `0` — a camera
     * configured with port `0` fails in a way the photographer cannot diagnose.
     */
    public function test_an_unusable_port_is_never_offered_to_the_camera(): void
    {
        $user = $this->photographer(['ftp_slug' => 'florian']);
        $reported = [];

        foreach ([null, 0, 65536, 'abc'] as $sftpPort) {
            $this->configureTransport(['sftp_port' => $sftpPort]);

            $response = $this->statusFor($user);

            $response->assertOk();
            $response->assertJsonPath('connection.configured', false);
            $this->assertNull($response->json('connection.sftp_port'));

            $reported[var_export($sftpPort, true)] = $response->json('connection.sftp_port');
        }

        $this->assertSame(
            [
                'NULL' => null,
                '0' => null,
                '65536' => null,
                "'abc'" => null,
            ],
            $reported,
        );
    }

    /**
     * The passive range is a firewall matter and is reported but does not gate
     * the configuration, so an operator who has not opened 50000–50100 yet still
     * gets a usable SFTP/FTPS configuration for the camera.
     */
    public function test_the_camera_configuration_stays_usable_without_the_passive_range(): void
    {
        $this->configureTransport(['pasv_port_start' => null, 'pasv_port_end' => null]);

        $response = $this->statusFor($this->photographer(['ftp_slug' => 'florian']));

        $response->assertOk();
        $response->assertJsonPath('connection.configured', true);
        $response->assertJsonPath('connection.pasv_port_start', null);
        $response->assertJsonPath('connection.pasv_port_end', null);
    }

    /**
     * Feature doc 7.4/7.5: the read path may not depend on SFTPGo, and the
     * connection block is the most tempting place to add a live port query — a
     * lookup that would answer authoritatively and would also make this read
     * path fail whenever the service is down. The fake answers happily, so a
     * query would not surface as a wrong value but as a sent request.
     */
    public function test_the_connection_details_are_read_from_the_configuration_and_never_from_sftpgo(): void
    {
        Http::fake([
            'sftpgo.test:8080/*' => fn (Request $request) => Http::response([
                'username' => 'florian',
                'status' => 1,
                'home_dir' => '/var/www/ftp/florian',
                'host' => 'live-service-host.invalid',
                'sftp_port' => 2022,
            ], 200),
        ]);

        config([
            'services.sftpgo' => [
                'base_url' => 'http://sftpgo.test:8080',
                'api_key' => 'test-api-key',
                'admin_username' => null,
                'admin_password' => null,
            ],
        ]);
        $this->configureTransport();

        $response = $this->statusFor($this->photographer(['ftp_slug' => 'florian']));

        $response->assertOk();
        $response->assertJsonPath('connection.host', 'ftp.example.invalid');
        $response->assertJsonPath('connection.sftp_port', 2222);

        Http::assertNothingSent();
    }

    /**
     * The addition is additive: the fields the inbox UI already reads are still
     * there, with the same values, next to the new `connection` block. A
     * response that dropped one of them would break the import view — a
     * regression the new payload must not be able to cause.
     */
    public function test_the_previously_existing_status_fields_are_preserved(): void
    {
        $this->configureTransport();
        $user = $this->photographer(['ftp_slug' => 'florian']);
        $user->forceFill([
            'ftp_account_status' => 'active',
            'ftp_provisioned_at' => '2026-09-26 10:11:12',
            'ftp_account_error' => null,
        ])->save();

        $response = $this->statusFor($user);

        $response->assertOk();
        $response->assertJsonStructure([
            'ftp_folder',
            'file_count',
            'current_target_gallery',
            'ftp_account_status',
            'ftp_provisioned_at',
            'ftp_account_error',
            'connection' => [
                'configured',
                'host',
                'username',
                'path',
                'sftp_port',
                'ftps_port',
                'pasv_port_start',
                'pasv_port_end',
                'ftps_tls_mode',
            ],
        ]);

        $this->assertSame('/florian', $response->json('ftp_folder'));
        $this->assertSame(0, $response->json('file_count'));
        $this->assertNull($response->json('current_target_gallery'));
        $this->assertSame('active', $response->json('ftp_account_status'));
        $this->assertNull($response->json('ftp_account_error'));

        // The wire format is the model's `datetime` cast (ISO-8601), so the
        // assertion compares the parsed instant: pinning the string would fail on
        // a timezone-formatting change with no difference for the photographer.
        $reported = $response->json('ftp_provisioned_at');
        $this->assertIsString($reported);
        $this->assertTrue(
            Carbon::parse($reported)->equalTo(Carbon::parse('2026-09-26 10:11:12')),
            "The stored instant must be preserved, got '{$reported}'.",
        );
    }

    /**
     * `file_count` keeps counting the inbox next to the new block, so a change to
     * the read path cannot quietly turn the counter into a constant zero. One
     * image is counted, a non-image and a subdirectory are not.
     */
    public function test_the_inbox_file_count_still_works_beside_the_connection_block(): void
    {
        $this->configureTransport();
        $user = $this->photographer(['ftp_slug' => 'florian']);

        Storage::disk('ftp_inbox')->put('florian/upload-1.jpg', 'binary');
        Storage::disk('ftp_inbox')->put('florian/upload-2.JPEG', 'binary');
        Storage::disk('ftp_inbox')->put('florian/notes.txt', 'binary');
        Storage::disk('ftp_inbox')->put('florian/2026/raw-1.cr2', 'binary');
        Storage::disk('ftp_inbox')->makeDirectory('florian/thumbs');

        $response = $this->statusFor($user);

        $response->assertOk();
        $this->assertSame(2, $response->json('file_count'));
        $response->assertJsonPath('connection.configured', true);
    }

    /**
     * The reset quota is a security rule, and the camera setup guide tells the
     * photographer what it is ("Es sind n Anforderungen pro Stunde möglich").
     * That sentence went stale the moment the quota was raised from three to ten on
     * 2026-09-27, because the number lived in the German copy instead of in the
     * response. The field exists so the guide is a reader of the rule, not a second
     * copy of it.
     *
     * The assertion is against the constant, never against a number, and that is
     * the whole point: the test cannot tell anyone what the quota is, it can only
     * tell them that the response and the guard agree. A test pinning `10` would
     * have kept passing through the raise that produced this defect — it would have
     * failed on the *guide* instead, which is the side that has no constant to
     * assert against.
     */
    public function test_the_status_reports_the_reset_quota_the_credential_service_enforces(): void
    {
        $response = $this->statusFor($this->photographer(['ftp_slug' => 'florian']));

        $response->assertOk();
        $response->assertJsonPath('ftp_reset_limit_per_hour', FtpCredentialService::RESET_LIMIT_PER_HOUR);
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
     * The whole transport section in one write, merged over a working baseline.
     * `env()` values are strings, so the ports are configured as strings here —
     * that is the shape the deployment really produces.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function configureTransport(array $overrides = []): void
    {
        config([
            'services.ftp_transport' => array_merge([
                'public_host' => 'ftp.example.invalid',
                'sftp_port' => '2222',
                'ftps_port' => '989',
                'pasv_port_start' => '50000',
                'pasv_port_end' => '50100',
                'ftps_tls_mode' => '1',
            ], $overrides),
        ]);
    }
}
