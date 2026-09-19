<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Models\Customer;
use App\Models\ModelProfile;
use App\Services\ModelFileStore;
use App\Services\ModelQuestionnaire;
use App\Support\BrandRegistry;
use Ercsctt\FileEncryption\Facades\FileEncrypter;
use Illuminate\Contracts\Encryption\DecryptException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

/**
 * Encryption at rest: AES-256-GCM (chunked) für Personen-Dateien und der
 * answers-Snapshot; Suchfelder bleiben plaintext.
 */
class ModelFileEncryptionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
        Storage::fake('local');
    }

    public function test_put_and_get_roundtrip(): void
    {
        $store = app(ModelFileStore::class);
        $plain = 'hochgeheim-'.bin2hex(random_bytes(8));
        $path = 'model-age-proofs/test/secret.jpg';

        $store->putEncrypted($path, $plain);

        $raw = Storage::disk('local')->get($path);
        $this->assertNotSame($plain, $raw);
        $this->assertStringNotContainsString('hochgeheim', $raw);
        $this->assertTrue($store->isEncrypted($path));
        $this->assertSame($plain, $store->getDecrypted($path));
    }

    public function test_large_payload_roundtrips_across_chunks(): void
    {
        $store = app(ModelFileStore::class);
        // > 64 KB default chunk size → exercises the chunked streaming path.
        $plain = random_bytes(200_000);
        $path = 'model-photos/test/large.bin';

        $store->putEncrypted($path, $plain);

        $this->assertSame($plain, $store->getDecrypted($path));
    }

    public function test_upload_is_encrypted_and_metadata_stripped(): void
    {
        $store = app(ModelFileStore::class);
        $file = UploadedFile::fake()->image('foto.jpg');
        $original = file_get_contents($file->getRealPath());

        $stored = $store->storeUpload('model-photos/test', $file);

        $raw = Storage::disk('local')->get($stored['path']);
        $this->assertNotSame($original, $raw);
        $this->assertTrue($store->isEncrypted($stored['path']));

        $decrypted = $store->getDecrypted($stored['path']);
        $this->assertNotSame('', $decrypted);
        $this->assertSame($stored['size'], strlen($decrypted));
        $this->assertSame('image/jpeg', $stored['mime']);
    }

    public function test_answers_snapshot_is_encrypted_at_rest(): void
    {
        $customer = Customer::factory()->create(['brand' => 'rp', 'is_model' => true]);

        $profile = ModelProfile::create([
            'customer_id' => $customer->id,
            'catalog_version' => ModelQuestionnaire::CURRENT,
            'answers' => [
                ['scope' => 'person', 'key' => 'stage_name', 'label' => 'Künstlername', 'type' => 'text', 'value' => 'streng-geheim'],
            ],
            'submitted_at' => now(),
        ]);

        $raw = DB::table('model_profiles')->where('id', $profile->id)->value('answers');
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('streng-geheim', $raw);
        $this->assertStringNotContainsString('stage_name', $raw);

        // The cast round-trips transparently.
        $this->assertSame('streng-geheim', $profile->fresh()->answersMap()['stage_name']);
    }

    public function test_wrong_key_fails_authentication(): void
    {
        $store = app(ModelFileStore::class);
        $path = 'model-age-proofs/test/rotate.jpg';
        $store->putEncrypted($path, 'geheim');

        config(['file-encryption.key' => 'base64:'.base64_encode(str_repeat('X', 32))]);
        app()->forgetInstance('file.encrypter');
        FileEncrypter::clearResolvedInstances();

        $this->expectException(DecryptException::class);
        app(ModelFileStore::class)->getDecrypted($path);
    }

    public function test_orphaned_plaintext_temp_files_are_swept(): void
    {
        $store = app(ModelFileStore::class);
        $disk = Storage::disk('local');

        $stale = 'model-age-proofs/test/stale.jpg.plain-deadbeef';
        $fresh = 'model-age-proofs/test/fresh.jpg.plain-cafebabe';
        $disk->put($stale, 'plaintext-ausweis');
        $disk->put($fresh, 'plaintext-ausweis');

        // Simulate a crash hours ago (mtime older than the sweep window).
        touch($disk->path($stale), now()->subHours(2)->getTimestamp());

        $deleted = $store->cleanupOrphanedTempFiles();

        $this->assertSame(1, $deleted);
        $disk->assertMissing($stale);
        $disk->assertExists($fresh);
    }

    public function test_store_upload_leaves_no_plaintext_temp_file(): void
    {
        app(ModelFileStore::class)->storeUpload(
            'model-photos/test',
            UploadedFile::fake()->image('foto.jpg')
        );

        $leftovers = collect(Storage::disk('local')->allFiles('model-photos'))
            ->filter(fn (string $file) => str_contains($file, '.plain-'))
            ->values()
            ->all();

        $this->assertSame([], $leftovers);
    }
}
