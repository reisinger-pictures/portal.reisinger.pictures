<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * First-slug provisioning and recovery from an uncertain account state.
 *
 * `User::booted()` gives every new user a slug, but no account: a fresh
 * photographer is `pending` and SFTPGo has never heard of that name. The slug
 * A fresh photographer is `pending`, so changing the generated slug skips a
 * delete and provisions the new name. An `error` status is different: the last
 * remote operation may have completed despite an ambiguous response, so the
 * known old name is deleted again; a 404 verifies it is already absent.
 *
 * The status decides whether deletion is skipped (`pending`) or retried
 * (`active` / `error`), never whether an uncertain failure is tolerated: an
 * unreachable service still aborts the change, pinned here.
 *
 * The counter direction (an `active` account is still deleted and recreated, in
 * that order) lives in `FtpSlugValidationTest`, where the `DELETE`-then-`POST`
 * sequence was already asserted; this file is about the states where there is
 * nothing to delete.
 */
class FtpFirstSlugChangeTest extends TestCase
{
    use RefreshDatabase;

    private const SFTPGO_BASE_URL = 'http://sftpgo.test:8080';

    private string $inboxRoot;

    protected function setUp(): void
    {
        parent::setUp();

        // A real, writable inbox root: the slug write path creates
        // `ftp/<slug>` before it commits (D-2), and the tests assert that the
        // account's `home_dir` is derived from the same root.
        $this->inboxRoot = $this->useTemporaryFtpInboxRoot();

        config([
            'services.sftpgo' => [
                'base_url' => self::SFTPGO_BASE_URL,
                'api_key' => 'test-api-key',
                'admin_username' => null,
                'admin_password' => null,
            ],
        ]);

        $this->fakeSftpGo();
    }

