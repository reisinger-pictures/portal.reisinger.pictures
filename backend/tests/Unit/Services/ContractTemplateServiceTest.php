<?php

namespace Tests\Unit\Services;

use App\Enums\Brand;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Services\ContractTemplateService;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
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
        $expiresAt = now()->addDays(10);
        $closesAt = now()->addDays(20);
        $template = Contract::factory()->template()->create([
            'status' => 'active',
            'expires_at' => $expiresAt,
            'closes_at' => $closesAt,
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

        $service = new ContractTemplateService;
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
        $this->assertSame($template->expires_at->toDateTimeString(), $instance->expires_at->toDateTimeString());
        $this->assertSame($template->closes_at->toDateTimeString(), $instance->closes_at->toDateTimeString());

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
            'available_roles' => ['Fotograf'],
        ]);

        $service = new ContractTemplateService;
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

    public function test_signer_failure_rolls_back_the_contract(): void
    {
        $template = Contract::factory()->template()->create([
            'status' => 'active',
            'available_roles' => ['Model'],
        ]);

        $contractsBefore = Contract::count();

        ContractSigner::creating(function () {
            throw new \RuntimeException('signer insert failed');
        });

        $service = new ContractTemplateService;

        try {
            $service->createInstance($template, [
                'name' => 'Rollback',
                'email' => 'rollback@example.com',
                'roles' => ['Model'],
                'personal_token' => 'rollback-token',
            ]);
            $this->fail('Expected the signer failure to bubble up.');
        } catch (\RuntimeException $e) {
            $this->assertSame('signer insert failed', $e->getMessage());
        }

        // No orphaned contract row must remain.
        $this->assertSame($contractsBefore, Contract::count());
        $this->assertDatabaseCount('contract_signers', 0);
    }

    /**
     * The second call represents the loser of two concurrent requests. The
     * SQLite :memory: test database cannot safely fork two writers, so the
     * serialized cache/row-lock path is exercised sequentially here. The
     * model event also proves that the duplicate check and signer insert run
     * inside the same transaction.
     */
    public function test_repeated_normalized_email_join_is_serialized_to_one_instance_and_signer(): void
    {
        $template = Contract::factory()->template()->create([
            'status' => 'active',
            'available_roles' => ['Model'],
        ]);
        $transactionLevelsAtInsert = [];
        $instanceTransactionLevels = [];
        $rowsVisibleAtInsert = null;
        $baseTransactionLevel = DB::connection()->transactionLevel();

        $this->assertSame(
            Contract::joinLockKey($template->getKey(), 'race-signer@example.com'),
            Contract::joinLockKey($template->getKey(), '  RACE-SIGNER@example.com  '),
        );

        Contract::creating(function (Contract $contract) use ($template, &$instanceTransactionLevels): void {
            if ($contract->type !== 'contract' || $contract->template_id !== $template->getKey()) {
                return;
            }

            $instanceTransactionLevels[] = DB::connection()->transactionLevel();
        });

        ContractSigner::creating(function (ContractSigner $signer) use ($template, &$transactionLevelsAtInsert, &$rowsVisibleAtInsert): void {
            if ($signer->email !== 'race-signer@example.com') {
                return;
            }

            $transactionLevelsAtInsert[] = DB::connection()->transactionLevel();
            $rowsVisibleAtInsert = ContractSigner::query()
                ->whereHas('contract', function ($query) use ($template): void {
                    $query->where('template_id', $template->getKey());
                })
                ->count();
        });

        $service = new ContractTemplateService;
        $first = $service->createInstance($template, [
            'name' => 'Race Signer',
            'email' => 'race-signer@example.com',
            'roles' => ['Model'],
            'personal_token' => 'race-token',
        ]);
        $second = $service->createInstance($template, [
            'name' => 'Race Signer',
            'email' => '  RACE-SIGNER@example.com  ',
            'roles' => ['Model'],
            'personal_token' => 'race-token-2',
        ]);

        $this->assertTrue($first['created']);
        $this->assertFalse($second['created']);
        $this->assertSame(409, $second['status']);
        $this->assertNull($second['signer']);
        $this->assertNotEmpty($transactionLevelsAtInsert);
        $this->assertGreaterThan($baseTransactionLevel, min($transactionLevelsAtInsert));
        $this->assertNotEmpty($instanceTransactionLevels);
        $this->assertGreaterThan($baseTransactionLevel, min($instanceTransactionLevels));
        $this->assertSame(0, $rowsVisibleAtInsert);
        $this->assertDatabaseCount('contracts', 2);
        $this->assertDatabaseCount('contract_signers', 1);
        $this->assertDatabaseHas('contract_signers', [
            'email' => 'race-signer@example.com',
            'personal_token' => 'race-token',
        ]);
    }

    public function test_create_instance_rejects_deadline_crossed_while_template_is_locked(): void
    {
        $template = Contract::factory()->template()->create([
            'status' => 'active',
            'expires_at' => now()->addHour(),
            'available_roles' => ['Model'],
        ]);
        $crossed = false;

        Contract::retrieved(function (Contract $loadedContract) use ($template, &$crossed): void {
            if ($crossed
                || DB::connection()->transactionLevel() < 1
                || $loadedContract->getKey() !== $template->getKey()) {
                return;
            }

            $crossed = true;
            DB::table('contracts')
                ->where('id', $template->getKey())
                ->update([
                    'expires_at' => DB::raw('CURRENT_TIMESTAMP'),
                ]);
        });

        $service = new ContractTemplateService;
        $result = $service->createInstance($template, [
            'name' => 'Crossing Signer',
            'email' => 'service-crossing@example.com',
            'roles' => ['Model'],
            'personal_token' => 'service-crossing-token',
        ]);

        $this->assertFalse($result['created']);
        $this->assertSame(410, $result['status']);
        $this->assertNull($result['instance']);
        $this->assertNull($result['signer']);
        $this->assertDatabaseCount('contracts', 1);
        $this->assertDatabaseCount('contract_signers', 0);
    }

    public function test_multiple_instances_from_same_template(): void
    {
        $template = Contract::factory()->template()->create([
            'status' => 'active',
            'available_roles' => ['Model', 'Fotograf'],
        ]);

        $service = new ContractTemplateService;
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
