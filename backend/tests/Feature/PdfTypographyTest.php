<?php

namespace Tests\Feature;

use App\Enums\UserRole;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Models\InvoiceSnapshot;
use App\Models\Role;
use App\Models\Setting;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use PHPUnit\Framework\Attributes\DataProvider;
use Tests\TestCase;

/**
 * PDF-Typografie-Vertrag (orphans/widows/page-break-inside), Brand-Farben,
 * Entfernung der hervorgehobenen WYSIWYG-Box und Dedup-Regression der
 * Items-/Totals-Fragmente. Läuft ohne laufenden Server (pure View-Render).
 */
class PdfTypographyTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Erforderliche Stammdaten für den PDF-Header/Footer (Muster ManualDocumentTest)
        Setting::updateOrCreate(['key' => 'bank_holder', 'brand' => 'rp'], ['value' => 'Test Holder']);
        Setting::updateOrCreate(['key' => 'bank_iban', 'brand' => 'rp'], ['value' => 'AT123456789']);
        Setting::updateOrCreate(['key' => 'bank_bic', 'brand' => 'rp'], ['value' => 'TESTAT11']);
    }

    // -----------------------------------------------------------------------
    // View-Render (alle 3 Templates): Typografie, Farben, keine Box, Dedup
    // -----------------------------------------------------------------------

    public static function templateProvider(): array
    {
        return [
            'invoice' => ['pdf.invoice'],
            'manual_offer' => ['pdf.manual_offer'],
            'contract_signatures' => ['pdf.contract_signatures'],
        ];
    }

    #[DataProvider('templateProvider')]
    public function test_typography_contract_brand_colors_and_no_box(string $viewName): void
    {
        $viewData = match ($viewName) {
            'pdf.invoice' => $this->invoiceViewData(),
            'pdf.manual_offer' => $this->offerViewData(),
            'pdf.contract_signatures' => $this->contractViewData(),
        };

        $html = view($viewName, $viewData['data'])->render();

        // Typografie-Vertrag (Task E): orphans/widows + page-break-inside-Kompensation
        $this->assertStringContainsString('orphans: 2', $html);
        $this->assertStringContainsString('widows: 2', $html);
        $this->assertStringContainsString('.editor-content p, .editor-content li { page-break-inside: avoid; }', $html);
        $this->assertStringContainsString('h1, h2, h3, h4, h5, h6 { color: ', $html);

        // Brand-Farben aus BrandRegistry::configOrDefault() sind im HTML enthalten
        $brand = BrandRegistry::configOrDefault();
        $this->assertStringContainsString($brand->primaryColor, $html);
        $this->assertStringContainsString($brand->secondaryColor, $html);

        // Keine hervorgehobene WYSIWYG-Box mehr (Task B)
        $this->assertStringNotContainsString('#fcfcfc', $html);
        $this->assertStringNotContainsString('border: 1px solid #eee', $html);

        // Übergebene WYSIWYG-Paragraph-Inhalte erscheinen im .editor-content-Bereich
        foreach ($viewData['editorParagraphs'] as $paragraph) {
            $this->assertStringContainsString($paragraph, $html);
        }

        // Regression Dedup: Gesamtbetrag-Label + formatierter Betrag genau einmal
        $this->assertSame(1, substr_count($html, $viewData['totalLabelProbe']));
        $this->assertSame(1, substr_count($html, $viewData['formattedTotal']));
    }

    // -----------------------------------------------------------------------
    // API-Streams (echte dompdf-Generierung, Muster ManualDocumentTest)
    // -----------------------------------------------------------------------

    public function test_manual_invoice_api_streams_pdf_content(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));
        $token = auth('api')->login($superAdmin);

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/invoices/manual', [
                'invoice_number' => 'R-2026-777',
                'date' => '2026-04-14',
                'due_date' => 'Zahlbar sofort.',
                'type' => 'invoice',
                'customer_name' => 'Test Customer',
                'items' => [
                    ['type' => 'item', 'description' => 'Service A', 'qty' => 2, 'price' => 50],
                ],
            ]);

        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'application/pdf');

        $pdfContent = $res->streamedContent();
        $this->assertStringStartsWith('%PDF-1.', $pdfContent);
    }

    public function test_manual_offer_api_streams_pdf_with_offer_marker(): void
    {
        $superAdmin = User::factory()->create();
        $superAdmin->roles()->attach(Role::firstOrCreate(['name' => UserRole::SUPER_ADMIN->value]));
        $token = auth('api')->login($superAdmin);

        $res = $this->withHeaders(['Authorization' => "Bearer $token"])
            ->postJson('/api/management/invoices/manual', [
                'invoice_number' => 'A-2026-777',
                'date' => '2026-04-14',
                'due_date' => date('Y-m-d', strtotime('+30 days')),
                'type' => 'offer',
                'customer_name' => 'Smart Doc Tester',
                'customer_email' => 'smart@doc.test',
                'items' => [
                    ['type' => 'item', 'description' => 'Consulting', 'qty' => 10, 'price' => 100],
                    ['type' => 'discount_fixed', 'description' => 'Rabatt', 'qty' => 1, 'price' => 50],
                ],
                'terms_html' => '<p>Test Terms</p>',
            ]);

        $res->assertStatus(200);
        $res->assertHeader('Content-Type', 'application/pdf');

        $pdfContent = $res->streamedContent();
        $this->assertStringStartsWith('%PDF-1.', $pdfContent);
        $this->assertStringContainsString('%OFFER_JWT:', $pdfContent);
    }

    // -----------------------------------------------------------------------
    // View-Datenaufbau (Muster InvoiceController::generateManualInvoice)
    // -----------------------------------------------------------------------

    /**
     * @return array{data: array, totalLabelProbe: string, formattedTotal: string, editorParagraphs: string[]}
     */
    private function invoiceViewData(): array
    {
        $snapshot = new InvoiceSnapshot([
            'invoice_number' => 'R-2026-001',
            'customer_details' => [
                'name' => 'Test Kunde',
                'company' => '',
                'street' => 'Teststraße 1',
                'zip' => '12345',
                'city' => 'Wien',
                'country' => 'Österreich',
                'uid' => '',
                'email' => 'kunde@test.at',
                'service_date' => '01.05.2026',
                'due_date' => 'Zahlbar sofort.',
                'custom_conditions' => '<p>Lizenzbedingungen Test</p>',
                'custom_html_terms' => '<p>Allgemeine Geschäftsbedingungen Test</p>',
            ],
            'total_net' => 8500,
            'total_gross' => 8500,
            'tax_rate' => 0,
        ]);
        $snapshot->created_at = '2026-04-14';

        return [
            'data' => [
                'title' => 'RECHNUNG',
                'snapshot' => $snapshot,
                'items' => [
                    ['type' => 'item', 'filename' => 'Foto A', 'tier' => 'custom', 'qty' => 2, 'price' => 5000, 'row_total' => 10000],
                    ['type' => 'discount_fixed', 'filename' => 'Rabatt', 'tier' => 'custom', 'qty' => 1, 'price' => 1500, 'row_total' => -1500],
                ],
                'bankHolder' => 'Test Holder',
                'bankIban' => 'AT123456789',
                'bankBic' => 'TESTAT11',
                'pfx' => 'rp',
                'primaryColor' => BrandRegistry::configOrDefault()->primaryColor,
                'secondaryColor' => BrandRegistry::configOrDefault()->secondaryColor,
            ],
            // "Rechnungsbetrag" steht zusätzlich im Footer-Text ("den Rechnungsbetrag …") →
            // Label als eigenständigen Textknoten prüfen (genau einmal in der Total-Zeile).
            'totalLabelProbe' => '>Rechnungsbetrag<',
            'formattedTotal' => '85,00 €',
            'editorParagraphs' => [
                '<p>Lizenzbedingungen Test</p>',
                '<p>Allgemeine Geschäftsbedingungen Test</p>',
            ],
        ];
    }

    /**
     * @return array{data: array, totalLabelProbe: string, formattedTotal: string, editorParagraphs: string[]}
     */
    private function offerViewData(): array
    {
        $snapshot = new InvoiceSnapshot([
            'invoice_number' => 'A-2026-001',
            'customer_details' => [
                'name' => 'Test Kunde',
                'company' => '',
                'street' => 'Teststraße 1',
                'zip' => '12345',
                'city' => 'Wien',
                'country' => 'Österreich',
                'uid' => '',
                'email' => 'kunde@test.at',
                'due_date' => '2026-05-14',
                'custom_conditions' => '<p>Lizenzbedingungen Test</p>',
                'custom_html_terms' => '<p>Angebotstext Test</p>',
            ],
            'total_net' => 9000,
            'total_gross' => 9000,
            'tax_rate' => 0,
        ]);
        $snapshot->created_at = '2026-04-14';

        return [
            'data' => [
                'title' => 'ANGEBOT',
                'snapshot' => $snapshot,
                'items' => [
                    ['type' => 'item', 'filename' => 'Foto A', 'tier' => 'custom', 'qty' => 1, 'price' => 10000, 'row_total' => 10000],
                    ['type' => 'discount_percent', 'filename' => '10% Rabatt', 'tier' => 'custom', 'qty' => 1, 'price' => 10, 'row_total' => -1000, 'calculated_percentage' => 10],
                ],
                'bankHolder' => 'Test Holder',
                'bankIban' => 'AT123456789',
                'bankBic' => 'TESTAT11',
                'pfx' => 'rp',
                'primaryColor' => BrandRegistry::configOrDefault()->primaryColor,
                'secondaryColor' => BrandRegistry::configOrDefault()->secondaryColor,
            ],
            'totalLabelProbe' => 'Voraussichtlicher Gesamtbetrag',
            'formattedTotal' => '90,00 €',
            'editorParagraphs' => [
                '<p>Angebotstext Test</p>',
                '<p>Lizenzbedingungen Test</p>',
            ],
        ];
    }

    /**
     * @return array{data: array, totalLabelProbe: string, formattedTotal: string, editorParagraphs: string[]}
     */
    private function contractViewData(): array
    {
        $contract = Contract::factory()->create([
            'status' => 'closed',
            'billing_details' => [
                'name' => 'Max Mustermann',
                'company' => 'Muster GmbH',
                'street' => 'Teststr. 1',
                'zip' => '12345',
                'city' => 'Berlin',
                'country' => 'Deutschland',
                'email' => 'max@muster.de',
                'uid' => 'ATU12345678',
            ],
            'items' => [
                ['type' => 'item', 'description' => 'Foto A', 'qty' => 1, 'price' => 3000],
            ],
            'discounts' => [
                ['type' => 'discount_fixed', 'description' => 'Rabatt', 'qty' => 1, 'price' => 500],
            ],
            'terms_html' => '<p>Vertragsbedingungen Test</p>',
        ]);

        ContractSigner::factory()->signed()->create([
            'contract_id' => $contract->id,
        ]);

        return [
            'data' => [
                'contract' => $contract,
                'items' => [
                    ['type' => 'item', 'filename' => 'Foto A', 'qty' => 1, 'price' => 3000, 'row_total' => 3000],
                    ['type' => 'discount_fixed', 'filename' => 'Rabatt', 'qty' => 1, 'price' => 500, 'row_total' => -500],
                ],
                'total' => 2500,
                'bankHolder' => 'Test Holder',
                'bankIban' => 'AT123456789',
                'bankBic' => 'TESTAT11',
                'signers' => $contract->signers,
                'offerMarker' => '%OFFER_JWT:test.jwt.payload%',
                'pfx' => 'rp',
                'ageLabel' => '',
                'primaryColor' => BrandRegistry::configOrDefault()->primaryColor,
                'secondaryColor' => BrandRegistry::configOrDefault()->secondaryColor,
            ],
            'totalLabelProbe' => 'Gesamtbetrag',
            'formattedTotal' => '25,00 €',
            'editorParagraphs' => [
                '<p>Vertragsbedingungen Test</p>',
            ],
        ];
    }
}
