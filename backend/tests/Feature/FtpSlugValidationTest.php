<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\SftpGoException;
use App\Models\Role;
use App\Models\User;
use App\Support\FtpSlug;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P1-M21: `ftp_slug` is the FTP/SFTP account name (7.2 in
 * features/infrastructure/19-ftp-upload-pipeline.md), so the endpoint a
 * photographer uses to change it has to hold the account-name format.
 *
 * The use case these tests describe: a photographer changes the login their
 * camera uses, gets an error for a value the account system cannot carry, and
 * gets the login stored for a value it can.
 *
 * A slug change is a reset (P1-M34), so it runs through the SFTPGo Admin API:
 * the old account is deleted and a new one is provisioned. SFTPGo therefore has
 * to be configured and faked for the happy path — the same setup as
 * `FtpPasswordResetTest`. The alternative is not a green test but a misleading
 * one: without a fake the endpoint would fail on "SFTPGo is not configured",
 * which says nothing about the slug format this file is about.
 */
class FtpSlugValidationTest extends TestCase
{
    use RefreshDatabase;

    /**
     * The inbox root the service derives the account's home directory from.
     * Fixed rather than read from `filesystems.php`, because the derivation is
     * one of the things these tests assert on.
     */
    private const INBOX_ROOT = '/var/www/ftp';

    private const SFTPGO_BASE_URL = 'http://sftpgo.test:8080';

    protected function setUp(): void
    {
        parent::setUp();

        $this->configureSftpGo();
        $this->fakeSftpGo();
    }

