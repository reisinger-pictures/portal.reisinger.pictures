<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Domain\MonthStatement;
use App\Eur\Reference\EurYear;
use App\Exceptions\DocumentContextMismatchException;
use App\Http\Controllers\Controller;
use App\Models\EurAsset;
use App\Models\EurAuditLog;
use App\Models\EurDocument;
use App\Models\EurEntry;
use App\Models\EurHour;
use App\Models\EurMonthlyReport;
use App\Models\EurSignature;
use App\Models\EurWarehouseEntry;
use App\Services\Eur\EurDocumentPositionService;
use App\Services\Eur\EurPdfFingerprintService;
use App\Services\Eur\EurPdfService;
use App\Services\Eur\EurPdfSignatureService;
use App\Services\Eur\EurReceiptOcrService;
use App\Support\BoundFileEncryption;
use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;
use Throwable;

/**
 * EA Rechnung · Belege (Portal #27).
 *
 * Dateien liegen ausschließlich verschlüsselt auf dem privaten `local`-Disk
 * unter `eur/{brand}/{sha256}`. Die Verschlüsselung läuft über
 * {@see BoundFileEncryption}: Context sind Brand und die eigene Dokument-ID,
 * der Download gibt nur nach erfolgreicher Prüfung Klartext zurück. Die
 * JSON-Antwort enthält nur Metadaten — niemals den `storage_path` des
 * Ursprungsbelegs oder eine öffentliche URL. Der Download ist authentifiziert und brand-scoped; eine
 * fremde ID ist ein 404.
 *
 * Die Berichtigungs-PDFs werden unsigned erzeugt, extern signiert und mit
 * einer Inhaltsbindung wieder eingeliefert. Zusätzlich prüft der Upload eine
 * vorhandene CMS-/PAdES-Signatur über diese Bytes (D-84, PHP-Bibliothek). Das
 * ist weiterhin keine qualifizierte elektronische Signatur: keine Trust-,
 * Ketten- oder Revocation-Prüfung und kein `pdfsig` im Deployment-Image.
 */
