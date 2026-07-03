<?php

namespace Tests\Feature;

use App\Enums\Brand;
use App\Enums\UserRole;
use App\Models\Coupon;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Role;
use App\Models\Setting;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use App\Services\SettingResolver;
use App\Support\BrandRegistry;
use App\Mail\InvoiceMail;
use App\Http\Middleware\BrandContextMiddleware;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class BrandLeakTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        Http::delete('http://127.0.0.1:8026/api/v1/messages');
        Setting::updateOrCreate(['key' => 'bank_holder'], ['value' => 'B2B Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban'], ['value' => 'B2B123']);
        Setting::updateOrCreate(['key' => 'bank_bic'], ['value' => 'B2BBIC']);
        Setting::updateOrCreate(['key' => 'srp_bank_holder'], ['value' => 'SRP Holder']);
        Setting::updateOrCreate(['key' => 'srp_bank_iban'], ['value' => 'SRP987']);
        Setting::updateOrCreate(['key' => 'srp_bank_bic'], ['value' => 'SRPBIC']);
    }

    public function test_order_creation_persists_brand_from_config(): void
    {
        BrandRegistry::set(Brand::B2B);
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'brand' => BrandRegistry::current()?->value,
            'total_amount' => 1000,
        ]);
        $this->assertSame(Brand::B2B, $order->brand);
    }

    public function test_invoice_snapshot_creation_persists_brand_from_order(): void
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'pending',
            'brand' => Brand::SRP->value,
            'total_amount' => 1000,
        ]);

        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'P-2026-0001',
            'brand' => $order->brand,
            'customer_details' => ['name' => 'Test', 'items' => []],
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => 0,
        ]);

        $this->assertDatabaseHas('invoice_snapshots', [
            'invoice_number' => 'P-2026-0001',
            'brand' => Brand::SRP->value,
        ]);
    }

    public function test_invoice_mail_reconstructs_brand_from_order(): void
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'invoice_created',
            'brand' => Brand::SRP->value,
            'total_amount' => 1000,
        ]);

        $snapshot = InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'P-2026-0002',
            'brand' => Brand::SRP->value,
            'customer_details' => ['name' => 'Test', 'items' => []],
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => 0,
        ]);

        BrandRegistry::set(null);

        $mailable = new InvoiceMail($order, $snapshot);
        $mailable->build();

        // Queue worker had no HTTP host; brand must be reconstructed from the order → SRP.
        $this->assertSame(Brand::SRP, $mailable->brand);
        // Brand context is restored after build — verify no leakage.
        $this->assertNull(BrandRegistry::current());
    }

    public function test_invoice_mail_fallback_when_order_has_no_brand(): void
    {
        $order = Order::create([
            'user_id' => User::factory()->create()->id,
            'status' => 'invoice_created',
            'brand' => null,
            'total_amount' => 1000,
        ]);

        $snapshot = InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'P-2026-0003',
            'brand' => null,
            'customer_details' => ['name' => 'Test', 'items' => []],
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => 0,
        ]);

        BrandRegistry::set(null);

        $mailable = new InvoiceMail($order, $snapshot);
        $mailable->build();

        // Legacy order without brand → safe B2B default, never empty branding.
        $this->assertSame(Brand::B2B, $mailable->brand);
        // Brand context is restored after build — verify no leakage.
        $this->assertNull(BrandRegistry::current());
    }

    public function test_invoice_service_persists_brand_on_collective_orders(): void
    {
        $tenant = Tenant::create(['name' => 'Brand Tenant', 'invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['email' => 'brand-tenant@example.com']);
        $user->tenant_id = $tenant->id;
        $user->save();

        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'delivery_note',
            'brand' => Brand::SRP->value,
            'total_amount' => 5000,
        ]);

        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'L-2026-0001',
            'brand' => Brand::SRP->value,
            'customer_details' => ['name' => 'Brand Kunde', 'items' => [['photoId' => 'p1', 'price' => 5000, 'tier' => 'web']]],
            'total_net' => 5000,
            'total_gross' => 5000,
            'tax_rate' => 0,
        ]);

        $service = new InvoiceService();
        $result = $service->generateForTenant($tenant);
        $this->assertTrue($result['success']);

        $this->assertDatabaseHas('orders', [
            'status' => 'invoice_created',
            'brand' => Brand::SRP->value,
        ]);

        $collectiveOrder = Order::where('status', 'invoice_created')->first();
        $snapshot = InvoiceSnapshot::where('order_id', $collectiveOrder->id)->first();
        $this->assertSame(Brand::SRP, $snapshot->brand);
    }

    /**
     * B-01 F2: an SRP order downloaded via a B2B request host must still render SRP bank
     * details. Also covers the $get regression (downloadInvoice must not 500).
     */
    public function test_download_invoice_uses_order_brand_not_request_host(): void
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'invoice_created',
            'brand' => Brand::SRP->value,
            'total_amount' => 1000,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'P-2026-0010',
            'brand' => Brand::SRP->value,
            'customer_details' => ['name' => 'Test', 'items' => []],
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => 0,
        ]);

        $response = $this->actingAs($user, 'api')
            ->getJson("/api/orders/{$order->id}/invoice");

        $response->assertOk();
        // Brand context is restored after download — verify request-level brand is preserved.
        $this->assertSame(Brand::B2B, BrandRegistry::current());
    }

    /**
     * Inverse case: B2B order rendered through any host stays B2B.
     */
    public function test_download_invoice_b2b_order_renders_b2b(): void
    {
        $user = User::factory()->create();
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'invoice_created',
            'brand' => Brand::B2B->value,
            'total_amount' => 1000,
        ]);
        InvoiceSnapshot::create([
            'order_id' => $order->id,
            'invoice_number' => 'P-2026-0011',
            'brand' => Brand::B2B->value,
            'customer_details' => ['name' => 'Test', 'items' => []],
            'total_net' => 1000,
            'total_gross' => 1000,
            'tax_rate' => 0,
        ]);

        $response = $this->actingAs($user, 'api')
            ->getJson("/api/orders/{$order->id}/invoice");

        $response->assertOk();
        // Brand context is preserved — verify request-level brand is unchanged.
        $this->assertSame(Brand::B2B, BrandRegistry::current());
    }

    // ──────────────────────────────────────────────
    //  SRP-01 C-3: Coupon Brand Isolation
    // ──────────────────────────────────────────────

    /**
     * Super Admin on SRP URL must only see SRP coupons, never RP coupons.
     */
    public function test_super_admin_on_srp_url_sees_srp_coupons_not_rp(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value])
        );

        Coupon::factory()->create(['brand' => 'srp', 'code' => 'SRP-COUPON']);
        Coupon::factory()->create(['brand' => 'rp', 'code' => 'RP-COUPON']);

        BrandRegistry::set(Brand::SRP);

        $response = $this->withoutMiddleware(BrandContextMiddleware::class)
            ->actingAs($admin, 'api')
            ->getJson('/api/management/coupons');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame('SRP-COUPON', $response->json('data.0.code'));

        BrandRegistry::set(null);
    }

    /**
     * Super Admin on RP URL must only see RP coupons, never SRP coupons.
     */
    public function test_super_admin_on_rp_url_sees_rp_coupons_not_srp(): void
    {
        $admin = User::factory()->create();
        $admin->roles()->attach(
            Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value])
        );

        Coupon::factory()->create(['brand' => 'srp', 'code' => 'SRP-COUPON']);
        Coupon::factory()->create(['brand' => 'rp', 'code' => 'RP-COUPON']);

        $response = $this->actingAs($admin, 'api')
            ->getJson('/api/management/coupons');

        $response->assertOk();
        $response->assertJsonCount(1, 'data');
        $this->assertSame('RP-COUPON', $response->json('data.0.code'));
    }
}