    private function photographer(string $email = 'max@example.com'): User
    {
        $user = User::factory()->create(['email' => $email, 'name' => 'Max Mustermann']);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        return $user;
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function acceptedValues(): array
    {
        return [
            'dash' => ['j-doe'],
            'underscore' => ['j_doe'],
            'trailing digit' => ['jdoe1'],
            'plain' => ['jdoe'],
            'minimum length' => ['abc'],
            'maximum length' => [str_repeat('a', FtpSlug::MAX_LENGTH)],
        ];
    }

    #[DataProvider('acceptedValues')]
    public function test_photographer_login_is_stored_for_a_compliant_value(string $value): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => $value,
            ])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'ftp_slug' => $value,
        ]);
    }

    public function test_cosmetic_input_is_normalized_before_it_is_stored(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'Max NeÚ',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'ftp_slug' => 'max-neu',
        ]);
    }

    /**
     * @return array<string, array{0: string}>
     */
    public static function rejectedValues(): array
    {
        return [
            'dot' => ['a.b'],
            'at sign' => ['a@b'],
            'slash' => ['a/b'],
            'path traversal' => ['../etc'],
            'leading underscore' => ['_jdoe'],
            'leading dash' => ['-jdoe'],
            'too short' => ['ab'],
            'too long' => [str_repeat('a', FtpSlug::MAX_LENGTH + 1)],
        ];
    }

    #[DataProvider('rejectedValues')]
    public function test_photographer_gets_an_error_for_a_value_the_account_system_cannot_carry(string $value): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => $value,
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ftp_slug')
            ->assertJsonFragment(['ftp_slug' => [FtpSlug::message()]]);

        // The rejected value must not be persisted, and the previous login must
        // still be the account name.
        $this->assertDatabaseMissing('users', ['id' => $photographer->id, 'ftp_slug' => $value]);
        $this->assertNotSame($value, $photographer->fresh()->ftp_slug);
    }

    public function test_uniqueness_still_guards_the_login(): void
    {
        $other = $this->photographer('florian@example.com');
        $other->forceFill(['ftp_slug' => 'florian'])->save();

        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'florian',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ftp_slug');

        $this->assertDatabaseMissing('users', ['id' => $photographer->id, 'ftp_slug' => 'florian']);
    }

    public function test_keeping_your_own_login_is_allowed(): void
    {
        $photographer = $this->photographer();
        $photographer->forceFill(['ftp_slug' => 'max'])->save();
        $token = auth('api')->login($photographer->fresh());

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'max',
            ])
            ->assertOk();

        $this->assertDatabaseHas('users', ['id' => $photographer->id, 'ftp_slug' => 'max']);
    }

    /**
     * The documented decision: existing, non-conforming values are not migrated.
     * A photographer whose legacy login does not satisfy the rule gets the error
     * until they pick a compliant one — the stored value is never rewritten
     * behind their back, because the slug is a foreign key into the inbox
     * directory.
     */
    public function test_legacy_non_conforming_login_is_reported_not_silently_renamed(): void
    {
        $photographer = $this->photographer();
        $photographer->forceFill(['ftp_slug' => 'j.doe'])->save();
        $token = auth('api')->login($photographer->fresh());

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'j.doe',
            ])
            ->assertStatus(422)
            ->assertJsonValidationErrors('ftp_slug');

        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'ftp_slug' => 'j.doe',
        ]);
    }

    public function test_profile_update_without_a_slug_stays_valid(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', ['name' => 'Max Mustermann'])
            ->assertOk()
            ->assertJsonPath('success', true);

        $this->assertDatabaseHas('users', ['id' => $photographer->id, 'name' => 'Max Mustermann']);
    }

    // ── The account behind the slug ──────────────────────────────────────────

    /**
     * A stored slug is not a string in a column: it is the name of a live
     * account. Changing it therefore has to mean one specific thing — the old
     * account is gone and the new one exists — in that order, with the new
     * account carrying the home directory SFTPGo will actually use.
     *
     * The order is the part worth pinning. Delete-after-provision would leave a
     * stale account behind under the previous name, and the photographer would
     * only find out when a retired camera turns out to still authenticate.
     */
    public function test_a_slug_change_replaces_the_account_in_sftpgo_before_the_new_slug_is_committed(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'j-doe',
            ])
            ->assertOk();

        // Recorded in order, so "delete first" is an assertion and not a hope.
        $calls = Http::recorded()
            ->map(fn (array $exchange): string => $exchange[0]->method().' '.$exchange[0]->url())
            ->all();

        $this->assertSame([
            'DELETE '.self::SFTPGO_BASE_URL.'/api/v2/users/max',
            'POST '.self::SFTPGO_BASE_URL.'/api/v2/users',
        ], $calls);

        Http::assertSent(function (Request $request): bool {
            if ($request->method() !== 'POST') {
                return true;
            }

            $body = $request->data();

            $this->assertSame('j-doe', $body['username']);
            // Derived from the `ftp_inbox` root, so the two containers agree on
            // one path instead of two constants that can drift apart.
            $this->assertSame(self::INBOX_ROOT.'/j-doe', $body['home_dir']);
            $this->assertSame(['/' => ['*']], $body['permissions']);
            $this->assertSame(1, $body['status']);

            return true;
        });

        $this->assertDatabaseHas('users', ['id' => $photographer->id, 'ftp_slug' => 'j-doe']);
    }

    /**
     * The deliberate decision, not an accident: if the account cannot be
     * deleted, the slug is not changed either.
     *
     * `updateProfile()` aborts the transaction on purpose ("der User soll nicht
     * aktualisiert werden, wenn der Account nicht gelöscht wurde"), and this
     * test exists so that it stays a decision. A "helpful" refactoring that
     * updates the row first and reconciles SFTPGo afterwards looks friendlier
     * and breaks the one invariant the inbox depends on: `users.ftp_slug` names
     * an account that exists. The camera would then upload into a folder SFTPGo
     * never created, and that surfaces as an empty inbox rather than an error.
     *
     * No request may be sent either — the refusal happens before anything
     * leaves the process, so there is nothing to reconcile and nothing to log
     * against a service that was never asked.
     */
    public function test_a_slug_change_is_refused_when_sftpgo_is_not_configured(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);
        $slugBefore = $photographer->ftp_slug;

        // Caught rather than rendered, because there is no handler for it — and
        // that absence is part of what is pinned: no "saved anyway" path hides
        // behind the 500.
        $this->withoutExceptionHandling();

        $reported = [];

        // An unset variable arrives as `null`, one copied out of a `.env` as
        // `''`. Both have to refuse, so both are exercised.
        foreach ([null, ''] as $credential) {
            $this->configureSftpGo([
                'api_key' => $credential,
                'admin_username' => $credential,
                'admin_password' => $credential,
            ]);
            // A catch-all fake rather than the stub map: the point of the
            // assertion below is that this recorder stays empty.
            Http::fake();

            try {
                $this->withHeaders(['Authorization' => "Bearer $token"])
                    ->putJson('/api/auth/profile', [
                        'name' => 'Max Mustermann',
                        'ftp_slug' => 'j-doe',
                    ]);

                $this->fail('A slug change must be refused while SFTPGo is unconfigured.');
            } catch (SftpGoException $exception) {
                $reported[var_export($credential, true)] = $exception->reason;
            }

            Http::assertNothingSent();
            $this->assertDatabaseHas('users', [
                'id' => $photographer->id,
                'ftp_slug' => $slugBefore,
            ]);
        }

        $this->assertSame(
            [
                'NULL' => SftpGoException::REASON_NOT_CONFIGURED,
                "''" => SftpGoException::REASON_NOT_CONFIGURED,
            ],
            $reported,
        );
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

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

    /**
     * A SFTPGo Admin API that answers in the shape `SftpGoClient` documents, per
     * method: `POST /users` 201 with the created user, `DELETE /users/{name}`
     * 200 with a message, the read-modify-write `GET`/`PUT` 200 with the user
     * object. Less than that is rejected by `assertJsonObject()` or by the "a
     * 2xx must still be a JSON object" rule, so the fake would fail for a
     * reason that has nothing to do with the slug.
     *
     * The pattern deliberately has no trailing slash. `Factory::stubUrl()`
     * matches with `Str::is('*'.$pattern, $url)` and a stub that does not match
     * falls through to the real HTTP handler — so a pattern covering only
     * `/users/{name}` would send a genuine request for the collection endpoint
     * instead of failing the test.
     */
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

    /**
     * Which account a call is about: the body for the collection endpoint, the
     * last path segment for a single account.
     */
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
