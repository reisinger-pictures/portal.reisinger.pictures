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
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * The reconciliation command `php artisan ftp:reconcile` (P1-M30).
 *
 * The command is the operator's view on the reconciliation: it says what it
 * did, how many accounts it touched, and whether the run can be trusted. Three
 * properties are therefore load-bearing:
 *
 * 1. **The exit code separates a real error from a successful run.** A run in
 *    which SFTPGo could not be reached has to be distinguishable from a run in
 *    which everything was checked — otherwise a scheduled job would report
 *    success while the account states were never verified.
 * 2. **The summary counts, it does not narrate.** The operator needs the
 *    numbers, and the log file outlives the terminal: no name, no e-mail
 *    address, no password — the slug is what the account is named by.
 * 3. **A failure changes nothing.** The states that could not be verified keep
 *    their status; the run does not write a guess.
 *
 * The command is run through `Artisan::call()` rather than through the test
 * harness' `artisan()` helper, because the summary is one line that carries
 * all the counts: the harness matches one expectation per write, so several
 * substrings of the same line would only ever match the first one.
 *
 * SFTPGo is faked; no test here needs a running instance.
 */
class FtpReconcileCommandTest extends TestCase
{
    use RefreshDatabase;

    private const SFTPGO_BASE_URL = 'http://sftpgo.test:8080';

    protected function setUp(): void
    {
        parent::setUp();

        config([
            'services.sftpgo' => [
                'base_url' => self::SFTPGO_BASE_URL,
                'api_key' => 'test-api-key',
                'admin_username' => null,
                'admin_password' => null,
            ],
        ]);
    }

