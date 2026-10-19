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
use PHPOpenSourceSaver\JWTAuth\Payload;
use PHPUnit\Framework\AssertionFailedError;
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
        $guestRowId = 'ffffffff-ffff-4fff-8fff-ffffffffffff';
        $userRowId = '00000000-0000-4fff-8fff-000000000043';
        $legacyId = '00000000-0000-4fff-8fff-000000000042';

        DB::table('ratings')->insert([
            [
                'id' => $guestRowId,
                'photo_id' => $photo->id,
                'user_id' => null,
                'guest_id' => $guestId,
                'rating' => 1,
                'comment' => 'guest row',
                'actor_key' => null,
            ],
            [
                'id' => $userRowId,
                'photo_id' => $photo->id,
                'user_id' => $user->id,
                'guest_id' => null,
                'rating' => 2,
                'comment' => 'user row',
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
            'id' => $guestRowId,
            'guest_id' => $guestId,
            'actor_key' => 'guest:'.$guestId,
            'comment' => 'guest row',
        ]);
        $this->assertDatabaseHas('ratings', [
            'id' => $userRowId,
            'user_id' => $user->id,
            'actor_key' => 'user:'.$user->id,
            'comment' => 'user row',
        ]);
        $this->assertDatabaseHas('ratings', [
            'id' => $legacyId,
            'user_id' => null,
            'guest_id' => null,
            'actor_key' => null,
            'comment' => 'ownerless legacy row',
        ]);
    }

    /**
     * INFRA-8: duplicates for one identifiable actor are no longer deleted.
     * The migration must abort and leave every row untouched.
     */
    public function test_v038_aborts_on_duplicate_actor_ratings_without_deleting_them(): void
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

        try {
            $migration->up();
            $this->fail('V038 must abort when duplicate actor ratings exist');
        } catch (\RuntimeException $exception) {
            $this->assertStringContainsString('V038 rating actor-key preflight failed', $exception->getMessage());
            $this->assertStringContainsString('duplicate_groups=2', $exception->getMessage());
        }

        // Nothing was deleted, merged, or normalized.
        $this->assertDatabaseCount('ratings', 5);
        foreach ([...$guestIds, ...$userIds, $legacyId] as $id) {
            $this->assertDatabaseHas('ratings', [
                'id' => $id,
                'actor_key' => null,
            ]);
        }
        $this->assertFalse(Schema::hasIndex('ratings', 'ratings_photo_actor_key_unique', 'unique'));
    }

    /**
     * FINAL-3: the preflight used to accumulate every rating id for the whole
     * table, so memory grew with table size even when nothing was duplicated.
     * It now keeps a running count plus a bounded sample. This pins that the
     * reported count stays exact while the id list stays capped, so an
     * operator can still size the reconciliation work from the abort message.
     */
    public function test_v038_duplicate_report_counts_every_row_but_samples_a_bounded_id_list(): void
    {
        $gallery = Gallery::factory()->create();
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create();

        // The report caps ids per group at 20; use clearly more than that.
        $duplicateCount = 25;

        Schema::table('ratings', function ($table): void {
            $table->dropUnique('ratings_photo_id_user_id_guest_id_unique');
            $table->dropUnique('ratings_photo_actor_key_unique');
        });

        $rows = [];
        for ($i = 0; $i < $duplicateCount; $i++) {
            $rows[] = [
                'id' => sprintf('10000000-0000-4fff-8fff-%012d', $i),
                'photo_id' => $photo->id,
                'user_id' => $user->id,
                'guest_id' => null,
                'rating' => 3,
                'comment' => 'duplicate '.$i,
                'actor_key' => null,
            ];
        }
        DB::table('ratings')->insert($rows);

        $migration = require database_path('migrations/V038__add_rating_actor_key.php');

        try {
            $migration->up();
            $this->fail('V038 must abort when duplicate actor ratings exist');
        } catch (\RuntimeException $exception) {
            $message = $exception->getMessage();

            // The exact row count is reported even though only a sample of ids
            // is retained, so the operator can size the work from the message.
            $this->assertStringContainsString('duplicate_groups=1', $message);
            $this->assertStringContainsString('duplicate_rows='.$duplicateCount, $message);
            $this->assertStringContainsString('count='.$duplicateCount, $message);
            $this->assertStringContainsString('at most 20 ids per group', $message);

            preg_match('/^ratings identity=.* count=\d+ ids=(.*)$/m', $message, $matches);
            $this->assertNotEmpty($matches, 'The report must list the duplicate group with its ids.');

            $listedIds = array_filter(explode(',', trim($matches[1])), static fn (string $id): bool => $id !== '');
            $this->assertCount(20, $listedIds, 'The id sample must stay capped at the documented limit.');
            $this->assertCount(
                count($listedIds),
                array_unique($listedIds),
                'The sampled ids must be distinct, so the operator can act on each one.',
            );
            $this->assertLessThan(
                $duplicateCount,
                count($listedIds),
                'The sample must be strictly smaller than the group, otherwise this proves nothing.',
            );
        }

        $this->assertDatabaseCount('ratings', $duplicateCount);
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

    /**
     * A registered user and a transient guest must end up with two separate
     * rating rows.
     *
     * The guest token is issued FIRST on purpose — it is the claim set that
     * could leak into the registered user's request.
     *
     * The jwt-auth `Factory` is a container singleton whose claims collection
     * accumulates, and `Manager::decode()` merges the whole collection into
     * the decoded payload. A guest token that was issued before a request
     * therefore puts its `guest_id` and its `transient_galleries` grant into
     * the payload of that request. This test would then pass for the wrong
     * reason: a selection gallery is never handed out through a persistent
     * client grant (D-39), so the registered user's rating would only be
     * authorised by the *guest's* leaked transient grant.
     *
     * Both requests therefore run with an explicit claim set of their own:
     * the registered client gets the invite grant it redeemed, the guest gets
     * its own guest claim set, and no request inherits a foreign claim.
     */
    public function test_registered_users_and_guests_have_isolated_rating_rows(): void
    {
        $gallery = Gallery::factory()->create([
            'type' => 'selection',
            'is_public' => true,
        ]);
        $photo = Photo::factory()->create(['gallery_id' => $gallery->id]);
        $user = User::factory()->create(['brand' => 'rp']);
        $user->galleries()->attach($gallery);
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'rating-'.Str::uuid(),
            'name' => 'Rating Guest',
        ]);
        $guestId = (string) Str::uuid();

        // Issued first: the guest claims are the ones that could leak.
        $guestToken = $this->guestToken($guestId, $gallery->id, $invite->id);

        // The registered client holds the invite grant explicitly. This is
        // the claim set the invite redemption issues for a registered client,
        // and the only legitimate way into a selection gallery.
        $userToken = $this->userToken($user, [
            'transient_galleries' => [$gallery->id],
            'transient_invites' => [
                (string) $invite->id => [
                    'gallery_id' => (string) $gallery->id,
                    'can_edit_metadata' => false,
                ],
            ],
            'transient_invite_ids' => [(string) $invite->id],
        ]);

        $this->withCleanClaims($userToken, function () use ($userToken, $photo): void {
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
        });

        // The registered user's request must have been authenticated as the
        // registered user and with its own claim set — a guest claim that
        // leaked into it would authorise the request and hide the leak.
        $this->assertGuestClaimsAbsent(
            Auth::guard('api')->payload(),
            'The registered user request'
        );
        $this->assertSame(
            (string) $user->id,
            (string) Auth::guard('api')->user()->getKey(),
            'The registered user request must be authenticated as the registered user.'
        );

        $this->withCleanClaims($guestToken, function () use ($guestToken, $photo): void {
            $this->withHeader('Authorization', 'Bearer '.$guestToken)
                ->postJson("/api/photos/{$photo->id}/rate", [
                    'rating' => 1,
                    'comment' => 'Guest rating',
                ])
                ->assertOk();
        });

        $this->assertSame(
            $guestId,
            Auth::guard('api')->user()->guest_id,
            'The guest request must be authenticated as the guest.'
        );
        $this->assertNull(
            Auth::guard('api')->user()->getKey(),
            'The guest request must not be authenticated as a registered user.'
        );

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

    /**
     * The isolation check of the test above must have teeth.
     *
     * This reproduces the leak on purpose: a guest token is issued first and
     * the registered user token is built without a claim reset, so the guest
     * claims end up in the registered user's token. The check must reject
     * that token. Without this companion the green isolation test would only
     * prove that the leak happens to be harmless today, and a silent
     * regression of the claim reset would go unnoticed.
     */
    public function test_a_leaked_guest_claim_breaks_the_registered_user_token(): void
    {
        $gallery = Gallery::factory()->create([
            'type' => 'selection',
            'is_public' => true,
        ]);
        $invite = GalleryInvite::create([
            'gallery_id' => $gallery->id,
            'token' => 'rating-'.Str::uuid(),
            'name' => 'Rating Guest',
        ]);
        $guestId = (string) Str::uuid();
        $user = User::factory()->create(['brand' => 'rp']);

        // The guest token leaves its claims in the shared claims collection.
        $this->guestToken($guestId, $gallery->id, $invite->id);

        // The registered user token is built on purpose WITHOUT the claim
        // reset, so the collection leaks the guest claims into the token.
        $leakedPayload = app(Factory::class)->customClaims([
            'sub' => (string) $user->getKey(),
            'transient_galleries' => [$gallery->id],
        ])->make();
        $leakedToken = app(JWTAuth::class)->encode($leakedPayload)->get();

        $this->assertTrue(
            $leakedPayload->hasKey('guest_id'),
            'The leak must be reproducible: the guest claim has to reach the registered user token.'
        );
        $this->assertSame($guestId, $leakedPayload->get('guest_id'));

        // The very assertion the isolation test makes has to reject this token.
        $this->expectException(AssertionFailedError::class);
        $this->assertGuestClaimsAbsent($leakedPayload, 'The registered user request');
    }

    /**
     * A guest token that starts from a clean claim collection.
     *
     * `make(true)` empties the collection first: the guest token must not
     * inherit anything that was issued before it.
     */
    private function guestToken(string $guestId, string $galleryId, string $inviteId): string
    {
        $payload = app(Factory::class)->customClaims([
            'sub' => 'guest_'.$guestId,
            'guest_id' => $guestId,
            'guest_name' => 'Rating Guest',
            'guest_invite_id' => $inviteId,
            'transient_galleries' => [$galleryId],
        ])->make(true);

        return app(JWTAuth::class)->encode($payload)->get();
    }

    /**
     * A registered user token with an explicit claim set.
     *
     * `make(true)` empties the claims collection first, so the token carries
     * exactly the claims of this request and nothing that was issued before.
     *
     * @param  array<string, mixed>  $claims
     */
    private function userToken(User $user, array $claims = []): string
    {
        $payload = app(Factory::class)->customClaims(array_merge([
            'sub' => (string) $user->getKey(),
        ], $claims))->make(true);

        return app(JWTAuth::class)->encode($payload)->get();
    }

    /**
     * Run a request with a clean jwt-auth claim state.
     *
     * `Manager::decode()` merges the whole claims collection of the shared
     * `Factory` singleton into the decoded payload, and the guard keeps the
     * last token of the process. Both have to be reset, otherwise the request
     * runs with the claims of a foreign token.
     */
    private function withCleanClaims(string $token, callable $callback): void
    {
        $this->resetJwtClaimState();

        Auth::forgetGuards();
        Auth::guard('api')->setToken($token);

        $callback();
    }

    /**
     * Assert that a payload carries no guest identity.
     *
     * @param  Payload  $payload
     */
    private function assertGuestClaimsAbsent($payload, string $context): void
    {
        $guestClaims = [
            'guest_id',
            'guest_name',
            'guest_invite_id',
        ];

        $leaked = array_values(array_filter(
            $guestClaims,
            fn (string $claim): bool => $payload->hasKey($claim)
        ));

        $this->assertSame(
            [],
            $leaked,
            $context.' must not be authenticated with a guest identity, but carries: '.implode(', ', $leaked).'.'
        );
    }
}
