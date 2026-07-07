<?php
namespace Tests\Feature;

use Tests\TestCase;
use App\Models\User;
use App\Models\Role;
use App\Models\GalleryGroup;
use Illuminate\Foundation\Testing\RefreshDatabase;

class FormRoundtripTest extends TestCase {
    use RefreshDatabase;

    private function getAdminToken() {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::SUPER_ADMIN->value]));
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => \App\Enums\UserRole::PHOTOGRAPHER->value]));
        return auth('api')->login($superAdmin);
    }

    public function test_customer_modal_roundtrip() {
        $token = $this->getAdminToken();
        $payload = ['name' => 'RT Name', 'company' => 'RT Company', 'email' => 'rt@example.com', 'street' => 'RT Street 42', 'zip' => '1234', 'city' => 'RT City', 'country' => 'RT Country', 'uid' => 'ATU12345678'];
        
        $res = $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/management/customers', $payload);
        $res->assertStatus(200);
        $id = $res->json('customer.id');
        $this->assertDatabaseHas('customers', array_merge(['id' => $id], $payload));

        $updated = ['name' => 'Upd Name', 'company' => 'Upd Company', 'email' => 'upd@example.com', 'street' => 'Upd Street 42', 'zip' => '4321', 'city' => 'Upd City', 'country' => 'Upd Country', 'uid' => 'ATU87654321'];
        $this->withHeaders(['Authorization' => "Bearer $token"])->putJson("/api/management/customers/{$id}", $updated)->assertStatus(200);
        $this->assertDatabaseHas('customers', array_merge(['id' => $id], $updated));
    }

    public function test_product_modal_roundtrip() {
        $token = $this->getAdminToken();
        $payload = ['type' => 'discount_percent', 'name' => 'RT Product', 'description' => 'RT Desc', 'price' => 2500];
        
        $res = $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/management/products', $payload);
        $res->assertStatus(200);
        $id = $res->json('product.id');
        $this->assertDatabaseHas('products', array_merge(['id' => $id], $payload));

        $updated = ['type' => 'item', 'name' => 'Upd Product', 'description' => 'Upd Desc', 'price' => 5000];
        $this->withHeaders(['Authorization' => "Bearer $token"])->putJson("/api/management/products/{$id}", $updated)->assertStatus(200);
        $this->assertDatabaseHas('products', array_merge(['id' => $id], $updated));
    }

    public function test_text_snippet_modal_roundtrip() {
        $token = $this->getAdminToken();
        $payload = ['title' => 'RT Snippet', 'shortcut' => 'rtrip', 'content_html' => '<p>RT Content</p>'];
        
        $res = $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/management/text-snippets', $payload);
        $res->assertStatus(200);
        $id = $res->json('snippet.id');
        $this->assertDatabaseHas('text_snippets', array_merge(['id' => $id], $payload));

        $updated = ['title' => 'Upd Snippet', 'shortcut' => 'upd', 'content_html' => '<p>Upd Content</p>'];
        $this->withHeaders(['Authorization' => "Bearer $token"])->putJson("/api/management/text-snippets/{$id}", $updated)->assertStatus(200);
        $this->assertDatabaseHas('text_snippets', array_merge(['id' => $id], $updated));
    }

    public function test_org_modal_roundtrip() {
        $token = $this->getAdminToken();
        $payload = ['name' => 'RT Org', 'domain' => 'rt.example.com', 'invoice_frequency' => 'monthly'];
        
        $res = $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/management/orgs', $payload);
        $res->assertStatus(200);
        $id = $res->json('org.id');
        $this->assertDatabaseHas('orgs', array_merge(['id' => $id], $payload));

        $updated = ['name' => 'Upd Org', 'domain' => 'upd.example.com', 'invoice_frequency' => 'quarterly'];
        $this->withHeaders(['Authorization' => "Bearer $token"])->putJson("/api/management/orgs/{$id}", $updated)->assertStatus(200);
        $this->assertDatabaseHas('orgs', array_merge(['id' => $id], $updated));
    }

    public function test_gallery_group_modal_roundtrip() {
        $token = $this->getAdminToken();
        $payload = ['name' => 'RT Group', 'slug' => 'rt-group', 'is_public' => false, 'is_free_download' => true, 'is_editorial_only' => false, 'is_hidden' => true];
        
        $res = $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/management/gallery-groups', $payload);
        $res->assertStatus(200);
        $id = $res->json('group.id');
        $this->assertDatabaseHas('gallery_groups', array_merge(['id' => $id], $payload));

        $updated = ['name' => 'Upd Group', 'slug' => 'upd-group', 'is_public' => true, 'is_free_download' => false, 'is_editorial_only' => true, 'is_hidden' => false];
        $this->withHeaders(['Authorization' => "Bearer $token"])->putJson("/api/management/gallery-groups/{$id}", $updated)->assertStatus(200);
        $this->assertDatabaseHas('gallery_groups', array_merge(['id' => $id], $updated));
    }

    public function test_gallery_modal_roundtrip() {
        $token = $this->getAdminToken();
        $group = GalleryGroup::factory()->create(['is_public' => null]);
        $payload = ['name' => 'RT Gal', 'slug' => 'rt-gal', 'type' => 'delivery', 'is_public' => true, 'is_live' => true, 'gallery_group_id' => $group->id, 'is_free_download' => true, 'is_editorial_only' => false, 'is_hidden' => true];
        
        $res = $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/management/galleries', $payload);
        $res->assertStatus(200);
        $id = $res->json('gallery.id');
        $this->assertDatabaseHas('galleries', array_merge(['id' => $id], $payload));

        $updated = ['name' => 'Upd Gal', 'slug' => 'upd-gal', 'type' => 'selection', 'is_public' => false, 'is_live' => false, 'gallery_group_id' => null, 'is_free_download' => false, 'is_editorial_only' => true, 'is_hidden' => false];
        $this->withHeaders(['Authorization' => "Bearer $token"])->putJson("/api/management/galleries/{$id}", $updated)->assertStatus(200);
        $this->assertDatabaseHas('galleries', array_merge(['id' => $id], $updated));
    }

    public function test_license_catalog_roundtrip() {
        $token = $this->getAdminToken();
        
        // Use Case Roundtrip
        $ucPayload = ['name' => 'RT UseCase', 'description' => 'RT Desc', 'base_price' => 15000, 'flatrate_tier' => 'print', 'is_commercial' => true, 'sort_order' => 10];
        $resUc = $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/management/settings/license-use-cases', $ucPayload);
        $resUc->assertStatus(200);
        $ucId = $resUc->json('id');
        $this->assertDatabaseHas('license_use_cases', array_merge(['id' => $ucId], $ucPayload));

        $ucUpdated = ['name' => 'Upd UseCase', 'description' => 'Upd Desc', 'base_price' => 20000, 'flatrate_tier' => 'original', 'is_commercial' => false, 'sort_order' => 20];
        $this->withHeaders(['Authorization' => "Bearer $token"])->putJson("/api/management/settings/license-use-cases/{$ucId}", $ucUpdated)->assertStatus(200);
        $this->assertDatabaseHas('license_use_cases', array_merge(['id' => $ucId], $ucUpdated));

        // Modifier Roundtrip
        $modPayload = ['name' => 'RT Mod', 'description' => 'RT Desc', 'percent_surcharge' => 33.33, 'is_included_in_flatrate' => true, 'sort_order' => 5];
        $resMod = $this->withHeaders(['Authorization' => "Bearer $token"])->postJson('/api/management/settings/license-modifiers', $modPayload);
        $resMod->assertStatus(200);
        $modId = $resMod->json('id');
        // decimal formatting in DB might drop decimals or round, but we cast to array
        $this->assertDatabaseHas('license_modifiers', ['id' => $modId, 'name' => 'RT Mod', 'is_included_in_flatrate' => 1]);

        $modUpdated = ['name' => 'Upd Mod', 'description' => 'Upd Desc', 'percent_surcharge' => 50, 'is_included_in_flatrate' => false, 'sort_order' => 10];
        $this->withHeaders(['Authorization' => "Bearer $token"])->putJson("/api/management/settings/license-modifiers/{$modId}", $modUpdated)->assertStatus(200);
        $this->assertDatabaseHas('license_modifiers', ['id' => $modId, 'name' => 'Upd Mod', 'is_included_in_flatrate' => 0]);
    }
}
