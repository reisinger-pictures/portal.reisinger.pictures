<?php

namespace Tests\Feature\Notification;

use App\Models\GalleryGroup;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Tests\TestCase;

/**
 * Contract coverage for V048, the own table of the group notification
 * subscription (owner decision 2026-10-21, D-46 / AD-10).
 *
 * The migration is load-bearing in both directions: the schema has to hold the
 * subscription apart from the client group grant, and the backfill has to move
 * the subscription state of the existing rows without deleting a pivot row — a
 * deleted pivot row could revoke a real grant that was additionally subscribed
 * to.
 */
class GroupNotificationSubscriptionMigrationTest extends TestCase
{
    use RefreshDatabase;

    public function test_v048_creates_the_subscription_table_with_the_documented_columns(): void
    {
        $this->assertTrue(Schema::hasTable('group_notification_subscriptions'));

        $columns = collect(Schema::getColumns('group_notification_subscriptions'))->keyBy('name');

        $this->assertEqualsCanonicalizing(
            ['id', 'user_id', 'gallery_group_id', 'created_at', 'updated_at'],
            $columns->keys()->all(),
        );

        $this->assertFalse((bool) $columns['user_id']['nullable']);
        $this->assertFalse((bool) $columns['gallery_group_id']['nullable']);
    }

    public function test_v048_creates_the_documented_indexes(): void
    {
        $indexes = collect(Schema::getIndexes('group_notification_subscriptions'));

        $unique = $indexes->firstWhere('name', 'group_notification_subscriptions_user_group_unique');
        $this->assertNotNull($unique, 'The unique index over (user_id, gallery_group_id) is missing.');
        $this->assertTrue((bool) $unique['unique']);
        $this->assertSame(['user_id', 'gallery_group_id'], $this->indexColumns($unique));

        $byGroup = $indexes->firstWhere('name', 'group_notification_subscriptions_gallery_group_id_index');
        $this->assertNotNull($byGroup, 'The index over (gallery_group_id) is missing.');
        $this->assertSame(['gallery_group_id'], $this->indexColumns($byGroup));
    }

    public function test_v048_cascades_the_deletion_of_the_user_and_the_group(): void
    {
        $user = User::factory()->create();
        $group = GalleryGroup::factory()->create();

        DB::table('group_notification_subscriptions')->insert([
            'user_id' => $user->id,
            'gallery_group_id' => $group->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $user->delete();
        $this->assertDatabaseMissing('group_notification_subscriptions', [
            'user_id' => $user->id,
            'gallery_group_id' => $group->id,
        ]);

        $group->delete();
        $this->assertDatabaseMissing('group_notification_subscriptions', [
            'user_id' => $user->id,
            'gallery_group_id' => $group->id,
        ]);
    }

    public function test_v048_moves_the_opt_in_state_into_the_subscription_table(): void
    {
        $optedIn = User::factory()->create();
        $notOptedIn = User::factory()->create();
        $group = GalleryGroup::factory()->create();
        $otherGroup = GalleryGroup::factory()->create();

        DB::table('user_gallery_groups')->insert([
            [
                'user_id' => $optedIn->id,
                'gallery_group_id' => $group->id,
                'wants_notifications' => 1,
            ],
            [
                'user_id' => $notOptedIn->id,
                'gallery_group_id' => $group->id,
                'wants_notifications' => 0,
            ],
            [
                'user_id' => $optedIn->id,
                'gallery_group_id' => $otherGroup->id,
                'wants_notifications' => 0,
            ],
        ]);

        // The state before the migration: the table does not exist yet.
        $this->migration()->down();
        $this->assertFalse(Schema::hasTable('group_notification_subscriptions'));

        $this->migration()->up();

        // Every row that carries the opt-in has become a subscription.
        $this->assertDatabaseHas('group_notification_subscriptions', [
            'user_id' => $optedIn->id,
            'gallery_group_id' => $group->id,
        ]);

        // A row without the opt-in is not a subscription.
        $this->assertDatabaseMissing('group_notification_subscriptions', [
            'user_id' => $notOptedIn->id,
            'gallery_group_id' => $group->id,
        ]);
        $this->assertDatabaseMissing('group_notification_subscriptions', [
            'user_id' => $optedIn->id,
            'gallery_group_id' => $otherGroup->id,
        ]);

        $this->assertDatabaseCount('group_notification_subscriptions', 1);
    }

    public function test_v048_does_not_delete_the_legacy_pivot_rows(): void
    {
        $user = User::factory()->create();
        $group = GalleryGroup::factory()->create();

        DB::table('user_gallery_groups')->insert([
            'user_id' => $user->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);

        $this->migration()->down();
        $this->migration()->up();

        // The flagged pivot row survives untouched: it is ambiguous — an
        // opt-in-only row or a real grant that was additionally subscribed to.
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $user->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);
        $this->assertDatabaseCount('user_gallery_groups', 1);
    }

    public function test_v048_does_not_reflag_the_legacy_pivot_rows(): void
    {
        $user = User::factory()->create();
        $group = GalleryGroup::factory()->create();
        $otherGroup = GalleryGroup::factory()->create();

        DB::table('user_gallery_groups')->insert([
            [
                'user_id' => $user->id,
                'gallery_group_id' => $group->id,
                'wants_notifications' => 1,
            ],
            [
                'user_id' => $user->id,
                'gallery_group_id' => $otherGroup->id,
                'wants_notifications' => 0,
            ],
        ]);

        $this->migration()->up();

        // The flag is untouched: a row without the opt-in does not get one.
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $user->id,
            'gallery_group_id' => $otherGroup->id,
            'wants_notifications' => 0,
        ]);
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $user->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);
    }

    public function test_v048_replay_changes_nothing(): void
    {
        $user = User::factory()->create();
        $group = GalleryGroup::factory()->create();

        DB::table('user_gallery_groups')->insert([
            'user_id' => $user->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);

        $this->migration()->up();
        $this->migration()->up();

        $this->assertDatabaseCount('group_notification_subscriptions', 1);
        $this->assertDatabaseCount('user_gallery_groups', 1);
    }

    public function test_v048_rollback_drops_the_table_and_keeps_the_pivot_rows(): void
    {
        $user = User::factory()->create();
        $group = GalleryGroup::factory()->create();

        DB::table('user_gallery_groups')->insert([
            'user_id' => $user->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);

        $this->migration()->up();
        $this->assertDatabaseHas('group_notification_subscriptions', [
            'user_id' => $user->id,
            'gallery_group_id' => $group->id,
        ]);

        $this->migration()->down();

        $this->assertFalse(Schema::hasTable('group_notification_subscriptions'));

        // The legacy flag still carries the subscription, so the rollback
        // restores the state before the migration exactly.
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $user->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);
    }

    private function migration(): object
    {
        return require database_path('migrations/V048__group_notification_subscriptions.php');
    }

    /**
     * @param  array<string, mixed>  $index
     * @return array<int, string>
     */
    private function indexColumns(array $index): array
    {
        $columns = $index['columns'] ?? [];

        if (is_string($columns)) {
            $columns = explode(',', $columns);
        }

        return array_map(
            static fn (mixed $column): string => strtolower(trim((string) $column)),
            is_array($columns) ? $columns : [],
        );
    }
}
