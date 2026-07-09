<?php

namespace Tests\Unit\Services;

use App\Enums\Brand;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Services\ContractTemplateService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractTemplateServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
    }

    public function test_create_instance_from_template(): void
    {
        $template = Contract::factory()->template()->create([
            'status' => 'active',
            'billing_details' => ['name' => 'Template Biller', 'email' => 'biller@example.com'],
            'items' => [
                ['type' => 'item', 'description' => 'Template Item', 'qty' => 1, 'price' => 10000],
            ],
            'discounts' => [
                ['type' => 'discount_fixed', 'description' => 'Template Discount', 'price' => 500],
            ],
            'terms_html' => '<p>Template Terms</p>',
            'available_roles' => ['Model', 'Fotograf'],
            'allow_multiple_roles_per_signer' => true,
            'brand' => Brand::B2B,
        ]);

        $service = new ContractTemplateService();
        $result = $service->createInstance($template, [
            'name' => 'Test Signer',
            'email' => 'signer@example.com',
            'roles' => ['Model'],
            'personal_token' => 'test-personal-token-123',
        ]);

        $instance = $result['instance'];
        $signer = $result['signer'];

        $this->assertInstanceOf(Contract::class, $instance);
        $this->assertInstanceOf(ContractSigner::class, $signer);

        $this->assertEquals('contract', $instance->type);
        $this->assertEquals($template->id, $instance->template_id);
        $this->assertEquals('active', $instance->status);

        $this->assertEquals($template->billing_details, $instance->billing_details);
        $this->assertEquals($template->items, $instance->items);
        $this->assertEquals($template->discounts, $instance->discounts);
        $this->assertEquals($template->terms_html, $instance->terms_html);
        $this->assertEquals($template->available_roles, $instance->available_roles);
        $this->assertEquals($template->allow_multiple_roles_per_signer, $instance->allow_multiple_roles_per_signer);
        $this->assertEquals($template->brand, $instance->brand);

        $this->assertNull($instance->join_token);
        $this->assertEquals(0, $instance->content_version);

        $this->assertEquals($instance->id, $signer->contract_id);
        $this->assertEquals('Test Signer', $signer->name);
        $this->assertEquals('signer@example.com', $signer->email);
        $this->assertEquals(['Model'], $signer->roles);
        $this->assertEquals('test-personal-token-123', $signer->personal_token);
        $this->assertEquals('joined', $signer->status);
    }

    public function test_create_instance_persists_to_database(): void
    {
        $template = Contract::factory()->template()->create([
            'status' => 'active',
        ]);

        $service = new ContractTemplateService();
        $result = $service->createInstance($template, [
            'name' => 'DB Test',
            'email' => 'db@example.com',
            'roles' => ['Fotograf'],
            'personal_token' => 'db-token',
        ]);

        $this->assertDatabaseHas('contracts', [
            'id' => $result['instance']->id,
            'type' => 'contract',
            'template_id' => $template->id,
        ]);

        $this->assertDatabaseHas('contract_signers', [
            'contract_id' => $result['instance']->id,
            'name' => 'DB Test',
            'email' => 'db@example.com',
            'personal_token' => 'db-token',
            'status' => 'joined',
        ]);
    }

    public function test_multiple_instances_from_same_template(): void
    {
        $template = Contract::factory()->template()->create([
            'status' => 'active',
        ]);

        $service = new ContractTemplateService();
        $result1 = $service->createInstance($template, [
            'name' => 'Signer One',
            'email' => 'one@example.com',
            'roles' => ['Model'],
            'personal_token' => 'token-one',
        ]);

        $result2 = $service->createInstance($template, [
            'name' => 'Signer Two',
            'email' => 'two@example.com',
            'roles' => ['Fotograf'],
            'personal_token' => 'token-two',
        ]);

        $this->assertNotEquals($result1['instance']->id, $result2['instance']->id);
        $this->assertNotEquals($result1['signer']->id, $result2['signer']->id);

        $this->assertEquals($template->id, $result1['instance']->template_id);
        $this->assertEquals($template->id, $result2['instance']->template_id);

        $this->assertEquals(2, Contract::where('template_id', $template->id)->count());
    }
}
