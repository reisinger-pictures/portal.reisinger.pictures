<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\FtpPasswordReset;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpCredentialService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\ConnectionException;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * `POST /api/management/ftp/reset-password` for every account state (P1-M58).
 *
 * Before this, a camera account could not be created at all: the only action the
 * inbox offers is "Neues Kamera-Passwort", it calls `resetPassword()`, and
 * `SftpGoClient::resetPassword()` is a read-modify-write that starts with
 * `findUser()` — so an account that was never provisioned answers 404. A
 * photographer who never changed their slug stayed `pending` forever, with an
 * inbox that said "request your credentials first" and no way to do that.
 *
 * The endpoint therefore serves both cases and stays the only door: `pending`
 * provisions, `active` rotates, `revoked` and `error` are refused. What this
 * file pins is mostly the *refusals*, because they are the part that can be
 * lost silently — an unguarded `revoked` would hand a photographer whose role
 * was withdrawn a working SFTPGo access back, and the status column would go on
 * claiming `revoked` while the service held a live credential (19-ftp 7.16).
 *
 * The fake answers happily for every call and method. That is what makes
 * `Http::assertNothingSent()` a real assertion and not a missing fixture: with
 * no guard, a `revoked` click would be recorded, answered 200 and the test would
 * fail on the request count — not on an error nobody could interpret.
 */
class FtpFirstCameraAccountTest extends TestCase
{
    use RefreshDatabase;

    private const SFTPGO_BASE_URL = 'http://sftpgo.test:8080';

    /**
     * A real, writable inbox root: `provisionAndShow()` creates `ftp/<slug>` on
     * disk before it creates the account (D-2).
     */
    private string $inboxRoot;

    /**
     * Makes every fixture's slug unique. `users.ftp_slug` is unique, and the
     * column has to carry a *name* for the refused cases to be meaningful — an
     * account without a slug would be refused for a different reason (there
     * would be nothing to send), and the tests below would pass for the wrong
     * one.
     */
    private int $sequence = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->inboxRoot = $this->useTemporaryFtpInboxRoot();

        config([
            'services.sftpgo' => [
                'base_url' => self::SFTPGO_BASE_URL,
                'api_key' => 'test-api-key',
                'admin_username' => null,
                'admin_password' => null,
            ],
            'filesystems.disks.ftp_inbox.root' => $this->inboxRoot,
        ]);

