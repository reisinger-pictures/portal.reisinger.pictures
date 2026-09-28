<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use App\Services\FtpCredentialService;
use App\Support\FtpInboxDirectory;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

/**
 * D-2 (AGENTS.md §14): the software creates `ftp/<ftp_slug>` itself when the
 * slug is set, and a slug is never stored without its directory.
 *
 * SFTPGo's own documentation is explicit that the virtual folder is not created
 * for you ("you have to create the folder on disk yourself"), so before this the
 * directory only existed if someone had created it by hand. The state "slug
 * saved, folder missing" does not look like an error from the portal — it looks
 * like an empty inbox — which is why the write path has to fail closed instead.
 *
 * The failure path is the point. A green "the directory exists" case is easy and
 * proves little; the assertion this file exists for is that a directory that
 * cannot be created leaves the stored slug untouched.
 */
class FtpSlugDirectoryProvisioningTest extends TestCase
{
    use RefreshDatabase;

    private const SFTPGO_BASE_URL = 'http://sftpgo.test:8080';

    private string $inboxRoot;

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
        ]);

        $this->fakeSftpGo();
    }

    public function test_setting_a_slug_creates_its_inbox_directory(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $this->putSlug($token, 'j-doe')->assertOk();

        $this->assertDirectoryExists($this->inboxRoot.'/j-doe');
    }

    /**
     * The mode is observable behaviour, not a detail of the implementation.
     *
     * Group and other bits are asserted instead of owner bits: what carries the
     * pipeline is the access of the processes that upload into and import from
     * the directory — both are in `webgroup` and neither is its owner — and
     * macOS and Linux report the group and other bits identically. The owner
     * bits belong to whichever uid created the directory, which is a deployment
     * fact (D-1), not part of the contract this method guarantees.
     *
     * The group-write assertion is the one that tells `2775` apart from a
     * narrower `2755`: a world-writable `2777` and a `2755` both satisfy
     * `& 0055`, so without it the test would pass for three different modes.
     */
    public function test_the_created_directory_has_the_group_writable_setgid_mode(): void
    {
        $photographer = $this->photographer();
        $token = auth('api')->login($photographer);

        $this->putSlug($token, 'j-doe')->assertOk();

        $path = $this->inboxRoot.'/j-doe';
        $this->assertDirectoryExists($path);

        clearstatcache(true, $path);
        $permissions = fileperms($path);
        $this->assertNotFalse($permissions);

        $this->assertSame(
            0055,
            $permissions & 0055,
            'group and other must be able to read and traverse the inbox',
        );
        $this->assertSame(
            0020,
            $permissions & 0020,
            'the group must be able to write, or a non-owner upload/import cannot work',
        );
        $this->assertSame(
            0,
            $permissions & 0002,
            'the inbox must not be world-writable',
        );
        $this->assertSame(
            02000,
            $permissions & 02000,
            'setgid is what makes new uploads inherit webgroup',
        );
    }

    public function test_a_slug_change_creates_the_new_directory_and_retains_the_old_one(): void
    {
        $photographer = $this->photographer('old-slug');
        $token = auth('api')->login($photographer);

        $this->assertDirectoryExists($this->inboxRoot.'/old-slug');

        $this->putSlug($token, 'new-slug')->assertOk();

        $this->assertDirectoryExists($this->inboxRoot.'/new-slug');
        // Retained on purpose: the old folder can still hold uploads that were
        // not imported yet, and deleting it would destroy them. The operator is
        // told about it through the warning the controller logs (see below).
        $this->assertDirectoryExists($this->inboxRoot.'/old-slug');
    }

    public function test_the_slug_is_not_saved_when_the_directory_cannot_be_created(): void
    {
        $photographer = $this->photographer('old-slug');
        $token = auth('api')->login($photographer);

        // A missing parent is the failure the deployed pipeline actually risks
        // (the bind mount is absent) and it fails for every user, including root.
        // A `chmod 0500` fixture would not: CI runs the suite as root, so the
        // permission would be ignored and the test would be green for the wrong
        // reason. `mkdir()` without the recursive flag is what makes this a
        // missing-parent failure rather than a silently created tree.
        config(['filesystems.disks.ftp_inbox.root' => $this->inboxRoot.'/absent']);

        $response = $this->putSlug($token, 'j-doe');

        $response->assertStatus(500);
        $response->assertJsonStructure(['error']);

        // The whole decision rests on this assertion: the slug is not stored.
        $this->assertSame('old-slug', $photographer->fresh()?->ftp_slug);
        $this->assertDirectoryDoesNotExist($this->inboxRoot.'/absent/j-doe');

        // Nothing reached SFTPGo: the directory is created before the old account
        // is touched, so a failure changes nothing at all — not even the delete.
        Http::assertNothingSent();
    }

    public function test_the_failure_message_names_the_path_and_the_reason(): void
    {
        $photographer = $this->photographer('old-slug');
        $token = auth('api')->login($photographer);

        $path = $this->inboxRoot.'/absent/j-doe';
        config(['filesystems.disks.ftp_inbox.root' => $this->inboxRoot.'/absent']);

        $error = (string) $this->putSlug($token, 'j-doe')->json('error');

        $this->assertStringContainsString(
            $path,
            $error,
            'The message must name the directory that could not be created.',
        );
        // The operating system's own reason, not a generic "failed": an operator
        // has to be able to tell a missing mount from a read-only filesystem or a
        // permission problem.
        $this->assertStringContainsString('No such file or directory', $error);
    }

    public function test_an_existing_directory_is_left_untouched(): void
    {
        // Idempotence: a save must not fail just because the folder already
        // exists, and it must not chmod a mode an operator set deliberately —
        // correcting a mode is `ftp:provision-folders --fix-permissions`, not a
        // side effect of a profile save.
        $photographer = $this->photographer('max');
        mkdir($this->inboxRoot.'/j-doe', 0755);
        $before = fileperms($this->inboxRoot.'/j-doe');

        $token = auth('api')->login($photographer);
        $this->putSlug($token, 'j-doe')->assertOk();

        clearstatcache(true, $this->inboxRoot.'/j-doe');
        $this->assertSame($before, fileperms($this->inboxRoot.'/j-doe'));
    }

    // ── Fixtures ─────────────────────────────────────────────────────────────

    private function putSlug(string $token, string $slug)
    {
        return $this->withHeaders(['Authorization' => "Bearer $token"])
            ->putJson('/api/auth/profile', [
                'name' => 'Max Mustermann',
                'ftp_slug' => $slug,
            ]);
    }

    /**
     * A provisioned photographer: the account is `active`, so a slug change takes
     * the delete-then-provision path, and its folder already exists because the
     * account is live. The folder is created here for real — not through the code
     * under test — so the tests that assert on it are not circular.
     */
    private function photographer(string $slug = 'max'): User
    {
        $user = User::factory()->create(['name' => 'Max Mustermann']);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
        $user->forceFill([
            'ftp_account_status' => FtpCredentialService::STATUS_ACTIVE,
            'ftp_slug' => $slug,
        ])->save();

        mkdir($this->inboxRoot.'/'.$slug, FtpInboxDirectory::MODE);

        return $user->fresh();
    }

    /**
     * A SFTPGo Admin API that answers every call, in the shapes `SftpGoClient`
     * documents: `POST /users` 201 with the user object, `DELETE /users/{name}`
     * 200 with a message, the read-modify-write `GET`/`PUT` 200.
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
     * @return array<string, mixed>
     */
    private function sftpGoUser(string $account): array
    {
        return [
            'id' => 7,
            'username' => $account,
            'status' => 1,
            'home_dir' => $this->inboxRoot.'/'.$account,
            'permissions' => ['/' => ['*']],
        ];
    }
}
