<?php

declare(strict_types=1);

namespace Tests\Feature\Ea;

use App\Models\EaDocument;
use App\Services\Ea\EaPdfFingerprintService;
use App\Services\Ea\EaPdfSignatureService;
use App\Services\OfferTokenService;
use App\Support\BoundFileEncryption;
use App\Support\BrandRegistry;
use Ercsctt\FileEncryption\Facades\FileEncrypter;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Illuminate\Testing\TestResponse;

/**
 * EAR · Unsigned Berichtigungs-PDF, externe Signatur, Wiedereingang mit
 * Content-Identity- und echter CMS-/PAdES-Prüfung (D-81, D-84). Keine Trust-,
 * Ketten- oder Revocation-Prüfung und keine QES-Behauptung.
 */
class EaDocumentSignedUploadApiTest extends EaBookkeepingTestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Storage::fake('local');
        config(['file-encryption.key' => 'base64:'.base64_encode(str_repeat('X', 32))]);
        app()->forgetInstance('file.encrypter');
        FileEncrypter::clearResolvedInstances();
    }

    /** Symfony 8 setzt bei Test-Uploads keinen Multipart-Content-Type mehr. */
    private function uploadHeaders(string $token): array
    {
        return array_merge($this->bearer($token), ['Content-Type' => 'multipart/form-data; boundary=eurboundary']);
    }

    public function test_correction_creates_an_unsigned_document_with_a_content_bound_marker(): void
    {
        $token = $this->tokenFor();
        $document = $this->createCorrection($token, 'monthly', 2026, 5);

        $this->assertSame('unsigned', $document['status']);
        $this->assertSame(2026, $document['year']);
        $this->assertSame(5, $document['month']);
        $this->assertNull($document['signed_storage_path']);
        $this->assertNull($document['signed_sha256']);
        $this->assertNull($document['signed_at']);
        $this->assertSame('Berichtigung-monthly-2026-05.pdf', $document['original_name']);

        $row = EaDocument::forCurrentBrand()->findOrFail($document['id']);
        $marked = $this->storedBytes($row);
        $this->assertSame(hash('sha256', $marked), $row->sha256);
        $this->assertSame(hash('sha256', $marked), $document['sha256']);
        $this->assertSame(strlen($marked), (int) $row->size_bytes);

        preg_match('/%EURDOC:([A-Za-z0-9_\.\-]+)%/', $marked, $matches);
        $this->assertNotSame([], $matches);
        $payload = app(OfferTokenService::class)->verify($matches[1]);
        $this->assertIsArray($payload);
        $this->assertSame('ea_correction', $payload['type']);
        $this->assertSame(BrandRegistry::currentId(), $payload['brand']);
        $this->assertSame('monthly', $payload['kind']);
        $this->assertSame(2026, $payload['year']);
        $this->assertSame(5, $payload['month']);
        $this->assertSame($payload['sha256'], hash('sha256', $this->preMarkerBytes($marked)));
        $this->assertDatabaseHas('ea_audit_logs', ['action' => 'correction_created', 'subject_type' => 'ea_document']);
    }

    public function test_eigenbeleg_and_assets_corrections_are_generated(): void
    {
        $token = $this->tokenFor();

        foreach (['eigenbeleg', 'assets'] as $kind) {
            $document = $this->createCorrection($token, $kind, 2026, 7);
            $this->assertSame('unsigned', $document['status']);
            $this->assertStringStartsWith('%PDF-', $this->storedBytes(EaDocument::findOrFail($document['id'])));
        }
    }

    public function test_signed_upload_binds_the_content_and_leaves_the_original_row_untouched(): void
    {
        $token = $this->tokenFor();
        $document = $this->createCorrection($token, 'monthly', 2026, 5);
        $row = EaDocument::findOrFail($document['id']);
        $original = $this->storedBytes($row);
        $signedBytes = $this->signedBytes($original);

        $this->uploadSigned($token, (int) $document['id'], $signedBytes)
            ->assertOk()
            ->assertJsonPath('document.status', 'signed')
            ->assertJsonPath('document.signed_sha256', hash('sha256', $signedBytes))
            ->assertJsonPath('document.signed_mime_type', 'application/pdf')
            ->assertJsonPath('document.signed_size_bytes', strlen($signedBytes));

        $updated = EaDocument::findOrFail($document['id']);
        $this->assertSame($row->storage_path, $updated->storage_path);
        $this->assertSame($row->sha256, $updated->sha256);
        $this->assertSame((int) $row->size_bytes, (int) $updated->size_bytes);
        $this->assertSame($row->mime_type, $updated->mime_type);
        $this->assertSame($original, $this->storedBytes($updated));
        $this->assertNotNull($updated->signed_at);
        $this->assertNotNull($updated->signed_storage_path);

        $this->assertTrue(Storage::disk('local')->exists($updated->signed_storage_path));
        $this->assertTrue(FileEncrypter::isEncrypted(Storage::disk('local')->path($updated->signed_storage_path)));
        $this->assertSame($signedBytes, app(BoundFileEncryption::class)->read(
            $updated->signed_storage_path,
            BrandRegistry::currentId(),
            (string) $updated->id,
        ));

        $originalDownload = $this->withHeaders($this->bearer($token))
            ->get("/api/management/ea/documents/{$updated->id}/download");
        $originalDownload->assertOk();
        $this->assertSame($original, $originalDownload->streamedContent());

        $signedDownload = $this->withHeaders($this->bearer($token))
            ->get("/api/management/ea/documents/{$updated->id}/signed/download");
        $signedDownload->assertOk();
        $this->assertSame($signedBytes, $signedDownload->streamedContent());
        $this->assertStringContainsString('signierter-beleg-2026-05', $signedDownload->headers->get('content-disposition'));

        $this->assertDatabaseHas('ea_audit_logs', ['action' => 'signed_uploaded', 'subject_type' => 'ea_document']);
    }

    public function test_test_only_route_produces_a_verifiable_signature(): void
    {
        $token = $this->tokenFor();
        $document = $this->createCorrection($token, 'monthly', 2026, 5);
        $original = $this->storedBytes(EaDocument::findOrFail($document['id']));

        $response = $this->postJson("/api/test/ea/sign-document/{$document['id']}")->assertOk();
        $signed = $response->getContent();
        $this->assertIsString($signed);
        $this->assertTrue(app(EaPdfSignatureService::class)->verify($signed, $original));
    }

    public function test_upload_without_a_valid_pades_signature_is_rejected(): void
    {
        $token = $this->tokenFor();
        $document = $this->createCorrection($token, 'monthly', 2026, 5);
        $original = $this->storedBytes(EaDocument::findOrFail($document['id']));

        $this->uploadSigned($token, (int) $document['id'], $original."\n%FAKE-SIGNATURE%\n")
            ->assertUnprocessable()
            ->assertJsonPath('error', 'Die hochgeladene PDF-Datei enthält keine gültige Signatur über unseren Beleg.')
            ->assertJsonStructure(['error', 'action']);

        $this->assertSame('unsigned', EaDocument::findOrFail($document['id'])->status);
        $this->assertNull(EaDocument::findOrFail($document['id'])->signed_storage_path);
    }

    public function test_a_new_file_with_a_copied_marker_is_rejected(): void
    {
        $token = $this->tokenFor();
        $document = $this->createCorrection($token, 'monthly', 2026, 5);
        $marked = $this->storedBytes(EaDocument::findOrFail($document['id']));
        $forged = "%PDF-1.7 neu erzeugt\n%%EOF".$this->markerFrom($marked);

        $this->uploadSigned($token, (int) $document['id'], $forged)
            ->assertUnprocessable()
            ->assertJsonStructure(['error', 'action']);

        $this->assertSame('unsigned', EaDocument::findOrFail($document['id'])->status);
        $this->assertNull(EaDocument::findOrFail($document['id'])->signed_storage_path);
    }

    public function test_tampered_foreign_and_mismatched_markers_are_rejected(): void
    {
        $token = $this->tokenFor();
        $document = $this->createCorrection($token, 'monthly', 2026, 5);
        $id = (int) $document['id'];
        $marked = $this->storedBytes(EaDocument::findOrFail($id));

        preg_match('/%EURDOC:([A-Za-z0-9_\.\-]+)%/', $marked, $matches, PREG_OFFSET_CAPTURE);
        [$jwt, $jwtOffset] = $matches[1];
        $last = substr($jwt, -1);
        $tampered = substr_replace($marked, $last === 'A' ? 'B' : 'A', $jwtOffset + strlen($jwt) - 1, 1);

        $this->uploadSigned($token, $id, $tampered)->assertUnprocessable()->assertJsonStructure(['error', 'action']);
        $this->uploadSigned($token, $id, '%PDF-1.4 ohne marker')->assertUnprocessable()->assertJsonStructure(['error', 'action']);

        $originalBytes = $this->preMarkerBytes($marked);
        $foreignMarker = app(EaPdfFingerprintService::class)->issueMarker(
            self::OTHER_BRAND,
            'monthly',
            2026,
            5,
            hash('sha256', $originalBytes),
        );
        $this->uploadSigned($token, $id, $originalBytes.$foreignMarker)
            ->assertUnprocessable()
            ->assertJsonStructure(['error', 'action']);

        $wrongContext = app(EaPdfFingerprintService::class)->issueMarker(
            BrandRegistry::currentId(),
            'monthly',
            2025,
            5,
            hash('sha256', $originalBytes),
        );
        $this->uploadSigned($token, $id, $originalBytes.$wrongContext)
            ->assertUnprocessable()
            ->assertJsonStructure(['error', 'action']);

        $this->assertSame('unsigned', EaDocument::findOrFail($id)->status);
        $this->assertNull(EaDocument::findOrFail($id)->signed_storage_path);
    }

    public function test_signed_upload_validates_mime_type_and_size(): void
    {
        $token = $this->tokenFor();
        $document = $this->createCorrection($token, 'monthly', 2026, 5);
        $id = (int) $document['id'];

        $this->withHeaders($this->uploadHeaders($token))
            ->post("/api/management/ea/documents/{$id}/signed", [
                'file' => UploadedFile::fake()->create('script.txt', 1, 'text/plain'),
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error', 'action']);

        $this->withHeaders($this->uploadHeaders($token))
            ->post("/api/management/ea/documents/{$id}/signed", [
                'file' => UploadedFile::fake()->create('gross.pdf', 10241, 'application/pdf'),
            ])
            ->assertUnprocessable()
            ->assertJsonStructure(['error', 'action']);

        $this->assertNull(EaDocument::findOrFail($id)->signed_storage_path);
    }

    public function test_signed_routes_require_authentication_and_are_brand_scoped(): void
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

        $this->postJson('/api/management/ea/documents/corrections', [])->assertUnauthorized();
        $this->postJson("/api/management/ea/documents/{$foreign->id}/signed", [])->assertUnauthorized();
        $this->getJson("/api/management/ea/documents/{$foreign->id}/signed/download")->assertUnauthorized();

        $this->withHeaders($this->uploadHeaders($token))
            ->post("/api/management/ea/documents/{$foreign->id}/signed", [
                'file' => UploadedFile::fake()->createWithContent('fremd.pdf', '%PDF-1.4'),
            ])
            ->assertNotFound();
        $this->withHeaders($this->bearer($token))
            ->get("/api/management/ea/documents/{$foreign->id}/signed/download")
            ->assertNotFound();
    }

    public function test_correction_validation_rejects_unknown_kind_year_and_missing_month(): void
    {
        $token = $this->tokenFor();

        $this->withHeaders($this->bearer($token))->postJson('/api/management/ea/documents/corrections', [
            'kind' => 'unknown', 'year' => 2026, 'month' => 5,
        ])->assertUnprocessable();
        $this->withHeaders($this->bearer($token))->postJson('/api/management/ea/documents/corrections', [
            'kind' => 'monthly', 'year' => 2025, 'month' => 5,
        ])->assertUnprocessable();
        $this->withHeaders($this->bearer($token))->postJson('/api/management/ea/documents/corrections', [
            'kind' => 'monthly', 'year' => 2026,
        ])->assertUnprocessable();

        $this->assertDatabaseCount('ea_documents', 0);
    }

    /**
     * @return array<string, mixed>
     */
    private function createCorrection(string $token, string $kind, int $year, int $month): array
    {
        $response = $this->withHeaders($this->bearer($token))->postJson('/api/management/ea/documents/corrections', [
            'kind' => $kind,
            'year' => $year,
            'month' => $month,
        ]);
        $response->assertCreated();

        /** @var array<string, mixed> $document */
        $document = $response->json('document');

        return $document;
    }

    private function uploadSigned(string $token, int $id, string $bytes): TestResponse
    {
        return $this->withHeaders($this->uploadHeaders($token))->post("/api/management/ea/documents/{$id}/signed", [
            'file' => UploadedFile::fake()->createWithContent('signiert.pdf', $bytes),
        ]);
    }

    private function storedBytes(EaDocument $document): string
    {
        return app(BoundFileEncryption::class)->read(
            $document->storage_path,
            BrandRegistry::currentId(),
            (string) $document->id,
        );
    }

    private function preMarkerBytes(string $markedBytes): string
    {
        preg_match('/%EURDOC:[A-Za-z0-9_\.\-]+%/', $markedBytes, $matches, PREG_OFFSET_CAPTURE);

        return substr($markedBytes, 0, $matches[0][1] - 1);
    }

    private function markerFrom(string $markedBytes): string
    {
        preg_match('/%EURDOC:[A-Za-z0-9_\.\-]+%/', $markedBytes, $matches);

        return "\n".$matches[0]."\n";
    }
}