class EurDocumentController extends Controller
{
    /** Feste, serverseitige Endungen — nie aus dem Dateinamen übernommen. */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpeg',
    ];

    /** @var list<string> */
    private const CORRECTION_KINDS = ['monthly', 'eigenbeleg', 'assets'];

    private const SIGNED_MAX_KILOBYTES = 10240;

    public function __construct(
        private readonly BoundFileEncryption $encryption,
        private readonly EurPdfService $pdf,
        private readonly EurPdfFingerprintService $fingerprints,
        private readonly EurPdfSignatureService $signatures,
        private readonly EurDocumentPositionService $positions,
    ) {}

    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $query = EurDocument::forCurrentBrand();

        if (isset($data['year'])) {
            $query->where('year', (int) $data['year']);
        }

        if (isset($data['month'])) {
            $query->where('month', (int) $data['month']);
        }

        $documents = $query->orderByDesc('year')->orderByDesc('month')->orderByDesc('id')->get();

        // Lesende Zuordnung über die Belegnummer: es gibt keine eigene
        // Fremdschlüsselspalte in `eur_entries`, daher bleibt dies eine
        // read-only Look-up ohne Migration. Fremde Brands fallen durch den
        // brand-scoped Query heraus.
        $receiptNumbers = $documents->pluck('receipt_no')
            ->filter(static fn (mixed $value): bool => is_string($value) && $value !== '')
            ->unique()
            ->values()
            ->all();

        $entriesByReceipt = [];
        if ($receiptNumbers !== []) {
            $entriesByReceipt = EurEntry::forCurrentBrand()
                ->whereIn('receipt_no', $receiptNumbers)
                ->get(['id', 'receipt_no'])
                ->groupBy('receipt_no')
                ->map(static fn ($rows) => $rows->pluck('id')->map(static fn (mixed $id): int => (int) $id)->values()->all())
                ->all();
        }

        return response()->json([
            'documents' => $documents
                ->map(fn (EurDocument $document): array => $this->serialize($document, $entriesByReceipt))
                ->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => 'required|file|mimes:pdf,png,jpeg|max:10240',
            'year' => $this->yearRule(),
            'month' => 'nullable|integer|min:1|max:12',
            'receipt_no' => 'nullable|string|max:64',
        ]);

        $file = $request->file('file');
        $content = $file->getContent();
        $sha256 = hash('sha256', $content);
        $brand = BrandRegistry::currentId();

        // Ein erneuter Upload desselben Belegs ist idempotent: kein zweiter
        // Datensatz, keine zweite Datei und kein zweiter Audit-Eintrag.
        $existing = EurDocument::forCurrentBrand()->where('sha256', $sha256)->first();

        if ($existing !== null) {
            return response()->json(['document' => $this->serialize($existing, [])]);
        }

        $extension = self::EXTENSIONS[$file->getMimeType()] ?? null;

        if ($extension === null) {
            abort(422, 'Nicht unterstützter Dateityp.');
        }

        $storagePath = sprintf('eur/%s/%s.%s', $brand, $sha256, $extension);

        $document = EurDocument::create([
            'brand' => $brand,
            'year' => (int) ($data['year'] ?? EurYear::DEFAULT),
            'month' => isset($data['month']) ? (int) $data['month'] : null,
            'receipt_no' => $data['receipt_no'] ?? null,
            'original_name' => $file->getClientOriginalName(),
            'storage_path' => $storagePath,
            'mime_type' => $file->getMimeType(),
            'size_bytes' => $file->getSize(),
            'sha256' => $sha256,
            'status' => 'uploaded',
        ]);

        // Die ID muss aus der Datenbank stammen: sie ist Teil der im
        // Chiffre-Envelope gebundenen Contexts. Schlägt die Verschlüsselung
        // fehl, bleibt kein halb angelegter Datensatz zurück.
        try {
            $this->encryption->store($storagePath, $content, $brand, (string) $document->id);
        } catch (Throwable $e) {
            $document->delete();
            throw $e;
        }

        $this->audit('uploaded', $document);

        return response()->json(['document' => $this->serialize($document, [])], 201);
    }

    /**
     * D-82: beratende OCR-Hinweise für einen bereits hochgeladenen Bildbeleg.
     *
     * Der Endpunkt ändert nichts am Beleg und liefert keine URL oder Pfade.
     * PDFs werden nicht verarbeitet (Tesseract liest keine PDFs); fehlende
     * Sprachdaten führen zu `supported: false`, nicht zu einem Upload-Fehler.
     */
    public function ocr(EurReceiptOcrService $ocr, int $id): JsonResponse
    {
        $document = EurDocument::forCurrentBrand()->findOrFail($id);

        if (! in_array($document->mime_type, ['image/png', 'image/jpeg'], true)) {
            return response()->json([
                'ocr' => [
                    'supported' => false,
                    'reason' => 'OCR ist nur für Bildbelege (PNG/JPEG) verfügbar.',
                ],
            ]);
        }

        try {
            $bytes = $this->encryption->read(
                $document->storage_path,
                BrandRegistry::currentId(),
                (string) $document->id,
            );
        } catch (DocumentContextMismatchException) {
            abort(422, 'Der Beleg kann nicht gelesen werden.');
        }

        $text = $ocr->recognise($bytes);

        if ($text === null) {
            return response()->json([
                'ocr' => [
                    'supported' => false,
                    'reason' => 'OCR ist in dieser Umgebung nicht verfügbar.',
                ],
            ]);
        }

        return response()->json([
            'ocr' => [
                'supported' => true,
                'text' => $text,
                ...$ocr->hints($text),
            ],
        ]);
    }

    /**
     * Berichtigungs-PDF erzeugen und mit Inhaltsmarker als `unsigned` ablegen.
     *
     * Der jwt-Hash bindet die PDF-Bytes VOR dem Marker; die Zeile speichert
     * weiterhin den Hash der kompletten (markierten) Datei.
     */
    public function createCorrection(Request $request): JsonResponse
    {
        $data = $request->validate([
            'kind' => ['required', 'string', 'in:'.implode(',', self::CORRECTION_KINDS)],
            'year' => ['required', 'integer', ...$this->yearRule()],
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $kind = (string) $data['kind'];
        $year = (int) $data['year'];
        $month = isset($data['month']) ? (int) $data['month'] : null;

        if (in_array($kind, ['monthly', 'eigenbeleg'], true) && $month === null) {
            abort(422, 'Für diesen Beleg ist ein Monat erforderlich.');
        }

        $pdfBytes = $this->correctionPdf($kind, $year, $month);
        $brand = BrandRegistry::currentId();
        $marker = $this->fingerprints->issueMarker($brand, $kind, $year, $month, hash('sha256', $pdfBytes));
        $markedBytes = $this->fingerprints->appendMarker($pdfBytes, $marker);
        $markedSha256 = hash('sha256', $markedBytes);
        $storagePath = sprintf('eur/%s/%s.pdf', $brand, $markedSha256);

        $document = EurDocument::create([
            'brand' => $brand,
            'year' => $year,
            'month' => $month,
            'original_name' => $this->correctionFilename($kind, $year, $month),
            'storage_path' => $storagePath,
            'mime_type' => 'application/pdf',
            'size_bytes' => strlen($markedBytes),
            'sha256' => $markedSha256,
            'status' => 'unsigned',
        ]);

        try {
            $this->encryption->store($storagePath, $markedBytes, $brand, (string) $document->id);
        } catch (Throwable $e) {
            $document->delete();
            throw $e;
        }

        $this->audit('correction_created', $document);

        return response()->json(['document' => $this->serialize($document, [])], 201);
    }

    /**
     * Extern signierte PDF wieder einliefern.
     *
     * Drei Prüfungen müssen bestehen: die Upload-Bytes beginnen mit den exakten
     * gespeicherten Bytes (damit eine neu erzeugte Datei mit kopiertem Marker
     * scheitert), der Marker bindet Brand/Art/Jahr/Monat sowie den Hash der
     * Ursprungsbytes, und eine CMS-/PAdES-Signatur verifiziert diese Bytes.
     * Keine Trust-, Ketten- oder Revocation-Prüfung.
     */
    public function storeSigned(Request $request, int $id): JsonResponse
    {
        $document = EurDocument::forCurrentBrand()->findOrFail($id);
        $file = $request->file('file');

        if (! $file instanceof UploadedFile || ! $file->isValid()
            || $file->getMimeType() !== 'application/pdf'
            || $file->getSize() > self::SIGNED_MAX_KILOBYTES * 1024) {
            return $this->signedRejection(
                'Bitte laden Sie eine gültige PDF-Datei mit maximal 10 MB hoch.',
                'Prüfen Sie Dateityp und Dateigröße und wählen Sie die extern signierte PDF-Datei erneut aus.',
            );
        }

        $brand = BrandRegistry::currentId();

        try {
            $originalBytes = $this->encryption->read(
                $document->storage_path,
                $brand,
                (string) $document->id,
            );
        } catch (DocumentContextMismatchException) {
            abort(404, 'Belegdatei nicht gefunden');
        }

        $uploadedBytes = $file->getContent();
        $prefix = substr($uploadedBytes, 0, strlen($originalBytes));

        if (! str_starts_with($uploadedBytes, $originalBytes) || ! hash_equals($originalBytes, $prefix)) {
            return $this->signedRejection(
                'Die hochgeladene Datei enthält nicht den unveränderten Beleg aus diesem Portal.',
                'Laden Sie genau die PDF-Datei hoch, die Sie aus diesem Portal heruntergeladen und extern signiert haben.',
            );
        }

        if (! $this->fingerprints->verify(
            $originalBytes,
            $brand,
            (int) $document->year,
            $document->month === null ? null : (int) $document->month,
        )) {
            return $this->signedRejection(
                'Der Beleg-Marker fehlt, wurde verändert oder gehört zu einem anderen Beleg.',
                'Laden Sie die aus diesem Portal heruntergeladene, extern signierte PDF-Datei unverändert wieder hoch.',
            );
        }

        // D-81/D-84: zusätzlich zur Inhaltsbindung muss die PDF eine gültige
        // Signatur über genau diese Bytes enthalten. Keine Trust-/QES-Prüfung.
        if (! $this->signatures->verify($uploadedBytes, $originalBytes)) {
            return $this->signedRejection(
                'Die hochgeladene PDF-Datei enthält keine gültige Signatur über unseren Beleg.',
                'Signieren Sie die aus diesem Portal heruntergeladene PDF-Datei extern und laden Sie das signierte Ergebnis erneut hoch.',
            );
        }

        $signedSha256 = hash('sha256', $uploadedBytes);
        $signedPath = sprintf('eur/%s/signed/%d/%s.pdf', $brand, (int) $document->id, $signedSha256);

        try {
            $this->encryption->store($signedPath, $uploadedBytes, $brand, (string) $document->id);
        } catch (Throwable $e) {
            if (Storage::disk('local')->exists($signedPath)) {
                Storage::disk('local')->delete($signedPath);
            }

            throw $e;
        }

        // Die Ursprungszeile (Bytes, Pfad, Prüfsumme) bleibt unverändert.
        $document->update([
            'signed_storage_path' => $signedPath,
            'signed_sha256' => $signedSha256,
            'signed_mime_type' => 'application/pdf',
            'signed_size_bytes' => strlen($uploadedBytes),
            'signed_at' => now(),
            'status' => 'signed',
        ]);

        $this->audit('signed_uploaded', $document);

        return response()->json(['document' => $this->serialize($document, [])]);
    }

    /** Signierte Fassung brand-scoped ausliefern (Content-Identity, keine QES). */
    public function downloadSigned(int $id): StreamedResponse
    {
        $document = EurDocument::forCurrentBrand()->findOrFail($id);
        $path = $document->signed_storage_path;

        if ($path === null || ! Storage::disk('local')->exists($path)) {
            abort(404, 'Signierte Belegdatei nicht gefunden');
        }

        try {
            $plain = $this->encryption->read($path, BrandRegistry::currentId(), (string) $document->id);
        } catch (DocumentContextMismatchException) {
            abort(404, 'Signierte Belegdatei nicht gefunden');
        }

        $filename = sprintf(
            'signierter-beleg-%d-%s-%d.pdf',
            (int) $document->year,
            $document->month === null ? '00' : str_pad((string) (int) $document->month, 2, '0', STR_PAD_LEFT),
            (int) $document->id,
        );

        return response()->streamDownload(
            static function () use ($plain): void {
                echo $plain;
            },
            $filename,
            ['Content-Type' => 'application/pdf'],
        );
    }

    public function download(int $id): StreamedResponse
    {
        $document = EurDocument::forCurrentBrand()->findOrFail($id);

        if (! Storage::disk('local')->exists($document->storage_path)) {
            abort(404, 'Belegdatei nicht gefunden');
        }

        try {
            $plain = $this->encryption->read(
                $document->storage_path,
                BrandRegistry::currentId(),
                (string) $document->id,
            );
        } catch (DocumentContextMismatchException) {
            // Fehlgeschlagene Bindung ist kein Download-Fehler mit Inhalt:
            // kein Klartext, kein Context, keine unterscheidbare Meldung.
            abort(404, 'Belegdatei nicht gefunden');
        }

        return response()->streamDownload(
            static function () use ($plain): void {
                echo $plain;
            },
            $document->original_name,
            ['Content-Type' => $document->mime_type],
        );
    }

    public function destroy(int $id): JsonResponse
    {
        $document = EurDocument::forCurrentBrand()->findOrFail($id);
        $path = $document->storage_path;
        $document->delete();

        if (Storage::disk('local')->exists($path)) {
            Storage::disk('local')->delete($path);
        }

        $this->audit('deleted', $document);

        return response()->json(['success' => true]);
    }

    private function correctionPdf(string $kind, int $year, ?int $month): string
    {
        return match ($kind) {
            'monthly' => $this->pdf->monthly($this->monthStatement($year, (int) $month)),
            'eigenbeleg' => $this->pdf->eigenbeleg(
                $year,
                (int) $month,
                'Extern zu signierender Eigenbeleg',
                0,
            ),
            'assets' => $this->pdf->assets($year, $this->assetRows()),
        };
    }

    /** @return array<string, mixed> */
    private function monthStatement(int $year, int $month): array
    {
        $entries = EurEntry::forCurrentBrand()->where('year', $year)->where('month', $month)->get();
        $warehouse = EurWarehouseEntry::forCurrentBrand()->where('year', $year)->where('month', $month)->get();
        $hours = EurHour::forCurrentBrand()->where('year', $year)->where('month', $month)->get();
        $report = EurMonthlyReport::forCurrentBrand()->where('year', $year)->where('month', $month)->first();
        $signature = EurSignature::forCurrentBrand()->where('year', $year)->where('month', $month)->first();

        return MonthStatement::build(
            $year,
            $month,
            $entries->map(static function (EurEntry $entry): array {
                $row = $entry->toArray();
                $row['kind'] = $entry->direction;

                return $row;
            })->all(),
            $warehouse->toArray(),
            $hours->toArray(),
            [
                'report_status' => $report?->status,
                'closed_at' => $report?->closed_at?->toIso8601String(),
                'signature_status' => $signature?->status,
                'signature_provider' => $signature?->provider,
            ],
        );
    }

    /** @return list<array<string, mixed>> */
    private function assetRows(): array
    {
        return EurAsset::forCurrentBrand()->with('years')->orderBy('name')->get()
            ->map(static function (EurAsset $asset): array {
                $row = $asset->toArray();
                $row['years'] = $asset->years->map(static fn ($year): array => $year->toArray())->values()->all();

                return $row;
            })->all();
    }

    private function correctionFilename(string $kind, int $year, ?int $month): string
    {
        return sprintf(
            'Berichtigung-%s-%d-%s.pdf',
            $kind,
            $year,
            $month === null ? '00' : str_pad((string) $month, 2, '0', STR_PAD_LEFT),
        );
    }

    private function signedRejection(string $error, string $action): JsonResponse
    {
        return response()->json(['error' => $error, 'action' => $action], 422);
    }

    /**
     * @param  array<string, list<int>>  $entriesByReceipt
     * @return array<string, mixed>
     */
    private function serialize(EurDocument $document, array $entriesByReceipt): array
    {
        // `storage_path` wird bewusst nicht ausgeliefert.
        $allocation = $this->positions->serializeDocument($document);

        return [
            'id' => (int) $document->id,
            'year' => (int) $document->year,
            'month' => $document->month === null ? null : (int) $document->month,
            'receipt_no' => $document->receipt_no,
            'linked_entry_ids' => $document->receipt_no !== null
                ? ($entriesByReceipt[$document->receipt_no] ?? [])
                : [],
            'original_name' => $document->original_name,
            // Portal #59/D-90: Positionsdaten aus der Pivot, nie aus dem Dateipfad.
            'total_cents' => $allocation['total_cents'],
            'allocated_cents' => $allocation['allocated_cents'],
            'remaining_cents' => $allocation['remaining_cents'],
            'positions' => $allocation['positions'],
            'mime_type' => $document->mime_type,
            'size_bytes' => (int) $document->size_bytes,
            'sha256' => $document->sha256,
            'status' => $document->status,
            // Signierte Fassung: Pfad auf dem privaten Disk, keine öffentliche URL.
            'signed_storage_path' => $document->signed_storage_path,
            'signed_sha256' => $document->signed_sha256,
            'signed_mime_type' => $document->signed_mime_type,
            'signed_size_bytes' => $document->signed_size_bytes === null
                ? null
                : (int) $document->signed_size_bytes,
            'signed_at' => $document->signed_at?->toIso8601String(),
        ];
    }

    private function audit(string $action, EurDocument $document): void
    {
        EurAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => $action,
            'subject_type' => 'eur_document',
            'subject_id' => $document->id,
            'payload' => [
                'year' => $document->year,
                'month' => $document->month,
                'mime_type' => $document->mime_type,
                'size_bytes' => $document->size_bytes,
                'sha256' => $document->sha256,
            ],
        ]);
    }

    /**
     * @return array<int, mixed>
     */
    private function yearRule(): array
    {
        return ['nullable', 'integer', function (string $attribute, mixed $value, Closure $fail): void {
            try {
                EurYear::assert((int) $value);
            } catch (InvalidArgumentException) {
                $fail(EurYear::warning((int) $value)['warning']);
            }
        }];
    }
}
