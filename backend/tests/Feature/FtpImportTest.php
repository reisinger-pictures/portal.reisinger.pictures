<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use App\Services\PhotoProcessingService;
use Illuminate\Contracts\Filesystem\Filesystem;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Storage;
use Mockery;
use Tests\TestCase;

class FtpImportTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('ftp_inbox');
        Storage::fake('photos');
    }

    public function test_photographer_can_get_ftp_status()
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/management/ftp/status');
        $response->assertStatus(200)->assertJsonStructure(['ftp_folder', 'file_count']);
    }

    public function test_client_cannot_access_ftp()
    {
        $user = User::factory()->create();
        $token = auth('api')->login($user);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/management/ftp/status');
        $response->assertStatus(403);
    }

    public function test_photographer_cannot_set_target_to_other_brand_gallery()
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $otherGallery = Gallery::factory()->create(['brand' => 'test-brand']);

        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/target', ['gallery_id' => $otherGallery->id]);

        $response->assertStatus(403);
    }

    public function test_photographer_can_set_target_to_own_brand_gallery()
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $gallery = Gallery::factory()->create(['brand' => Brand::B2B]);
        $user->galleries()->attach($gallery->id);

        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/target', ['gallery_id' => $gallery->id]);

        $response->assertStatus(200);
    }

    public function test_photographer_cannot_process_other_brand_gallery()
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $otherGallery = Gallery::factory()->create(['brand' => 'test-brand']);
        $user->update(['current_ftp_gallery_id' => $otherGallery->id]);

        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/process');

        $response->assertStatus(403);
    }

    public function test_set_target_rejects_nonexistent_gallery()
    {
        $user = User::factory()->create(['brand' => Brand::B2B]);
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/ftp/target', ['gallery_id' => 'non-existent']);

        $response->assertStatus(422);
    }

    public function test_process_removes_inbox_file_only_after_storage_processing_and_database_success(): void
    {
        [$user, $sourcePath] = $this->createImportContext();

        $this->processAs($user)
            ->assertOk()
            ->assertJson(['success' => true, 'processed' => 1]);

        $this->assertFalse(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertDatabaseCount('photos', 1);
        $this->assertDatabaseHas('photos', [
            'gallery_id' => $user->current_ftp_gallery_id,
            'title' => 'source',
        ]);
    }

    public function test_process_retains_inbox_file_when_photo_processing_throws(): void
    {
        [$user, $sourcePath] = $this->createImportContext();

        $photoService = $this->createMock(PhotoProcessingService::class);
        $photoService->expects($this->once())
            ->method('processImage')
            ->willThrowException(new \RuntimeException('simulated processing failure'));
        $this->app->instance(PhotoProcessingService::class, $photoService);

        $this->assertProcessThrows($user, 'simulated processing failure');

        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_process_retains_inbox_file_when_photo_storage_put_fails(): void
    {
        [$user, $sourcePath] = $this->createImportContext();

        $manager = Storage::getFacadeRoot();
        $failingDisk = Mockery::mock(Filesystem::class);
        $failingDisk->shouldReceive('put')->once()->andReturn(false);

        Storage::shouldReceive('disk')
            ->andReturnUsing(function (?string $name = null) use ($manager, $failingDisk) {
                return $name === 'photos' ? $failingDisk : $manager->disk($name);
            });

        $this->assertProcessThrows($user, 'Foto konnte nicht in den Photo-Speicher geschrieben werden.');

        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertDatabaseCount('photos', 0);
    }

    public function test_process_retains_inbox_file_when_database_transaction_fails(): void
    {
        [$user, $sourcePath] = $this->createImportContext();

        Photo::creating(static function (): void {
            throw new \RuntimeException('simulated database failure');
        });

        $this->assertProcessThrows($user, 'simulated database failure');

        $this->assertTrue(Storage::disk('ftp_inbox')->exists($sourcePath));
        $this->assertDatabaseCount('photos', 0);
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

    private function assertProcessThrows(User $user, string $expectedMessage): void
    {
        $this->withoutExceptionHandling();

        try {
            $this->processAs($user);
        } catch (\Throwable $exception) {
            $this->assertInstanceOf(\RuntimeException::class, $exception);
            $this->assertSame($expectedMessage, $exception->getMessage());

            return;
        }

        $this->fail('Expected the FTP process request to throw.');
    }
}
