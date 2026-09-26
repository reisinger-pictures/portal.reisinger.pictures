<?php

namespace Tests\Feature\Services;

use App\Exceptions\FtpCredentialException;
use App\Exceptions\SftpGoException;
use App\Models\User;
use App\Services\FtpCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Log\Events\MessageLogged;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use Tests\TestCase;

/**
 * Password flow of the camera account (P1-M23): generate, hand over, show once,
 * discard. No password at rest anywhere — that is the whole point of the
 * switch to SFTPGo (feature doc 7.3).
 */
class FtpCredentialServiceTest extends TestCase
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

    public function test_generated_camera_password_matches_the_camera_rule(): void
    {
        $service = app(FtpCredentialService::class);
        $lengths = [];

        for ($i = 0; $i < 200; $i++) {
            $password = $service->generateCameraPassword();

            $this->assertMatchesRegularExpression(FtpCredentialService::PASSWORD_PATTERN, $password);
            $this->assertGreaterThanOrEqual(FtpCredentialService::PASSWORD_MIN_LENGTH, strlen($password));
            $this->assertLessThanOrEqual(FtpCredentialService::PASSWORD_MAX_LENGTH, strlen($password));
            // A camera cannot type a special character, and mixed case would
            // turn a keyboard layout into a support case.
            $this->assertMatchesRegularExpression('/^[a-z0-9]+$/', $password);
            $this->assertSame($password, Str::lower($password));

            $lengths[] = strlen($password);
        }

        // The length is drawn per call, not fixed at the minimum.
        $this->assertGreaterThan(
            FtpCredentialService::PASSWORD_MIN_LENGTH,
            max($lengths),
            'At least one of 200 passwords must be longer than the minimum length.',
        );
    }

    public function test_generated_camera_passwords_do_not_repeat(): void
    {
        $service = app(FtpCredentialService::class);
        $passwords = [];

        for ($i = 0; $i < 500; $i++) {
            $passwords[] = $service->generateCameraPassword();
        }

        $this->assertCount(500, array_unique($passwords));
    }

    public function test_generation_stays_inside_the_camera_alphabet_even_for_a_hostile_random_source(): void
    {
        // Str::random() is a base64 alphabet with mixed case; the filter is what
        // turns it into a camera-safe string, and the pattern check is the
        // guarantee. A hostile factory proves the filter does the work.
        Str::createRandomStringsUsing(fn (int $length): string => str_repeat('ABCdef-123!', (int) ceil($length / 12)));

        try {
            $password = app(FtpCredentialService::class)->generateCameraPassword();
            $raw = Str::random(64);

            $this->assertStringContainsString('!', $raw, 'The hostile source really is hostile.');
            $this->assertMatchesRegularExpression(FtpCredentialService::PASSWORD_PATTERN, $password);
            $this->assertSame(
                substr((string) preg_replace('/[^a-z0-9]/', '', Str::lower($raw)), 0, strlen($password)),
                $password,
            );
        } finally {
            Str::createRandomStringsUsing(null);
        }
    }

    public function test_provision_and_show_returns_the_password_it_sent_to_the_service(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/users' => Http::response([
                'id' => 7,
                'username' => 'florian',
                'status' => 1,
                'home_dir' => self::INBOX_ROOT.'/florian',
            ], 201),
        ]);

        $user = User::factory()->create(['ftp_slug' => 'florian']);

        $password = app(FtpCredentialService::class)->provisionAndShow($user);

        $this->assertMatchesRegularExpression(FtpCredentialService::PASSWORD_PATTERN, $password);

        Http::assertSent(function (Request $request) use ($password): bool {
            $this->assertSame('POST', $request->method());
            $this->assertSame('florian', $request->data()['username']);
            $this->assertSame($password, $request->data()['password']);
            $this->assertSame(self::INBOX_ROOT.'/florian', $request->data()['home_dir']);

            return true;
        });
    }

    public function test_provision_and_show_stores_nothing(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/users' => Http::response(['id' => 7, 'username' => 'florian'], 201),
        ]);

        $user = User::factory()->create(['ftp_slug' => 'florian']);
        $before = $user->fresh()?->getAttributes();

        $password = app(FtpCredentialService::class)->provisionAndShow($user);

        $after = $user->fresh()?->getAttributes();

        $this->assertSame($before, $after, 'The show-once flow must not write to the user record.');
        $this->assertStringNotContainsString(
            $password,
            (string) json_encode($after, JSON_THROW_ON_ERROR),
        );
        // There is not even a column that could hold it.
        $this->assertFalse(Schema::hasColumn('users', 'ftp_password'));
        $this->assertFalse(Schema::hasColumn('users', 'ftp_credentials'));
    }

    public function test_provision_and_show_does_not_leak_the_password_into_the_log(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/users' => Http::response(['error' => 'internal failure'], 500),
        ]);

        $user = User::factory()->create(['ftp_slug' => 'florian']);

        $records = [];
        Event::listen(MessageLogged::class, function (MessageLogged $event) use (&$records): void {
            $records[] = $event;
        });

        try {
            app(FtpCredentialService::class)->provisionAndShow($user);
            $this->fail('Expected the service failure to surface.');
        } catch (SftpGoException $exception) {
            $this->assertSame(SftpGoException::REASON_SERVER_ERROR, $exception->reason);
        }

        // The password only exists in the request the fake recorded.
        $password = null;
        Http::assertSent(function (Request $request) use (&$password): bool {
            $password = (string) $request->data()['password'];

            return true;
        });
        $this->assertIsString($password);
        $this->assertMatchesRegularExpression(FtpCredentialService::PASSWORD_PATTERN, $password);

        $this->assertNotEmpty($records, 'A failed provisioning must be logged.');

        foreach ($records as $record) {
            $serialized = (string) json_encode([$record->level, $record->message, $record->context], JSON_THROW_ON_ERROR);

            $this->assertStringNotContainsString($password, $serialized);
        }
    }

    public function test_provisioning_fails_before_any_request_when_the_account_name_is_missing(): void
    {
        Http::fake();

        // The slug is nullable, and the model only generates one from the email
        // on insert — a row without an email-less slug is a real state.
        $user = User::factory()->create();
        $user->forceFill(['ftp_slug' => null])->saveQuietly();

        $this->assertNull($user->fresh()?->ftp_slug);

        try {
            app(FtpCredentialService::class)->provisionAndShow($user);
            $this->fail('Expected an FtpCredentialException for a user without an account name.');
        } catch (FtpCredentialException $exception) {
            $this->assertSame(FtpCredentialException::REASON_MISSING_ACCOUNT_NAME, $exception->reason);
        }

        Http::assertNothingSent();
    }

    public function test_provisioning_refuses_an_account_name_that_cannot_work(): void
    {
        Http::fake();

        $user = User::factory()->create(['ftp_slug' => 'j.doe']);

        try {
            app(FtpCredentialService::class)->provisionAndShow($user);
            $this->fail('Expected an FtpCredentialException for a rule-violating account name.');
        } catch (FtpCredentialException $exception) {
            $this->assertSame(FtpCredentialException::REASON_UNUSABLE_ACCOUNT_NAME, $exception->reason);
        }

        Http::assertNothingSent();
    }

    public function test_provisioning_fails_when_the_inbox_path_is_not_absolute(): void
    {
        config(['filesystems.disks.ftp_inbox.root' => 'ftp']);
        Http::fake();

        $user = User::factory()->create(['ftp_slug' => 'florian']);

        try {
            app(FtpCredentialService::class)->provisionAndShow($user);
            $this->fail('Expected an FtpCredentialException for a relative inbox root.');
        } catch (FtpCredentialException $exception) {
            $this->assertSame(FtpCredentialException::REASON_UNUSABLE_INBOX_PATH, $exception->reason);
        }

        Http::assertNothingSent();
    }

    public function test_provisioning_surfaces_a_duplicate_account(): void
    {
        Http::fake([
            'sftpgo.test:8080/api/v2/users' => Http::response(['error' => 'username already in use'], 409),
        ]);

        $user = User::factory()->create(['ftp_slug' => 'florian']);

        $this->expectException(SftpGoException::class);

        app(FtpCredentialService::class)->provisionAndShow($user);
    }

    public function test_a_trailing_slash_in_the_inbox_root_does_not_produce_a_double_slash(): void
    {
        config(['filesystems.disks.ftp_inbox.root' => self::INBOX_ROOT.'/']);
        Http::fake([
            'sftpgo.test:8080/api/v2/users' => Http::response(['id' => 7, 'username' => 'florian'], 201),
        ]);

        $user = User::factory()->create(['ftp_slug' => 'florian']);

        app(FtpCredentialService::class)->provisionAndShow($user);

        Http::assertSent(function (Request $request): bool {
            $this->assertSame(self::INBOX_ROOT.'/florian', $request->data()['home_dir']);

            return true;
        });
    }
}
