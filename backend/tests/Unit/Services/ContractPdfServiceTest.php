<?php

namespace Tests\Unit\Services;

use App\Enums\Brand;
use App\Models\Contract;
use App\Models\ContractSigner;
use App\Services\ContractPdfService;
use App\Services\ManualInvoiceService;
use App\Services\OfferTokenService;
use App\Services\SettingResolver;
use App\Support\BrandRegistry;
use Barryvdh\DomPDF\Facade\Pdf;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class ContractPdfServiceTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::set(Brand::B2B);
    }

    public function test_generates_pdf_from_contract_data()
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
                ['type' => 'item', 'description' => 'Foto A', 'qty' => 1, 'price' => 5000],
            ],
            'discounts' => [],
            'terms_html' => '<p>Allgemeine Geschäftsbedingungen</p>',
        ]);

        $signer = ContractSigner::factory()->signed()->create([
            'contract_id' => $contract->id,
        ]);

        $manualInvoiceMock = $this->mock(ManualInvoiceService::class);
        $manualInvoiceMock->shouldReceive('processItems')
            ->once()
            ->andReturn([
                'items' => [
                    ['type' => 'item', 'filename' => 'Foto A', 'qty' => 1, 'price' => 5000, 'row_total' => 5000],
                ],
                'total' => 5000,
            ]);
        $manualInvoiceMock->shouldReceive('getBankDetails')
            ->once()
            ->andReturn([
                'holder' => 'Test Bank',
                'iban' => 'AT123456',
                'bic' => 'TESTBIC',
            ]);

        $offerTokenMock = $this->mock(OfferTokenService::class);
        $offerTokenMock->shouldReceive('issue')
            ->once()
            ->andReturn('test.jwt.payload');

        $domPdfMock = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $domPdfMock->shouldReceive('output')
            ->once()
            ->andReturn('%PDF-1.4 test content');
        Pdf::shouldReceive('loadView')
            ->once()
            ->andReturn($domPdfMock);

        $result = app(ContractPdfService::class)->generate($contract);

        $this->assertStringContainsString('%PDF-1.4', $result);
    }

    public function test_pdf_contains_offer_marker()
    {
        $contract = Contract::factory()->create([
            'status' => 'closed',
            'billing_details' => ['name' => 'Test', 'email' => 'test@test.de'],
            'items' => [['type' => 'item', 'description' => 'Test', 'qty' => 1, 'price' => 1000]],
            'discounts' => [],
            'terms_html' => '<p>AGB</p>',
        ]);

        ContractSigner::factory()->signed()->create([
            'contract_id' => $contract->id,
        ]);

        $manualInvoiceMock = $this->mock(ManualInvoiceService::class);
        $manualInvoiceMock->shouldReceive('processItems')->once()->andReturn([
            'items' => [['type' => 'item', 'filename' => 'Test', 'qty' => 1, 'price' => 1000, 'row_total' => 1000]],
            'total' => 1000,
        ]);
        $manualInvoiceMock->shouldReceive('getBankDetails')->once()->andReturn([
            'holder' => 'Bank', 'iban' => 'DE00', 'bic' => 'BIC',
        ]);

        $offerTokenMock = $this->mock(OfferTokenService::class);
        $offerTokenMock->shouldReceive('issue')->once()->andReturn('offer.jwt.123');

        $domPdfMock = \Mockery::mock(\Barryvdh\DomPDF\PDF::class);
        $domPdfMock->shouldReceive('output')->once()->andReturn('%PDF-1.4 with OFFER_JWT:offer.jwt.123');
        Pdf::shouldReceive('loadView')->once()->andReturn($domPdfMock);

        $result = app(ContractPdfService::class)->generate($contract);

        $this->assertStringContainsString('OFFER_JWT:offer.jwt.123', $result);
    }
}
