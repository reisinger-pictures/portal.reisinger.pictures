<?php

namespace Tests\Feature;

use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\Photo;
use App\Models\User;
use Illuminate\Database\UniqueConstraintViolationException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;
use PHPOpenSourceSaver\JWTAuth\Factory;
use PHPOpenSourceSaver\JWTAuth\JWTAuth;
use Tests\TestCase;

/**
 * Regression coverage for the V038 rating actor-key invariant.
 */
class RatingUniquenessTest extends TestCase
{
    use RefreshDatabase;

    public function test_v038_schema_keeps_the_legacy_unique_index_and_ownerless_rows(): void
    {
        $this->assertTrue(Schema::hasColumn('ratings', 'actor_key'));

        $indexes = collect(Schema::getIndexes('ratings'))->keyBy('name');
        $this->assertTrue($indexes->has('ratings_photo_actor_key_unique'));
        $this->assertSame(
            ['photo_id', 'actor_key'],
            $indexes->get('ratings_photo_actor_key_unique')['columns'],
        );
        $this->assertTrue($indexes->get('ratings_photo_actor_key_unique')['unique']);
        $this->assertTrue($indexes->has('ratings_photo_id_user_id_guest_id_unique'));

        $photo = Photo::factory()->create();
        $legacyIds = [(string) Str::uuid(), (string) Str::uuid()];
        foreach ($legacyIds as $id) {
            DB::table('ratings')->insert([
                'id' => $id,
                'photo_id' => $photo->id,
                'user_id' => null,
                'guest_id' => null,
                'guest_name' => null,
                'actor_key' => null,
                'rating' => 3,
                'comment' => 'legacy',
            ]);
        }

        $this->assertDatabaseCount('ratings', 2);
        foreach ($legacyIds as $id) {
            $this->assertDatabaseHas('ratings', [
                'id' => $id,
                'actor_key' => null,
            ]);
        }
    }

    public function test_v038_normalizes_legacy_actor_rows_deterministically(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();
        $guestId = (string) Str::uuid();
        $guestIds = [
            'ffffffff-ffff-4fff-8fff-ffffffffffff',
            '00000000-0000-4fff-8fff-000000000041',
        ];
        $userIds = [
            'ffffffff-ffff-4fff-8fff-fffffffffffd',
            '00000000-0000-4fff-8fff-000000000043',
        ];
        $legacyId = '00000000-0000-4fff-8fff-000000000042';

        Schema::table('ratings', function ($table): void {
            $table->dropUnique('ratings_photo_id_user_id_guest_id_unique');
            $table->dropUnique('ratings_photo_actor_key_unique');
        });

        DB::table('ratings')->insert([
            [
                'id' => $guestIds[0],
                'photo_id' => $photo->id,
                'user_id' => null,
                'guest_id' => $guestId,
                'rating' => 1,
                'comment' => 'guest duplicate with larger id',
                'actor_key' => null,
            ],
            [
                'id' => $guestIds[1],
                'photo_id' => $photo->id,
                'user_id' => null,
                'guest_id' => $guestId,
                'rating' => 5,
                'comment' => 'guest canonical',
                'actor_key' => null,
            ],
            [
                'id' => $userIds[0],
                'photo_id' => $photo->id,
                'user_id' => $user->id,
                'guest_id' => null,
                'rating' => 2,
                'comment' => 'user duplicate with larger id',
                'actor_key' => null,
            ],
            [
                'id' => $userIds[1],
                'photo_id' => $photo->id,
                'user_id' => $user->id,
                'guest_id' => null,
                'rating' => 4,
                'comment' => 'user canonical',
                'actor_key' => null,
            ],
            [
                'id' => $legacyId,
                'photo_id' => $photo->id,
                'user_id' => null,
                'guest_id' => null,
                'rating' => 3,
                'comment' => 'ownerless legacy row',
                'actor_key' => null,
            ],
        ]);

        $migration = require database_path('migrations/V038__add_rating_actor_key.php');
        $migration->up();

        $this->assertDatabaseCount('ratings', 3);
        $this->assertTrue(Schema::hasIndex('ratings', 'ratings_photo_actor_key_unique', 'unique'));
        $this->assertDatabaseHas('ratings', [
            'id' => $guestIds[1],
            'guest_id' => $guestId,
            'actor_key' => 'guest:'.$guestId,
            'comment' => 'guest canonical',
        ]);
        $this->assertDatabaseHas('ratings', [
            'id' => $userIds[1],
            'user_id' => $user->id,
            'actor_key' => 'user:'.$user->id,
            'comment' => 'user canonical',
        ]);
        $this->assertDatabaseHas('ratings', [
            'id' => $legacyId,
            'user_id' => null,
            'guest_id' => null,
            'actor_key' => null,
            'comment' => 'ownerless legacy row',
        ]);
    }

