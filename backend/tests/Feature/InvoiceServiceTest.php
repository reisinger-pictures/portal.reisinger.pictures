<?php

namespace Tests\Feature;

use App\Models\InvoiceSequence;
use App\Models\InvoiceSnapshot;
use App\Models\Order;
use App\Models\Tenant;
use App\Models\User;
use App\Services\InvoiceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Http;
use Tests\TestCase;

class InvoiceServiceTest extends TestCase
{
    use RefreshDatabase;

    private InvoiceService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new InvoiceService();
        // Mailpit leeren für sauberen State (QUEUE_CONNECTION=sync → ShouldQueue-Mailables
        // werden inline versendet, kein queue:work nötig).
        Http::delete('http://127.0.0.1:8026/api/v1/messages');

        // Bankdaten für den InvoiceMail-PDF-Build
        \App\Models\Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        \App\Models\Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => 'rp'], ['value' => 'BIC']);
    }

    /**
     * Helper: delivery_note-Order + InvoiceSnapshot für einen User anlegen.
     * invoice_number bewusst leer lassen → Auto-Generierung via booted()-Hook (L-Präfix).
     */
    private function makeDeliveryNoteOrder(User $user, int $netGross, array $items = [], array $terms = []): Order
    {
        $order = Order::create([
            'user_id' => $user->id,
            'status' => 'delivery_note',
            'total_amount' => 0,
        ]);

        InvoiceSnapshot::create([
            'order_id' => $order->id,
            // invoice_number absichtlich weggelassen → Hook generiert L-Nummer
            'customer_details' => [
                'name' => $user->name,
                'items' => $items,
                'terms' => $terms,
            ],
            'total_net' => $netGross,
            'total_gross' => $netGross,
            'tax_rate' => 0,
        ]);

        return $order;
    }

    public function test_generateForTenant_happy_path_single_user_single_order(): void
    {
        $tenant = Tenant::create(['name' => 'Happy Tenant', 'invoice_frequency' => 'monthly']);
        $user = User::factory()->create(['email' => 'happy@example.com']);
        $user->tenants()->attach($tenant);

        $order = $this->makeDeliveryNoteOrder($user, 5000, [
            ['photoId' => 'p1', 'price' => 5000, 'tier' => 'web'],
        ]);

        $result = $this->service->generateForTenant($tenant);

        // Return-Shape
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['processed_orders']);
        $this->assertNotEmpty($result['invoice_number']);

        // Ursprünglicher Lieferschein archiviert
        $order->refresh();
        $this->assertSame('archived_in_collective', $order->status);

        // Neue collective Order angelegt
        $this->assertDatabaseHas('orders', [
            'status' => 'invoice_created',
            'total_amount' => 5000,
        ]);

        // InvoiceMail via Mailpit (KEIN Mail::fake())
        $messages = Http::get('http://127.0.0.1:8026/api/v1/messages')->json('messages');
        $this->assertGreaterThan(0, count($messages), 'Keine E-Mail an Mailpit versendet.');
        $this->assertStringContainsString('happy@example.com', $messages[0]['To'][0]['Address']);
    }

    /**
     * Invarianten zur Rechnungsnummern-Generierung:
     *  - Rückgabe invoice_number identisch mit InvoiceSnapshot->invoice_number der collective Order.
     *  - InvoiceSequence.current_value inkrementiert exakt 1× pro generateForTenant-Auf­ruf.
     *
     * Hinweis: Der Service reicht 'invoice_number' => $invoiceNumber ans InvoiceSnapshot::create
     * durch, sodass der booted()-Creating-Hook (nur bei leerer Nummer aktiv) nicht erneut generiert.
     * (Verifiziert: kein Doppel-Inkrement.)
     */
    public function test_generateForTenant_invoice_number_consistency_single_increment_review(): void
    {
        $tenant = Tenant::create(['name' => 'Seq Tenant']);
        $user = User::factory()->create(['email' => 'seq@example.com']);
        $user->tenants()->attach($tenant);
        $this->makeDeliveryNoteOrder($user, 1000);

        $year = (int)date('Y');
        // makeDeliveryNoteOrder hat bereits 1× inkrementiert (L-Nummer) → $before NACHDEM lesen
        $before = InvoiceSequence::firstOrCreate(['year' => $year], ['current_value' => 0])->current_value;

        $result = $this->service->generateForTenant($tenant);

        $after = InvoiceSequence::where('year', $year)->value('current_value');

        // Exakt EIN Inkrement durch generateForTenant
        $this->assertSame(
            1,
            $after - $before,
            'InvoiceSequence muss pro generateForTenant-Aufruf exakt einmal inkrementieren.'
        );

        // Rückgabe-Nummer identisch mit Snapshot-Datensatz der collective Order
        $collectiveOrder = Order::where('status', 'invoice_created')->first();
        $this->assertNotNull($collectiveOrder);

        $snapshotNumber = InvoiceSnapshot::where('order_id', $collectiveOrder->id)->value('invoice_number');
        $this->assertSame(
            $result['invoice_number'],
            $snapshotNumber,
            'Rückgabe invoice_number muss mit der im Snapshot gespeicherten Nummer identisch sein.'
        );
    }

    public function test_generateForTenant_enriches_items_with_ordered_by_and_original_order_id(): void
    {
        $tenant = Tenant::create(['name' => 'Items Tenant']);
        $user = User::factory()->create(['name' => 'Maria Bestellerin', 'email' => 'items@example.com']);
        $user->tenants()->attach($tenant);

        $this->makeDeliveryNoteOrder($user, 3000, [
            ['photoId' => 'photo-A', 'price' => 3000, 'tier' => 'web'],
        ]);

        $this->service->generateForTenant($tenant);

        $collectiveOrder = Order::where('status', 'invoice_created')->first();
        $snapshot = InvoiceSnapshot::where('order_id', $collectiveOrder->id)->first();

        $items = $snapshot->customer_details['items'];
        $this->assertCount(1, $items);
        $this->assertSame('Maria Bestellerin', $items[0]['ordered_by']);
        $this->assertSame('photo-A', $items[0]['photoId']);
        // original_order_id = ID der Ursprungs-Lieferschein-Order
        $originalOrder = Order::where('status', 'archived_in_collective')->first();
        $this->assertSame($originalOrder->id, $items[0]['original_order_id']);
    }

    public function test_generateForTenant_merges_terms_across_multiple_orders(): void
    {
        $tenant = Tenant::create(['name' => 'Terms Tenant']);
        $user = User::factory()->create(['email' => 'terms@example.com']);
        $user->tenants()->attach($tenant);

        $this->makeDeliveryNoteOrder($user, 1000, [['photoId' => 'x1', 'price' => 1000, 'tier' => 'web']], [
            'delivery_time' => 'sofort',
            'payment_term' => '14 Tage',
        ]);
        $this->makeDeliveryNoteOrder($user, 2000, [['photoId' => 'x2', 'price' => 2000, 'tier' => 'print']], [
            'delivery_time' => '5 Tage',  // Overwrite
            'shipping' => 'versichert',
        ]);

        $this->service->generateForTenant($tenant);

        $collectiveOrder = Order::where('status', 'invoice_created')->first();
        $snapshot = InvoiceSnapshot::where('order_id', $collectiveOrder->id)->first();

        $terms = $snapshot->customer_details['terms'];
        // Beide Orders haben jeweils delivery_time → letzter gewinnt ('5 Tage')
        $this->assertSame('5 Tage', $terms['delivery_time']);
        $this->assertSame('14 Tage', $terms['payment_term']);
        $this->assertSame('versichert', $terms['shipping']);
    }

    public function test_generateForTenant_billing_fallback_firmenadresse_when_initiator_null_and_user_billing_empty(): void
    {
        $tenant = Tenant::create(['name' => 'Fallback Tenant']);
        // billing_* bewusst NICHT setzen → bleiben null → Fallback greift
        $user = User::factory()->create(['email' => 'fallback@example.com']);
        $user->tenants()->attach($tenant);

        $this->makeDeliveryNoteOrder($user, 1500);

        $this->service->generateForTenant($tenant);

        $collectiveOrder = Order::where('status', 'invoice_created')->first();
        $snapshot = InvoiceSnapshot::where('order_id', $collectiveOrder->id)->first();
        $details = $snapshot->customer_details;

        $this->assertSame('Firmenadresse', $details['street']);
        $this->assertSame('0000', $details['zip']);
        $this->assertSame('Unbekannt', $details['city']);
        $this->assertSame('Österreich', $details['country']);
    }

    public function test_generateForTenant_uses_initiator_billing_when_provided(): void
    {
        $tenant = Tenant::create(['name' => 'Initiator Tenant']);
        $user = User::factory()->create(['email' => 'tenantuser@example.com']);
        $user->tenants()->attach($tenant);
        $this->makeDeliveryNoteOrder($user, 2200);

        // Initiator mit eigenen billing_* Werten (NICHT mass-assignment-fillable → Property + save)
        $initiator = User::factory()->create(['email' => 'initiator@example.com']);
        $initiator->billing_street = 'Initiatorenweg 7';
        $initiator->billing_zip = '4020';
        $initiator->billing_city = 'Linz';
        $initiator->save();
        $initiator->tenants()->attach($tenant);

        $this->service->generateForTenant($tenant, $initiator);

        $collectiveOrder = Order::where('status', 'invoice_created')->first();
        $this->assertSame($initiator->id, $collectiveOrder->user_id);

        $snapshot = InvoiceSnapshot::where('order_id', $collectiveOrder->id)->first();
        $details = $snapshot->customer_details;
        $this->assertSame('Initiatorenweg 7', $details['street']);
        $this->assertSame('4020', $details['zip']);
        $this->assertSame('Linz', $details['city']);
        $this->assertSame('initiator@example.com', $details['email']);

        // Mail geht an Initiator
        $messages = Http::get('http://127.0.0.1:8026/api/v1/messages')->json('messages');
        $this->assertGreaterThan(0, count($messages));
        $this->assertStringContainsString('initiator@example.com', $messages[0]['To'][0]['Address']);
    }

    public function test_generateForTenant_tenant_without_users_returns_error(): void
    {
        $tenant = Tenant::create(['name' => 'Empty Tenant']);

        $result = $this->service->generateForTenant($tenant);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Keine offenen Lieferscheine', $result['error']);
        // Keine collective Order, keine Mail
        $this->assertDatabaseMissing('orders', ['status' => 'invoice_created']);
        $messages = Http::get('http://127.0.0.1:8026/api/v1/messages')->json('messages');
        $this->assertSame(0, count($messages));

        // REVIEW-Hinweis: Der ehemals tote mailTo=null Zweig wurde entfernt,
        // da er über die vorherigen Guards ohnehin unerreichbar war.
    }

    public function test_generateForTenant_users_without_delivery_note_orders_returns_error(): void
    {
        $tenant = Tenant::create(['name' => 'NoDelivery Tenant']);
        $user = User::factory()->create(['email' => 'paid@example.com']);
        $user->tenants()->attach($tenant);

        // Order mit anderem Status (nicht delivery_note) → darf NICHT erfasst werden
        $paidOrder = Order::create(['user_id' => $user->id, 'status' => 'paid', 'total_amount' => 999]);

        $result = $this->service->generateForTenant($tenant);

        $this->assertFalse($result['success']);
        $this->assertStringContainsString('Keine offenen Lieferscheine', $result['error']);
        // Ursprüngliche paid-Order bleibt unverändert (nicht archiviert)
        $this->assertDatabaseHas('orders', ['id' => $paidOrder->id, 'status' => 'paid']);
        $this->assertDatabaseMissing('orders', ['status' => 'invoice_created']);
    }

    public function test_generateForTenant_multiple_users_multiple_orders_all_archived_and_accumulated(): void
    {
        $tenant = Tenant::create(['name' => 'Multi Tenant']);

        $userA = User::factory()->create(['name' => 'Alpha', 'email' => 'alpha@example.com']);
        $userB = User::factory()->create(['name' => 'Beta', 'email' => 'beta@example.com']);
        $userA->tenants()->attach($tenant);
        $userB->tenants()->attach($tenant);

        // 3 delivery_note-Orders über 2 User, Summe 1000+2000+4000 = 7000
        $this->makeDeliveryNoteOrder($userA, 1000, [['photoId' => 'a1', 'price' => 1000, 'tier' => 'web']]);
        $this->makeDeliveryNoteOrder($userA, 2000, [['photoId' => 'a2', 'price' => 2000, 'tier' => 'print']]);
        $this->makeDeliveryNoteOrder($userB, 4000, [['photoId' => 'b1', 'price' => 4000, 'tier' => 'original']]);

        $result = $this->service->generateForTenant($tenant);

        $this->assertTrue($result['success']);
        $this->assertSame(3, $result['processed_orders']);

        // Alle 3 Ursprungs-Orders archiviert
        $this->assertSame(
            3,
            Order::where('status', 'archived_in_collective')->count(),
            'Alle Ursprungs-Lieferscheine müssen archiviert werden.'
        );

        // Eine collective Order mit kumuliertem total_amount (Geld = Cents)
        $collectiveOrder = Order::where('status', 'invoice_created')->first();
        $this->assertNotNull($collectiveOrder);
        $this->assertSame(7000, (int)$collectiveOrder->total_amount);

        // Collective Snapshot summiert Net/Gross
        $snapshot = InvoiceSnapshot::where('order_id', $collectiveOrder->id)->first();
        $this->assertSame(7000, (int)$snapshot->total_net);
        $this->assertSame(7000, (int)$snapshot->total_gross);

        // Items enthalten alle 3 Einträge mit korrektem ordered_by
        $items = $snapshot->customer_details['items'];
        $this->assertCount(3, $items);
        $orderedBy = array_column($items, 'ordered_by');
        sort($orderedBy);
        $this->assertSame(['Alpha', 'Alpha', 'Beta'], $orderedBy);
    }

    public function test_generateForTenant_status_filter_excludes_non_delivery_note_orders(): void
    {
        // Sicherheitsnetz: auch wenn eine Order is_quote_request=true hat, entscheidet der
        // status=delivery_note Filter. Andere Status werden nicht gezogen.
        $tenant = Tenant::create(['name' => 'QuoteFilter Tenant']);
        $user = User::factory()->create(['email' => 'qf@example.com']);
        $user->tenants()->attach($tenant);

        Order::create([
            'user_id' => $user->id,
            'status' => 'pending',
            'total_amount' => 500,
            'is_quote_request' => true,
        ]);
        $this->makeDeliveryNoteOrder($user, 800);

        $result = $this->service->generateForTenant($tenant);

        // Nur die delivery_note-Order wurde verarbeitet
        $this->assertTrue($result['success']);
        $this->assertSame(1, $result['processed_orders']);
        $this->assertSame(1, Order::where('status', 'archived_in_collective')->count());
        $this->assertSame(800, (int)Order::where('status', 'invoice_created')->value('total_amount'));
    }
}
