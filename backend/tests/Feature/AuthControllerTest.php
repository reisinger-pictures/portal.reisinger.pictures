<?php
namespace Tests\Feature;
use Tests\TestCase;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;

class AuthControllerTest extends TestCase {
    use RefreshDatabase;

    public function test_user_can_login_with_correct_credentials() {
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'password123']);
        $response->assertStatus(200)->assertCookie('rp_jwt');
    }

    public function test_user_cannot_login_with_incorrect_credentials() {
        $user = User::factory()->create(['password' => bcrypt('password123')]);
        $response = $this->postJson('/api/auth/login', ['email' => $user->email, 'password' => 'wrongpass']);
        $response->assertStatus(401);
    }

    public function test_me_endpoint_returns_user_data() {
        $user = User::factory()->create();
        $token = auth('api')->login($user);
        $response = $this->withHeaders(['Authorization' => "Bearer $token"])->getJson('/api/auth/me');
        $response->assertStatus(200)->assertJsonPath('email', $user->email);
    }
}