<?php

namespace Tests\Unit\Services;

use App\Models\Gallery;
use App\Models\Photo;
use App\Models\Rating;
use App\Models\User;
use App\Services\RatingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class RatingServiceTest extends TestCase
{
    use RefreshDatabase;

    private RatingService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(RatingService::class);
    }

    // ─── ratingStatus() ─────────────────────────────────────────────

    public function test_ratingStatus_returns_empty_users_and_zero_photos_for_gallery_without_photos(): void
    {
        $gallery = Gallery::factory()->create();

        $result = $this->service->ratingStatus($gallery);

        $this->assertSame(['users' => [], 'total_photos' => 0], $result);
    }

    public function test_ratingStatus_returns_correct_progress_for_user_ratings(): void
    {
        $gallery = Gallery::factory()->create();
        $photos = Photo::factory()->count(5)->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);

        foreach ($photos->take(3) as $photo) {
            Rating::create([
                'photo_id' => $photo->id,
                'user_id' => $user->id,
                'rating' => 4,
            ]);
        }

        $result = $this->service->ratingStatus($gallery);

        $this->assertCount(1, $result['users']);
        $this->assertSame(5, $result['total_photos']);

        $userStatus = $result['users'][0];
        $this->assertSame($user->id, $userStatus['user_id']);
        $this->assertSame(3, $userStatus['rated_count']);
        $this->assertSame(5, $userStatus['total_photos']);
    }

    public function test_ratingStatus_counts_only_positive_ratings(): void
    {
        $gallery = Gallery::factory()->create();
        $photos = Photo::factory()->count(4)->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $user->galleries()->attach($gallery->id);

        Rating::create(['photo_id' => $photos[0]->id, 'user_id' => $user->id, 'rating' => 5]);
        Rating::create(['photo_id' => $photos[1]->id, 'user_id' => $user->id, 'rating' => 3]);
        Rating::create(['photo_id' => $photos[2]->id, 'user_id' => $user->id, 'rating' => 0]);

        $result = $this->service->ratingStatus($gallery);

        $this->assertSame(2, $result['users'][0]['rated_count']);
    }

    public function test_ratingStatus_returns_multiple_users(): void
    {
        $gallery = Gallery::factory()->create();
        $photos = Photo::factory()->count(3)->create(['gallery_id' => $gallery->id]);

        $userA = User::factory()->create();
        $userB = User::factory()->create();
        $userA->galleries()->attach($gallery->id);
        $userB->galleries()->attach($gallery->id);

        Rating::create(['photo_id' => $photos[0]->id, 'user_id' => $userA->id, 'rating' => 4]);
        Rating::create(['photo_id' => $photos[0]->id, 'user_id' => $userB->id, 'rating' => 5]);

        $result = $this->service->ratingStatus($gallery);

        $this->assertCount(2, $result['users']);
    }

    public function test_ratingStatus_includes_guest_ratings(): void
    {
        $gallery = Gallery::factory()->create();
        $photos = Photo::factory()->count(3)->create(['gallery_id' => $gallery->id]);
        $guestId = Str::uuid()->toString();

        Rating::create([
            'photo_id' => $photos[0]->id,
            'guest_id' => $guestId,
            'guest_name' => 'Max Mustermann',
            'rating' => 4,
        ]);
        Rating::create([
            'photo_id' => $photos[1]->id,
            'guest_id' => $guestId,
            'guest_name' => 'Max Mustermann',
            'rating' => 5,
        ]);

        $result = $this->service->ratingStatus($gallery);

        $guests = array_values(array_filter($result['users'], fn($u) => str_starts_with($u['user_id'], 'guest_')));
        $this->assertCount(1, $guests);
        $this->assertSame("guest_{$guestId}", $guests[0]['user_id']);
        $this->assertSame('Max Mustermann', $guests[0]['name']);
        $this->assertSame(2, $guests[0]['rated_count']);
        $this->assertSame(3, $guests[0]['total_photos']);
    }

    public function test_ratingStatus_guest_without_name_falls_back_to_Gast(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);

        Rating::create([
            'photo_id' => $photo->id,
            'guest_id' => Str::uuid()->toString(),
            'guest_name' => null,
            'rating' => 3,
        ]);

        $result = $this->service->ratingStatus($gallery);

        $guest = current(array_filter($result['users'], fn($u) => str_starts_with($u['user_id'], 'guest_')));
        $this->assertSame('Gast', $guest['name']);
    }

    // ─── exportRatings() ────────────────────────────────────────────

    public function test_exportRatings_returns_empty_array_for_unrated_gallery(): void
    {
        $gallery = Gallery::factory()->create();
        Photo::factory()->count(3)->create(['gallery_id' => $gallery->id]);

        $result = $this->service->exportRatings($gallery);

        $this->assertSame([], $result);
    }

    public function test_exportRatings_returns_correct_structure(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'title' => 'Sunset',
            'lr_uuid' => 'lr-uuid-123',
        ]);

        Rating::create([
            'photo_id' => $photo->id,
            'user_id' => null,
            'guest_id' => Str::uuid()->toString(),
            'guest_name' => 'Gast User',
            'rating' => 4,
            'comment' => 'Schönes Foto!',
        ]);

        $result = $this->service->exportRatings($gallery);

        $this->assertCount(1, $result);
        $entry = $result[0];
        $this->assertSame($photo->id, $entry['id']);
        $this->assertSame('Sunset', $entry['filename']);
        $this->assertSame('lr-uuid-123', $entry['lr_uuid']);
        $this->assertEquals(4, $entry['avg_rating']);
        $this->assertStringContainsString('Gast User (4 Sterne): Schönes Foto!', $entry['all_comments']);
    }

    public function test_exportRatings_uses_fallback_filename_when_title_empty(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create([
            'gallery_id' => $gallery->id,
            'title' => null,
        ]);

        Rating::create([
            'photo_id' => $photo->id,
            'user_id' => null,
            'guest_id' => Str::uuid()->toString(),
            'rating' => 5,
        ]);

        $result = $this->service->exportRatings($gallery);

        $this->assertStringStartsWith('Bild ', $result[0]['filename']);
    }

    public function test_exportRatings_ignored_ratings_show_ignoriert(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id, 'title' => 'Test']);

        Rating::create([
            'photo_id' => $photo->id,
            'user_id' => null,
            'guest_id' => Str::uuid()->toString(),
            'rating' => 0,
        ]);

        $result = $this->service->exportRatings($gallery);

        $this->assertStringContainsString('Ignoriert', $result[0]['all_comments']);
    }

    public function test_exportRatings_calculates_correct_avg_rating(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id, 'title' => 'Avg Test']);

        $guestA = Str::uuid()->toString();
        $guestB = Str::uuid()->toString();
        Rating::create(['photo_id' => $photo->id, 'guest_id' => $guestA, 'rating' => 3]);
        Rating::create(['photo_id' => $photo->id, 'guest_id' => $guestB, 'rating' => 5]);

        $result = $this->service->exportRatings($gallery);

        $this->assertEquals(4, $result[0]['avg_rating']);
    }

    public function test_exportRatings_avg_rating_ignores_zero_ratings(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id, 'title' => 'Zero Test']);

        $guestA = Str::uuid()->toString();
        $guestB = Str::uuid()->toString();
        Rating::create(['photo_id' => $photo->id, 'guest_id' => $guestA, 'rating' => 0]);
        Rating::create(['photo_id' => $photo->id, 'guest_id' => $guestB, 'rating' => 4]);

        $result = $this->service->exportRatings($gallery);

        $this->assertEquals(4, $result[0]['avg_rating']);
    }

    public function test_exportRatings_linked_user_name_is_used(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id, 'title' => 'User Rated']);
        $user = User::factory()->create(['name' => 'Anna Beispiel']);

        Rating::create([
            'photo_id' => $photo->id,
            'user_id' => $user->id,
            'rating' => 5,
            'comment' => 'Fantastisch!',
        ]);

        $result = $this->service->exportRatings($gallery);

        $this->assertStringContainsString('Anna Beispiel (5 Sterne): Fantastisch!', $result[0]['all_comments']);
    }

    public function test_exportRatings_handles_multiple_photos_and_ratings(): void
    {
        $gallery = Gallery::factory()->create();
        $photoA = Photo::factory()->create(['gallery_id' => $gallery->id, 'title' => 'Photo A']);
        $photoB = Photo::factory()->create(['gallery_id' => $gallery->id, 'title' => 'Photo B']);

        Rating::create(['photo_id' => $photoA->id, 'guest_id' => Str::uuid()->toString(), 'rating' => 3]);
        Rating::create(['photo_id' => $photoB->id, 'guest_id' => Str::uuid()->toString(), 'rating' => 5]);

        $result = $this->service->exportRatings($gallery);

        $this->assertCount(2, $result);
    }
}
