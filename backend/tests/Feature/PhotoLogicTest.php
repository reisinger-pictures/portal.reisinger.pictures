<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Tests\TestCase;

class PhotoLogicTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
    }

    private function attachRole(User $user, UserRole $role): void
    {
        $user->roles()->attach(Role::firstOrCreate(['name' => $role->value]));
    }

    // ------------------------------------------------------------------
    // requiresWatermark()
    // ------------------------------------------------------------------

    public function test_requires_watermark_returns_true_when_gallery_is_null(): void
    {
        // gallery_id ist DB-seitig NOT NULL; den Code-Pfad (!$this->gallery)
        // testen wir über eine entladene Relation im Speicher.
        $gallery = Gallery::factory()->create(['is_free_download' => false]);
        $photo = Photo::factory()->for($gallery)->create();
        $photo->setRelation('gallery', null);

        $this->assertTrue($photo->requiresWatermark());
    }

    public function test_requires_watermark_returns_false_when_gallery_is_free_download(): void
    {
        $gallery = Gallery::factory()->create(['is_free_download' => true]);
        $photo = Photo::factory()->for($gallery)->create();

        $this->assertFalse($photo->requiresWatermark());
    }

    public function test_requires_watermark_returns_true_for_unauthenticated_user(): void
    {
        $gallery = Gallery::factory()->create(['is_free_download' => false]);
        $photo = Photo::factory()->for($gallery)->create();

        $this->assertTrue($photo->requiresWatermark());
    }

    public function test_requires_watermark_returns_false_for_admin_user(): void
    {
        $admin = User::factory()->create();
        $this->attachRole($admin, UserRole::ADMIN);
        $gallery = Gallery::factory()->create(['is_free_download' => false]);
        $photo = Photo::factory()->for($gallery)->create();

        $this->actingAs($admin, 'api');

        $this->assertFalse($photo->requiresWatermark());
    }

    public function test_requires_watermark_returns_false_for_photographer_user(): void
    {
        $photographer = User::factory()->create();
        $this->attachRole($photographer, UserRole::PHOTOGRAPHER);
        $gallery = Gallery::factory()->create([
            'is_free_download' => false,
            'restricted_photographers' => false,
        ]);
        $photo = Photo::factory()->for($gallery)->create();

        $this->actingAs($photographer, 'api');

        $this->assertFalse($photo->requiresWatermark());
    }

    public function test_requires_watermark_returns_false_for_user_with_web_flatrate_and_access(): void
    {
        $user = User::factory()->create(['flatrate_level' => 'web']);
        $gallery = Gallery::factory()->create(['is_free_download' => false]);
        $user->galleries()->attach($gallery->id);
        $photo = Photo::factory()->for($gallery)->create();

        $this->actingAs($user, 'api');

        $this->assertFalse($photo->requiresWatermark());
    }

    public function test_requires_watermark_returns_false_for_user_with_original_flatrate_and_access(): void
    {
        $user = User::factory()->create(['flatrate_level' => 'original']);
        $gallery = Gallery::factory()->create(['is_free_download' => false]);
        $user->galleries()->attach($gallery->id);
        $photo = Photo::factory()->for($gallery)->create();

        $this->actingAs($user, 'api');

        $this->assertFalse($photo->requiresWatermark());
    }

    public function test_requires_watermark_returns_true_when_user_has_access_but_no_flatrate(): void
    {
        $user = User::factory()->create(['flatrate_level' => 'none']);
        $gallery = Gallery::factory()->create(['is_free_download' => false]);
        $user->galleries()->attach($gallery->id);
        $photo = Photo::factory()->for($gallery)->create();

        $this->actingAs($user, 'api');

        $this->assertTrue($photo->requiresWatermark());
    }

    public function test_requires_watermark_returns_true_when_user_has_access_but_invalid_flatrate_level(): void
    {
        $user = User::factory()->create(['flatrate_level' => 'invalid_tier']);
        $gallery = Gallery::factory()->create(['is_free_download' => false]);
        $user->galleries()->attach($gallery->id);
        $photo = Photo::factory()->for($gallery)->create();

        $this->actingAs($user, 'api');

        // Invalid level maps to rank 0 → watermark required
        $this->assertTrue($photo->requiresWatermark());
    }

    public function test_requires_watermark_returns_true_when_user_has_flatrate_but_no_gallery_access(): void
    {
        $user = User::factory()->create(['flatrate_level' => 'original']);
        $gallery = Gallery::factory()->create(['is_free_download' => false]);
        $photo = Photo::factory()->for($gallery)->create();

        $this->actingAs($user, 'api');

        // No gallery assignment → canAccessGallery false → watermark
        $this->assertTrue($photo->requiresWatermark());
    }

    // ------------------------------------------------------------------
    // effective_is_editorial_only / effective_is_hidden
    // ------------------------------------------------------------------

    public function test_effective_is_editorial_only_true_when_photo_flag_set(): void
    {
        $gallery = Gallery::factory()->create(['is_editorial_only' => false]);
        $photo = Photo::factory()->for($gallery)->create(['is_editorial_only' => true]);

        $this->assertTrue($photo->effective_is_editorial_only);
    }

    public function test_effective_is_editorial_only_inherits_from_gallery(): void
    {
        $gallery = Gallery::factory()->create(['is_editorial_only' => true]);
        $photo = Photo::factory()->for($gallery)->create(['is_editorial_only' => false]);

        $this->assertTrue($photo->effective_is_editorial_only);
    }

    public function test_effective_is_editorial_only_false_when_neither_set(): void
    {
        $gallery = Gallery::factory()->create(['is_editorial_only' => false]);
        $photo = Photo::factory()->for($gallery)->create(['is_editorial_only' => false]);

        $this->assertFalse($photo->effective_is_editorial_only);
    }

    public function test_effective_is_editorial_only_with_null_gallery(): void
    {
        // gallery_id ist DB-seitig NOT NULL; null-Relation im Speicher setzen.
        $gallery = Gallery::factory()->create(['is_editorial_only' => true]);
        $photo = Photo::factory()->for($gallery)->create(['is_editorial_only' => false]);
        $photo->setRelation('gallery', null);

        // gallery null → effective fällt auf photo's eigenen Wert (false) zurück
        $this->assertFalse($photo->effective_is_editorial_only);
    }

    public function test_effective_is_hidden_inherits_from_gallery(): void
    {
        $gallery = Gallery::factory()->create(['is_hidden' => true]);
        $photo = Photo::factory()->for($gallery)->create(['is_hidden' => false]);

        $this->assertTrue($photo->effective_is_hidden);
    }

    public function test_effective_is_hidden_with_null_gallery(): void
    {
        // gallery_id ist DB-seitig NOT NULL; null-Relation im Speicher setzen.
        $gallery = Gallery::factory()->create(['is_hidden' => true]);
        $photo = Photo::factory()->for($gallery)->create(['is_hidden' => false]);
        $photo->setRelation('gallery', null);

        // gallery null → effective fällt auf photo's eigenen Wert (false) zurück
        $this->assertFalse($photo->effective_is_hidden);
    }

    // ------------------------------------------------------------------
    // URL / thumb_url / srcset attributes
    // ------------------------------------------------------------------

    public function test_url_attribute_contains_path_and_size_2000(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->for($gallery)->create();

        // No gallery access (unauth) → watermark required
        $expected = '/api/media/' . $photo->gallery_id . '/watermarked/_thumbs/2000/' . $photo->id . '.webp';

        $this->assertStringStartsWith($expected, $photo->url);
        $this->assertStringContainsString('?v=', $photo->url);
    }

    public function test_url_attribute_uses_timestamp_v_when_created_at_set(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->for($gallery)->create();
        $photo->refresh(); // ensure created_at is hydrated

        $expectedV = (string)$photo->created_at->timestamp;
        $this->assertStringContainsString('?v=' . $expectedV . '_', $photo->url);
    }

    public function test_url_attribute_uses_v1_when_created_at_null(): void
    {
        // created_at ist DB-seitig NOT NULL + Timestamp-Trait setzt es beim Insert.
        // Den Code-Pfad ($this->created_at ? timestamp : '1') testen wir über
        // eine Speicher-Zuweisung ohne save().
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->for($gallery)->create();
        $photo->created_at = null;

        $this->assertStringContainsString('?v=1_', $photo->url);
    }

    public function test_url_attribute_without_watermark_has_no_watermarked_prefix(): void
    {
        $gallery = Gallery::factory()->create(['is_free_download' => true]);
        $photo = Photo::factory()->for($gallery)->create();

        $expected = '/api/media/' . $photo->gallery_id . '/_thumbs/2000/' . $photo->id . '.webp';

        $this->assertStringStartsWith($expected, $photo->url);
        $this->assertStringNotContainsString('watermarked/', $photo->url);
    }

    public function test_url_attribute_includes_cached_watermark_version(): void
    {
        Cache::put('watermark_version', '7');
        try {
            $gallery = Gallery::factory()->create();
            $photo = Photo::factory()->for($gallery)->create();

            $this->assertStringContainsString('_7', $photo->url);
        } finally {
            Cache::forget('watermark_version');
        }
    }

    public function test_url_attribute_defaults_watermark_version_to_1(): void
    {
        Cache::forget('watermark_version');
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->for($gallery)->create();

        $this->assertStringContainsString('_1', $photo->url);
    }

    public function test_thumb_url_attribute_uses_size_800(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->for($gallery)->create();

        $this->assertStringContainsString('_thumbs/800/', $photo->thumb_url);
    }

    public function test_srcset_attribute_contains_all_four_sizes(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->for($gallery)->create();

        $srcset = $photo->srcset;

        $this->assertStringContainsString('_thumbs/250/', $srcset);
        $this->assertStringContainsString('_thumbs/400/', $srcset);
        $this->assertStringContainsString('_thumbs/800/', $srcset);
        $this->assertStringContainsString('_thumbs/1200/', $srcset);
        $this->assertStringContainsString(' 250w, ', $srcset);
        $this->assertStringContainsString(' 1200w', $srcset);
    }

    public function test_srcset_attribute_without_watermark_has_no_prefix(): void
    {
        $gallery = Gallery::factory()->create(['is_free_download' => true]);
        $photo = Photo::factory()->for($gallery)->create();

        $this->assertStringNotContainsString('watermarked/', $photo->srcset);
    }

    // ------------------------------------------------------------------
    // getArtistAttribute
    // ------------------------------------------------------------------

    public function test_artist_attribute_returns_null_when_no_user(): void
    {
        $photo = Photo::factory()->create(['user_id' => null]);

        $this->assertNull($photo->artist);
    }

    public function test_artist_attribute_returns_metadata_copyright_when_set(): void
    {
        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'metadata_copyright' => '© Jane Photography',
        ]);
        $photo = Photo::factory()->for($user, 'user')->create();

        $this->assertSame('© Jane Photography', $photo->artist);
    }

    public function test_artist_attribute_falls_back_to_name_when_copyright_empty(): void
    {
        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'metadata_copyright' => null,
        ]);
        $photo = Photo::factory()->for($user, 'user')->create();

        $this->assertSame('Jane Doe', $photo->artist);
    }

    /**
     * REVIEW: `metadata_copyright ?: name` treatiert den String '0' als falsy.
     * Wenn ein User explizit `metadata_copyright = '0'` setzt (z.B. als Platzhalter),
     * fällt der Artist überraschend auf den Namen zurück. Aktuelles (getestetes)
     * Verhalten wird hier eingefroren; ggf. mit `?? ` statt `?: ` fixen.
     */
    public function test_artist_attribute_falls_back_to_name_when_copyright_is_zero_string_review(): void
    {
        $user = User::factory()->create([
            'name' => 'Jane Doe',
            'metadata_copyright' => '0',
        ]);
        $photo = Photo::factory()->for($user, 'user')->create();

        // Fixed: '0' is preserved
        $this->assertSame('0', $photo->artist);
    }

    // ------------------------------------------------------------------
    // getFilenameAttribute
    // ------------------------------------------------------------------

    public function test_filename_attribute_for_png_mime(): void
    {
        $photo = Photo::factory()->create(['mime_type' => 'image/png']);

        $this->assertSame($photo->id . '.png', $photo->filename);
    }

    public function test_filename_attribute_for_webp_mime(): void
    {
        $photo = Photo::factory()->create(['mime_type' => 'image/webp']);

        $this->assertSame($photo->id . '.webp', $photo->filename);
    }

    public function test_filename_attribute_for_jpeg_mime_defaults_to_jpg(): void
    {
        $photo = Photo::factory()->create(['mime_type' => 'image/jpeg']);

        $this->assertSame($photo->id . '.jpg', $photo->filename);
    }

    public function test_filename_attribute_for_null_mime_defaults_to_jpg(): void
    {
        $photo = Photo::factory()->create(['mime_type' => null]);

        $this->assertSame($photo->id . '.jpg', $photo->filename);
    }

    public function test_filename_attribute_for_gif_mime_defaults_to_jpg(): void
    {
        $photo = Photo::factory()->create(['mime_type' => 'image/gif']);

        $this->assertSame($photo->id . '.jpg', $photo->filename);
    }

    // ------------------------------------------------------------------
    // shouldBeSearchable()
    // ------------------------------------------------------------------

    public function test_should_be_searchable_returns_false_when_gallery_is_null(): void
    {
        // gallery_id ist DB-seitig NOT NULL; null-Relation im Speicher setzen.
        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        $photo = Photo::factory()->for($gallery)->create();
        $photo->setRelation('gallery', null);

        $this->assertFalse($photo->shouldBeSearchable());
    }

    public function test_should_be_searchable_returns_false_for_selection_gallery(): void
    {
        $gallery = Gallery::factory()->create(['type' => 'selection']);
        $photo = Photo::factory()->for($gallery)->create();

        $this->assertFalse($photo->shouldBeSearchable());
    }

    public function test_should_be_searchable_returns_true_for_delivery_gallery(): void
    {
        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        $photo = Photo::factory()->for($gallery)->create();

        $this->assertTrue($photo->shouldBeSearchable());
    }
}
