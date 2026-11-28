<?php

declare(strict_types=1);

namespace Tests\Feature\Ea;

use App\Models\EaDocument;
use App\Services\Ea\EaReceiptOcrService;
use App\Support\BrandRegistry;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

class EaDocumentApiTest extends EaBookkeepingTestCase
{
    /** Symfony 8 setzt bei Test-Uploads keinen Multipart-Content-Type mehr. */
    private function uploadHeaders(string $token): array
    {
        return array_merge($this->bearer($token), ['Content-Type' => 'multipart/form-data; boundary=eurboundary']);
    }

    protected function setUp(): void
    {
        parent::setUp();
        Storage::fake('local');
    }

    public function test_documents_require_authentication(): void
    {
        $this->getJson('/api/management/ea/documents')->assertUnauthorized();
    }

    public function test_upload_stores_a_private_file_and_never_leaks_its_path(): void
    {
        $token = $this->tokenFor();
        $file = UploadedFile::fake()->create('beleg.pdf', 10, 'application/pdf');

        $response = $this->withHeaders($this->uploadHeaders($token))->post('/api/management/ea/documents', [
            'file' => $file,
            'year' => 2026,
            'month' => 5,
            'receipt_no' => 'R-100',
        ]);

        $response->assertCreated();
        $document = $response->json('document');
        $this->assertArrayNotHasKey('storage_path', $document);
        $this->assertArrayNotHasKey('path', $document);
        $this->assertSame('beleg.pdf', $document['original_name']);
        $this->assertSame('R-100', $document['receipt_no']);
        $this->assertNotEmpty($document['sha256']);

        $stored = EaDocument::forCurrentBrand()->findOrFail($document['id']);
        Storage::disk('local')->assertExists($stored->storage_path);
        $this->assertStringStartsWith('ea/'.BrandRegistry::currentId().'/', $stored->storage_path);
        $this->assertDatabaseHas('ea_audit_logs', ['action' => 'uploaded', 'subject_type' => 'ea_document']);
    }

    public function test_ocr_returns_advisory_hints_for_an_image_receipt(): void
    {
        $token = $this->tokenFor();
        $this->app->instance(EaReceiptOcrService::class, new EaReceiptOcrService(
            static fn (string $bytes): string => "Beleg Nr: R-2026-42\n15.05.2026\nGesamt 1.234,56 €",
        ));
        $upload = $this->withHeaders($this->uploadHeaders($token))->post('/api/management/ea/documents', [
            'file' => UploadedFile::fake()->image('beleg.png', 20, 20),
            'year' => 2026,
            'month' => 5,
        ]);
        $id = $upload->json('document.id');

        $response = $this->withHeaders($this->bearer($token))->postJson("/api/management/ea/documents/{$id}/ocr");

        $response->assertOk();
        $response->assertJsonPath('ocr.supported', true);
        $response->assertJsonPath('ocr.receipt_no', 'R-2026-42');
        $response->assertJsonPath('ocr.date', '2026-05-15');
        $response->assertJsonPath('ocr.amount_cents', 123456);
    }

    public function test_ocr_reports_unsupported_for_pdf_instead_of_failing_the_upload(): void
    {
        $token = $this->tokenFor();
        $upload = $this->withHeaders($this->uploadHeaders($token))->post('/api/management/ea/documents', [
            'file' => UploadedFile::fake()->create('beleg.pdf', 10, 'application/pdf'),
            'year' => 2026,
            'month' => 5,
        ]);
        $id = $upload->json('document.id');

        $response = $this->withHeaders($this->bearer($token))->postJson("/api/management/ea/documents/{$id}/ocr");

        $response->assertOk();
        $response->assertJsonPath('ocr.supported', false);
    }

    public function test_duplicate_upload_is_idempotent(): void
    {
        $token = $this->tokenFor();
        $payload = ['year' => 2026, 'month' => 5];

        $first = $this->withHeaders($this->uploadHeaders($token))->post('/api/management/ea/documents', array_merge($payload, [
            'file' => UploadedFile::fake()->createWithContent('beleg.pdf', '%PDF-1.4 test'),
        ]));
        $second = $this->withHeaders($this->uploadHeaders($token))->post('/api/management/ea/documents', array_merge($payload, [
            'file' => UploadedFile::fake()->createWithContent('beleg.pdf', '%PDF-1.4 test'),
        ]));

        $first->assertCreated();
        $second->assertOk();
        $this->assertSame($first->json('document.id'), $second->json('document.id'));
        $this->assertDatabaseCount('ea_documents', 1);
        $this->assertDatabaseCount('ea_audit_logs', 1);
    }

    public function test_upload_rejects_invalid_mime_and_oversized_files(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->uploadHeaders($token))->post('/api/management/ea/documents', [
            'file' => UploadedFile::fake()->create('script.txt', 1, 'text/plain'),
            'year' => 2026,
        ])->assertUnprocessable();

        $this->withHeaders($this->uploadHeaders($token))->post('/api/management/ea/documents', [
            'file' => UploadedFile::fake()->create('gross.pdf', 10241, 'application/pdf'),
            'year' => 2026,
        ])->assertUnprocessable();

        $this->withHeaders($this->uploadHeaders($token))->post('/api/management/ea/documents', [
            'file' => UploadedFile::fake()->create('beleg.pdf', 1, 'application/pdf'),
            'year' => 2025,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('ea_documents', 0);
    }

    public function test_download_and_delete_are_authenticated_and_brand_scoped(): void
    {
        $token = $this->tokenFor();

        $foreign = EaDocument::create([
            'brand' => self::OTHER_BRAND,
            'year' => 2026,
            'original_name' => 'fremd.pdf',
            'storage_path' => 'ea/srp/fremd.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 5,
            'sha256' => str_repeat('f', 64),
        ]);

        $this->getJson("/api/management/ea/documents/{$foreign->id}/download")->assertUnauthorized();
        $this->withHeaders($this->bearer($token))
            ->get("/api/management/ea/documents/{$foreign->id}/download")
            ->assertNotFound();
        $this->withHeaders($this->bearer($token))
            ->deleteJson("/api/management/ea/documents/{$foreign->id}")
            ->assertNotFound();

        $file = UploadedFile::fake()->createWithContent('eigen.pdf', '%PDF-1.4 eigen');
        $upload = $this->withHeaders($this->uploadHeaders($token))->post('/api/management/ea/documents', [
            'file' => $file, 'year' => 2026,
        ]);
        $id = $upload->json('document.id');

        $this->withHeaders($this->bearer($token))
            ->get("/api/management/ea/documents/{$id}/download")
            ->assertOk();

        $this->withHeaders($this->bearer($token))
            ->deleteJson("/api/management/ea/documents/{$id}")
            ->assertOk();

        $this->assertDatabaseMissing('ea_documents', ['id' => $id]);
    }
}
