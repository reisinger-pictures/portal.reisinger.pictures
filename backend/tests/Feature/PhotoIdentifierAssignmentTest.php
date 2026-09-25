<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Exceptions\InvalidPhotoIdentifierException;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * P1-M11: `photos.id` is the photo's filesystem identity (it is the stored
 * original's file name and the key of every derivative URL), so it must never be
 * reachable through generic mass assignment. Import writers that have to mint
 * the identifier up front use the explicit `Photo::createWithId()` API instead.
 *
 * These tests pin the four halves of that contract: request input cannot set
 * `id`, the explicit API accepts a canonical UUID, a non-canonical identifier is
 * rejected, and the public upload contract is unchanged.
 */
class PhotoIdentifierAssignmentTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('photos');
    }

    // ---------------------------------------------------------------------
    // id is not mass-assignable
    // ---------------------------------------------------------------------

    public function test_id_is_not_fillable(): void
    {
        $fillable = (new Photo)->getFillable();

        $this->assertNotContains('id', $fillable);
        $this->assertContains('gallery_id', $fillable, 'sanity: the whitelist itself is still populated');
    }

    public function test_generic_create_cannot_set_id(): void
    {
        $gallery = Gallery::factory()->create();
        $attackerId = (string) Str::uuid();

        $photo = Photo::create([
            'gallery_id' => $gallery->id,
            'lr_uuid' => 'generic-create',
            'id' => $attackerId,
        ]);

        $this->assertNotSame($attackerId, $photo->id);
        $this->assertTrue(Str::isUuid($photo->id), 'the framework still assigns a UUID key');
        $this->assertDatabaseMissing('photos', ['id' => $attackerId]);
    }

    public function test_update_cannot_change_the_identifier(): void
    {
        $photo = Photo::factory()->create();
        $originalId = $photo->id;
        $replacementId = (string) Str::uuid();

        $photo->fill(['id' => $replacementId])->save();
        $photo->refresh();

        $this->assertSame($originalId, $photo->id);
        $this->assertDatabaseMissing('photos', ['id' => $replacementId]);
    }

    // ---------------------------------------------------------------------
    // the explicit creation API
    // ---------------------------------------------------------------------

    public function test_create_with_id_accepts_a_canonical_uuid(): void
    {
        $gallery = Gallery::factory()->create();
        $id = (string) Str::uuid();

        $photo = Photo::createWithId([
            'gallery_id' => $gallery->id,
            'lr_uuid' => 'explicit-api-uuid',
            'title' => 'Explicit API',
        ], $id);

        $this->assertTrue($photo->exists);
        $this->assertSame($id, $photo->id);
        $this->assertSame('Explicit API', $photo->title);
        $this->assertDatabaseHas('photos', [
            'id' => $id,
            'gallery_id' => $gallery->id,
            'lr_uuid' => 'explicit-api-uuid',
        ]);
    }

    public function test_create_with_id_accepts_an_uppercase_canonical_uuid(): void
    {
        $gallery = Gallery::factory()->create();
        $id = strtoupper((string) Str::uuid());

        $photo = Photo::createWithId([
            'gallery_id' => $gallery->id,
            'lr_uuid' => 'uppercase-uuid',
        ], $id);

        $this->assertSame($id, $photo->id);
    }

    public function test_create_with_id_persists_the_derived_exif_capture_time(): void
    {
        // `captured_at` is EXIF-derived and deliberately not fillable; the import
        // path used to persist it through forceFill(), so it must survive the
        // move to mass assignment.
        $gallery = Gallery::factory()->create();
        $id = (string) Str::uuid();

        $photo = Photo::createWithId([
            'gallery_id' => $gallery->id,
            'lr_uuid' => 'derived-captured-at',
            'captured_at' => '2024-03-05 10:11:12',
        ], $id);

        $this->assertSame('2024-03-05 10:11:12', $photo->captured_at?->toDateTimeString());
        $this->assertDatabaseHas('photos', ['id' => $id, 'captured_at' => '2024-03-05 10:11:12']);
    }

    public function test_create_with_id_rejects_a_non_uuid_identifier(): void
    {
        $gallery = Gallery::factory()->create();

        try {
            Photo::createWithId([
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'rejected-identifier',
            ], 'invalid-id-to-force-create');
            $this->fail('Expected a non-UUID identifier to be rejected.');
        } catch (InvalidPhotoIdentifierException $exception) {
            $this->assertSame('invalid-id-to-force-create', $exception->identifier());
        }

        $this->assertDatabaseCount('photos', 0);
    }

    #[DataProvider('nonCanonicalIdentifiers')]
    public function test_create_with_id_rejects_non_canonical_spellings(string $id): void
    {
        $gallery = Gallery::factory()->create();

        try {
            Photo::createWithId([
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'rejected-spelling',
            ], $id);
            $this->fail("Expected [{$id}] to be rejected as a photo identifier.");
        } catch (InvalidPhotoIdentifierException $exception) {
            $this->assertSame($id, $exception->identifier());
        }

        $this->assertDatabaseCount('photos', 0);
    }

    public static function nonCanonicalIdentifiers(): array
    {
        return [
            'empty string' => [''],
            'plain slug' => ['not-a-uuid'],
            'undashed hex' => ['9f1b2c3d4e5f60718293a4b5c6d7e8f90'],
            'urn prefixed' => ['urn:uuid:9f1b2c3d-4e5f-6071-8293-a4b5c6d7e8f9'],
            'braced' => ['{9f1b2c3d-4e5f-6071-8293-a4b5c6d7e8f9}'],
            'truncated' => ['9f1b2c3d-4e5f-6071-8293-a4b5c6d7e8f'],
            'non hex digit' => ['9f1b2c3d-4e5f-6071-8293-a4b5c6d7e8fz'],
            'leading whitespace' => [' 9f1b2c3d-4e5f-6071-8293-a4b5c6d7e8f9'],
            'trailing garbage' => ['9f1b2c3d-4e5f-6071-8293-a4b5c6d7e8f9x'],
        ];
    }

    public function test_create_with_id_rejects_an_id_smuggled_into_the_attribute_array(): void
    {
        $gallery = Gallery::factory()->create();

        try {
            Photo::createWithId([
                'id' => (string) Str::uuid(),
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'smuggled-id',
            ], (string) Str::uuid());
            $this->fail('Expected an id inside the attribute array to be rejected.');
        } catch (InvalidPhotoIdentifierException $exception) {
            $this->assertStringContainsString('dedicated createWithId() argument', $exception->getMessage());
        }

        $this->assertDatabaseCount('photos', 0);
    }

    public function test_create_with_id_rejects_an_attribute_it_cannot_persist(): void
    {
        // Fail loudly instead of silently dropping a column the import path used
        // to write through forceFill().
        $gallery = Gallery::factory()->create();

        try {
            Photo::createWithId([
                'gallery_id' => $gallery->id,
                'lr_uuid' => 'unmanaged-attribute',
                'created_at' => now(),
            ], (string) Str::uuid());
            $this->fail('Expected an unmanaged attribute to be rejected.');
        } catch (InvalidPhotoIdentifierException $exception) {
            $this->assertStringContainsString('created_at', $exception->getMessage());
        }

        $this->assertDatabaseCount('photos', 0);
    }

    public function test_photo_never_disables_mass_assignment_to_set_the_identifier(): void
    {
        // Guard against a future "quick fix" reintroducing an unguarded window
        // or a forceFill() bypass on the model itself.
        $code = $this->codeWithoutComments(app_path('Models/Photo.php'));

        $this->assertStringNotContainsString('unguarded', $code);
        $this->assertStringNotContainsString('forceFill', $code);
    }

    // ---------------------------------------------------------------------
    // public upload contract is unchanged
    // ---------------------------------------------------------------------

    public function test_upload_response_contract_is_unchanged_and_ignores_a_client_supplied_id(): void
    {
        $photographer = $this->createPhotographer();
        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        $photographer->galleries()->attach($gallery);

        $clientSuppliedId = (string) Str::uuid();

        $response = $this->actingAs($photographer, 'api')->postJson('/api/management/upload', [
            'gallery_id' => $gallery->id,
            'lr_uuid' => 'web-upload-contract',
            'id' => $clientSuppliedId,
            'file' => UploadedFile::fake()->createWithContent('test.jpg', $this->fixtureContent()),
        ]);

        $response->assertOk();

        // The public response shape is exactly {success, photo_id} as before.
        $this->assertSame(['success', 'photo_id'], array_keys($response->json()));

        $returnedId = $response->json('photo_id');

        $this->assertTrue($response->json('success'));
        $this->assertNotSame($clientSuppliedId, $returnedId, 'the client must not be able to choose the photo identity');
        $this->assertTrue(Str::isUuid($returnedId), 'the server assigns a canonical UUID');
        $this->assertDatabaseHas('photos', ['id' => $returnedId, 'lr_uuid' => 'web-upload-contract']);
        $this->assertDatabaseMissing('photos', ['id' => $clientSuppliedId]);

        // The stored original is still named after the server-assigned identity.
        Storage::disk('photos')->assertExists("{$gallery->id}/{$returnedId}.jpg");
    }

    public function test_ftp_import_still_writes_a_canonical_uuid_identity(): void
    {
        [$user, $sourcePath] = $this->createImportContext();

        $response = $this->postJsonAsUser($user, '/api/management/ftp/process');

        $response->assertOk()->assertJson(['success' => true, 'processed' => 1]);
        $this->assertFalse(Storage::disk('ftp_inbox')->exists($sourcePath));

        $photo = Photo::query()->sole();
        $this->assertTrue(Str::isUuid($photo->id), 'the import still mints a canonical UUID identity');
        Storage::disk('photos')->assertExists("{$photo->gallery_id}/{$photo->id}.jpg");
    }

    // ---------------------------------------------------------------------
    // helpers
    // ---------------------------------------------------------------------

    private function createPhotographer(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        return $user;
    }

    private function fixtureContent(): string
    {
        return file_get_contents(base_path('tests/Fixtures/sample.jpg'));
    }

    /**
     * @return array{0: User, 1: string}
     */
    private function createImportContext(): array
    {
        Storage::fake('ftp_inbox');

        $user = User::factory()->create();
        $user->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'apply_metadata_to_photos' => false,
            'restricted_photographers' => true,
        ]);
        $user->galleries()->attach($gallery->id);
        $user->update(['current_ftp_gallery_id' => $gallery->id]);

        $sourcePath = $user->ftp_slug.'/source.jpg';
        Storage::disk('ftp_inbox')->put($sourcePath, $this->fixtureContent());

        return [$user, $sourcePath];
    }

    private function postJsonAsUser(User $user, string $uri)
    {
        return $this->withHeaders([
            'Authorization' => 'Bearer '.auth('api')->login($user),
        ])->postJson($uri);
    }

    /**
     * Executable source of a file, with comments and docblocks stripped.
     *
     * Keeps the guard tests honest: prose that merely *mentions* `unguarded`
     * must not satisfy or trip them.
     */
    private function codeWithoutComments(string $path): string
    {
        $tokens = token_get_all((string) file_get_contents($path));
        $code = '';

        foreach ($tokens as $token) {
            if (is_array($token)) {
                if (in_array($token[0], [T_COMMENT, T_DOC_COMMENT], true)) {
                    continue;
                }
                $code .= $token[1];

                continue;
            }
            $code .= $token;
        }

        return $code;
    }
}
