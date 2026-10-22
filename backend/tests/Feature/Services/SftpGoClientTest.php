<?php

namespace Tests\Feature\Services;

use App\Exceptions\SftpGoException;
use App\Services\SftpGoClient;
use App\Support\FtpSlug;
use App\Values\SftpGoAdminCredentials;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * SftpGoClient against the SFTPGo Admin API v2 (P1-M22).
 *
 * Level 1 of the transport test strategy (feature doc 7.14): a mocked HTTP
 * client, no running SFTPGo. The endpoint shapes are the verified ones from
 * `drakkan/sftpgo` v2.7.6 — `GET /api/v2/token` (a POST answers 405), 201 on
 * add, 409 on a duplicated key, 404 on an unknown account.
 */
class SftpGoClientTest extends TestCase
{
    private const BASE_URL = 'http://sftpgo.test:8080';

    private const USERS_URL = 'sftpgo.test:8080/api/v2/users';

    private const USER_URL = 'sftpgo.test:8080/api/v2/users/*';

    private const TOKEN_URL = 'sftpgo.test:8080/api/v2/token';

    /**
     * The *user* token endpoint: the login of a camera account, not of the
     * admin. It is where a camera password is verified (D-51).
     */
    private const USER_TOKEN_URL = 'sftpgo.test:8080/api/v2/user/token';

    /**
     * Matches the camera rule on purpose, so the leak tests carry a value a
     * real provisioning would really send.
     */
    private const PASSWORD_MARKER = 'kamerapasswort01';

    /**
     * Two markers, because PHP truncates string arguments in a stack trace
     * after 15 characters: `'kamerapasswort01'` would appear in a trace as
     * `'kamerapasswort0...'` and the scan would pass by accident. The short
     * marker is what actually proves the `#[\SensitiveParameter]` redaction.
     */
    private const PASSWORD_MARKERS = [
        'camera password' => self::PASSWORD_MARKER,
        'short marker' => 'kamera01x',
    ];

    /**
     * @return array<string, array{0: string}>
     */
    public static function passwordMarkerProvider(): array
    {
        return array_map(
            fn (string $markerKey): array => [$markerKey],
            array_keys(self::PASSWORD_MARKERS),
        );
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->configure(['api_key' => 'test-api-key']);
    }

