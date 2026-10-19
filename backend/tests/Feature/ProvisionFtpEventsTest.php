<?php

namespace Tests\Feature;

use App\Console\Commands\ProvisionFtpEvents;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Http\Client\Response;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The SFTPGo upload-event provisioning command (D-36).
 *
 * The command is what makes the event rule reproducible: the rule lives in the
 * SFTPGo data provider, so it cannot be expressed in the compose file, and hand
 * editing the web UI leaves no trace in the repository. Its two properties are
 * therefore the ones a test has to hold:
 *
 * 1. **Idempotent.** Running it twice must produce one action and one rule, not
 *    two. The read-first-then-POST-or-PUT shape is asserted on the *requests*
 *    (`assertSentCount`), because a second POST is exactly the duplicate the
 *    command exists to avoid.
 * 2. **Updating.** A rotated secret must reach the existing action. SFTPGo's
 *    update is a full replace, so this only holds if the command writes the
 *    complete object — which is why the secret is asserted in the PUT body.
 *
 * SFTPGo is faked. The Admin API's shape was read from `openapi/openapi.yaml`
 * v2.7.6 (`/eventactions`, `/eventrules`), not from a running instance: no test
 * here may require a live SFTPGo, and a real instance would not prove anything
 * about the payloads anyway.
 */
class ProvisionFtpEventsTest extends TestCase
{
    private const ACTION = 'portal-ftp-upload';

