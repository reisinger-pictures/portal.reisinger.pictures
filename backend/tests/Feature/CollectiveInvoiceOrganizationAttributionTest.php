<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Mail\InvoiceMail;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Org;
use App\Models\Role;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Tests\TestCase;

class CollectiveInvoiceOrganizationAttributionTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Mail::fake();
    }

    public function test_generation_uses_purchase_org_snapshot_after_user_reassignment(): void
    {
        $originalOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $newOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['org_id' => $originalOrg->id]);
        $order = $this->makeDeliveryNote($user, 2500, $originalOrg->id, true);

        $user->update(['org_id' => $newOrg->id]);

        $service = app(InvoiceService::class);
        $this->assertSame(1, $service->countOpenDeliveryNotesForOrg($originalOrg));
        $this->assertSame(0, $service->countOpenDeliveryNotesForOrg($newOrg));
        $result = $service->generateForOrg($originalOrg);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['processed_orders']);
        $this->assertSame('archived_in_collective', $order->fresh()->status);

        $collectiveOrder = Order::query()->where('status', 'invoice_created')->sole();
        $collectiveSnapshot = $collectiveOrder->invoiceSnapshot;
        $this->assertNotNull($collectiveSnapshot);
        $this->assertSame(
            (string) $originalOrg->id,
            $collectiveSnapshot->customer_details[InvoiceSnapshot::PURCHASE_ORG_ID_KEY]
        );
        $this->assertSame($user->id, $collectiveOrder->user_id);
        Mail::assertQueued(InvoiceMail::class);

        $newOrgResult = $service->generateForOrg($newOrg);

        $this->assertFalse($newOrgResult['success']);
        $this->assertStringContainsString('Keine offenen Lieferscheine', $newOrgResult['error']);
        $this->assertSame(1, Order::query()->where('status', 'invoice_created')->count());
    }

    public function test_org_summary_uses_purchase_org_attribution_after_reassignment(): void
    {
        $purchaseOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $newOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['org_id' => $purchaseOrg->id]);
        $this->makeDeliveryNote($user, 2900, $purchaseOrg->id, true);

        $user->update(['org_id' => $newOrg->id]);

        $admin = User::factory()->create();
        $admin->roles()->attach(Role::firstOrCreate(['name' => UserRole::ADMIN->value]));
        $token = auth('api')->login($admin);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/management/orgs/{$purchaseOrg->id}")
            ->assertOk()
            ->assertJsonPath('open_delivery_notes_count', 1);

        $this->withHeader('Authorization', "Bearer {$token}")
            ->getJson("/api/management/orgs/{$newOrg->id}")
            ->assertOk()
            ->assertJsonPath('open_delivery_notes_count', 0);
    }

    public function test_legacy_snapshot_without_purchase_org_key_falls_back_to_current_membership(): void
    {
        $originalOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $newOrg = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['org_id' => $originalOrg->id]);
        $order = $this->makeDeliveryNote($user, 1700, null, false);

        $user->update(['org_id' => $newOrg->id]);

        $service = app(InvoiceService::class);
        $this->assertSame(1, $service->countOpenDeliveryNotesForOrg($newOrg));
        $result = $service->generateForOrg($newOrg);

        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['processed_orders']);
        $this->assertSame('archived_in_collective', $order->fresh()->status);

        $collectiveOrder = Order::query()->where('status', 'invoice_created')->sole();
        $this->assertSame(
            (string) $newOrg->id,
            $collectiveOrder->invoiceSnapshot->customer_details[InvoiceSnapshot::PURCHASE_ORG_ID_KEY]
        );
    }

    public function test_present_but_invalid_purchase_org_snapshot_fails_closed_for_summary_and_generation(): void
    {
        $org = Org::factory()->create(['invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['org_id' => $org->id]);
        $order = $this->makeDeliveryNote($user, 1600, null, true);

        $service = app(InvoiceService::class);
        $this->assertSame(0, $service->countOpenDeliveryNotesForOrg($org));
        $result = $service->generateForOrg($org);

        $this->assertFalse($result['success']);
        $this->assertSame('delivery_note', $order->fresh()->status);
    }

    private function makeDeliveryNote(
        User $user,
        int $amount,
        ?string $purchaseOrgId,
        bool $includePurchaseOrg,
    ): Order {
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'delivery_note',
            'total_amount' => $amount,
        ]);

        $customerDetails = [
            'name' => $user->name,
            'email' => $user->email,
            'items' => [[
                'photoId' => 'photo-'.$order->id,
                'price' => $amount,
                'tier' => 'web',
            ]],
            'terms' => [],
        ];
        if ($includePurchaseOrg) {
            $customerDetails[InvoiceSnapshot::PURCHASE_ORG_ID_KEY] = $purchaseOrgId;
        }

        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'L-ATTRIBUTION-'.$order->id,
            'customer_details' => $customerDetails,
            'total_net' => $amount,
            'total_gross' => $amount,
            'tax_rate' => 0,
        ]);

        return $order->fresh(['invoiceSnapshot', 'user']);
    }
}
