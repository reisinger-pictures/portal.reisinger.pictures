<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Gallery;
use App\Models\GalleryInvite;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class InviteTest extends TestCase {
    use RefreshDatabase;

    public function test_redeem_invite_issues_jwt_without_db_user() {
        $gallery = Gallery::factory()->create(['password_hash' => null]);
        $invite = GalleryInvite::create(['gallery_id' => $gallery->id, 'token' => 'randomtoken123']);

        $response = $this->postJson('/api/invites/redeem', [
            'token' => 'randomtoken123',
            'name' => 'Guest User',
            'email' => 'guest@example.com'
        ]);

        $response->assertStatus(200)->assertCookie('rp_jwt');
        $this->assertDatabaseMissing('users', ['email' => 'guest@example.com']);
    }
}