        $this->fakeSftpGo();
    }

    /**
     * The whole point of P1-M58: a photographer without an account gets one, and
     * a usable password, from the button that already existed.
     *
     * Asserted down to the status column, because "the response contains a
     * password" is also true for a rotation — a test that only looked at the
     * response would keep passing if the branch quietly went back to resetting.
     * `active` + `ftp_provisioned_at` is what `provisionAndShow()` writes and
     * therefore the proof that the account really was created.
     */
    public function test_a_photographer_without_an_account_gets_one(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $this->assertSame('pending', $photographer->fresh()?->ftp_account_status);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/reset-password');

        $response->assertOk();
        $response->assertJsonPath('success', true);

        $password = $response->json('password');
        $this->assertIsString($password);
        $this->assertMatchesRegularExpression(FtpCredentialService::PASSWORD_PATTERN, $password);

        $after = $photographer->fresh();
        $this->assertSame('active', $after?->ftp_account_status);
        $this->assertNotNull($after?->ftp_provisioned_at, 'Provisioning must record when it happened.');

        // The account is created, not rotated: a `GET`-then-`PUT` would be the
        // reset path and would have 404'd on the very first call.
        $this->assertSame(['POST'], $this->methodsSentToSftpGo());

        $slug = (string) $photographer->ftp_slug;

        Http::assertSent(function (Request $request) use ($password, $slug): bool {
            if ($request->method() !== 'POST') {
                return true;
            }

            $body = $request->data();
            $this->assertSame($slug, $body['username']);
            $this->assertSame($password, $body['password'], 'The shown password must be the stored one.');
            $this->assertSame($this->inboxRoot.'/'.$slug, $body['home_dir']);
            $this->assertSame(['/' => ['*']], $body['permissions']);
            $this->assertSame(1, $body['status']);

            return true;
        });
    }

    /**
     * D-2 for the reset path: a first-time provisioning creates the inbox
     * directory, not only the SFTPGo account. Before this, a photographer who
     * never changed their (auto-generated) slug and used "Neues Kamera-Passwort"
     * got an account with no directory behind it — an account pointing at a
     * folder that is not there, which from the portal looks like an empty inbox.
     */
    public function test_a_first_provision_creates_the_inbox_directory(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);
        $slug = (string) $photographer->ftp_slug;

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/reset-password')
            ->assertOk();

        $this->assertDirectoryExists($this->inboxRoot.'/'.$slug);
    }

    /**
     * The same failure contract as the slug path: if the directory cannot be
     * created, no account is created either.
     *
     * The ordering is the guarantee. `provisionAndShow()` ensures the directory
     * *before* it calls SFTPGo, because the Admin API has no rollback for a
     * created user — an account created first and then failed on `mkdir()` would
     * be exactly the state D-2 exists to make unrepresentable. The residue of
     * this order is an empty directory, which is harmless and reused on the next
     * call.
     *
     * A missing parent with non-recursive `mkdir()` is the failure the deployed
     * pipeline actually risks (an absent bind mount), and it fails for every
     * user including root. A `chmod 0500` fixture would not: CI runs the suite
     * as root, so the permission would be ignored and the test would be green
     * for the wrong reason.
     */
    public function test_a_first_provision_that_cannot_create_the_directory_creates_no_account(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        config(['filesystems.disks.ftp_inbox.root' => $this->inboxRoot.'/absent']);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/reset-password');

        // The endpoint classifies a portal-side precondition as 422 (`fromCredentialException()`).
        $response->assertStatus(422);
        $this->assertNull($response->json('password'));

        // The whole decision rests on this assertion: nothing reached SFTPGo.
        Http::assertNothingSent();

        $after = $photographer->fresh();
        $this->assertSame('pending', $after?->ftp_account_status);
        $this->assertNull($after?->ftp_provisioned_at);
        $this->assertDirectoryDoesNotExist($this->inboxRoot.'/absent/'.$photographer->ftp_slug);
    }

    /**
     * The counter direction. Without it the test above would also pass against an
     * implementation that provisions unconditionally — which would re-create the
     * account on every reset and 409 on the second call, breaking the recovery
     * path this endpoint exists for.
     */
    public function test_an_existing_account_is_still_rotated_not_recreated(): void
    {
        $photographer = $this->photographer(FtpCredentialService::STATUS_ACTIVE);
        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/reset-password');

        $response->assertOk();

        // Read-modify-write against the existing account: `GET` then `PUT`, and
        // no `POST` — a `POST` here would be a 409 duplicate against the real
        // service.
        $this->assertSame(['GET', 'PUT'], $this->methodsSentToSftpGo());
        $this->assertSame('active', $photographer->fresh()?->ftp_account_status);
    }

    /**
     * The load-bearing guard. A revocation is a decision (a lost role, 19-ftp
     * 7.16); if the reset could undo it, the whole guard would be worth nothing.
     *
     * Without the check this test would fail on the very first assertion:
     * `resetPassword()` would read the account, the fake would answer 200, the
     * response would carry a working password — and the row would still say
     * `revoked`, which is the worst of both worlds: a live credential that no
     * state records.
     */
    public function test_a_revoked_account_cannot_be_revived_by_a_reset(): void
    {
        $photographer = $this->photographer(FtpCredentialService::STATUS_REVOKED);
        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/reset-password');

        $response->assertStatus(409);
        $this->assertNull($response->json('password'));
        $this->assertMatchesRegularExpression(
            '/\p{L}/u',
            (string) $response->json('error'),
            'The message must be human-readable text, not a code.',
        );

        // Not one request may leave the process: the refusal is decided from the
        // status column alone.
        Http::assertNothingSent();
        $this->assertSame('revoked', $photographer->fresh()?->ftp_account_status);
    }

    /**
     * `error` is refused for the same reason in the other direction. The last
     * provisioning attempt failed, so the real state is unknown — a reset would
     * overwrite that uncertainty with a guess and report a password for an
     * account whose existence nobody can vouch for.
     */
    public function test_an_account_in_error_is_not_reset(): void
    {
        $photographer = $this->photographer(FtpCredentialService::STATUS_ERROR);
        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/reset-password');

        $response->assertStatus(409);
        $this->assertNull($response->json('password'));
        $this->assertMatchesRegularExpression('/\p{L}/u', (string) $response->json('error'));

        Http::assertNothingSent();
        $this->assertSame('error', $photographer->fresh()?->ftp_account_status);
    }

    /**
     * 409 and not 422: nothing about the request is wrong, the account is simply
     * not in a state that allows a credential, and no corrected input can change
     * that. A client can act on 409 (stop, show the message); 422 would promise
     * that fixing the input fixes the result. The reset handler in the inbox
     * already branches on 409.
     */
    public function test_a_refused_state_is_a_conflict_and_not_an_invalid_request(): void
    {
        $reported = [];

        foreach ([FtpCredentialService::STATUS_REVOKED, FtpCredentialService::STATUS_ERROR] as $status) {
            $photographer = $this->photographer($status);

            $response = $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($photographer)])
                ->postJson('/api/management/ftp/reset-password');

            $reported[$status] = $response->getStatusCode();
        }

        $this->assertSame([FtpCredentialService::STATUS_REVOKED => 409, FtpCredentialService::STATUS_ERROR => 409], $reported);
    }

    /**
     * Why the state check sits *inside* the `try`: a click on "new password" for
     * a revoked account is a reset attempt that failed, and that is exactly the
     * row an audit trail exists to produce. A check in front of the `try` would
     * refuse the same states and leave the interesting click unrecorded.
     */
    public function test_a_refused_reset_is_audited_as_a_failed_attempt(): void
    {
        foreach ([FtpCredentialService::STATUS_REVOKED, FtpCredentialService::STATUS_ERROR] as $status) {
            $photographer = $this->photographer($status);

            $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($photographer)])
                ->postJson('/api/management/ftp/reset-password')
                ->assertStatus(409);
        }

        $rows = FtpPasswordReset::query()->orderBy('reset_at')->get();

        $this->assertCount(2, $rows, 'A refused reset is still an attempt, so it belongs in the trail.');
        $this->assertSame(
            [false, false],
            $rows->map->success->values()->all(),
            'A refused reset must not be recorded as a success.',
        );
    }

    /**
     * The one path that writes nothing: an account *creation* is not a reset, and
     * `ftp_password_resets` is the reset trail. A row here would be a lie about
     * what happened — and the trail is read as "this photographer rotated their
     * password" when it is read at all.
     */
    public function test_a_first_account_creation_is_not_written_to_the_reset_trail(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/reset-password')
            ->assertOk();

        $this->assertSame(0, FtpPasswordReset::query()->count());
    }

    /**
     * Fail-closed, like the slug change (P1-M34): an unreachable service must not
     * leave a status that claims an account exists. `provisionAndShow()` writes
     * `active` only after SFTPGo accepted the account, so an outage leaves the
     * photographer `pending` and able to try again — the opposite of a "success"
     * that produces an account nobody can reach.
     */
    public function test_provisioning_aborts_while_sftpgo_is_unreachable_and_the_status_stays_pending(): void
    {
        Http::fake([
            'sftpgo.test:8080/*' => fn () => throw new ConnectionException('connection refused'),
        ]);

        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/reset-password');

        $response->assertStatus(503);
        $this->assertNull($response->json('password'));

        $after = $photographer->fresh();
        $this->assertSame('pending', $after?->ftp_account_status);
        $this->assertNull($after?->ftp_provisioned_at);
    }

    /**
     * The hourly quota bounds how often one account can invalidate a working
     * camera, and it has to cover the provisioning branch too — otherwise the
     * first-account path would be a way around it.
     */
    public function test_the_hourly_quota_also_covers_the_provisioning_branch(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        for ($attempt = 0; $attempt < FtpCredentialService::RESET_LIMIT_PER_HOUR; $attempt++) {
            $this->withHeaders(['Authorization' => "Bearer $token"])
                ->postJson('/api/management/ftp/reset-password')
                ->assertOk();
        }

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/reset-password');

        $response->assertStatus(429);
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    /**
     * A photographer in the given account state.
     *
     * `pending` is the column default, so the factory already produces the state
     * a fresh photographer is in; the parameter exists for the other three. The
     * column is not mass-assignable and is written the way the service writes it.
     */
    private function photographer(string $status = FtpCredentialService::STATUS_PENDING): User
    {
        $user = User::factory()->create(['name' => 'Max Mustermann']);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
        $user->forceFill([
            'ftp_account_status' => $status,
            // A revocation never clears the slug: it names the account that
            // existed once, so the refused reset would have had a name to send.
            'ftp_slug' => 'max'.(++$this->sequence),
        ])->save();

        return $user->fresh();
    }

    /**
     * The methods SFTPGo was actually asked for, in order. `GET`+`PUT` is the
     * read-modify-write of a rotation, a lone `POST` is a provisioning, and
     * `DELETE` belongs to the slug change — so the sequence says which of the
     * three happened.
     *
     * @return array<int, string>
     */
    private function methodsSentToSftpGo(): array
    {
        return Http::recorded()
            ->map(fn (array $exchange): string => $exchange[0]->method())
            ->values()
            ->all();
    }

    /**
     * A SFTPGo Admin API that answers every call happily, in the shapes
     * `SftpGoClient` documents: `POST /users` 201 with the user object, the
     * read-modify-write `GET` 200 with it, `PUT` 200 with a message, `DELETE` 200
     * with a message. Less than that is rejected by `assertJsonObject()`, so the
     * fake would fail for a reason unrelated to the state machine.
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
                $account = $this->accountIn($request);

                return match ($request->method()) {
                    'POST' => Http::response($this->sftpGoUser($account), 201),
                    'DELETE' => Http::response(['message' => 'user deleted'], 200),
                    'PUT' => Http::response(['message' => 'user updated'], 200),
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
            'home_dir' => $this->inboxRoot.'/'.$account,
            'description' => 'Portal FTP-Kamera-Zugang',
            'permissions' => ['/' => ['*']],
            'virtual_folders' => [],
        ];
    }
}
