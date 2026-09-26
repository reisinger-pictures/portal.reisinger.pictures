<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * AIS-3: both AI POST endpoints carry a dedicated per-actor limiter that is
 * much tighter than the generic `throttle:api` budget.
 */
class AIGenerateThrottleTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Keep the provider out of the test: the controller answers 503 while
        // the throttle middleware still counts every attempt.
        config([
            'services.ai.enabled' => false,
            'app.throttle_ai_generate' => 5,
        ]);
    }

    public function test_generate_metadata_is_rate_limited_per_actor(): void
    {
        $user = $this->photographer();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($user, 'api')
                ->postJson('/api/ai/generate-metadata', [])
                ->assertStatus(503);
        }

        $this->actingAs($user, 'api')
            ->postJson('/api/ai/generate-metadata', [])
            ->assertStatus(429);
    }

    public function test_generate_metadata_text_is_rate_limited_per_actor(): void
    {
        $user = $this->photographer();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($user, 'api')
                ->postJson('/api/ai/generate-metadata-text', ['text_input' => 'Hochzeit'])
                ->assertStatus(503);
        }

        $this->actingAs($user, 'api')
            ->postJson('/api/ai/generate-metadata-text', ['text_input' => 'Hochzeit'])
            ->assertStatus(429);
    }

    public function test_each_actor_has_an_independent_budget(): void
    {
        $limited = $this->photographer();
        $other = $this->photographer();

        for ($attempt = 0; $attempt < 5; $attempt++) {
            $this->actingAs($limited, 'api')
                ->postJson('/api/ai/generate-metadata', [])
                ->assertStatus(503);
        }

        $this->actingAs($limited, 'api')
            ->postJson('/api/ai/generate-metadata', [])
            ->assertStatus(429);

        // A different actor still has its own untouched budget.
        $this->actingAs($other, 'api')
            ->postJson('/api/ai/generate-metadata', [])
            ->assertStatus(503);
    }

    private function photographer(): User
    {
        $user = User::factory()->create();
        $user->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]),
        );

        return $user;
    }
}
