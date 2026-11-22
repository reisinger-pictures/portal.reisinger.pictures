<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Reference\EurYear;
use App\Http\Controllers\Controller;
use App\Models\EurAuditLog;
use App\Models\EurDocument;
use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Storage;
use InvalidArgumentException;
use Symfony\Component\HttpFoundation\StreamedResponse;

/**
 * EÜR · Belege (Portal #27).
 *
 * Dateien liegen ausschließlich auf dem privaten `local`-Disk unter
 * `eur/{brand}/{sha256}`. Die JSON-Antwort enthält nur Metadaten — niemals
 * `storage_path` oder einen öffentlichen URL. Der Download ist authentifiziert
 * und brand-scoped; eine fremde ID ist ein 404.
 */
class EurDocumentController extends Controller
{
    /** Feste, serverseitige Endungen — nie aus dem Dateinamen übernommen. */
    private const EXTENSIONS = [
        'application/pdf' => 'pdf',
        'image/png' => 'png',
        'image/jpeg' => 'jpeg',
    ];

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

        return response()->json([
            'documents' => $documents->map(fn (EurDocument $document): array => $this->serialize($document))->values(),
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
            return response()->json(['document' => $this->serialize($existing)]);
        }

        $extension = self::EXTENSIONS[$file->getMimeType()] ?? null;

        if ($extension === null) {
            abort(422, 'Nicht unterstützter Dateityp.');
        }

        $storagePath = sprintf('eur/%s/%s.%s', $brand, $sha256, $extension);
        Storage::disk('local')->put($storagePath, $content);

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

        $this->audit('uploaded', $document);

        return response()->json(['document' => $this->serialize($document)], 201);
    }

    public function download(int $id): StreamedResponse
    {
        $document = EurDocument::forCurrentBrand()->findOrFail($id);

        if (! Storage::disk('local')->exists($document->storage_path)) {
            abort(404, 'Belegdatei nicht gefunden');
        }

        return Storage::disk('local')->download(
            $document->storage_path,
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
     * @return array<string, mixed>
     */
    private function serialize(EurDocument $document): array
    {
        // `storage_path` wird bewusst nicht ausgeliefert.
        return [
            'id' => (int) $document->id,
            'year' => (int) $document->year,
            'month' => $document->month === null ? null : (int) $document->month,
            'receipt_no' => $document->receipt_no,
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
