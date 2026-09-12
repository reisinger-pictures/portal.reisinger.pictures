<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Gallery;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

/**
 * Regression: P1 finding — sendCustom() must enforce the gallery manage gate,
 * so a photographer cannot target an unmanageable gallery.
 */
class MailControllerAuthorizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_photographer_cannot_send_custom_email_for_unmanageable_gallery(): void
    {
        Mail::fake();

        $photographer = User::factory()->create(['brand' => 'rp']);
        $photographer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));

        // Restricted gallery the photographer is not assigned to.
        $gallery = Gallery::factory()->create([
            'type' => 'delivery',
            'restricted_photographers' => true,
        ]);

        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/galleries/{$gallery->id}/send-custom-email", [
                'subject' => 'Hello',
                'body' => 'Body',
            ]);

        $response->assertStatus(403);
        Mail::assertNothingQueued();
    }

    public function test_photographer_can_send_custom_email_for_own_unrestricted_gallery(): void
    {
        Mail::fake();

        $photographer = User::factory()->create(['brand' => 'rp']);
        $photographer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
        $gallery = Gallery::factory()->create(['type' => 'delivery', 'brand' => 'rp']);

        $client = User::factory()->create(['email' => 'optin@example.com', 'brand' => 'rp']);
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $client->galleries()->attach($gallery->id, ['wants_notifications' => true]);

        $token = auth('api')->login($photographer);

        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson("/api/management/galleries/{$gallery->id}/send-custom-email", [
                'subject' => 'Hello {user_name}',
                'body' => 'Body {gallery_name}',
            ]);

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('notified_count'));
        Mail::assertQueuedCount(1);
    }
}
