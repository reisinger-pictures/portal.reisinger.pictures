<?php

namespace Tests\Feature;

use Tests\TestCase;
use App\Models\Contract;
use App\Models\User;
use App\Models\Role;
use App\Enums\UserRole;
use App\Support\BrandRegistry;
use App\Values\BrandConfig;
use Illuminate\Foundation\Testing\RefreshDatabase;

class ContractDownloadControllerTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        $testBrand = new BrandConfig(
            id: 'test-brand',
            name: 'Test Brand',
            theme: 'rp',
            portalName: 'Test Portal',
            impressumUrl: null,
            logoPath: null,
            features: [],
            hostnames: [],
            isActive: true,
        );
        BrandRegistry::set($testBrand);
    }

    protected function tearDown(): void
    {
        BrandRegistry::set(null);
        parent::tearDown();
    }

    public function test_super_admin_can_download_closed_contract()
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value])
        );
        $token = auth('api')->login($admin);

        $contract = Contract::create([
            'status' => 'closed',
            'type' => 'contract',
            'brand' => 'test-brand',
            'terms_html' => '<p>Test Contract</p>',
            'items' => [],
            'discounts' => [],
            'billing_details' => ['name' => 'Test'],
            'available_roles' => ['buyer'],
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/management/contracts/' . $contract->id . '/download');

        $response->assertStatus(200);
        $response->assertHeader('Content-Type', 'application/pdf');
    }

    public function test_cannot_download_open_contract()
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value])
        );
        $token = auth('api')->login($admin);

        $contract = Contract::create([
            'status' => 'active',
            'type' => 'contract',
            'brand' => 'test-brand',
            'terms_html' => '<p>Test Contract</p>',
            'items' => [],
            'discounts' => [],
            'billing_details' => ['name' => 'Test'],
            'available_roles' => ['buyer'],
        ]);

        $response = $this->withHeaders(['Authorization' => 'Bearer ' . $token])
            ->getJson('/api/management/contracts/' . $contract->id . '/download');

        $response->assertStatus(403);
    }
}