    public function test_provision_user_creates_an_enabled_camera_account(): void
    {
        Http::fake([
            self::USERS_URL => Http::response([
                'id' => 7,
                'username' => 'florian',
                'status' => 1,
                'home_dir' => '/var/www/ftp/florian',
                'permissions' => ['/' => ['*']],
            ], 201),
        ]);

        $user = (new SftpGoClient)->provisionUser('florian', self::PASSWORD_MARKER, '/var/www/ftp/florian');

        $this->assertSame('florian', $user['username']);
        $this->assertSame('/var/www/ftp/florian', $user['home_dir']);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('POST', $request->method());
            $this->assertSame('http://sftpgo.test:8080/api/v2/users', $request->url());
            $this->assertSame([
                'username' => 'florian',
                'password' => self::PASSWORD_MARKER,
                'status' => 1,
                'home_dir' => '/var/www/ftp/florian',
                'description' => 'Portal FTP-Kamera-Zugang',
                'permissions' => ['/' => ['*']],
            ], $request->data());
            $this->assertSame('application/json', $request->header('Content-Type')[0]);

            return true;
        });
    }

    public function test_api_key_authentication_skips_the_token_endpoint(): void
    {
        Http::fake([self::USERS_URL => Http::response(['username' => 'florian'], 201)]);

        (new SftpGoClient)->provisionUser('florian', self::PASSWORD_MARKER, '/var/www/ftp/florian');

        Http::assertSent(fn (Request $request): bool => $request->hasHeader(SftpGoClient::API_KEY_HEADER, 'test-api-key')
            && ! $request->hasHeader('Authorization'));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v2/token'));
        $this->assertTrue((new SftpGoClient)->isConfigured());
    }

    public function test_jwt_fallback_fetches_the_token_once_and_reuses_it(): void
    {
        $this->configure(['api_key' => null, 'admin_username' => 'admin', 'admin_password' => 'admin-secret']);
        Http::fake([
            self::TOKEN_URL => Http::response([
                'access_token' => 'jwt-token-1',
                'expires_at' => Carbon::now()->addHour()->toIso8601ZuluString(),
            ]),
            self::USERS_URL => Http::response(['username' => 'florian'], 201),
        ]);

        $client = new SftpGoClient;
        $client->provisionUser('florian', self::PASSWORD_MARKER, '/var/www/ftp/florian');
        $client->provisionUser('florian', self::PASSWORD_MARKER, '/var/www/ftp/florian');

        Http::assertSentCount(3);
        // Exactly one token call for two API calls.
        $this->assertSame(1, $this->countSentTo('/api/v2/token'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v2/token')
            && $request->hasHeader('Authorization', 'Basic '.base64_encode('admin:admin-secret')));
        // Both API calls used the token.
        $this->assertSame(2, $this->countSentTo('/api/v2/users'));
        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), '/api/v2/token')
            && $request->hasHeader('Authorization', 'Bearer jwt-token-1'));
    }

    public function test_jwt_token_is_renewed_before_it_expires(): void
    {
        $this->configure(['api_key' => null, 'admin_username' => 'admin', 'admin_password' => 'admin-secret']);
        Http::fake([
            self::TOKEN_URL => Http::response([
                'access_token' => 'short-lived',
                // Inside the 30 s renewal window: the second call must not reuse it.
                'expires_at' => Carbon::now()->addSeconds(10)->toIso8601ZuluString(),
            ]),
            self::USERS_URL => Http::response(['username' => 'florian'], 201),
        ]);

        $client = new SftpGoClient;
        $client->provisionUser('florian', self::PASSWORD_MARKER, '/var/www/ftp/florian');
        $client->provisionUser('florian', self::PASSWORD_MARKER, '/var/www/ftp/florian');

        Http::assertSentCount(4);
        $this->assertSame(2, $this->countSentTo('/api/v2/token'));
        Http::assertSent(fn (Request $request): bool => str_contains($request->url(), '/api/v2/token'));
    }

    public function test_a_token_response_without_an_access_token_is_a_clean_error(): void
    {
        $this->configure(['api_key' => null, 'admin_username' => 'admin', 'admin_password' => 'admin-secret']);
        Http::fake([
            self::TOKEN_URL => Http::response(['error' => 'no token for you'], 200),
            self::USERS_URL => Http::response(['username' => 'florian'], 201),
        ]);

        try {
            (new SftpGoClient)->provisionUser('florian', self::PASSWORD_MARKER, '/var/www/ftp/florian');
            $this->fail('Expected a SftpGoException for a token response without an access token.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_MALFORMED_RESPONSE, $exception->reason);
        }

        Http::assertNotSent(fn (Request $request): bool => ! str_contains($request->url(), '/api/v2/token'));
    }

    public function test_a_rejected_admin_login_is_reported_as_unauthorized(): void
    {
        $this->configure(['api_key' => null, 'admin_username' => 'admin', 'admin_password' => 'wrong']);
        Http::fake([
            self::TOKEN_URL => Http::response(['error' => 'invalid credentials'], 401),
            self::USERS_URL => Http::response(['username' => 'florian'], 201),
        ]);

        try {
            (new SftpGoClient)->provisionUser('florian', self::PASSWORD_MARKER, '/var/www/ftp/florian');
            $this->fail('Expected a SftpGoException for a rejected admin login.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_UNAUTHORIZED, $exception->reason);
            $this->assertSame(401, $exception->status);
        }
    }

    public function test_missing_credentials_fail_before_a_request_is_sent(): void
    {
        $this->configure(['api_key' => null, 'admin_username' => null, 'admin_password' => null]);
        Http::fake();

        $client = new SftpGoClient;

        $this->assertFalse($client->isConfigured());

        try {
            $client->provisionUser('florian', self::PASSWORD_MARKER, '/var/www/ftp/florian');
            $this->fail('Expected a SftpGoException for an unconfigured service.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_NOT_CONFIGURED, $exception->reason);
            $this->assertNull($exception->status);
        }

        Http::assertNothingSent();
    }

    public function test_a_half_configured_admin_login_is_treated_as_unconfigured(): void
    {
        $this->configure(['api_key' => null, 'admin_username' => 'admin', 'admin_password' => null]);
        Http::fake();

        $this->assertFalse((new SftpGoClient)->isConfigured());
        $this->assertNull(SftpGoAdminCredentials::fromConfig());

        $this->expectException(SftpGoException::class);

        (new SftpGoClient)->provisionUser('florian', self::PASSWORD_MARKER, '/var/www/ftp/florian');
    }

    public function test_an_account_name_that_cannot_work_is_rejected_before_any_request(): void
    {
        Http::fake();

        $this->assertRejectedUsername('j.doe');
        $this->assertRejectedUsername('Florian');
        $this->assertRejectedUsername('florian@host');
        $this->assertRejectedUsername('fl');
        $this->assertRejectedUsername('-florian');
        $this->assertRejectedUsername('a'.str_repeat('b', 32));

        Http::assertNothingSent();
    }

    public function test_a_relative_home_directory_is_rejected_before_any_request(): void
    {
        Http::fake();

        try {
            (new SftpGoClient)->provisionUser('florian', self::PASSWORD_MARKER, 'ftp/florian');
            $this->fail('Expected a SftpGoException for a relative home directory.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_INVALID_REQUEST, $exception->reason);
        }

        Http::assertNothingSent();
    }

    public function test_provision_user_reports_a_duplicate_account(): void
    {
        Http::fake([self::USERS_URL => Http::response(['error' => 'username already in use'], 409)]);

        try {
            (new SftpGoClient)->provisionUser('florian', self::PASSWORD_MARKER, '/var/www/ftp/florian');
            $this->fail('Expected a SftpGoException for an existing account.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_ALREADY_EXISTS, $exception->reason);
            $this->assertSame(409, $exception->status);
        }
    }

    /**
     * @return array<string, array{0: int, 1: string}>
     */
    public static function statusProvider(): array
    {
        return [
            'validation error' => [400, SftpGoException::REASON_INVALID_REQUEST],
            'unauthorized' => [401, SftpGoException::REASON_UNAUTHORIZED],
            'forbidden' => [403, SftpGoException::REASON_UNAUTHORIZED],
            'conflict' => [409, SftpGoException::REASON_ALREADY_EXISTS],
            'server error' => [500, SftpGoException::REASON_SERVER_ERROR],
            'gateway timeout' => [504, SftpGoException::REASON_SERVER_ERROR],
        ];
    }

    #[DataProvider('statusProvider')]
    public function test_error_responses_map_to_the_documented_reason(int $status, string $reason): void
    {
        Http::fake([self::USERS_URL => Http::response(['error' => 'nope'], $status)]);

        try {
            (new SftpGoClient)->provisionUser('florian', self::PASSWORD_MARKER, '/var/www/ftp/florian');
            $this->fail("Expected a SftpGoException for HTTP {$status}.");
        } catch (SftpGoException $exception) {
            $this->assertSame($reason, $exception->reason);
            $this->assertSame($status, $exception->status);
        }
    }

    public function test_an_unreachable_service_produces_a_clean_error_instead_of_a_transport_error(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            (new SftpGoClient)->provisionUser('florian', self::PASSWORD_MARKER, '/var/www/ftp/florian');
            $this->fail('Expected a SftpGoException for an unreachable service.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_UNREACHABLE, $exception->reason);
            $this->assertNull($exception->status);
            $this->assertInstanceOf(ConnectionException::class, $exception->getPrevious());
        }
    }

    public function test_reset_password_keeps_the_existing_account_configuration(): void
    {
        Http::fake(function (Request $request) {
            // The real service answers the update with an ApiResponse, not with
            // the user object (openapi: PUT /users/{username} -> ApiResponse).
            if ($request->method() === 'PUT') {
                return Http::response(['message' => 'User updated'], 200);
            }

            return Http::response([
                'id' => 7,
                'username' => 'florian',
                'status' => 1,
                'home_dir' => '/var/www/ftp/florian',
                'description' => 'Portal FTP-Kamera-Zugang',
                'permissions' => ['/' => ['*'], '/sub' => ['list', 'download']],
                'virtual_folders' => [],
                'has_password' => true,
            ]);
        });

        $result = (new SftpGoClient)->resetPassword('florian', self::PASSWORD_MARKER);

        $this->assertSame(['message' => 'User updated'], $result);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }

            $this->assertSame('http://sftpgo.test:8080/api/v2/users/florian?disconnect=1', $request->url());
            // Read-modify-write: a PUT with only the password would wipe
            // home_dir and permissions (SFTPGo 2.7.6 updateUser).
            $this->assertSame(self::PASSWORD_MARKER, $request->data()['password']);
            $this->assertSame('/var/www/ftp/florian', $request->data()['home_dir']);
            $this->assertSame(['/' => ['*'], '/sub' => ['list', 'download']], $request->data()['permissions']);
            $this->assertSame(1, $request->data()['status']);

            return true;
        });
    }

    /**
     * The update has to keep the *shape* of the answer, not only its values.
     *
     * SFTPGo renders empty objects (`filters.totp_config.secret`,
     * `filesystem.osconfig`, …), and PHP's `json_encode` writes an empty
     * *array* as `[]`. A `PUT` with `"secret":[]` is refused by the service —
     * measured on SFTPGo 2.7.6: `json: cannot unmarshal array into Go struct
     * field UserTOTPConfig.filters.totp_config.secret of type kms.BaseSecret`.
     * The password reset therefore never worked against a real instance: every
     * test faked the HTTP client, and the JSON round-trip was never exercised.
     */
    public function test_the_password_reset_keeps_the_empty_json_objects_of_the_user(): void
    {
        Http::fake(function (Request $request) {
            if ($request->method() === 'PUT') {
                return Http::response(['message' => 'User updated'], 200);
            }

            // The body of a real answer, in the shape the service renders it:
            // the empty values there are objects, not arrays.
            return Http::response(
                '{"id":7,"status":1,"username":"florian","has_password":true,'
                .'"home_dir":"/var/www/ftp/florian","uid":0,"gid":0,"max_sessions":0,'
                .'"quota_size":0,"quota_files":0,"permissions":{"/":["*"]},'
                .'"upload_data_transfer":0,"download_data_transfer":0,"total_data_transfer":0,'
                .'"description":"Portal FTP-Kamera-Zugang",'
                .'"filters":{"hooks":{"external_auth_disabled":false,"pre_login_disabled":false,'
                .'"check_password_disabled":false},"totp_config":{"secret":{}}},'
                .'"filesystem":{"provider":0,"osconfig":{},"s3config":{},"gcsconfig":{},'
                .'"azblobconfig":{},"cryptconfig":{},"sftpconfig":{},"httpconfig":{}}}',
                200,
                ['Content-Type' => 'application/json'],
            );
        });

        (new SftpGoClient)->resetPassword('florian', self::PASSWORD_MARKER);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'PUT') {
                return false;
            }

            $body = $request->body();

            // The empty objects stay objects: the service refuses an array there.
            $this->assertStringContainsString('"secret":{}', $body);
            $this->assertStringContainsString('"osconfig":{}', $body);
            $this->assertStringNotContainsString('"secret":[]', $body);
            $this->assertStringNotContainsString('"osconfig":[]', $body);

            // The rest of the account survived the round-trip (read-modify-write).
            $this->assertSame('/var/www/ftp/florian', $request->data()['home_dir']);
            $this->assertSame(['/' => ['*']], $request->data()['permissions']);
            $this->assertSame(self::PASSWORD_MARKER, $request->data()['password']);
            // The password travels in the body, never in the URL.
            $this->assertStringNotContainsString(self::PASSWORD_MARKER, $request->url());

            return true;
        });
    }

    public function test_reset_password_of_an_unknown_account_fails_before_the_update(): void
    {
        Http::fake([self::USER_URL => Http::response(['error' => 'not found'], 404)]);

        try {
            (new SftpGoClient)->resetPassword('florian', self::PASSWORD_MARKER);
            $this->fail('Expected a SftpGoException for an unknown account.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_NOT_FOUND, $exception->reason);
        }

        Http::assertNotSent(fn (Request $request): bool => $request->method() === 'PUT');
    }

    // ── Password verification (D-51) ─────────────────────────────────────────

    /**
     * The verification is a *user* login: the account's own credentials go to
     * the user endpoint, in the Basic header. The portal stores no camera
     * password (P1-M23), so the service is the only place where one can be
     * checked.
     */
    public function test_the_verification_uses_the_account_credentials_in_the_basic_header(): void
    {
        Http::fake([self::USER_TOKEN_URL => Http::response(['access_token' => 'user-token'], 200)]);

        $this->assertTrue((new SftpGoClient)->verifyPassword('florian', self::PASSWORD_MARKER));

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('GET', $request->method());
            $this->assertSame('http://sftpgo.test:8080/api/v2/user/token', $request->url());
            $this->assertSame(
                'Basic '.base64_encode('florian:'.self::PASSWORD_MARKER),
                $request->header('Authorization')[0],
            );

            return true;
        });
    }

    /**
     * A wrong password is an answer, not a failure: the service rejected the
     * credentials, so the method says so and the caller decides what to do.
     */
    public function test_the_verification_reports_wrong_credentials_as_false(): void
    {
        Http::fake([self::USER_TOKEN_URL => Http::response(['error' => 'invalid credentials'], 401)]);

        $this->assertFalse((new SftpGoClient)->verifyPassword('florian', self::PASSWORD_MARKER));
    }

    /**
     * A 403 is the same answer: the account may not log in (disabled, or a
     * protocol that is not allowed). It is not a wrong password that a retry
     * could fix, but the caller cannot tell the two apart — and must not have
     * to.
     */
    public function test_the_verification_reports_a_forbidden_account_as_false(): void
    {
        Http::fake([self::USER_TOKEN_URL => Http::response(['error' => 'forbidden'], 403)]);

        $this->assertFalse((new SftpGoClient)->verifyPassword('florian', self::PASSWORD_MARKER));
    }

    /**
     * An answer that is neither a confirmation nor a rejection is a failure of
     * the service. It must not be reported as a wrong password: the
     * photographer would retype a password that is perfectly fine.
     */
    public function test_the_verification_reports_an_unusable_answer_as_a_service_failure(): void
    {
        Http::fake([self::USER_TOKEN_URL => Http::response(['error' => 'internal failure'], 500)]);

        try {
            (new SftpGoClient)->verifyPassword('florian', self::PASSWORD_MARKER);
            $this->fail('Expected a SftpGoException for an unusable answer.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_SERVER_ERROR, $exception->reason);
            $this->assertSame(500, $exception->status);
        }
    }

    /**
     * A transport failure is not a wrong password either. The two lead to
     * different actions — one to a retry, the other to a retyped password — so
     * they may not share an answer.
     */
    public function test_the_verification_reports_an_unreachable_service_as_an_exception(): void
    {
        Http::fake(fn () => throw new ConnectionException('cURL error 28: Operation timed out'));

        try {
            (new SftpGoClient)->verifyPassword('florian', self::PASSWORD_MARKER);
            $this->fail('Expected a SftpGoException for an unreachable service.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_UNREACHABLE, $exception->reason);
            $this->assertNull($exception->status);
            $this->assertInstanceOf(ConnectionException::class, $exception->getPrevious());
        }
    }

    /**
     * The password must not travel in the URL. SFTPGo's HTTP access log
     * contains the full request URL (measured on the production instance), so a
     * query string would write the camera password into a log file the portal
     * neither owns nor can redact.
     */
    public function test_the_verification_never_puts_the_password_into_the_url(): void
    {
        Http::fake([self::USER_TOKEN_URL => Http::response(['access_token' => 'user-token'], 200)]);

        (new SftpGoClient)->verifyPassword('florian', self::PASSWORD_MARKER);

        Http::assertSent(function (Request $request): bool {
            $this->assertStringNotContainsString(self::PASSWORD_MARKER, $request->url());
            $this->assertStringNotContainsString(self::PASSWORD_MARKER, (string) $request->body());

            return true;
        });
    }

    /**
     * The verification must not carry an admin credential. The request has to
     * be authenticated *as the camera account*; an API key or an admin JWT
     * would put the admin secret on a path that needs only the user's own.
     */
    public function test_the_verification_carries_no_admin_credential(): void
    {
        Http::fake([self::USER_TOKEN_URL => Http::response(['access_token' => 'user-token'], 200)]);

        (new SftpGoClient)->verifyPassword('florian', self::PASSWORD_MARKER);

        Http::assertSent(fn (Request $request): bool => ! $request->hasHeader(SftpGoClient::API_KEY_HEADER));
        Http::assertNotSent(fn (Request $request): bool => str_contains($request->url(), '/api/v2/token'));

        // The same with the JWT fallback: the admin token is not even fetched.
        $this->configure(['api_key' => null, 'admin_username' => 'admin', 'admin_password' => 'admin-secret']);
        Http::fake();

        (new SftpGoClient)->verifyPassword('florian', self::PASSWORD_MARKER);

        Http::assertSent(fn (Request $request): bool => ! str_contains($request->url(), '/api/v2/token'));
    }

    /**
     * The password of a verification must not reach the log either. The log
     * record of the call carries operation, account, status, duration and the
     * body *length* — never the body, never a header.
     */
    public function test_the_verification_keeps_the_password_out_of_the_log(): void
    {
        Http::fake([self::USER_TOKEN_URL => Http::response(['access_token' => 'user-token'], 200)]);

        $records = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$records): void {
            $records[] = $event;
        });

        (new SftpGoClient)->verifyPassword('florian', self::PASSWORD_MARKER);

        $this->assertNotEmpty($records, 'The verification must be logged.');

        foreach ($records as $record) {
            $serialized = (string) json_encode([$record->level, $record->message, $record->context], JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString(self::PASSWORD_MARKER, $serialized);
        }
    }

    public function test_delete_user_removes_the_account(): void
    {
        Http::fake([self::USER_URL => Http::response(['message' => 'User deleted'], 200)]);

        (new SftpGoClient)->deleteUser('florian');

        Http::assertSent(function (Request $request): bool {
            $this->assertSame('DELETE', $request->method());
            $this->assertSame('http://sftpgo.test:8080/api/v2/users/florian', $request->url());

            return true;
        });
    }

    public function test_delete_user_treats_an_unknown_account_as_already_absent(): void
    {
        Http::fake([self::USER_URL => Http::response(['error' => 'not found'], 404)]);

        (new SftpGoClient)->deleteUser('florian');

        Http::assertSentCount(1);
        Http::assertSent(fn (Request $request): bool => $request->method() === 'DELETE'
            && $request->url() === 'http://sftpgo.test:8080/api/v2/users/florian');
    }

    public function test_list_folders_returns_the_virtual_folders_of_the_account(): void
    {
        Http::fake([
            self::USER_URL => Http::response([
                'username' => 'florian',
                'virtual_folders' => [
                    ['name' => 'inbox', 'path' => '/inbox', 'provider' => 0],
                ],
            ]),
        ]);

        $this->assertSame(
            [['name' => 'inbox', 'path' => '/inbox', 'provider' => 0]],
            (new SftpGoClient)->listFolders('florian'),
        );
    }

    public function test_list_folders_is_empty_when_the_account_has_none(): void
    {
        Http::fake([self::USER_URL => Http::response(['username' => 'florian'])]);

        $this->assertSame([], (new SftpGoClient)->listFolders('florian'));
    }

    public function test_a_successful_but_malformed_body_is_rejected(): void
    {
        Http::fake([self::USER_URL => Http::response('<html>gateway</html>', 200)]);

        try {
            (new SftpGoClient)->findUser('florian');
            $this->fail('Expected a SftpGoException for a non-JSON success body.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_MALFORMED_RESPONSE, $exception->reason);
        }
    }

    public function test_a_folder_list_of_the_wrong_shape_is_rejected(): void
    {
        Http::fake([self::USER_URL => Http::response(['username' => 'florian', 'virtual_folders' => 'nope'])]);

        try {
            (new SftpGoClient)->listFolders('florian');
            $this->fail('Expected a SftpGoException for a folder list of the wrong shape.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_MALFORMED_RESPONSE, $exception->reason);
        }
    }

    public function test_the_tracked_config_carries_only_placeholders_and_the_in_network_default(): void
    {
        // The default is the compose service name: the admin API is reachable
        // in-network only, and a value pointing anywhere public would silently
        // expose an endpoint that creates accounts and sets passwords.
        $config = (string) file_get_contents(config_path('services.php'));
        $envExample = (string) file_get_contents(base_path('.env.example'));

        $this->assertStringContainsString("'base_url' => env('SFTPGO_BASE_URL', 'http://sftpgo:8080')", $config);
        $this->assertStringContainsString("'api_key' => env('SFTPGO_API_KEY')", $config);
        $this->assertStringContainsString("'admin_username' => env('SFTPGO_ADMIN_USERNAME')", $config);
        $this->assertStringContainsString("'admin_password' => env('SFTPGO_ADMIN_PASSWORD')", $config);
        // Placeholders only: no literal secret may reach a tracked file.
        $this->assertStringNotContainsString('SFTPGO_API_KEY=', $config);
        $this->assertMatchesRegularExpression('/^SFTPGO_API_KEY=$/m', $envExample);
        $this->assertMatchesRegularExpression('/^SFTPGO_ADMIN_PASSWORD=$/m', $envExample);
    }

    public function test_the_client_uses_the_fixed_connect_and_total_timeouts(): void
    {
        $this->assertSame(5, SftpGoClient::CONNECT_TIMEOUT_SECONDS);
        $this->assertSame(15, SftpGoClient::TOTAL_TIMEOUT_SECONDS);

        $source = (string) file_get_contents(app_path('Services/SftpGoClient.php'));

        // The Admin API call, the admin token call and the user token call (the
        // password verification, D-51) carry the fixed budgets.
        $this->assertSame(3, substr_count($source, 'connectTimeout(self::CONNECT_TIMEOUT_SECONDS)'));
        $this->assertSame(3, substr_count($source, '->timeout(self::TOTAL_TIMEOUT_SECONDS)'));
        // A retried POST /users would create a second account (409).
        $this->assertStringNotContainsString('->retry(', $source);
    }

    /**
     * The contract of P1-M22: a camera password may not reach the log or an
     * exception. Every method is exercised, every call is logged, and both the
     * log records and the full exception strings are scanned for the password.
     * The string cast of an exception includes the whole trace chain, so a leak
     * through `#[\SensitiveParameter]` would show up here as well.
     */
    #[DataProvider('passwordMarkerProvider')]
    public function test_no_camera_password_reaches_the_log_or_an_exception(string $markerKey): void
    {
        // Read from the map instead of taking the marker as an argument: PHPUnit
        // passes data-provider values as arguments of the test method, and the
        // trace of every exception in this test contains the test frame — the
        // marker would then find itself there and the scan would be worthless.
        $password = self::PASSWORD_MARKERS[$markerKey];

        Http::fake(function (Request $request) {
            if ($request->method() === 'GET') {
                return Http::response([
                    'id' => 7,
                    'username' => 'florian',
                    'status' => 1,
                    'home_dir' => '/var/www/ftp/florian',
                    'permissions' => ['/' => ['*']],
                ]);
            }

            return Http::response(['error' => 'internal failure'], 500);
        });

        $records = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$records): void {
            $records[] = $event;
        });

        $client = new SftpGoClient;
        $calls = [
            'provision' => fn () => $client->provisionUser('florian', $password, '/var/www/ftp/florian'),
            'reset_password' => fn () => $client->resetPassword('florian', $password),
            'delete' => fn () => $client->deleteUser('florian'),
        ];

        $exceptions = [];

        foreach ($calls as $operation => $call) {
            try {
                $call();
                $this->fail("Expected a SftpGoException for {$operation}.");
            } catch (SftpGoException $exception) {
                $exceptions[$operation] = $exception;
            }
        }

        $this->assertSame(
            ['provision', 'reset_password', 'delete'],
            array_keys($exceptions),
            'Every call must fail with a SftpGoException, not with a transport error.',
        );

        // Not vacuous: the client really did log, including the failing update
        // whose request body carried the password.
        $this->assertNotEmpty($records);
        $loggedOperations = array_map(
            fn (MessageLogged $record): string => (string) ($record->context['operation'] ?? ''),
            $records,
        );
        $this->assertContains('provision', $loggedOperations);
        $this->assertContains('reset_password', $loggedOperations);
        $this->assertContains('delete', $loggedOperations);

        foreach ($records as $record) {
            $serialized = (string) json_encode([$record->level, $record->message, $record->context], JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString($password, $serialized);
        }

        foreach ($exceptions as $exception) {
            $this->assertStringNotContainsString($password, (string) $exception);
        }
    }

    public function test_admin_credentials_are_neither_dumpable_nor_serialisable(): void
    {
        $credentials = new SftpGoAdminCredentials('admin', 'admin-secret');

        $this->assertSame('admin', $credentials->username);

        ob_start();
        var_dump($credentials);
        $dump = (string) ob_get_clean();

        // __debugInfo/__serialize reduce both to key names, never values.
        $this->assertStringContainsString('username', $dump);
        $this->assertStringContainsString('password', $dump);
        $this->assertStringNotContainsString('admin-secret', $dump);
        $this->assertStringNotContainsString('admin-secret', serialize($credentials));
    }

    private function configure(array $overrides): void
    {
        config([
            'services.sftpgo' => array_merge([
                'base_url' => self::BASE_URL,
                'api_key' => null,
                'admin_username' => null,
                'admin_password' => null,
            ], $overrides),
        ]);
    }

    private function assertRejectedUsername(string $username): void
    {
        try {
            (new SftpGoClient)->findUser($username);
            $this->fail("Expected the account name '{$username}' to be rejected.");
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_INVALID_REQUEST, $exception->reason);
            // The message comes from the one rule definition (P1-M21), so the
            // profile form and this client cannot drift apart.
            $this->assertStringContainsString(FtpSlug::message(), $exception->getMessage());
        }
    }

    /**
     * `Http::assertSentCount()` counts every recorded request, so a filtered
     * count needs the recorded pairs.
     */
    private function countSentTo(string $urlFragment): int
    {
        return Http::recorded()
            ->filter(fn (array $pair): bool => str_contains($pair[0]->url(), $urlFragment))
            ->count();
    }
}
