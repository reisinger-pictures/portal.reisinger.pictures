<?php

namespace Tests\Unit\Values;

use App\Models\User;
use App\Values\FtpConnectionDetails;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * `FtpConnectionDetails`: what a camera is configured with (feature doc 7.13).
 *
 * The value object exists for two guarantees, and both of them are about what
 * the UI must NOT be able to show:
 *
 * 1. A missing value is reported as "not configured" rather than as an empty
 *    field. Every input is host-facing configuration read with no default, so a
 *    silently substituted value is an invisible guess about this deployment's
 *    network that a photographer would paste into a camera.
 * 2. A port that is not a real TCP port never reaches the payload — not as a
 *    `0`, not as a `0`-coerced `"abc"`, and not as a `65536` a camera cannot
 *    connect to. The class of bug this guards is the first cutover, where the
 *    compose published a port the container did not listen on.
 *
 * The config is set per test with `config([...])`, never through `env()`: the
 * production values are the deployment's, and a test that reads them would
 * assert whatever the developer's machine happens to export.
 *
 * Verification rule: this file contacts nothing. No HTTP client, no shell-out,
 * no service. Every host is a fixture on the reserved `.invalid` TLD (RFC 2606,
 * guaranteed unresolvable) rather than a real deployment name, so no test can
 * reach a production stack even by accident — the value object reads the
 * configuration and nothing else.
 */
class FtpConnectionDetailsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // A developer's local `.env` may well fill these variables in, so
        // the baseline is pinned to "nothing set" before every test. Without
        // this an omitted configuration would silently pass on ambient values.
        $this->configureTransport([
            'public_host' => null,
            'sftp_port' => null,
            'ftps_port' => null,
            'pasv_port_start' => null,
            'pasv_port_end' => null,
            'ftps_tls_mode' => null,
        ]);
    }

    // ── The complete case ─────────────────────────────────────────────────────

    public function test_a_complete_configuration_reports_a_camera_ready_connection(): void
    {
        $this->configureTransport();

        $details = FtpConnectionDetails::forUser($this->user('florian'));

        $this->assertTrue($details->isConfigured());

        // The whole payload, not a subset: a key that quietly disappears is a
        // frontend crash, and an extra key is an undocumented contract.
        $this->assertSame([
            'configured' => true,
            'host' => 'ftp.example.invalid',
            'username' => 'florian',
            'path' => '/',
            'sftp_port' => 2222,
            'ftps_port' => 989,
            'pasv_port_start' => 50000,
            'pasv_port_end' => 50100,
            'ftps_tls_mode' => 'explicit',
        ], $details->toArray());
    }

    /**
     * The payload is read by a management UI, and the password is shown once by
     * the credential endpoint (P1-M23). A secret leaking into this payload would
     * put it in a browser, in a devtools network log and in any frontend error
     * report, so the key set is pinned exactly rather than by subset.
     */
    public function test_the_payload_carries_exactly_the_documented_keys_and_no_secret(): void
    {
        $this->configureTransport();

        $payload = FtpConnectionDetails::forUser($this->user('florian'))->toArray();

        $this->assertSame([
            'configured',
            'host',
            'username',
            'path',
            'sftp_port',
            'ftps_port',
            'pasv_port_start',
            'pasv_port_end',
            'ftps_tls_mode',
        ], array_keys($payload));

        $this->assertStringNotContainsString(
            'password',
            strtolower(implode('|', array_keys($payload))),
            'The camera password belongs to the show-once endpoint, not to this read path.',
        );
    }

    public function test_the_username_is_the_account_slug_of_the_calling_photographer(): void
    {
        $this->configureTransport();

        $first = FtpConnectionDetails::forUser($this->user('florian'));
        $second = FtpConnectionDetails::forUser($this->user('sabine'));

        $this->assertSame('florian', $first->toArray()['username']);
        $this->assertSame('sabine', $second->toArray()['username']);

        // One shared config, two accounts: the account name is the only part
        // that is per photographer, and it must come from the user, not from
        // the transport section.
        $this->assertSame($first->toArray()['host'], $second->toArray()['host']);
        $this->assertSame($first->toArray()['sftp_port'], $second->toArray()['sftp_port']);
    }

    /**
     * `env()` hands back strings, so this — not the integer case — is the shape
     * the value object sees in production. The camera needs a number, so the
     * configured value is what has to arrive, converted.
     */
    public function test_the_ports_are_read_from_the_string_values_the_configuration_yields(): void
    {
        $this->configureTransport([
            'sftp_port' => '2222',
            'ftps_port' => '989',
            'pasv_port_start' => '50000',
            'pasv_port_end' => '50100',
        ]);

        $details = FtpConnectionDetails::forUser($this->user('florian'));

        $this->assertTrue($details->isConfigured());
        $this->assertSame(2222, $details->sftpPort);
        $this->assertSame(989, $details->ftpsPort);
        $this->assertSame(50000, $details->pasvPortStart);
        $this->assertSame(50100, $details->pasvPortEnd);
    }

    // ── port() ───────────────────────────────────────────────────────────────

    /**
     * 1 and 65535 are inside the range and must survive: rejecting the last
     * usable port would make a legal deployment report "not configured".
     *
     * @return array<string, array{0: int|string}>
     */
    public static function validPorts(): array
    {
        return [
            'lowest port' => [1],
            'highest port' => [65535],
            'lowest port as string' => ['1'],
            'highest port as string' => ['65535'],
            'default sftp port as string' => ['2222'],
            'leading zero is a digit sequence' => ['00022'],
        ];
    }

    #[DataProvider('validPorts')]
    public function test_a_port_inside_the_range_is_kept(int|string $port): void
    {
        $this->configureTransport(['sftp_port' => $port]);

        $details = FtpConnectionDetails::forUser($this->user('florian'));

        $this->assertSame((int) $port, $details->sftpPort);
        $this->assertSame((int) $port, $details->toArray()['sftp_port']);
        $this->assertTrue($details->isConfigured());
    }

    /**
     * Everything outside the range, and everything that is not a port at all.
     * `env()` can hand back a non-string for a value that only looks like one
     * (`false` for `FOO=false`, `true` for `FOO=true`), and a boolean that
     * coerced to 1 would put port 1 into a camera configuration.
     *
     * @return array<string, array{0: mixed}>
     */
    public static function invalidPorts(): array
    {
        return [
            'zero' => [0],
            'zero as string' => ['0'],
            'above the range' => [65536],
            'above the range as string' => ['65536'],
            'far above the range' => [70000],
            'not a number' => ['abc'],
            'trailing garbage' => ['12a'],
            'leading garbage' => ['a12'],
            'negative' => [-1],
            'negative as string' => ['-1'],
            'float as string' => ['22.5'],
            'empty string' => [''],
            'whitespace' => [' '],
            'null' => [null],
            'false' => [false],
            'true' => [true],
            'array' => [[2222]],
        ];
    }

    #[DataProvider('invalidPorts')]
    public function test_a_value_that_is_not_a_port_is_reported_as_absent(mixed $port): void
    {
        $this->configureTransport(['sftp_port' => $port]);

        $details = FtpConnectionDetails::forUser($this->user('florian'));

        // Treated as absent, never passed on: a port the camera cannot use is
        // worse than a missing one, because it looks configured.
        $this->assertNull($details->sftpPort);
        $this->assertNull($details->toArray()['sftp_port']);
        $this->assertFalse(
            $details->isConfigured(),
            'An unusable port must not report a camera-ready configuration.',
        );
    }

    // ── text() ───────────────────────────────────────────────────────────────

    /**
     * `text()` is the host name, so a padded value is a copy/paste artefact and
     * a blank one is an unset variable. Both have to be normalised here rather
     * than in the UI, which would show the whitespace it received.
     *
     * @return array<string, array{0: mixed, 1: ?string}>
     */
    public static function hostValues(): array
    {
        return [
            'padded host is trimmed' => ['  example.invalid  ', 'example.invalid'],
            'inner whitespace survives' => ['ftp . example . invalid', 'ftp . example . invalid'],
            'unpadded host is kept' => ['ftp.example.invalid', 'ftp.example.invalid'],
            'whitespace only' => ['   ', null],
            'empty string' => ['', null],
            'null' => [null, null],
            'tab only' => ["\t", null],
            'zero' => [0, null],
            'false' => [false, null],
            'true' => [true, null],
        ];
    }

    #[DataProvider('hostValues')]
    public function test_the_host_is_trimmed_and_anything_blank_counts_as_absent(mixed $configured, ?string $expected): void
    {
        $this->configureTransport(['public_host' => $configured]);

        $details = FtpConnectionDetails::forUser($this->user('florian'));

        $this->assertSame($expected, $details->host);
        $this->assertSame($expected, $details->toArray()['host']);
    }

    // ── isConfigured() ───────────────────────────────────────────────────────

    /**
     * One missing value at a time, with the other three present in each case.
     * Asserting the survivor set is the point: a reader that collapsed the four
     * conditions into one truthiness check on the whole section would pass a
     * "everything set" and a "everything missing" test while being wrong for
     * every partial deployment in between.
     */
    public function test_a_missing_host_leaves_the_account_not_configured(): void
    {
        $this->configureTransport(['public_host' => null]);

        $details = FtpConnectionDetails::forUser($this->user('florian'));

        $this->assertFalse($details->isConfigured());
        $this->assertFalse($details->toArray()['configured']);
        $this->assertNull($details->host);
        // The rest of the transport is intact and is still reported, so the UI
        // can show what is known instead of blanking the whole panel.
        $this->assertSame('florian', $details->username);
        $this->assertSame(2222, $details->sftpPort);
        $this->assertSame(989, $details->ftpsPort);
    }

    public function test_a_missing_username_leaves_the_account_not_configured(): void
    {
        $this->configureTransport();

        $details = FtpConnectionDetails::forUser($this->user(null));

        $this->assertFalse($details->isConfigured());
        $this->assertNull($details->username);
        $this->assertSame('ftp.example.invalid', $details->host);
        $this->assertSame(2222, $details->sftpPort);
        $this->assertSame(989, $details->ftpsPort);
    }

    public function test_a_missing_sftp_port_leaves_the_account_not_configured(): void
    {
        $this->configureTransport(['sftp_port' => null]);

        $details = FtpConnectionDetails::forUser($this->user('florian'));

        $this->assertFalse($details->isConfigured());
        $this->assertNull($details->sftpPort);
        $this->assertSame('ftp.example.invalid', $details->host);
        $this->assertSame('florian', $details->username);
        $this->assertSame(989, $details->ftpsPort);
    }

    public function test_a_missing_ftps_port_leaves_the_account_not_configured(): void
    {
        $this->configureTransport(['ftps_port' => null]);

        $details = FtpConnectionDetails::forUser($this->user('florian'));

        $this->assertFalse($details->isConfigured());
        $this->assertNull($details->ftpsPort);
        $this->assertSame('ftp.example.invalid', $details->host);
        $this->assertSame('florian', $details->username);
        $this->assertSame(2222, $details->sftpPort);
    }

    /**
     * The passive range is a firewall matter, not something the camera is told,
     * so it is reported but does not gate the configuration. Feature doc 7.13
     * requires the range to be open, which is a deployment check — gating the UI
     * on it would tell a working camera configuration that it is broken.
     */
    public function test_the_passive_range_may_be_missing_without_breaking_the_configuration(): void
    {
        $this->configureTransport(['pasv_port_start' => null, 'pasv_port_end' => null]);

        $details = FtpConnectionDetails::forUser($this->user('florian'));

        $this->assertTrue($details->isConfigured());
        $this->assertTrue($details->toArray()['configured']);
        $this->assertNull($details->pasvPortStart);
        $this->assertNull($details->pasvPortEnd);
    }

    public function test_a_single_endpoint_of_the_passive_range_is_reported_as_given(): void
    {
        $this->configureTransport(['pasv_port_start' => 50000, 'pasv_port_end' => null]);

        $payload = FtpConnectionDetails::forUser($this->user('florian'))->toArray();

        $this->assertTrue($payload['configured']);
        $this->assertSame(50000, $payload['pasv_port_start']);
        $this->assertNull($payload['pasv_port_end'], 'A half-open range must not be completed by guesswork.');
    }

    // ── ftpsTlsMode ──────────────────────────────────────────────────────────

    /**
     * The TLS mode is translated, not passed through: SFTPGo speaks 1 and 2,
     * a camera's configuration screen says "explicit" or "implicit", and a
     * literal in the frontend would be a second source of truth for the setting
     * that decides whether the camera can connect at all.
     *
     * @return array<string, array{0: mixed, 1: ?string}>
     */
    public static function tlsModeValues(): array
    {
        return [
            'one as string is explicit' => ['1', 'explicit'],
            'one as int is explicit' => [1, 'explicit'],
            'two as string is implicit' => ['2', 'implicit'],
            'two as int is implicit' => [2, 'implicit'],
            'zero is not a mode' => ['0', null],
            'three is not a mode' => ['3', null],
            'out of range' => ['99', null],
            'named value is not the wire format' => ['explicit', null],
            'padded value' => [' 1 ', null],
            'leading zero is a different number' => ['01', null],
            'not a number' => ['abc', null],
            'empty string' => ['', null],
            'null' => [null, null],
            'true' => [true, null],
            'false' => [false, null],
        ];
    }

    #[DataProvider('tlsModeValues')]
    public function test_the_tls_mode_is_translated_and_anything_unknown_is_absent(mixed $configured, ?string $expected): void
    {
        $this->configureTransport(['ftps_tls_mode' => $configured]);

        $details = FtpConnectionDetails::forUser($this->user('florian'));

        $this->assertSame($expected, $details->ftpsTlsMode);
        $this->assertSame($expected, $details->toArray()['ftps_tls_mode']);
    }

    /**
     * The mode is reported, not required. It is a property of the published
     * binding, so a deployment that has not set it still has a working SFTP port
     * — gating the configuration on it would tell a photographer with a reachable
     * FTPS port that their camera setup is broken.
     */
    public function test_an_unset_tls_mode_does_not_break_the_configuration(): void
    {
        $this->configureTransport(['ftps_tls_mode' => null]);

        $details = FtpConnectionDetails::forUser($this->user('florian'));

        $this->assertTrue($details->isConfigured());
        $this->assertTrue($details->toArray()['configured']);
        $this->assertNull($details->toArray()['ftps_tls_mode']);
    }

    // ── UPLOAD_PATH ──────────────────────────────────────────────────────────
    /**
     * The account root is the upload target (feature doc 7.9), so `path` is a
     * constant rather than something a deployment configures. It has to be in
     * the payload in every state — a UI that renders the panel only when the
     * account is configured would then be missing it exactly when the
     * photographer needs to see what is wrong.
     */
    public function test_the_upload_path_is_reported_in_every_state(): void
    {
        $this->configureTransport();
        $configured = FtpConnectionDetails::forUser($this->user('florian'))->toArray();

        $this->assertArrayHasKey('path', $configured);
        $this->assertSame('/', $configured['path']);
        $this->assertSame(FtpConnectionDetails::UPLOAD_PATH, $configured['path']);

        $this->configureTransport([
            'public_host' => null,
            'sftp_port' => null,
            'ftps_port' => null,
            'pasv_port_start' => null,
            'pasv_port_end' => null,
        ]);
        $unconfigured = FtpConnectionDetails::forUser($this->user('florian'))->toArray();

        $this->assertArrayHasKey('path', $unconfigured);
        $this->assertSame('/', $unconfigured['path']);
        $this->assertFalse($unconfigured['configured']);
    }

    public function test_the_upload_path_constant_is_the_account_root(): void
    {
        $this->assertSame('/', FtpConnectionDetails::UPLOAD_PATH);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * The whole transport section in one write, merged over a working baseline.
     * Assigning the section as a whole (rather than five dotted keys) also keeps
     * a renamed key from surviving as a leftover from another test.
     *
     * @param  array<string, mixed>  $overrides
     */
    private function configureTransport(array $overrides = []): void
    {
        config([
            'services.ftp_transport' => array_merge([
                'public_host' => 'ftp.example.invalid',
                'sftp_port' => 2222,
                'ftps_port' => 989,
                'pasv_port_start' => 50000,
                'pasv_port_end' => 50100,
                // `SFTPGO_FTPD_TLS_MODE`, so 1 = AUTH TLS on 989. A string,
                // because that is what `env()` produces.
                'ftps_tls_mode' => '1',
            ], $overrides),
        ]);
    }

    /**
     * An unsaved model on purpose: this object reads a config section and one
     * column, so a factory — and with it a migrated database per test — would
     * add setup without covering anything the value object actually does.
     */
    private function user(?string $slug = 'florian'): User
    {
        return new User(['ftp_slug' => $slug]);
    }
}
