<?php

namespace Tests\Feature\Notification;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Database\Events\QueryExecuted;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Mockery;
use PHPUnit\Framework\Attributes\Group;
use Tests\TestCase;

/**
 * The group notification opt-in is a subscription, never a grant.
 *
 * Before the decision of 2026-10-21 the opt-in was written into
 * `user_gallery_groups`, the pivot that carries the client group grant
 * (`AuthorizationService::getAllowedGalleryIds()` reads it as "2. group
 * assignments (recursive)"). The opt-in therefore granted access, and a group
 * grant covers the whole subtree of the group: the subscription kept the
 * galleries of the group and of every group below it reachable after the
 * membership that justified it was gone.
 *
 * Owner decision: D-46 in features/owner-decisions.md, AD-10 in
 * features/tech/07-architectural-decisions.md, section 2.2 in
 * features/infrastructure/02-email-system.md.
 */
class GroupNotificationSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_opt_in_does_not_write_into_the_grant_pivot(): void
    {
        [$client, $group] = $this->assignedClientWithGroup();

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => true]);
        $response->assertStatus(200);

        // The subscription must not be written into the grant pivot: the pivot
        // carries the client group grant, and a group grant covers the whole
        // subtree of the group.
        $this->assertDatabaseMissing('user_gallery_groups', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);

        // The assignment itself is untouched — the opt-in neither creates nor
        // removes it.
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
        ]);
    }

    public function test_a_subscription_does_not_keep_the_group_reachable_after_the_membership_is_gone(): void
    {
        [$client, $group, $gallery] = $this->assignedClientWithGroup();

        $token = auth('api')->login($client);
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        // The membership is revoked; the group is not the user's any more.
        $client->galleryGroups()->detach($group->id);

        $allowed = app(AuthorizationService::class)->getAllowedGalleryIds($client->fresh());

        $this->assertNotContains(
            $gallery->id,
            $allowed,
            'A notification subscription must never keep a gallery reachable.',
        );
    }

    public function test_a_subscription_survives_the_membership_that_justified_it(): void
    {
        // The subscription is not part of the grant: revoking the membership
        // must not silently destroy the user's own state. The entry stays
        // visible so that it can be revoked.
        [$client, $group] = $this->assignedClientWithGroup();

        $token = auth('api')->login($client);
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        $client->galleryGroups()->detach($group->id);

        $this->assertDatabaseHas('group_notification_subscriptions', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
        ]);
    }

    #[Group('smoke')]
    public function test_the_opt_in_is_stored_in_the_subscription_table_and_can_be_revoked(): void
    {
        [$client, $group] = $this->assignedClientWithGroup();

        $token = auth('api')->login($client);
        $headers = ['Authorization' => "Bearer $token"];

        $this->withHeaders($headers)
            ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        $this->assertDatabaseHas('group_notification_subscriptions', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
        ]);

        $this->withHeaders($headers)
            ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => false])
            ->assertStatus(200);

        $this->assertDatabaseMissing('group_notification_subscriptions', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
        ]);

        // The grant pivot stays untouched: the opt-in neither wrote nor
        // changed it.
        $this->assertDatabaseHas('user_gallery_groups', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
        ]);
    }

    public function test_the_preferences_endpoint_reports_the_subscription_state(): void
    {
        [$client, $group] = $this->assignedClientWithGroup();

        $token = auth('api')->login($client);
        $headers = ['Authorization' => "Bearer $token"];

        // Before the opt-in the group is not subscribed to.
        $preferences = $this->withHeaders($headers)
            ->getJson('/api/notifications/preferences')
            ->assertStatus(200)
            ->json('groups');

        $this->assertSame(
            false,
            $this->preferenceFor($preferences, $group->id)['wants_notifications'] ?? null,
        );

        $this->withHeaders($headers)
            ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        $preferences = $this->withHeaders($headers)
            ->getJson('/api/notifications/preferences')
            ->assertStatus(200)
            ->json('groups');

        $this->assertSame(
            true,
            $this->preferenceFor($preferences, $group->id)['wants_notifications'] ?? null,
        );
        // The response shape is unchanged: the frontend reads
        // `groups[].wants_notifications`.
        $this->assertSame(
            ['id', 'name', 'type', 'wants_notifications'],
            array_keys($this->preferenceFor($preferences, $group->id)),
        );
    }

    public function test_the_preferences_endpoint_lists_a_subscription_without_a_grant(): void
    {
        // A subscription can outlive the membership that justified it. The
        // subscription is the user's own state and must stay visible so that it
        // can be revoked.
        [$client, $group] = $this->assignedClientWithGroup();

        $token = auth('api')->login($client);
        $headers = ['Authorization' => "Bearer $token"];

        $this->withHeaders($headers)
            ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        $client->galleryGroups()->detach($group->id);

        $preferences = $this->withHeaders($headers)
            ->getJson('/api/notifications/preferences')
            ->assertStatus(200)
            ->json('groups');

        $this->assertTrue(
            $this->preferenceFor($preferences, $group->id)['wants_notifications'] ?? false,
            'A subscription without a grant must stay visible in the preferences.',
        );
    }

    public function test_a_subscription_can_be_revoked_after_the_membership_is_gone(): void
    {
        [$client, $group] = $this->assignedClientWithGroup();

        $token = auth('api')->login($client);
        $headers = ['Authorization' => "Bearer $token"];

        $this->withHeaders($headers)
            ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        $client->galleryGroups()->detach($group->id);

        // Revoking the own subscription is allowed: it touches the caller's own
        // row and grants nothing.
        $this->withHeaders($headers)
            ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => false])
            ->assertStatus(200);

        $this->assertDatabaseMissing('group_notification_subscriptions', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
        ]);
    }

    public function test_the_preferences_endpoint_does_not_disclose_the_name_of_an_unreachable_group(): void
    {
        [$client, $group] = $this->assignedClientWithGroup();

        $token = auth('api')->login($client);
        $headers = ['Authorization' => "Bearer $token"];

        $this->withHeaders($headers)
            ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        // The membership is revoked: the group is out of reach for the caller.
        $client->galleryGroups()->detach($group->id);

        $preferences = $this->withHeaders($headers)
            ->getJson('/api/notifications/preferences')
            ->assertStatus(200)
            ->json('groups');

        $entry = $this->preferenceFor($preferences, $group->id);

        // The entry stays visible — the id is needed to revoke the subscription.
        $this->assertNotNull($entry);
        $this->assertTrue($entry['wants_notifications']);
        // The name of a group the caller cannot reach is not disclosed.
        $this->assertNotSame($group->name, $entry['name']);
        $this->assertSame('Nicht zugänglicher Ordner', $entry['name']);
    }

    public function test_the_preferences_endpoint_discloses_the_name_of_a_reachable_group(): void
    {
        [$client, $group] = $this->assignedClientWithGroup();

        $token = auth('api')->login($client);

        $preferences = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/notifications/preferences')
            ->assertStatus(200)
            ->json('groups');

        $this->assertSame($group->name, $this->preferenceFor($preferences, $group->id)['name'] ?? null);
    }

    public function test_the_preferences_endpoint_discloses_the_name_of_an_assigned_group_whose_content_is_unreachable(): void
    {
        // The durable rule (features/infrastructure/02-email-system.md §2.2):
        // a group is reachable when the caller is assigned to it — or when a
        // gallery of its subtree is reachable. The assignment is the first
        // disjunct and it has to work on its own: a group whose subtree hands
        // out nothing is still a group the caller is a member of, and its name
        // is the caller's own information.
        //
        // The fixture is a D-39-voided subtree: the group holds a selection
        // gallery, a grant on the group would hand out that gallery, so the
        // grant is fail-closed and the subtree is unreachable.
        [$client, $group, $gallery] = $this->clientAssignedToVoidedGroup();

        $allowed = app(AuthorizationService::class)->getAllowedGalleryIds($client->fresh());

        $this->assertNotContains(
            $gallery->id,
            $allowed,
            'The fixture must be a D-39-voided subtree: the grant hands out nothing.',
        );

        $token = auth('api')->login($client);

        $preferences = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/notifications/preferences')
            ->assertStatus(200)
            ->json('groups');

        $this->assertSame(
            $group->name,
            $this->preferenceFor($preferences, $group->id)['name'] ?? null,
            'The caller is assigned to the group and may name it, even when the subtree is unreachable.',
        );
    }

    public function test_the_preferences_endpoint_redacts_a_group_that_is_neither_assigned_nor_reachable(): void
    {
        // The direction must stay safe: a group the caller is neither assigned
        // to nor able to reach keeps its placeholder name. The subscription is
        // the caller's own state and keeps the entry revocable, but it is
        // neither an assignment nor an access.
        [$client, $assignedGroup] = $this->clientAssignedToVoidedGroup();

        // A second group: the caller was a member, subscribed to it, and the
        // membership is gone.
        $revokedGroup = GalleryGroup::factory()->create();
        Gallery::factory()->create(['type' => 'delivery', 'gallery_group_id' => $revokedGroup->id]);
        $client->galleryGroups()->attach($revokedGroup->id);

        $token = auth('api')->login($client);
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/gallery-groups/{$revokedGroup->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        $client->galleryGroups()->detach($revokedGroup->id);

        $preferences = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/notifications/preferences')
            ->assertStatus(200)
            ->json('groups');

        $entry = $this->preferenceFor($preferences, $revokedGroup->id);

        $this->assertNotNull($entry);
        $this->assertTrue($entry['wants_notifications']);
        $this->assertSame('Nicht zugänglicher Ordner', $entry['name']);
        $this->assertNotSame($revokedGroup->name, $entry['name']);

        // The assigned group next to it keeps its name: the redaction is not a
        // blanket rule over the whole list.
        $this->assertSame(
            $assignedGroup->name,
            $this->preferenceFor($preferences, $assignedGroup->id)['name'] ?? null,
        );
    }

    public function test_the_assigned_group_ids_reach_the_reachability_computation(): void
    {
        // The reachability computation has to receive the group's own id. The
        // relation of the assigned groups is a collection, and `keys()` on it
        // yields the positional indices of the collection (0, 1, 2 …) instead
        // of the ids — the assignment would never reach the computation and
        // the first disjunct of the rule would never fire.
        [$client, $group] = $this->clientAssignedToVoidedGroup();

        $seen = null;

        $service = Mockery::mock(AuthorizationService::class)->makePartial();
        $service->shouldReceive('getAllowedGalleryIds')->andReturn([]);
        $service->shouldReceive('getSubGroupIds')
            ->once()
            ->andReturnUsing(function (array $groupIds) use (&$seen, $group): array {
                $seen = $groupIds;

                return [(string) $group->id];
            });

        $this->instance(AuthorizationService::class, $service);

        $token = auth('api')->login($client);

        $preferences = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->getJson('/api/notifications/preferences')
            ->assertStatus(200)
            ->json('groups');

        $this->assertSame(
            [(string) $group->id],
            $seen,
            'The reachability computation must receive the assigned group ids.',
        );

        // The id that reached the computation is what makes the name
        // disclosable.
        $this->assertSame(
            $group->name,
            $this->preferenceFor($preferences, $group->id)['name'] ?? null,
        );
    }

    public function test_the_opt_in_never_writes_into_the_grant_pivot(): void
    {
        // The legacy write path was a check-then-write against the grant pivot:
        // the IDOR guard verified the membership, and `updateOrInsert()` created
        // the pivot row when it was missing in the meantime. A revocation that
        // landed between the two turned the opt-in into a group grant over the
        // whole subtree of the group. The subscription lives in its own table,
        // so the assignment pivot is not written at all — the write path cannot
        // create an assignment, not even in the race.
        [$client, $group] = $this->assignedClientWithGroup();

        $writes = [];
        DB::listen(function (QueryExecuted $query) use (&$writes): void {
            $sql = strtolower(ltrim($query->sql));

            if (! str_starts_with($sql, 'insert')
                && ! str_starts_with($sql, 'update')
                && ! str_starts_with($sql, 'delete')) {
                return;
            }

            if (! str_contains($query->sql, 'user_gallery_groups')) {
                return;
            }

            $writes[] = $query->sql;
        });

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => true]);

        $response->assertStatus(200);

        $this->assertSame(
            [],
            $writes,
            'The opt-in must not write into the grant pivot — it would turn the subscription into an assignment.',
        );
    }

    public function test_a_concurrent_membership_revocation_does_not_turn_the_opt_in_into_an_assignment(): void
    {
        // The membership is revoked while the request is in flight. The opt-in
        // must neither recreate the assignment row nor keep the group
        // reachable — and the subscription itself must not be lost.
        [$client, $group, $gallery] = $this->assignedClientWithGroup();

        $simulated = false;
        DB::beforeExecuting(function (string $query) use (&$simulated, $client, $group): void {
            if ($simulated) {
                return;
            }

            $sql = strtolower(ltrim($query));
            $isWrite = str_starts_with($sql, 'insert')
                || str_starts_with($sql, 'update')
                || str_starts_with($sql, 'delete');

            if (! $isWrite) {
                return;
            }

            if (! str_contains($query, 'user_gallery_groups')
                && ! str_contains($query, 'group_notification_subscriptions')) {
                return;
            }

            // The concurrent revocation that won the race.
            $simulated = true;
            DB::table('user_gallery_groups')
                ->where('user_id', $client->id)
                ->where('gallery_group_id', $group->id)
                ->delete();
        });

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => true]);

        $response->assertStatus(200);
        $this->assertTrue($simulated, 'The concurrent revocation must have been simulated.');

        // The opt-in must not create an assignment row.
        $this->assertDatabaseMissing('user_gallery_groups', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
        ]);

        // The subscription itself is not lost by the revocation.
        $this->assertDatabaseHas('group_notification_subscriptions', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
        ]);

        $allowed = app(AuthorizationService::class)->getAllowedGalleryIds($client->fresh());

        $this->assertNotContains(
            $gallery->id,
            $allowed,
            'A notification subscription must never create access.',
        );
    }

    public function test_a_concurrent_double_toggle_does_not_produce_a_server_error(): void
    {
        // The write path is a single atomic upsert. A check-then-insert would
        // raise a unique constraint violation (HTTP 500) when a concurrent
        // request of the same user commits between the read and the write.
        [$client, $group] = $this->assignedClientWithGroup();

        $simulated = false;
        DB::beforeExecuting(function (string $query) use (&$simulated, $client, $group): void {
            if ($simulated || ! str_contains($query, 'group_notification_subscriptions')) {
                return;
            }

            if (! str_starts_with(strtolower(ltrim($query)), 'insert')) {
                return;
            }

            // The concurrent writer that won the race.
            $simulated = true;
            DB::table('group_notification_subscriptions')->insert([
                'user_id' => $client->id,
                'gallery_group_id' => $group->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => true]);

        $response->assertStatus(200);
        $this->assertTrue($simulated, 'The concurrent writer must have been simulated.');
        $this->assertDatabaseCount('group_notification_subscriptions', 1);
    }

    public function test_a_guest_cannot_subscribe(): void
    {
        // The subscription table has a NOT NULL user_id; a guest has no
        // persistent user row.
        $group = GalleryGroup::factory()->create();

        $this->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(401);

        $this->assertDatabaseCount('group_notification_subscriptions', 0);
    }

    public function test_a_user_cannot_subscribe_to_a_group_they_do_not_belong_to(): void
    {
        // The IDOR guard of the write path is unchanged: a subscription row is
        // only created for a group the caller is assigned to.
        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $group = GalleryGroup::factory()->create();

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => true]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('group_notification_subscriptions', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
        ]);
        $this->assertDatabaseMissing('user_gallery_groups', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
        ]);
    }

    public function test_the_custom_mail_reaches_the_group_subscriber(): void
    {
        Mail::fake();

        [$client, $group, $gallery] = $this->assignedClientWithGroup();

        $token = auth('api')->login($client);
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        $response = $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($admin)])
            ->postJson("/api/management/galleries/{$gallery->id}/send-custom-email", [
                'subject' => 'OptIn Update',
                'body' => 'Hello',
            ]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('notified_count'));
        Mail::assertQueuedCount(1);
    }

    public function test_the_custom_mail_skips_a_client_without_a_group_subscription(): void
    {
        Mail::fake();

        [$client, $group, $gallery] = $this->assignedClientWithGroup();

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        $response = $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($admin)])
            ->postJson("/api/management/galleries/{$gallery->id}/send-custom-email", [
                'subject' => 'OptIn Update',
                'body' => 'Hello',
            ]);

        $response->assertStatus(404);
        Mail::assertNothingQueued();
    }

    public function test_the_grant_relation_does_not_load_the_legacy_opt_in_column(): void
    {
        // `user_gallery_groups.wants_notifications` is a legacy column (D-46).
        // The application neither reads nor writes it for groups, so the grant
        // relation does not select it.
        $client = User::factory()->create();
        $group = GalleryGroup::factory()->create();
        DB::table('user_gallery_groups')->insert([
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
            'wants_notifications' => 1,
        ]);

        $pivot = $client->galleryGroups()->first()->pivot;

        $this->assertArrayNotHasKey('wants_notifications', $pivot->getAttributes());
    }

    /**
     * A client who is assigned to a gallery group that holds a delivery
     * gallery.
     *
     * @return array{0: User, 1: GalleryGroup, 2: Gallery}
     */
    private function assignedClientWithGroup(): array
    {
        $group = GalleryGroup::factory()->create();
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'gallery_group_id' => $group->id]);

        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $client->galleryGroups()->attach($group->id);

        return [$client, $group, $gallery];
    }

    /**
     * A client who is assigned to a gallery group whose subtree is voided.
     *
     * The group holds a selection gallery. A grant on the group would hand
     * out that gallery, so the grant is fail-closed (D-39) and the subtree of
     * the group is unreachable — the assignment is nevertheless there, and it
     * is the caller's own.
     *
     * @return array{0: User, 1: GalleryGroup, 2: Gallery}
     */
    private function clientAssignedToVoidedGroup(): array
    {
        $group = GalleryGroup::factory()->create();
        $gallery = Gallery::factory()->create(['type' => 'selection', 'gallery_group_id' => $group->id]);

        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $client->galleryGroups()->attach($group->id);

        return [$client, $group, $gallery];
    }

    /**
     * @param  array<int, array<string, mixed>>  $preferences
     * @return array<string, mixed>|null
     */
    private function preferenceFor(array $preferences, string $groupId): ?array
    {
        foreach ($preferences as $preference) {
            if (($preference['id'] ?? null) === $groupId) {
                return $preference;
            }
        }

        return null;
    }
}