    public function test_repeated_guest_rating_requests_update_one_database_row(): void
    {
        $gallery = Gallery::factory()->create([
            'type' => 'selection',
            'is_public' => true,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'rating-'.Str::uuid(),
            'name' => 'Rating Guest',
        ]);
        $guestId = (string) Str::uuid();
        $token = $this->guestToken($guestId, $gallery->id, $invite->id);

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/photos/{$photo->id}/rate", [
                'rating' => 4,
                'comment' => 'First request',
            ])
            ->assertOk();

        $this->withHeader('Authorization', 'Bearer '.$token)
            ->postJson("/api/photos/{$photo->id}/rate", [
                'rating' => 2,
                'comment' => 'Second request',
            ])
            ->assertOk();

        $this->assertDatabaseCount('ratings', 1);
        $this->assertDatabaseHas('ratings', [
            'photo_id' => $photo->id,
            'user_id' => null,
            'guest_id' => $guestId,
            'actor_key' => 'guest:'.$guestId,
            'rating' => 2,
            'comment' => 'Second request',
        ]);

        $otherUser = User::factory()->create(['brand' => 'rp']);
        $this->expectException(UniqueConstraintViolationException::class);
        DB::table('ratings')->insert([
            'id' => (string) Str::uuid(),
            'photo_id' => $photo->id,
            'user_id' => $otherUser->id,
            'guest_id' => null,
            'actor_key' => 'guest:'.$guestId,
            'rating' => 5,
            'comment' => 'Conflicting actor bucket',
        ]);
    }

    public function test_registered_users_and_guests_have_isolated_rating_rows(): void
    {
        $gallery = Gallery::factory()->create([
            'type' => 'selection',
            'is_public' => true,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create(['brand' => 'rp']);
        $user->galleries()->attach($gallery);
        $userToken = app(JWTAuth::class)->fromUser($user);
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'rating-'.Str::uuid(),
            'name' => 'Rating Guest',
        ]);
        $guestId = (string) Str::uuid();
        $guestToken = $this->guestToken($guestId, $gallery->id, $invite->id);

        $this->withHeader('Authorization', 'Bearer '.$userToken)
            ->postJson("/api/photos/{$photo->id}/rate", [
                'rating' => 5,
                'comment' => 'User rating',
            ])
            ->assertOk();
        $this->withHeader('Authorization', 'Bearer '.$userToken)
            ->postJson("/api/photos/{$photo->id}/rate", [
                'rating' => 4,
                'comment' => 'Updated user rating',
            ])
            ->assertOk();

        Auth::forgetGuards();
        Auth::guard('api')->setToken($guestToken);
        $this->assertNotNull(Auth::guard('api')->user());
        $this->withHeader('Authorization', 'Bearer '.$guestToken)
            ->postJson("/api/photos/{$photo->id}/rate", [
                'rating' => 1,
                'comment' => 'Guest rating',
            ])
            ->assertOk();

        $this->assertDatabaseCount('ratings', 2);
        $this->assertDatabaseHas('ratings', [
            'photo_id' => $photo->id,
            'user_id' => $user->id,
            'guest_id' => null,
            'actor_key' => 'user:'.$user->id,
            'rating' => 4,
            'comment' => 'Updated user rating',
        ]);
        $this->assertDatabaseHas('ratings', [
            'photo_id' => $photo->id,
            'user_id' => null,
            'guest_id' => $guestId,
            'actor_key' => 'guest:'.$guestId,
            'rating' => 1,
            'comment' => 'Guest rating',
        ]);
    }

    private function guestToken(string $guestId, string $galleryId, string $inviteId): string
    {
        $factory = app(Factory::class);
        $payload = $factory->customClaims([
            'sub' => 'guest_'.$guestId,
            'guest_id' => $guestId,
            'guest_name' => 'Rating Guest',
            'guest_invite_id' => $inviteId,
            'transient_galleries' => [$galleryId],
        ])->make();

        return app(JWTAuth::class)->encode($payload)->get();
    }
}
