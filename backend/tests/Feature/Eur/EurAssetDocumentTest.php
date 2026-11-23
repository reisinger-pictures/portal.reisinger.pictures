<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurAsset;
use App\Models\EurAssetDocument;
use App\Models\EurDocument;
use App\Support\BrandRegistry;

/** D-85: Belege hängen am Stamm, sind markenbegrenzt und werden nie kopiert. */
class EurAssetDocumentTest extends EurBookkeepingTestCase
{
    public function test_attach_is_idempotent_and_never_exposes_storage_paths(): void
    {
        $token = $this->tokenFor();
        $asset = $this->asset('Gut mit Beleg');
        $document = $this->document('beleg.pdf', 'R-1');

        $first = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/documents',
            ['document_id' => $document->id],
        );
        $first->assertCreated();
        $first->assertJsonPath('already_linked', false);
        $first->assertJsonPath('document.id', $document->id);
        $first->assertJsonPath('document.receipt_no', 'R-1');
        $first->assertJsonPath('document.original_name', 'beleg.pdf');
        $first->assertJsonPath('document.sha256', $document->sha256);
        $this->assertArrayNotHasKey('storage_path', $first->json('document'));
        $this->assertArrayNotHasKey('signed_storage_path', $first->json('document'));

        $second = $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/documents',
            ['document_id' => $document->id],
        );
        $second->assertOk();
        $second->assertJsonPath('already_linked', true);
        $this->assertSame(1, EurAssetDocument::forCurrentBrand()->where('eur_asset_id', $asset->id)->count());

        $plan = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets/'.$asset->id.'/plan');
        $plan->assertOk();
        $this->assertCount(1, $plan->json('asset.documents'));
        $this->assertSame($document->id, $plan->json('asset.documents.0.id'));
        $this->assertArrayNotHasKey('storage_path', $plan->json('asset.documents.0'));
    }

    public function test_a_foreign_document_or_asset_cannot_be_linked(): void
    {
        $token = $this->tokenFor();
        $asset = $this->asset('Eigenes Gut');
        $foreignDocument = $this->document('fremd.pdf', 'R-F', self::OTHER_BRAND);
        $foreignAsset = EurAsset::create([
            'brand' => self::OTHER_BRAND,
            'name' => 'Fremdes Gut',
            'invoice_no' => 'F-2',
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 100000,
            'useful_life_years' => 3,
            'business_share' => 100,
            'scope' => 'business',
            'method' => 'linear',
        ]);
        $ownDocument = $this->document('eigen.pdf', 'R-2');

        $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/documents',
            ['document_id' => $foreignDocument->id],
        )->assertNotFound();

        $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$foreignAsset->id.'/documents',
            ['document_id' => $ownDocument->id],
        )->assertNotFound();

        $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/documents',
            ['document_id' => 999999],
        )->assertNotFound();

        $this->assertSame(0, EurAssetDocument::count());
    }

    public function test_detach_removes_the_link_and_is_idempotent(): void
    {
        $token = $this->tokenFor();
        $asset = $this->asset('Gut zum Trennen');
        $document = $this->document('beleg.pdf', 'R-3');

        $this->withHeaders($this->bearer($token))->postJson(
            '/api/management/eur/assets/'.$asset->id.'/documents',
            ['document_id' => $document->id],
        )->assertCreated();

        $this->withHeaders($this->bearer($token))->deleteJson(
            '/api/management/eur/assets/'.$asset->id.'/documents/'.$document->id,
        )->assertOk();

        $this->assertSame(0, EurAssetDocument::where('eur_asset_id', $asset->id)->count());

        $this->withHeaders($this->bearer($token))->deleteJson(
            '/api/management/eur/assets/'.$asset->id.'/documents/'.$document->id,
        )->assertOk();

        $plan = $this->withHeaders($this->bearer($token))->getJson('/api/management/eur/assets/'.$asset->id.'/plan');
        $plan->assertOk();
        $this->assertSame([], $plan->json('asset.documents'));
    }

    private function asset(string $name): EurAsset
    {
        return EurAsset::create([
            'brand' => BrandRegistry::currentId(),
            'name' => $name,
            'invoice_no' => 'R-'.$name,
            'supplier' => 'Lieferant',
            'purchase_date' => '2026-01-15',
            'cost_cents' => 240000,
            'useful_life_years' => 3,
            'business_share' => 100,
            'scope' => 'business',
            'method' => 'linear',
        ]);
    }

    private function document(string $name, string $receiptNo, string $brand = 'rp'): EurDocument
    {
        return EurDocument::create([
            'brand' => $brand,
            'year' => 2026,
            'month' => 1,
            'receipt_no' => $receiptNo,
            'original_name' => $name,
            'storage_path' => 'eur/'.$brand.'/'.$name,
            'mime_type' => 'application/pdf',
            'size_bytes' => 10,
            'sha256' => hash('sha256', $name.$brand),
        ]);
    }
}