    public function test_the_command_counts_the_outcomes_and_succeeds(): void
    {
        $known = $this->photographer('florian', FtpCredentialService::STATUS_PENDING);
        $withdrawn = $this->photographer('sabine', FtpCredentialService::STATUS_ACTIVE);
        $untouched = $this->photographer('max', FtpCredentialService::STATUS_PENDING);

        $this->fakeSftpGo(['florian' => 1]);

        $exitCode = Artisan::call('ftp:reconcile');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('florian: active', $output);
        $this->assertStringContainsString('sabine: revoked', $output);
        $this->assertStringContainsString('1 aktiv', $output);
        $this->assertStringContainsString('1 entzogen', $output);
        $this->assertStringContainsString('1 unverändert', $output);
        $this->assertStringContainsString('von 3 Konten', $output);

        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $known->fresh()->ftp_account_status);
        $this->assertSame(FtpCredentialService::STATUS_REVOKED, $withdrawn->fresh()->ftp_account_status);
        $this->assertNotNull($withdrawn->fresh()->ftp_revoked_at);
        $this->assertSame(FtpCredentialService::STATUS_PENDING, $untouched->fresh()->ftp_account_status);
    }

    /**
     * The exit code is the whole difference between "the states were checked"
     * and "the states could not be checked". A run against a service that is
     * down has to be a failure, and it has to leave the states alone.
     */
    public function test_the_command_fails_when_sftpgo_is_unreachable(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_ACTIVE);

        $this->fakeSftpGo([], [], unreachableUserLookup: true);

        $exitCode = Artisan::call('ftp:reconcile');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('fehlgeschlagen', $output);

        $fresh = $user->fresh();
        $this->assertSame(
            FtpCredentialService::STATUS_ACTIVE,
            $fresh->ftp_account_status,
            'An unverified state must not be changed.',
        );
        $this->assertNull($fresh->ftp_revoked_at);
        $this->assertNotNull($fresh->ftp_account_error);
    }

    public function test_a_failed_camera_account_list_aborts_before_changing_any_user(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_ACTIVE);
        Http::fake([
            'sftpgo.test:8080/api/v2/users*' => function (Request $request) {
                if (parse_url($request->url(), PHP_URL_PATH) === '/api/v2/users') {
                    return Http::response(['message' => 'unauthorized'], 401);
                }

                return Http::response(['message' => 'unexpected request'], 500);
            },
        ]);

        $exitCode = Artisan::call('ftp:reconcile');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('FATAL:', $output);
        $this->assertStringContainsString('unauthorized', $output);
        Http::assertSentCount(1);

        $fresh = $user->fresh();
        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $fresh->ftp_account_status);
        $this->assertNull($fresh->ftp_revoked_at);
        $this->assertNull($fresh->ftp_account_error);
    }

    public function test_an_unreachable_camera_account_list_aborts_before_changing_any_user(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_ACTIVE);
        Http::fake([
            'sftpgo.test:8080/api/v2/users*' => function (Request $request) {
                if (parse_url($request->url(), PHP_URL_PATH) === '/api/v2/users') {
                    throw new ConnectionException('connection refused');
                }

                return Http::response(['message' => 'unexpected request'], 500);
            },
        ]);

        $exitCode = Artisan::call('ftp:reconcile');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('FATAL:', $output);
        $this->assertStringContainsString('unreachable', $output);
        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $user->fresh()->ftp_account_status);
        $this->assertNull($user->fresh()->ftp_account_error);
        $this->assertNull($user->fresh()->ftp_revoked_at);
    }

    public function test_an_active_404_with_an_orphan_camera_account_is_reported_without_writes(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_ACTIVE);
        $user->forceFill(['ftp_account_error' => 'existing diagnostic'])->save();
        $this->fakeSftpGo([], [
            $this->cameraAccount('oldname', '/var/www/ftp/oldname'),
        ]);

        $exitCode = Artisan::call('ftp:reconcile');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('oldname: unbeanspruchtes Kamera-Konto in /var/www/ftp/oldname', $output);
        $this->assertStringContainsString('florian: Der Portal-Slug hat kein Konto', $output);
        $this->assertStringContainsString('unbeanspruchte Kamera-Konten: oldname', $output);
        $this->assertStringContainsString('mehrdeutig (Umbenennung oder tatsächlicher Entzug)', $output);
        $this->assertStringContainsString('der Status blieb unverändert', $output);
        $this->assertStringContainsString('Namensabweichungen: 2', $output);

        $fresh = $user->fresh();
        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $fresh->ftp_account_status);
        $this->assertNull($fresh->ftp_revoked_at);
        $this->assertSame('existing diagnostic', $fresh->ftp_account_error);
    }

    public function test_an_active_404_without_an_orphan_camera_account_still_revokes(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_ACTIVE);
        $this->fakeSftpGo();

        $exitCode = Artisan::call('ftp:reconcile');

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('florian: revoked (account_missing)', Artisan::output());
        $this->assertSame(FtpCredentialService::STATUS_REVOKED, $user->fresh()->ftp_account_status);
        $this->assertNotNull($user->fresh()->ftp_revoked_at);
    }

    public function test_an_orphan_account_is_reported_even_when_the_portal_account_is_present(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_PENDING);
        $this->fakeSftpGo(['florian' => 1], [
            $this->cameraAccount('florian', '/var/www/ftp/florian'),
            $this->cameraAccount('unclaimed', '/var/www/ftp/unclaimed'),
        ]);

        $exitCode = Artisan::call('ftp:reconcile', ['--user' => 'florian']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('unclaimed: unbeanspruchtes Kamera-Konto in /var/www/ftp/unclaimed', $output);
        $this->assertStringContainsString('Namensabweichungen: 1', $output);
        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $user->fresh()->ftp_account_status);
    }

    public function test_a_pending_404_with_an_orphan_does_not_mark_the_pending_user_as_drifted(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_PENDING);
        $this->fakeSftpGo([], [
            $this->cameraAccount('unclaimed', '/var/www/ftp/unclaimed'),
        ]);

        $exitCode = Artisan::call('ftp:reconcile');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode, 'The independent orphan account still requires an operator decision.');
        $this->assertStringContainsString('unclaimed: unbeanspruchtes Kamera-Konto', $output);
        $this->assertStringNotContainsString('florian: Der Portal-Slug hat kein Konto', $output);
        $this->assertSame(FtpCredentialService::STATUS_PENDING, $user->fresh()->ftp_account_status);
        $this->assertNull($user->fresh()->ftp_revoked_at);
    }

    public function test_the_camera_account_list_is_paged_and_filters_non_camera_users(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_PENDING);
        $firstPage = [];
        for ($index = 0; $index < 500; $index++) {
            // Zero-padded so the fixture is in the ascending order the live
            // endpoint guarantees; a lexical sort would otherwise interleave
            // external-10 before external-2 and trip the consistency guard.
            $name = 'external-'.str_pad((string) $index, 3, '0', STR_PAD_LEFT);
            $firstPage[] = [
                'username' => $name,
                'home_dir' => '/var/www/ftp/'.$name,
                'description' => 'Other SFTPGo account',
            ];
        }
        $this->fakeSftpGo(['florian' => 1], [
            ...$firstPage,
            $this->cameraAccount('florian', '/var/www/ftp/florian'),
        ]);

        $exitCode = Artisan::call('ftp:reconcile');

        $this->assertSame(0, $exitCode);
        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $user->fresh()->ftp_account_status);
        Http::assertSentCount(3);
        Http::assertSent(fn (Request $request): bool => $this->listQuery($request) === ['limit' => '500', 'offset' => '0']);
        Http::assertSent(fn (Request $request): bool => $this->listQuery($request) === ['limit' => '500', 'offset' => '500']);
    }

    public function test_a_skipped_account_is_not_counted_as_unchecked_after_a_service_wide_failure(): void
    {
        $users = collect([
            $this->photographer('florian', FtpCredentialService::STATUS_ACTIVE),
            $this->photographer('sabine', FtpCredentialService::STATUS_ACTIVE),
            $this->photographer('max', FtpCredentialService::STATUS_ACTIVE),
        ])->sortBy('id')->values();
        [$firstAttempt, $serviceFailure, $notAttempted] = $users->all();

        $attemptedSlugs = [];
        $this->fakeSftpGo();
        $credentials = \Mockery::mock(FtpCredentialService::class);
        $credentials->shouldReceive('reconcileAccount')
            ->twice()
            ->andReturnUsing(function (User $user, array $orphanAccountNames) use (&$attemptedSlugs, $firstAttempt): never {
                $this->assertSame([], $orphanAccountNames);
                $attemptedSlugs[] = $user->ftp_slug;

                if ($user->is($firstAttempt)) {
                    throw FtpCredentialException::unusableAccountName((string) $user->ftp_slug);
                }

                throw SftpGoException::unreachable((string) $user->ftp_slug);
            });
        $this->app->instance(FtpCredentialService::class, $credentials);

        $exitCode = Artisan::call('ftp:reconcile');
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertSame([$firstAttempt->ftp_slug, $serviceFailure->ftp_slug], $attemptedSlugs);
        $this->assertStringContainsString('1 übersprungen', $output);
        $this->assertStringContainsString('1 fehlgeschlagen', $output);
        $this->assertStringContainsString('1 nicht geprüft', $output);
        $this->assertStringContainsString('von 3 Konten', $output);
        $this->assertNotContains($notAttempted->ftp_slug, $attemptedSlugs);
    }

    public function test_the_command_counts_and_reports_accounts_that_can_be_retried(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_ACTIVE);
        $lock = \Mockery::mock(Lock::class);
        $lock->shouldReceive('block')
            ->once()
            ->with(10, \Mockery::type(Closure::class))
            ->andThrow(new LockTimeoutException);
        Cache::shouldReceive('lock')
            ->once()
            ->with(FtpCredentialService::rotationLockKey((string) $user->getKey()), 120)
            ->andReturn($lock);
        $this->fakeSftpGo();

        $exitCode = Artisan::call('ftp:reconcile', ['--user' => 'florian']);
        $output = Artisan::output();

        $this->assertSame(1, $exitCode);
        $this->assertStringContainsString('florian: später erneut abstimmen (rotation_in_progress)', $output);
        $this->assertStringContainsString('1 erneut abzustimmen', $output);
        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $user->fresh()->ftp_account_status);
        Http::assertSentCount(1);
        Http::assertNotSent(fn (Request $request): bool => parse_url($request->url(), PHP_URL_PATH) === '/api/v2/users/florian');
    }

    public function test_the_command_reconciles_only_the_requested_account(): void
    {
        $requested = $this->photographer('florian', FtpCredentialService::STATUS_PENDING);
        $other = $this->photographer('sabine', FtpCredentialService::STATUS_ACTIVE);

        $this->fakeSftpGo(['florian' => 1]);

        $exitCode = Artisan::call('ftp:reconcile', ['--user' => 'florian']);

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('florian: active', Artisan::output());

        // Two listing pages (one entry + the terminating empty page) and the
        // user lookup.
        Http::assertSentCount(2);
        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $requested->fresh()->ftp_account_status);
        $this->assertSame(
            FtpCredentialService::STATUS_ACTIVE,
            $other->fresh()->ftp_account_status,
            'An account that was not named must not be touched.',
        );
    }

    public function test_the_command_rejects_an_unknown_account(): void
    {
        Http::fake();

        $exitCode = Artisan::call('ftp:reconcile', ['--user' => 'nobody']);

        $this->assertSame(2, $exitCode);
        $this->assertStringContainsString('Kein Konto', Artisan::output());

        Http::assertNothingSent();
    }

    /**
     * The option accepts the three identifiers an operator has at hand: the
     * slug the account is named by, the user id and the e-mail address. A
     * photographer who writes to the support desk knows their address, not
     * their UUID.
     */
    public function test_the_account_can_also_be_named_by_id_or_email_address(): void
    {
        $user = $this->photographer('florian', FtpCredentialService::STATUS_PENDING);

        $this->fakeSftpGo(['florian' => 1]);

        foreach ([$user->id, $user->email] as $identifier) {
            $exitCode = Artisan::call('ftp:reconcile', ['--user' => $identifier]);

            $this->assertSame(0, $exitCode, "The account must be found by '{$identifier}'.");
            $this->assertStringContainsString('florian: active', Artisan::output());
        }
    }

    /**
     * The log of a run is a file that outlives the terminal it was written in.
     * The slug is the identifier the account itself is named by; a name or an
     * e-mail address would be personal data with no purpose in that line.
     */
    public function test_the_command_output_carries_no_name_and_no_email_address(): void
    {
        $user = User::factory()->create([
            'brand' => Brand::B2B,
            'name' => 'Florian Beispiel',
            'email' => 'florian.beispiel@example.invalid',
        ]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value])->id);
        $user->forceFill([
            'ftp_slug' => 'florian',
            'ftp_account_status' => FtpCredentialService::STATUS_PENDING,
        ])->save();

        $this->fakeSftpGo(['florian' => 1]);

        $exitCode = Artisan::call('ftp:reconcile');
        $output = Artisan::output();

        $this->assertSame(0, $exitCode);
        $this->assertStringContainsString('florian', $output);
        $this->assertStringNotContainsString('Florian Beispiel', $output);
        $this->assertStringNotContainsString('florian.beispiel@example.invalid', $output);
    }

    /**
     * A user who is not a photographer has no camera account, so a full run
     * does not touch them — the run is about camera accounts, not about every
     * row that happens to carry a slug.
     */
    public function test_the_command_only_reconciles_photographers(): void
    {
        $photographer = $this->photographer('florian', FtpCredentialService::STATUS_PENDING);
        $client = User::factory()->create(['brand' => Brand::B2B]);
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value])->id);
        $client->forceFill([
            'ftp_slug' => 'sabine',
            'ftp_account_status' => FtpCredentialService::STATUS_ACTIVE,
        ])->save();

        $this->fakeSftpGo(['florian' => 1]);

        $exitCode = Artisan::call('ftp:reconcile');

        $this->assertSame(0, $exitCode);
        // Two listing pages (one entry + the terminating empty page) and the
        // photographer's lookup.
        Http::assertSentCount(2);
        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $photographer->fresh()->ftp_account_status);
        $this->assertSame(FtpCredentialService::STATUS_ACTIVE, $client->fresh()->ftp_account_status);
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

    /**
     * SFTPGo knows exactly the accounts named here; every other name answers
     * 404. The value is the account's `status` (1 = enabled, 0 = disabled).
     *
     * @param  array<string, int>  $known
     */
    private function fakeSftpGo(array $known = [], ?array $cameraAccounts = null, bool $unreachableUserLookup = false): void
    {
        $cameraAccounts ??= array_map(
            fn (string $username): array => $this->cameraAccount($username, '/var/www/ftp/'.$username),
            array_keys($known),
        );

        Http::fake([
            'sftpgo.test:8080/api/v2/users*' => function (Request $request) use ($known, $cameraAccounts, $unreachableUserLookup) {
                $path = (string) parse_url($request->url(), PHP_URL_PATH);

                if ($path === '/api/v2/users') {
                    $query = $this->listQuery($request);

                    return Http::response(array_slice($cameraAccounts, (int) $query['offset'], (int) $query['limit']));
                }

                if ($unreachableUserLookup) {
                    throw new ConnectionException('connection refused');
                }

                $account = rawurldecode(basename($path));

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

    /** @return array{username: string, home_dir: string, description: string} */
    private function cameraAccount(string $username, string $homeDir): array
    {
        return [
            'username' => $username,
            'home_dir' => $homeDir,
            'description' => 'Portal FTP-Kamera-Zugang',
        ];
    }

    /** @return array{limit: string, offset: string} */
    private function listQuery(Request $request): array
    {
        parse_str((string) parse_url($request->url(), PHP_URL_QUERY), $query);

        return [
            'limit' => (string) ($query['limit'] ?? ''),
            'offset' => (string) ($query['offset'] ?? ''),
        ];
    }
}
