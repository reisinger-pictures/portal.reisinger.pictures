<?php

namespace Tests\Feature\Mail;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\Gallery;
use App\Enums\UserRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;

class FinishRatingTest extends TestCase
{
    use RefreshDatabase;

    public function test_unauthorized_user_cannot_trigger_finish_rating(): void
    {
        // H3 regression: a user without gallery access must not trigger the
        // rating-finished notification (spam/abuse vector, gallery-existence leak).
        Mail::fake();

        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));

        $gallery = Gallery::factory()->create(); // Client has NO access to this gallery

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->postJson("/api/galleries/{$gallery->id}/finish-rating");

        $response->assertStatus(403);
        Mail::assertNothingSent();
    }

    public function test_authorized_user_can_trigger_finish_rating(): void
    {
        // Happy path: a user attached to the gallery can finish rating.
        Mail::fake();

        $client = User::factory()->create();
        $client->roles()->attach(Role::firstOrCreate(['name' => UserRole::CLIENT->value]));
        $gallery = Gallery::factory()->create();
        $client->galleries()->attach($gallery);

        $token = auth('api')->login($client);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])
                         ->postJson("/api/galleries/{$gallery->id}/finish-rating");

        $response->assertStatus(200);
        // No photographers/admins with wants_notifications → 0 mails is correct.
        Mail::assertNothingSent();
    }
}
