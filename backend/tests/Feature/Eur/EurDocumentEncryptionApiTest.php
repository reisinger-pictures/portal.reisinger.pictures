<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Models\EurDocument;
use App\Models\EurEntry;
use App\Support\BrandRegistry;
use Ercsctt\FileEncryption\Facades\FileEncrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;

/** Portal #31 / P3 — Belege verschlüsselt ablegen und entschlüsselt ausliefern. */
class EurDocumentEncryptionApiTest extends EurBookkeepingTestCase
{
    private const PLAIN = '%PDF-1.4 VERTRAULICHER-BELEG-INHALT';

    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['file-encryption.key' => 'base64:'.base64_encode(str_repeat('X', 32))]);
        app()->forgetInstance('file.encrypter');
        FileEncrypter::clearResolvedInstances();
    }

    /**
     * @return array<string, string>
     */
    private function uploadHeaders(string $token): array
    {
        return array_merge($this->bearer($token), ['Content-Type' => 'multipart/form-data; boundary=eurboundary']);
    }

    public function test_upload_stores_ciphertext_and_download_returns_the_exact_plaintext(): void
    {
        $token = $this->tokenFor();

        $upload = $this->withHeaders($this->uploadHeaders($token))->post('/api/management/eur/documents', [
            'file' => UploadedFile::fake()->createWithContent('geheim.pdf', self::PLAIN),
            'year' => 2026,
            'month' => 5,
        ]);
        $upload->assertCreated();

        $document = EurDocument::forCurrentBrand()->findOrFail($upload->json('document.id'));
        $raw = Storage::disk('local')->get($document->storage_path);
        $this->assertIsString($raw);
        $this->assertStringNotContainsString('VERTRAULICHER-BELEG-INHALT', $raw);
        $this->assertTrue(FileEncrypter::isEncrypted(Storage::disk('local')->path($document->storage_path)));
        $this->assertStringStartsWith('eur/'.BrandRegistry::currentId().'/', $document->storage_path);

        $download = $this->withHeaders($this->bearer($token))
            ->get("/api/management/eur/documents/{$document->id}/download");
        $download->assertOk();
        $this->assertSame(self::PLAIN, $download->streamedContent());
    }

    public function test_foreign_document_id_is_a_404_on_download_and_delete(): void
    {
        $token = $this->tokenFor();
        $foreign = EurDocument::create([
            'brand' => self::OTHER_BRAND,
            'year' => 2026,
            'original_name' => 'fremd.pdf',
            'storage_path' => 'eur/srp/fremd.pdf',
            'mime_type' => 'application/pdf',
            'size_bytes' => 5,
            'sha256' => str_repeat('f', 64),
        ]);

        $this->withHeaders($this->bearer($token))
            ->get("/api/management/eur/documents/{$foreign->id}/download")
            ->assertNotFound();
        $this->withHeaders($this->bearer($token))
            ->deleteJson("/api/management/eur/documents/{$foreign->id}")
            ->assertNotFound();
    }

    public function test_index_exposes_receipt_matched_entry_ids_without_crossing_brands(): void
    {
        $token = $this->tokenFor();
        $brand = BrandRegistry::currentId();
        $attributes = [
            'year' => 2026, 'month' => 5, 'day' => 5,
            'direction' => 'expense', 'kind_type' => 'Porto', 'business_share' => 100,
            'amount_cents' => 350, 'business_amount_cents' => 350, 'receipt_no' => 'R-100',
        ];

        $own = EurEntry::create(array_merge($attributes, ['brand' => $brand]));
        EurEntry::create(array_merge($attributes, ['brand' => self::OTHER_BRAND]));

        $this->withHeaders($this->uploadHeaders($token))->post('/api/management/eur/documents', [
            'file' => UploadedFile::fake()->createWithContent('beleg.pdf', self::PLAIN),
            'year' => 2026,
            'receipt_no' => 'R-100',
        ])->assertCreated();

        $this->withHeaders($this->uploadHeaders($token))->post('/api/management/eur/documents', [
            'file' => UploadedFile::fake()->createWithContent('ohne.pdf', 'andere bytes'),
            'year' => 2026,
        ])->assertCreated();

        $response = $this->withHeaders($this->bearer($token))
            ->getJson('/api/management/eur/documents');
        $response->assertOk();

        $documents = collect($response->json('documents'));
        $linked = $documents->first(fn (array $document): bool => $document['receipt_no'] === 'R-100');
        $unlinked = $documents->first(fn (array $document): bool => $document['receipt_no'] === null);
        $this->assertNotNull($linked);
        $this->assertNotNull($unlinked);
        $this->assertSame([$own->id], $linked['linked_entry_ids']);
        $this->assertSame([], $unlinked['linked_entry_ids']);
    }

    public function test_upload_keeps_mime_and_size_validation(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->uploadHeaders($token))->post('/api/management/eur/documents', [
            'file' => UploadedFile::fake()->create('script.txt', 1, 'text/plain'),
            'year' => 2026,
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->withHeaders($this->uploadHeaders($token))->post('/api/management/eur/documents', [
            'file' => UploadedFile::fake()->create('gross.pdf', 10241, 'application/pdf'),
            'year' => 2026,
        ])->assertUnprocessable()->assertJsonValidationErrors('file');

        $this->assertDatabaseCount('eur_documents', 0);
    }
}
