<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ProductApiTest extends TestCase {
    use RefreshDatabase;

    public function test_super_admin_can_manage_products() {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::SUPER_ADMIN->value]));
        $token = auth('api')->login($superAdmin);

        // 1. Create
        $resCreate = $this->withHeaders(['Authorization' => "Bearer $token"])
                    ->postJson('/api/management/products', [
                        'type' => 'item',
                        'name' => 'Batch Test Product',
                        'description' => 'Original',
                        'price' => 100
                    ]);
        $resCreate->assertStatus(200);
        $productId = $resCreate->json('product.id');

        // 2. Update (Used primarily in Batch Edit loop)
        $resUpdate = $this->withHeaders(['Authorization' => "Bearer $token"])
                    ->putJson("/api/management/products/{$productId}", [
                        'type' => 'item',
                        'name' => 'Batch Test Product',
                        'description' => 'Updated via Batch',
                        'price' => 150
                    ]);
        $resUpdate->assertStatus(200);
        
        $this->assertDatabaseHas('products', [
            'id' => $productId,
            'description' => 'Updated via Batch',
            'price' => 150
        ]);

        // 3. Delete
        $resDelete = $this->withHeaders(['Authorization' => "Bearer $token"])
                    ->deleteJson("/api/management/products/{$productId}");
        $resDelete->assertStatus(200);
        $this->assertDatabaseMissing('products', ['id' => $productId]);
    }

    public function test_normal_admin_cannot_manage_products() {
        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $this->withHeaders(['Authorization' => "Bearer $token"])
             ->getJson('/api/management/products')
             ->assertStatus(403);
    }
}