    /**
     * A photographer who has never had an account changes their slug and gets
     * one — the account is created, the old name is never deleted because there
     * was nothing to delete.
     *
     * The status assertions are the point: they are what `provisionAndShow()`
     * writes, so they prove the transition happened rather than the response
     * merely carrying a password.
     */
    public function test_a_pending_account_is_provisioned_without_deleting_anything(): void
    {
        $photographer = $this->photographer(FtpCredentialService::STATUS_PENDING);
        $token = auth('api')->login($photographer);
        $slugBefore = $photographer->ftp_slug;

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'j-doe',
            ]);

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $password = $response->json('ftp_password');
        $this->assertIsString($password);
        $this->assertMatchesRegularExpression(FtpCredentialService::PASSWORD_PATTERN, $password);

        $after = $photographer->fresh();
        $this->assertSame('j-doe', $after?->ftp_slug);
        $this->assertSame('active', $after?->ftp_account_status);
        $this->assertNotNull($after?->ftp_provisioned_at);

        // Exactly one call, a `POST` for the new name. A `DELETE` of the old slug
        // is what used to 404 here, so the sequence is the assertion — not just
        // "no exception".
        $this->assertSame(
            ['POST '.self::SFTPGO_BASE_URL.'/api/v2/users'],
            $this->callsSentToSftpGo(),
        );

        Http::assertSent(function (Request $request) use ($password): bool {
            if ($request->method() !== 'POST') {
                return true;
            }

            $body = $request->data();
            $this->assertSame('j-doe', $body['username']);
            $this->assertSame($password, $body['password'], 'The shown password must be the stored one.');
            $this->assertSame($this->inboxRoot.'/j-doe', $body['home_dir']);

            return true;
        });

        $this->assertNotSame($slugBefore, 'j-doe', 'The test really does change the slug.');
    }

    /**
     * An `error` account may or may not exist. Retry deleting its known name
     * before provisioning the replacement; the lock serializes that recovery.
     */
    public function test_an_account_in_error_retries_deleting_its_known_name(): void
    {
        $photographer = $this->photographer(FtpCredentialService::STATUS_ERROR);
        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'j-doe',
            ]);

        $response->assertOk();
        $this->assertSame(
            [
                'DELETE '.self::SFTPGO_BASE_URL.'/api/v2/users/max',
                'POST '.self::SFTPGO_BASE_URL.'/api/v2/users',
            ],
            $this->callsSentToSftpGo(),
        );
        $this->assertSame('active', $photographer->fresh()?->ftp_account_status);
    }

    /**
     * Fail-closed must not regress: an unreachable service aborts the change.
     * The old slug remains because the DELETE did not return successfully, while
     * `error` records that the remote account's outcome is uncertain.
     */
    public function test_an_active_account_still_aborts_the_change_when_sftpgo_is_unreachable(): void
    {
        Http::fake([
            'sftpgo.test:8080/*' => fn () => throw new ConnectionException('connection refused'),
        ]);

        $photographer = $this->photographer(FtpCredentialService::STATUS_ACTIVE);
        $token = auth('api')->login($photographer);
        $slugBefore = $photographer->ftp_slug;

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'j-doe',
            ]);

        $response->assertStatus(500);
        $response->assertJsonMissingPath('ftp_password');

        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'ftp_slug' => $slugBefore,
        ]);
        $this->assertSame(FtpCredentialService::STATUS_ERROR, $photographer->fresh()?->ftp_account_status);
        $this->assertNotEmpty($photographer->fresh()?->ftp_account_error);
    }

    /**
     * For `pending`, no old account is deleted. The validated new slug is
     * committed before the create; if that SFTPGo call is uncertain, the new
     * slug and `error` remain so reconciliation can inspect that name.
     */
    public function test_a_pending_account_aborts_the_change_when_sftpgo_is_unreachable(): void
    {
        Http::fake([
            'sftpgo.test:8080/*' => fn () => throw new ConnectionException('connection refused'),
        ]);

        $photographer = $this->photographer(FtpCredentialService::STATUS_PENDING);
        $token = auth('api')->login($photographer);
        $slugBefore = $photographer->ftp_slug;

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => 'j-doe',
            ]);

        $response->assertStatus(500);
        $this->assertDatabaseHas('users', [
            'id' => $photographer->id,
            'ftp_slug' => 'j-doe',
            'ftp_account_status' => FtpCredentialService::STATUS_ERROR,
        ]);
        $this->assertNotSame('j-doe', $slugBefore);
        $this->assertNotEmpty($photographer->fresh()?->ftp_account_error);
        $this->assertNull($photographer->fresh()?->ftp_provisioned_at);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function photographer(string $status): User
    {
        $user = User::factory()->create(['email' => 'max@example.com', 'name' => 'Max Mustermann']);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        // The slug is set explicitly because that is the point of these tests:
        // a *named* account that does not exist is exactly the state the
        // unconditional delete used to trip over. The provisioning columns are not
        // mass-assignable and are written the way the service writes them.
        $user->forceFill([
            'ftp_account_status' => $status,
            'ftp_slug' => 'max',
        ])->save();

        return $user->fresh();
    }

    /**
     * `METHOD url` for every call, in order, so "no DELETE" and "a POST" are
     * asserted rather than assumed.
     *
     * @return array<int, string>
     */
    private function callsSentToSftpGo(): array
    {
        return Http::recorded()
            ->map(fn (array $exchange): string => $exchange[0]->method().' '.$exchange[0]->url())
            ->values()
            ->all();
    }

    /**
     * A SFTPGo Admin API that answers every call, in the shapes `SftpGoClient`
     * documents: `POST /users` 201 with the user object, `DELETE /users/{name}`
     * 200 with a message.
     *
     * The pattern deliberately has no trailing slash. `Factory::stubUrl()` matches
     * with `Str::is('*'.$pattern, $url)`, and a stub that does not match falls
     * through to the *real* HTTP handler — a pattern covering only
     * `/users/{name}` would send a genuine request for the collection endpoint
     * instead of failing the test.
     */
    private function fakeSftpGo(): void
    {
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
}
