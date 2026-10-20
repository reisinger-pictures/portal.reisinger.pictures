<?php

namespace Tests\Feature\Notification;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use App\Services\AuthorizationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * The gallery notification opt-in is a subscription, never a grant.
 *
 * Before the decision of 2026-10-20 the opt-in was written into
 * `user_galleries`, the pivot that carries the client grant
 * (`AuthorizationService::getAllowedGalleryIds()` reads it as "1. direct
 * assignments"). The opt-in therefore granted access: a delivery gallery
 * stayed reachable after the org membership that justified the subscription
 * was gone.
 *
 * Owner decision: D-45 in features/owner-decisions.md, AD-9 in
 * features/tech/07-architectural-decisions.md, section 2.1 in
 * features/infrastructure/02-email-system.md.
 */
class GalleryNotificationSubscriptionTest extends TestCase
{
    use RefreshDatabase;

    public function test_the_opt_in_does_not_write_into_the_grant_pivot(): void
    {
        [$client, $gallery] = $this->orgMemberWithDeliveryGallery();

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true]);
        $response->assertStatus(200);

        // The subscription must not create a grant row.
        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
        ]);
    }

    public function test_a_subscription_does_not_outlive_the_org_membership_that_justified_it(): void
    {
        [$client, $gallery] = $this->orgMemberWithDeliveryGallery();

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true]);
        $response->assertStatus(200);

        // The membership is revoked; the gallery is no longer the org's.
        $client->forceFill(['org_id' => null])->save();

        $allowed = app(AuthorizationService::class)->getAllowedGalleryIds($client->fresh());

        $this->assertNotContains(
            $gallery->id,
            $allowed,
            'A notification subscription must never keep a gallery reachable.',
        );
    }

    public function test_the_opt_in_is_stored_in_the_subscription_table_and_can_be_revoked(): void
    {
        [$client, $gallery] = $this->orgMemberWithDeliveryGallery();

        $token = auth('api')->login($client);
        $headers = ['Authorization' => "Bearer $token"];

        $this->withHeaders($headers)
            ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        $this->assertDatabaseHas('gallery_notification_subscriptions', [
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
        ]);

        $this->withHeaders($headers)
            ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => false])
            ->assertStatus(200);

        $this->assertDatabaseMissing('gallery_notification_subscriptions', [
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
        ]);
    }

    public function test_the_preferences_endpoint_reports_the_subscription_state(): void
    {
        [$client, $gallery] = $this->orgMemberWithDeliveryGallery();

        $token = auth('api')->login($client);
        $headers = ['Authorization' => "Bearer $token"];

        // Before the opt-in the gallery is not part of the list: the client
        // holds no direct grant for it.
        $preferences = $this->withHeaders($headers)
            ->getJson('/api/notifications/preferences')
            ->assertStatus(200)
            ->json('galleries');

        $this->assertNull($this->preferenceFor($preferences, $gallery->id));

        $this->withHeaders($headers)
            ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        $preferences = $this->withHeaders($headers)
            ->getJson('/api/notifications/preferences')
            ->assertStatus(200)
            ->json('galleries');

        $this->assertSame(
            true,
            $this->preferenceFor($preferences, $gallery->id)['wants_notifications'] ?? null,
        );
        // The response shape is unchanged: the frontend reads
        // `galleries[].wants_notifications`.
        $this->assertSame(
            ['id', 'name', 'type', 'gallery_type', 'wants_notifications'],
            array_keys($this->preferenceFor($preferences, $gallery->id)),
        );
    }

    public function test_the_preferences_endpoint_lists_a_subscription_without_a_grant(): void
    {
        // A subscription can outlive the access that justified it. The
        // subscription is the user's own state and must stay visible so that it
        // can be revoked.
        [$client, $gallery] = $this->orgMemberWithDeliveryGallery();

        $token = auth('api')->login($client);
        $headers = ['Authorization' => "Bearer $token"];

        $this->withHeaders($headers)
            ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        $client->forceFill(['org_id' => null])->save();

        $preferences = $this->withHeaders($headers)
            ->getJson('/api/notifications/preferences')
            ->assertStatus(200)
            ->json('galleries');

        $this->assertTrue(
            $this->preferenceFor($preferences, $gallery->id)['wants_notifications'] ?? false,
            'A subscription without a grant must stay visible in the preferences.',
        );
    }

    public function test_a_subscription_can_be_revoked_after_the_access_is_gone(): void
    {
        [$client, $gallery] = $this->orgMemberWithDeliveryGallery();

        $token = auth('api')->login($client);
        $headers = ['Authorization' => "Bearer $token"];

        $this->withHeaders($headers)
            ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        $client->forceFill(['org_id' => null])->save();

        // Revoking the own subscription is allowed: it touches the caller's own
        // row and grants nothing.
        $this->withHeaders($headers)
            ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => false])
            ->assertStatus(200);

        $this->assertDatabaseMissing('gallery_notification_subscriptions', [
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
        ]);
    }

    public function test_the_gallery_endpoint_reports_the_subscription_state(): void
    {
        // The frontend reads `wants_notifications` from the paginated gallery
        // response (frontend/src/logic/useGallery.ts).
        [$client, $gallery] = $this->orgMemberWithDeliveryGallery();

        $token = auth('api')->login($client);
        $headers = ['Authorization' => "Bearer $token"];

        $this->withHeaders($headers)
            ->getJson("/api/galleries/{$gallery->slug}")
            ->assertStatus(200)
            ->assertJsonPath('wants_notifications', false);

        $this->withHeaders($headers)
            ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        $this->withHeaders($headers)
            ->getJson("/api/galleries/{$gallery->slug}")
            ->assertStatus(200)
            ->assertJsonPath('wants_notifications', true)
            ->assertJsonPath('notified_count', 1);
    }

    public function test_the_custom_mail_reaches_the_subscriber(): void
    {
        Mail::fake();

        [$client, $gallery] = $this->orgMemberWithDeliveryGallery();

        $token = auth('api')->login($client);
        $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true])
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

    public function test_the_custom_mail_skips_a_client_without_a_subscription(): void
    {
        Mail::fake();

        [$client, $gallery] = $this->orgMemberWithDeliveryGallery();

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

    public function test_a_guest_cannot_subscribe(): void
    {
        // The subscription table has a NOT NULL user_id; a guest has no
        // persistent user row.
        $gallery = Gallery::factory()->create(['type' => 'delivery']);

        $this->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(401);

        $this->assertDatabaseCount('gallery_notification_subscriptions', 0);
    }

    public function test_the_finish_rating_notification_reaches_a_photographer_with_a_subscription(): void
    {
        Mail::fake();

        $gallery = Gallery::factory()->create(['type' => 'delivery']);

        $photographer = User::factory()->create();
        $photographer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
        // The photographer is explicitly assigned to the gallery (the grant)
        // and subscribes to it (the subscription).
        $photographer->galleries()->attach($gallery);

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($photographer)])
            ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $client->galleries()->attach($gallery);

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($client)])
            ->postJson("/api/galleries/{$gallery->id}/finish-rating")
            ->assertStatus(200);

        Mail::assertQueuedCount(1);
    }

    public function test_the_finish_rating_notification_skips_a_photographer_without_a_subscription(): void
    {
        Mail::fake();

        $gallery = Gallery::factory()->create(['type' => 'delivery']);

        $photographer = User::factory()->create();
        $photographer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
        $photographer->galleries()->attach($gallery);

        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $client->galleries()->attach($gallery);

        $this->withHeaders(['Authorization' => 'Bearer '.auth('api')->login($client)])
            ->postJson("/api/galleries/{$gallery->id}/finish-rating")
            ->assertStatus(200);

        Mail::assertNothingSent();
    }

    public function test_a_concurrent_double_toggle_does_not_produce_a_server_error(): void
    {
        // The write path is a single atomic upsert. A check-then-insert would
        // raise a unique constraint violation (HTTP 500) when a concurrent
        // request of the same user commits between the read and the write.
        [$client, $gallery] = $this->orgMemberWithDeliveryGallery();

        $simulated = false;
        DB::beforeExecuting(function (string $query) use (&$simulated, $client, $gallery): void {
            if ($simulated || ! str_contains($query, 'gallery_notification_subscriptions')) {
                return;
            }

            if (! str_starts_with(strtolower(ltrim($query)), 'insert')) {
                return;
            }

            // The concurrent writer that won the race.
            $simulated = true;
            DB::table('gallery_notification_subscriptions')->insert([
                'user_id' => $client->id,
                'gallery_id' => $gallery->id,
                'created_at' => now(),
                'updated_at' => now(),
            ]);
        });

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true]);

        $response->assertStatus(200);
        $this->assertTrue($simulated, 'The concurrent writer must have been simulated.');
        $this->assertDatabaseCount('gallery_notification_subscriptions', 1);
    }

    public function test_the_preferences_endpoint_does_not_disclose_the_name_of_an_unreachable_gallery(): void
    {
        [$client, $gallery] = $this->orgMemberWithDeliveryGallery();

        $token = auth('api')->login($client);
        $headers = ['Authorization' => "Bearer $token"];

        $this->withHeaders($headers)
            ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true])
            ->assertStatus(200);

        // The membership is revoked: the gallery is out of reach for the caller.
        $client->forceFill(['org_id' => null])->save();

        $preferences = $this->withHeaders($headers)
            ->getJson('/api/notifications/preferences')
            ->assertStatus(200)
            ->json('galleries');

        $entry = $this->preferenceFor($preferences, $gallery->id);

        // The entry stays visible — the id is needed to revoke the subscription.
        $this->assertNotNull($entry);
        $this->assertTrue($entry['wants_notifications']);
        // The name of a gallery the caller cannot reach is not disclosed.
        $this->assertNotSame($gallery->name, $entry['name']);
        $this->assertSame('Nicht zugängliche Galerie', $entry['name']);
    }

    public function test_the_grant_relation_does_not_load_the_legacy_opt_in_column(): void
    {
        // `user_galleries.wants_notifications` is a legacy column (D-45). The
        // application neither reads nor writes it for galleries, so the grant
        // relation does not select it.
        $client = User::factory()->create();
        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        DB::table('user_galleries')->insert([
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
            'wants_notifications' => 1,
        ]);

        $pivot = $client->galleries()->first()->pivot;

        $this->assertArrayNotHasKey('wants_notifications', $pivot->getAttributes());
    }

    /**
     * A client who is a member of an organization that owns a delivery gallery.
     *
     * @return array{0: User, 1: Gallery}
     */
    private function orgMemberWithDeliveryGallery(): array
    {
        $org = Org::factory()->create();
        $gallery = Gallery::factory()->create(['type' => 'delivery']);
        $gallery->orgs()->attach($org);

        $client = User::factory()->create(['org_id' => $org->id]);
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));

        return [$client, $gallery];
    }

    /**
     * @param  array<int, array<string, mixed>>  $preferences
     * @return array<string, mixed>|null
     */
    private function preferenceFor(array $preferences, string $galleryId): ?array
    {
        foreach ($preferences as $preference) {
            if (($preference['id'] ?? null) === $galleryId) {
                return $preference;
            }
        }

        return null;
    }
}
