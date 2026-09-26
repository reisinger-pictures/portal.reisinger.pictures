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
 * The first slug change (P1-M58), the same hole one line before the reset.
 *
 * `User::booted()` gives every new user a slug, but no account: a fresh
 * photographer is `pending` and SFTPGo has never heard of that name. The slug
 * change branch deleted the old account unconditionally as soon as a slug was
 * set, `SftpGoClient::deleteUser()` reports a 404 as `not_found` instead of
 * swallowing it, and the branch is fail-closed on purpose — so *every first slug
 * change* died with an HTTP 500. The way to get a camera account was blocked one
 * line earlier than the reset 404 that P1-M58 also fixes.
 *
 * The guard is the same one `FtpCredentialService::revoke()` already carries: an
 * account is deleted only when the status says one exists (19-ftp 7.16). It
 * narrows *whether* the delete happens, never whether a failure is tolerated —
 * `active` plus an unreachable service still aborts the change, pinned here.
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

    private const INBOX_ROOT = '/var/www/ftp';

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
            'filesystems.disks.ftp_inbox.root' => self::INBOX_ROOT,
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
            $this->assertSame(self::INBOX_ROOT.'/j-doe', $body['home_dir']);

            return true;
        });

        $this->assertNotSame($slugBefore, 'j-doe', 'The test really does change the slug.');
    }

    /**
     * An `error` account is in the same position as `pending` as far as SFTPGo is
     * concerned — one that may or may not exist is not one that can be deleted —
     * and the change has to work for it too rather than 500 on a 404.
     */
    public function test_an_account_in_error_is_not_deleted_either(): void
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
            ['POST '.self::SFTPGO_BASE_URL.'/api/v2/users'],
            $this->callsSentToSftpGo(),
        );
        $this->assertSame('active', $photographer->fresh()?->ftp_account_status);
    }

    /**
     * Fail-closed must not regress: the guard decides *whether* to delete, not
     * that failures are tolerated. An `active` account with an unreachable
     * service still aborts the change, and the slug stays as it was — otherwise
     * the photographer would end up with a slug that names no account at all,
     * which looks in the inbox like an empty one and not like a failure.
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
        $this->assertSame('active', $photographer->fresh()?->ftp_account_status);
    }

    /**
     * The same fail-closed rule for a `pending` photographer: the delete is
     * skipped, but the provisioning is a real SFTPGo write, so an outage still
     * aborts instead of committing a slug whose account does not exist.
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
            'ftp_slug' => $slugBefore,
        ]);
        $this->assertSame('pending', $photographer->fresh()?->ftp_account_status);
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
                        'home_dir' => self::INBOX_ROOT.'/'.$account,
                        'permissions' => ['/' => ['*']],
                    ], 201)
                    : Http::response(['message' => 'user deleted'], 200);
            },
        ]);
    }
}
