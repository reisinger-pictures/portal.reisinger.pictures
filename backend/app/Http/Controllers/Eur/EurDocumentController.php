<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Reference\EurYear;
use App\Exceptions\DocumentContextMismatchException;
use App\Http\Controllers\Controller;
use App\Models\EurAuditLog;
use App\Models\EurDocument;
use App\Models\EurEntry;
use App\Support\BoundFileEncryption;
use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
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
 * JSON-Antwort enthält nur Metadaten — niemals `storage_path` oder eine
 * öffentliche URL. Der Download ist authentifiziert und brand-scoped; eine
 * fremde ID ist ein 404.
 */
class EurDocumentController extends Controller
{
    /** Feste, serverseitige Endungen — nie aus dem Dateinamen übernommen. */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpeg',
    ];

    public function __construct(private readonly BoundFileEncryption $encryption) {}

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

    /**
     * @param  array<string, list<int>>  $entriesByReceipt
     * @return array<string, mixed>
     */
    private function serialize(EurDocument $document, array $entriesByReceipt): array
    {
        // `storage_path` wird bewusst nicht ausgeliefert.
        return [
            'id' => (int) $document->id,
            'year' => (int) $document->year,
            'month' => $document->month === null ? null : (int) $document->month,
            'receipt_no' => $document->receipt_no,
            'linked_entry_ids' => $document->receipt_no !== null
                ? ($entriesByReceipt[$document->receipt_no] ?? [])
                : [],
            'original_name' => $document->original_name,
            'mime_type' => $document->mime_type,
            'size_bytes' => (int) $document->size_bytes,
            'sha256' => $document->sha256,
            'status' => $document->status,
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
