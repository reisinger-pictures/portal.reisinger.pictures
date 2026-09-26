<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Http\Controllers\FtpController;
use App\Models\Gallery;
use App\Models\Role;
use App\Models\User;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Filesystem\FilesystemManager;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Regression coverage for P1-M25: the FTP import stays on a local directory.
 *
 * SFTPGo writes to the same host path /home/webadmin/websites/ftp that is
 * bind-mounted into the backend container, so the import needs no network hop
 * and no credential for our own host
 * (features/infrastructure/19-ftp-upload-pipeline.md §7.8). The failure this
 * file guards against is a "cleanup" that routes the import through
 * Storage::disk('sftp') or league/flysystem-sftp: the photos would still arrive,
 * just one network roundtrip to localhost per file, and the portal would carry
 * an SFTP credential for its own filesystem — with the import then broken
 * whenever SFTPGo is down, which §7.5 forbids.
 *
 * The FtpImportTest suite must stay green unchanged; these tests add the
 * negative direction, i.e. they fail as soon as the import leaves the local
 * disks.
 */
class FtpInboxLocalDiskTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ftp_inbox');
        Storage::fake('photos');
    }

    public function test_the_ftp_inbox_disk_is_a_local_directory_and_no_remote_transfer_disk_exists(): void
    {
        /** @var array<string, array<string, mixed>> $disks */
        $disks = config('filesystems.disks');

        $this->assertSame('local', $disks['ftp_inbox']['driver']);
        $this->assertSame('local', $disks['photos']['driver']);

        // A local disk is addressed by a filesystem path. A remote driver needs
        // a host, a credential and a network roundtrip, so the root must never
        // look like a URL. FTP_STORAGE_PATH may be relative in dev (the
        // committed default is `ftp`, resolved against the process directory),
        // which is still a local read; `photos` on the other hand is required to
        // be absolute by config/filesystems.php and is asserted as such.
        foreach (['ftp_inbox', 'photos'] as $disk) {
            $root = (string) $disks[$disk]['root'];
            $this->assertNotSame('', trim($root), "Disk [{$disk}] must have a root.");
            $this->assertDoesNotMatchRegularExpression(
                '#^[a-z][a-z0-9+.\-]*://#i',
                $root,
                "Disk [{$disk}] must not be addressed by a URL.",
            );
        }
        $this->assertStringStartsWith('/', (string) $disks['photos']['root']);

        // No FTP/SFTP disk may exist at all: the moment one is configured, the
        // "just refactor it onto the sftp driver" move becomes a one-liner.
        $this->assertArrayNotHasKey('sftp', $disks);
        $this->assertArrayNotHasKey('ftp', $disks);
        foreach ($disks as $name => $disk) {
            $this->assertNotContains(
                $disk['driver'] ?? null,
                ['ftp', 'sftp'],
                "Disk [{$name}] must not use a transfer driver while the import is a local-disk read.",
            );
        }
    }

    public function test_process_reads_and_writes_through_the_local_disks_only(): void
    {
        [$user] = $this->createImportContext();

        $requestedDisks = [];
        $this->spyOnDiskLookups($requestedDisks);

        $this->processAs($user)
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 1]);

        // Any additional disk — a new one, and 'sftp' above all — shows up here
        // and fails the assertion instead of quietly becoming a network hop.
        $this->assertEqualsCanonicalizing(
            ['ftp_inbox', 'photos'],
            array_values(array_unique($requestedDisks)),
        );
    }

    public function test_process_needs_no_transfer_driver_and_no_credentials_for_our_own_host(): void
    {
        [$user, $sourcePath] = $this->createImportContext();

        // Remove every non-local disk from the configuration, so a routed
        // import cannot even resolve a transfer driver, and fail the outbound
        // HTTP recorder to catch a roundtrip to an admin API.
        config(['filesystems.disks' => [
            'photos' => config('filesystems.disks.photos'),
            'ftp_inbox' => config('filesystems.disks.ftp_inbox'),
            'local' => config('filesystems.disks.local'),
        ]]);
        Http::fake();

        $this->processAs($user)
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 1]);

        Http::assertNothingSent();
        $this->assertDatabaseCount('photos', 1);
        $this->assertFalse(Storage::disk('ftp_inbox')->exists($sourcePath));
    }

    public function test_the_import_path_in_the_controller_contains_no_transfer_driver_reference(): void
    {
        $source = file_get_contents((new \ReflectionClass(FtpController::class))->getFileName());
        $this->assertIsString($source);
        $this->assertNotFalse($source);

        // Covers the branches this suite does not execute, such as an error
        // recovery path or a future getInboxPath() swap, which the behavioural
        // disk spy above would never reach.
        $this->assertStringNotContainsString("disk('sftp'", $source);
        $this->assertStringNotContainsString('disk("sftp"', $source);
        $this->assertStringNotContainsString("disk('ftp'", $source);
        $this->assertStringNotContainsString('sftp://', $source);
        $this->assertStringNotContainsString('ftp://', $source);
        $this->assertStringNotContainsString('Flysystem\PhpSftp', $source);
    }

    /**
     * @param  list<string>  $requestedDisks
     */
    private function spyOnDiskLookups(array &$requestedDisks): void
    {
        $manager = Storage::getFacadeRoot();
        $this->assertInstanceOf(FilesystemManager::class, $manager);

        Storage::shouldReceive('disk')->andReturnUsing(
            function (?string $name = null) use ($manager, &$requestedDisks): Filesystem {
                $requestedDisks[] = (string) $name;

                /** @var Filesystem $disk */
                $disk = $manager->disk($name);

                return $disk;
            }
        );
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createImportContext(): array
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $gallery = Gallery::factory()->create([
            'brand' => Brand::B2B,
            'type' => 'delivery',
            'apply_metadata_to_photos' => false,
            'restricted_photographers' => true,
        ]);
        $user->galleries()->attach($gallery->id);
        $user->update(['current_ftp_gallery_id' => $gallery->id]);

        $sourcePath = $user->ftp_slug.'/source.jpg';
        Storage::disk('ftp_inbox')->put(
            $sourcePath,
            file_get_contents(base_path('tests/Fixtures/sample.jpg'))
        );

        return [$user, $sourcePath];
    }

    private function processAs(User $user)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($user),
        ])->postJson('/api/management/ftp/process');
    }
}