    private const RULE = 'portal-ftp-upload-rule';

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
            'ftp.events.url' => 'http://portal.test/api/internal/ftp/upload-event',
            'ftp.events.secret' => 'first-secret',
            'ftp.events.secret_header' => 'X-FTP-Event-Secret',
            'ftp.events.action_name' => self::ACTION,
            'ftp.events.rule_name' => self::RULE,
        ]);
    }

    public function test_a_first_run_creates_the_action_and_the_rule_exactly_once(): void
    {
        Http::fake($this->nothingExists());

        $this->artisan('ftp:provision-events')->assertExitCode(0);

        Http::assertSentCount(4);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v2/eventactions')
            && $request->data()['type'] === 1
            && $request->data()['options']['http_config']['endpoint'] === 'http://portal.test/api/internal/ftp/upload-event'
            && $request->data()['options']['http_config']['method'] === 'POST'
            && $request->data()['options']['http_config']['timeout'] === ProvisionFtpEvents::HTTP_TIMEOUT_SECONDS
            && $request->data()['options']['http_config']['body'] === ProvisionFtpEvents::UPLOAD_EVENT_BODY
            && $request->data()['options']['http_config']['headers'] === [
                ['key' => 'X-FTP-Event-Secret', 'value' => 'first-secret'],
                ['key' => 'Content-Type', 'value' => ProvisionFtpEvents::UPLOAD_EVENT_CONTENT_TYPE],
            ]);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'POST'
            && str_ends_with($request->url(), '/api/v2/eventrules')
            && $request->data()['trigger'] === 1
            && $request->data()['status'] === 1
            && $request->data()['conditions']['fs_events'] === ['upload']
            && $request->data()['actions'] === [['name' => self::ACTION, 'order' => 1]]);

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    /**
     * The three fields SFTPGo rejects or ignores if they are missing.
     *
     * `timeout` is `json:"timeout,omitempty"` and validated as 1..180
     * (`internal/dataprovider/eventrule.go`): an omitted value is 0 and the Admin
     * API answers **400**, so the action cannot be created at all. A missing
     * `body` reaches the endpoint as an empty POST (SFTPGo sends no default body)
     * and is answered 422 `malformed`. And without a `Content-Type:
     * application/json` header SFTPGo does not JSON-escape the substituted
     * placeholders (`EventActionHTTPConfig::HasJSONBody()`), so a path with a
     * quote would break the body.
     *
     * Asserted as a range for the timeout, not as the literal: any value inside
     * the contract is fine, but removing the field (0) must be red.
     */
    public function test_the_action_payload_satisfies_the_sftpgo_http_action_contract(): void
    {
        Http::fake($this->nothingExists());

        $this->artisan('ftp:provision-events')->assertExitCode(0);

        $captured = null;
        Http::assertSent(function (Request $request) use (&$captured): bool {
            if ($request->method() !== 'POST' || ! str_ends_with($request->url(), '/api/v2/eventactions')) {
                return false;
            }

            $captured = $request->data()['options']['http_config'] ?? null;

            return true;
        });

        $this->assertIsArray($captured, 'The action must have been posted.');

        $timeout = $captured['timeout'] ?? null;
        $this->assertIsInt($timeout, 'timeout is required: SFTPGo rejects an omitted (0) timeout with a 400.');
        $this->assertGreaterThanOrEqual(1, $timeout);
        $this->assertLessThanOrEqual(180, $timeout);

        $this->assertIsString($captured['body'] ?? null, 'body is required: SFTPGo sends no default body.');
        $this->assertNotSame('', $captured['body']);

        $headers = $captured['headers'] ?? [];
        $contentType = null;
        foreach ($headers as $header) {
            if (($header['key'] ?? null) === 'Content-Type') {
                $contentType = $header['value'] ?? null;
            }
        }
        $this->assertIsString($contentType, 'Content-Type is required for SFTPGo to JSON-escape the placeholders.');
        $this->assertStringContainsString('application/json', $contentType);
    }

    /**
     * The body must name every placeholder the endpoint needs, spelled the way
     * SFTPGo spells it.
     *
     * The names are SFTPGo's own (`internal/common/eventmanager.go`
     * `getStringReplacements()`): `{{.Name}}` is the account, `{{.Event}}` the
     * operation, `{{.VirtualPath}}` the path, `{{.FileSize}}` the size. A typo
     * would not fail provisioning — SFTPGo leaves an unknown placeholder in
     * place — it would fail silently at the endpoint.
     */
    public function test_the_provisioned_body_uses_the_real_sftpgo_placeholders(): void
    {
        foreach (['{{.Name}}', '{{.Event}}', '{{.VirtualPath}}', '{{.FileSize}}'] as $placeholder) {
            $this->assertStringContainsString(
                $placeholder,
                ProvisionFtpEvents::UPLOAD_EVENT_BODY,
                "The body must carry {$placeholder}.",
            );
        }

        // After SFTPGo substitutes real values the body must still be valid JSON.
        $rendered = strtr(ProvisionFtpEvents::UPLOAD_EVENT_BODY, [
            '{{.Name}}' => 'florian',
            '{{.Event}}' => 'upload',
            '{{.VirtualPath}}' => '/florian/IMG_0001.JPG',
            '{{.FileSize}}' => '1234',
        ]);

        $decoded = json_decode($rendered, true);
        $this->assertIsArray($decoded);
        $this->assertSame('upload', $decoded['action']);
        $this->assertSame('florian', $decoded['username']);
    }

    public function test_a_second_run_updates_the_existing_objects_instead_of_duplicating_them(): void
    {
        Http::fake($this->bothExist());

        $this->artisan('ftp:provision-events')->assertExitCode(0);

        // Two GETs and two PUTs — and above all no POST, which is what a second
        // action and a second rule would come from.
        Http::assertSentCount(4);
        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'POST');
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/api/v2/eventactions/'.self::ACTION));
        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/api/v2/eventrules/'.self::RULE));
    }

    /**
     * The rotation case. The stored secret cannot be read back (SFTPGo masks
     * confidential data unless `confidential_data=1` is requested), so the
     * command must always write the object it was configured with — which is
     * also the only way a rotated secret can take effect.
     */
    public function test_a_changed_secret_updates_the_existing_action(): void
    {
        Http::fake($this->bothExist());
        config(['ftp.events.secret' => 'rotated-secret']);

        $this->artisan('ftp:provision-events')->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/api/v2/eventactions/'.self::ACTION)
            && $request->data()['options']['http_config']['headers'] === [
                ['key' => 'X-FTP-Event-Secret', 'value' => 'rotated-secret'],
                ['key' => 'Content-Type', 'value' => ProvisionFtpEvents::UPLOAD_EVENT_CONTENT_TYPE],
            ]);
    }

    public function test_a_changed_url_updates_the_existing_action(): void
    {
        Http::fake($this->bothExist());
        config(['ftp.events.url' => 'https://portal.example.invalid/api/internal/ftp/upload-event']);

        $this->artisan('ftp:provision-events')->assertExitCode(0);

        Http::assertSent(fn (Request $request): bool => $request->method() === 'PUT'
            && str_ends_with($request->url(), '/api/v2/eventactions/'.self::ACTION)
            && $request->data()['options']['http_config']['endpoint'] === 'https://portal.example.invalid/api/internal/ftp/upload-event');
    }

    public function test_an_unreachable_sftpgo_fails_with_a_named_reason(): void
    {
        Http::fake(static function (): void {
            throw new ConnectionException('Connection refused');
        });

        $this->artisan('ftp:provision-events')
            ->expectsOutputToContain('SFTPGo-Provisionierung fehlgeschlagen')
            ->assertExitCode(1);
    }

    public function test_a_missing_secret_writes_nothing(): void
    {
        Http::fake();
        config(['ftp.events.secret' => '']);

        $this->artisan('ftp:provision-events')
            ->expectsOutputToContain('FTP_EVENT_SECRET ist nicht gesetzt.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_a_missing_url_writes_nothing(): void
    {
        Http::fake();
        config(['ftp.events.url' => null]);

        $this->artisan('ftp:provision-events')
            ->expectsOutputToContain('FTP_EVENT_URL ist nicht gesetzt.')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_an_unconfigured_client_writes_nothing(): void
    {
        Http::fake();
        config(['services.sftpgo.api_key' => null]);

        $this->artisan('ftp:provision-events')
            ->expectsOutputToContain('SFTPGo ist nicht konfiguriert')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    public function test_a_dry_run_contacts_nothing(): void
    {
        Http::fake();

        $this->artisan('ftp:provision-events --dry-run')
            ->expectsOutputToContain('Dry run: SFTPGo wurde nicht kontaktiert.')
            ->assertExitCode(0);

        Http::assertNothingSent();
    }

    /**
     * The documented default must survive the way the compose file publishes the
     * variable.
     *
     * `deployment/docker-compose.yml` sets `FTP_EVENT_URL=${FTP_EVENT_URL:-}`, so
     * in the deployed stack the variable is **present** — as an empty string
     * whenever Portainer holds no value. Laravel's `env()` returns that empty
     * string instead of the default, so a fix that only touches the `env()`
     * default argument would still abort on every deploy. The three unusable
     * shapes are therefore all "not set" and must all resolve to
     * `APP_URL + /api/internal/ftp/upload-event`.
     */
    public function test_the_event_url_default_covers_missing_empty_and_whitespace_variables(): void
    {
        $appUrl = 'https://portal.test';
        $expected = $appUrl.'/api/internal/ftp/upload-event';

        foreach ([null, '', '   '] as $unusable) {
            $config = $this->loadFtpConfig([
                'APP_URL' => $appUrl,
                'FTP_EVENT_URL' => $unusable,
            ]);

            $this->assertSame(
                $expected,
                $config['events']['url'],
                'FTP_EVENT_URL = '.var_export($unusable, true).' must fall back to APP_URL + endpoint.',
            );
        }
    }

    /**
     * The other direction: a configured URL wins, and is used as configured.
     */
    public function test_a_configured_event_url_is_used_instead_of_the_default(): void
    {
        $config = $this->loadFtpConfig([
            'APP_URL' => 'https://portal.test',
            'FTP_EVENT_URL' => 'https://events.example.invalid/portal-hook',
        ]);

        $this->assertSame('https://events.example.invalid/portal-hook', $config['events']['url']);
    }

    /**
     * The second half of the chain: the fallback may only be derived from an
     * `APP_URL` that SFTPGo would accept.
     *
     * SFTPGo's rule is a case-sensitive `http://`/`https://` prefix, so a bare
     * host, another scheme or a relative path must yield `null` — the command
     * then names the missing value instead of provisioning an action SFTPGo
     * answers 400 to.
     */
    public function test_the_event_url_default_requires_an_absolute_http_app_url(): void
    {
        foreach (['portal.test', 'ftp://portal.test', '/relative/path'] as $unusableAppUrl) {
            $config = $this->loadFtpConfig([
                'APP_URL' => $unusableAppUrl,
                'FTP_EVENT_URL' => null,
            ]);

            $this->assertNull(
                $config['events']['url'],
                'APP_URL '.var_export($unusableAppUrl, true).' cannot produce an absolute http(s) endpoint.',
            );
        }
    }

    /**
     * A URL without the scheme SFTPGo requires is refused before the request.
     *
     * Without this guard the command posts the action and reports the Admin API's
     * 400 as a generic provisioning failure, which does not name the wrong
     * variable.
     */
    public function test_a_url_without_an_http_scheme_is_rejected_before_any_request(): void
    {
        Http::fake();
        config(['ftp.events.url' => 'portal.test/api/internal/ftp/upload-event']);

        $this->artisan('ftp:provision-events')
            ->expectsOutputToContain('keine absolute http(s)-URL')
            ->assertExitCode(1);

        Http::assertNothingSent();
    }

    /**
     * Re-evaluates `config/ftp.php` against a manipulated process environment.
     *
     * `env()` reads three stores — `$_ENV`, `$_SERVER` and `getenv()` — and the
     * first one holding the key wins, so setting only `getenv()` would be masked
     * by a `$_SERVER` value left over from phpunit. All three are set (or
     * cleared) together, and all three are restored afterwards. The file is
     * `require`d again rather than `require_once`d: it is a plain `return [...]`,
     * and re-evaluating it is the only way to see the new values, because the
     * application's `config()` was already populated at boot.
     *
     * @param  array<string, string|null>  $variables
     * @return array<string, mixed>
     */
    private function loadFtpConfig(array $variables): array
    {
        $previous = [];

        foreach ($variables as $name => $value) {
            $previous[$name] = [
                'env' => array_key_exists($name, $_ENV) ? $_ENV[$name] : null,
                'env_set' => array_key_exists($name, $_ENV),
                'server' => array_key_exists($name, $_SERVER) ? $_SERVER[$name] : null,
                'server_set' => array_key_exists($name, $_SERVER),
                'getenv' => getenv($name),
            ];

            if ($value === null) {
                unset($_ENV[$name], $_SERVER[$name]);
                putenv($name);

                continue;
            }

            $_ENV[$name] = $value;
            $_SERVER[$name] = $value;
            putenv($name.'='.$value);
        }

        try {
            return require base_path('config/ftp.php');
        } finally {
            foreach ($previous as $name => $state) {
                if ($state['env_set']) {
                    $_ENV[$name] = $state['env'];
                } else {
                    unset($_ENV[$name]);
                }

                if ($state['server_set']) {
                    $_SERVER[$name] = $state['server'];
                } else {
                    unset($_SERVER[$name]);
                }

                if ($state['getenv'] === false) {
                    putenv($name);
                } else {
                    putenv($name.'='.$state['getenv']);
                }
            }
        }
    }

    /**
     * @return array<string, Response>
     */
    private function nothingExists(): array
    {
        return [
            '*/api/v2/eventactions/*' => Http::response(['error' => 'not found'], 404),
            '*/api/v2/eventrules/*' => Http::response(['error' => 'not found'], 404),
            '*/api/v2/eventactions' => Http::response(['name' => self::ACTION, 'type' => 1], 201),
            '*/api/v2/eventrules' => Http::response(['name' => self::RULE], 201),
        ];
    }

    /**
     * @return array<string, Response>
     */
    private function bothExist(): array
    {
        return [
            '*/api/v2/eventactions/*' => Http::response(['name' => self::ACTION, 'type' => 1, 'id' => 1], 200),
            '*/api/v2/eventrules/*' => Http::response(['name' => self::RULE, 'status' => 1, 'id' => 1], 200),
            '*/api/v2/eventactions' => Http::response(['message' => 'Event action updated'], 200),
            '*/api/v2/eventrules' => Http::response(['message' => 'Event rules updated'], 200),
        ];
    }
}
