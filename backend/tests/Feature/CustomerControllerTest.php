<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Customer;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\DatabaseTransactions;
use Meilisearch\Contracts\TasksQuery;
use Tests\TestCase;

class CustomerControllerTest extends TestCase
{
    use DatabaseTransactions;

    private User $superAdmin;
    private User $admin;
    private User $photographer;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);

        // Flush + re-sync Meilisearch index settings so sortableAttributes
        // (e.g. created_at on the customers index) are applied and stale
        // documents from previous runs (without created_at) are removed.
        \Illuminate\Support\Facades\Artisan::call('scout:flush', ['model' => Customer::class]);
        \Illuminate\Support\Facades\Artisan::call('scout:sync-index-settings');
        $this->waitForMeilisearchTasks();

        $this->superAdmin = User::factory()->create(['brand' => Brand::B2B]);
        $this->superAdmin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));

        $this->admin = User::factory()->create(['brand' => Brand::B2B]);
        $this->admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));

        $this->photographer = User::factory()->create(['brand' => Brand::B2B]);
        $this->photographer->roles()->attach(Role::firstOrCreate(['name' => UserRole::PHOTOGRAPHER->value]));
    }

    /**
     * Wait for pending Meilisearch async tasks (flush, settings update) to finish,
     * mirroring the pattern in SearchTest.
     */
    private function waitForMeilisearchTasks(): void
    {
        $client = app(\Meilisearch\Client::class);
        $query = (new TasksQuery())->setStatuses(['enqueued', 'processing']);
        foreach ($client->getTasks($query) as $task) {
            $uid = is_array($task) ? $task['uid'] : $task->getUid();
            $client->waitForTask($uid, 5000, 50);
        }
    }

    public function test_super_admin_can_list_customers()
    {
        Customer::factory()->count(3)->create(['brand' => Brand::B2B]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->getJson('/api/management/customers');

        $response->assertStatus(200);
        $response->assertJsonCount(3);
    }

    public function test_super_admin_can_search_customers_by_name()
    {
        Customer::factory()->create(['name' => 'Max Mustermann', 'brand' => Brand::B2B]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->getJson('/api/management/customers?q=Max');

        $response->assertStatus(200);
    }

    public function test_super_admin_can_create_customer()
    {
        $payload = [
            'name' => 'Erika Musterfrau',
            'company' => 'Muster GmbH',
            'email' => 'erika@muster.de',
            'street' => 'Teststr. 1',
            'zip' => '12345',
            'city' => 'Berlin',
            'country' => 'Deutschland',
            'uid' => 'ATU12345678',
            'birthdate' => '2000-06-15',
        ];

        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/customers', $payload);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('customer.name', 'Erika Musterfrau');
        $response->assertJsonPath('customer.company', 'Muster GmbH');
        $response->assertJsonPath('customer.birthdate', '2000-06-15');
        $this->assertDatabaseHas('customers', ['email' => 'erika@muster.de']);
    }

    public function test_customer_create_validates_required_fields()
    {
        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/customers', [
                'email' => 'invalid-email',
                'name' => str_repeat('a', 256),
            ]);

        $response->assertStatus(422);
    }

    public function test_non_super_admin_cannot_access_customers()
    {
        foreach ([$this->admin, $this->photographer] as $user) {
            $response = $this->actingAs($user, 'api')
                ->getJson('/api/management/customers');

            $response->assertStatus(403);
        }
    }

    public function test_customers_are_brand_scoped()
    {
        Customer::factory()->count(2)->create(['brand' => Brand::B2B]);
        Customer::factory()->count(1)->create(['brand' => Brand::SRP]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->getJson('/api/management/customers');

        $response->assertStatus(200);
        $response->assertJsonCount(2);
    }

    public function test_super_admin_can_update_customer()
    {
        $customer = Customer::factory()->create(['brand' => Brand::B2B]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->putJson('/api/management/customers/' . $customer->id, [
                'name' => 'Updated Name',
                'birthdate' => '1995-03-20',
            ]);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $response->assertJsonPath('customer.name', 'Updated Name');
        $response->assertJsonPath('customer.birthdate', '1995-03-20');
        $this->assertDatabaseHas('customers', ['id' => $customer->id, 'name' => 'Updated Name']);
    }

    public function test_super_admin_can_delete_customer()
    {
        $customer = Customer::factory()->create(['brand' => Brand::B2B]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->deleteJson('/api/management/customers/' . $customer->id);

        $response->assertStatus(200);
        $response->assertJsonPath('success', true);
        $this->assertDatabaseMissing('customers', ['id' => $customer->id]);
    }

    public function test_update_validates_invalid_email()
    {
        $customer = Customer::factory()->create(['brand' => Brand::B2B]);

        $response = $this->actingAs($this->superAdmin, 'api')
            ->putJson('/api/management/customers/' . $customer->id, [
                'email' => 'not-an-email',
            ]);

        $response->assertStatus(422);
    }

    public function test_birthdate_validates_minimum_age_16()
    {
        // Under 16 → rejected (422)
        $underagePayload = [
            'name' => 'Too Young',
            'email' => 'young@test.com',
            'birthdate' => now()->subYears(15)->format('Y-m-d'),
        ];
        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/customers', $underagePayload);
        $response->assertStatus(422);

        // Exactly 16 → accepted
        $validPayload = [
            'name' => 'Sixteen',
            'email' => 'sixteen@test.com',
            'birthdate' => now()->subYears(16)->format('Y-m-d'),
        ];
        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/customers', $validPayload);
        $response->assertStatus(200);

        // Empty birthdate → accepted (optional)
        $noBirthdatePayload = [
            'name' => 'No Birthdate',
            'email' => 'none@test.com',
            'birthdate' => null,
        ];
        $response = $this->actingAs($this->superAdmin, 'api')
            ->postJson('/api/management/customers', $noBirthdatePayload);
        $response->assertStatus(200);
    }
}
