<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * `Gallery::latestPhoto()` must keep its selection rule on PostgreSQL.
 *
 * The previous implementation used `latestOfMany()`, which compiles to
 * `MAX(photos.id)` — an aggregate PostgreSQL does not define for UUID columns
 * (`SQLSTATE 42883: function max(uuid) does not exist`). The relationship now
 * selects the greatest id without an aggregate. These tests pin the row that is
 * chosen: the greatest id, independent of insertion order and of `created_at`,
 * for both lazy and eager access.
 */
class GalleryLatestPhotoTest extends TestCase
{
    use RefreshDatabase;

    private const LOWER_ID = '11111111-1111-4111-8111-111111111111';

    private const HIGHER_ID = 'ffffffff-ffff-4fff-8fff-ffffffffffff';

    public function test_latest_photo_is_the_greatest_id_for_lazy_and_eager_access(): void
    {
        $gallery = Gallery::factory()->create();
        $user = User::factory()->create();

        // The greater id is written first, so "latest" cannot be mistaken for
        // "last inserted". Both rows share the same `created_at`, so it cannot
        // be mistaken for the newest timestamp either.
        $createdAt = now()->subDay();
        $newest = $this->photo($gallery, $user, self::HIGHER_ID, $createdAt);
        $this->photo($gallery, $user, self::LOWER_ID, $createdAt);

        $this->assertSame($newest->id, $gallery->fresh()->latestPhoto->id);

        $loaded = Gallery::with('latestPhoto')->findOrFail($gallery->id);
        $this->assertSame($newest->id, $loaded->latestPhoto->id);
    }

    public function test_latest_photo_is_null_for_an_empty_gallery(): void
    {
        $gallery = Gallery::factory()->create();

        $this->assertNull($gallery->latestPhoto);

        $loaded = Gallery::with('latestPhoto')->findOrFail($gallery->id);
        $this->assertNull($loaded->latestPhoto);
    }

    public function test_eager_loading_keeps_one_photo_per_gallery(): void
    {
        $user = User::factory()->create();

        $first = Gallery::factory()->create();
        $firstLower = $this->photo($first, $user, self::LOWER_ID, now()->subDay());
        $firstHigher = $this->photo($first, $user, self::HIGHER_ID, now()->subDay());

        $second = Gallery::factory()->create();
        $secondOnly = $this->photo($second, $user, '22222222-2222-4222-8222-222222222222', now()->subDay());

        $galleries = Gallery::with('latestPhoto')
            ->whereKey([$first->id, $second->id])
            ->get()
            ->keyBy('id');

        $this->assertSame($firstHigher->id, $galleries->get($first->id)->latestPhoto->id);
        $this->assertSame($secondOnly->id, $galleries->get($second->id)->latestPhoto->id);
        $this->assertNotSame($firstLower->id, $galleries->get($first->id)->latestPhoto->id);
    }

    private function photo(Gallery $gallery, User $user, string $id, \DateTimeInterface $createdAt): Photo
    {
        $photo = Photo::createWithId([
            'gallery_id' => $gallery->id,
            'user_id' => $user->id,
            'lr_uuid' => 'latest-photo-'.$id,
            'title' => 'Photo '.$id,
        ], $id);
        $photo->forceFill(['created_at' => $createdAt])->save();

        return $photo;
    }
}
