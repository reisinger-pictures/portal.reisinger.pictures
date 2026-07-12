<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Gallery;
use App\Models\GalleryGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\Support\MailpitAssertions;

class NotificationOptInTest extends TestCase {
    use RefreshDatabase, MailpitAssertions;

    public function test_client_can_toggle_opt_in_and_mail_controller_respects_it() {
        $client = User::factory()->create(['email' => 'optin@test.com']);
        $client->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]));
        $gallery = Gallery::factory()->create();
        $client->galleries()->attach($gallery, ['wants_notifications' => false]); // Startet opt-out

        // 1. API Toggle Test
        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('user_galleries', ['user_id' => $client->id, 'gallery_id' => $gallery->id, 'wants_notifications' => 1]);

        // 2. Mail Controller Test (Sollte den User nun inkludieren)
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        $adminToken = auth('api')->login($admin);
        
        $adminResponse = $this->withHeaders(['Authorization' => "Bearer $adminToken"])
             ->postJson("/api/management/galleries/{$gallery->id}/send-custom-email", [
                 'subject' => 'OptIn Update', 'body' => 'Hello'
             ]);
        
        $adminResponse->assertStatus(200);
        $this->assertEquals(1, $adminResponse->json('notified_count'));

        // WORKER STARTEN: Leert die asynchrone Warteschlange für den Test
        \Illuminate\Support\Facades\Artisan::call('queue:work', ['--stop-when-empty' => true]);

        $this->assertMailpitSentTo('optin@test.com');
    }

    public function test_user_cannot_opt_in_to_gallery_they_cannot_access(): void
    {
        // H2 regression: a user must not subscribe to a gallery they have no access to.
        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]));
        $gallery = Gallery::factory()->create(); // Client has NO relationship to this gallery

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->postJson("/api/galleries/{$gallery->id}/opt-in", ['wants_notifications' => true]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('user_galleries', [
            'user_id' => $client->id,
            'gallery_id' => $gallery->id,
        ]);
    }

    public function test_user_cannot_opt_in_to_group_they_do_not_belong_to(): void
    {
        // H2 regression: a user must not subscribe to a group they are not a member of.
        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::CLIENT->value]));
        $group = GalleryGroup::factory()->create(); // Client has NO membership in this group

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->postJson("/api/gallery-groups/{$group->id}/opt-in", ['wants_notifications' => true]);

        $response->assertStatus(403);
        $this->assertDatabaseMissing('user_gallery_groups', [
            'user_id' => $client->id,
            'gallery_group_id' => $group->id,
        ]);
    }
}
