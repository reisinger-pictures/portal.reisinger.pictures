<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Reference\EurYear;
use App\Http\Controllers\Controller;
use App\Models\EurAuditLog;
use App\Models\EurDocument;
use App\Models\EurSignature;
use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * EAR · Signaturen (Portal #27).
 *
 * Je Brand/Jahr/Monat existiert genau eine Signatur; ein erneuter POST ist
 * idempotent (200, kein zweiter Datensatz). Provider mit `test` im Namen
 * werden als Testprovider gekennzeichnet und nie als echte Signatur gewertet.
 */
class EurSignatureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
        ]);

        $query = EurSignature::forCurrentBrand();

        if (isset($data['year'])) {
            $query->where('year', (int) $data['year']);
        }

        $signatures = $query->orderBy('year')->orderBy('month')->get();

        return response()->json([
            'signatures' => $signatures->map(fn (EurSignature $signature): array => $this->serialize($signature))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
            'month' => 'required|integer|min:1|max:12',
            'eur_document_id' => 'nullable|integer',
            'provider' => 'required|string|max:64',
            'status' => 'nullable|in:pending,signed,failed',
            'note' => 'nullable|string|max:2000',
        ]);

        if (! empty($data['eur_document_id'])) {
            EurDocument::forCurrentBrand()->findOrFail((int) $data['eur_document_id']);
        }

        $year = (int) ($data['year'] ?? EurYear::DEFAULT);
        $month = (int) $data['month'];
        $status = $data['status'] ?? 'pending';

        $existing = EurSignature::forCurrentBrand()
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $signature = EurSignature::updateOrCreate(
            [
                'brand' => BrandRegistry::currentId(),
                'year' => $year,
                'month' => $month,
            ],
            [
                'eur_document_id' => isset($data['eur_document_id']) ? (int) $data['eur_document_id'] : null,
                'provider' => $data['provider'],
                'status' => $status,
                'note' => $data['note'] ?? null,
            ],
        );

        if ($status === 'signed' && $signature->signed_at === null) {
            $signature->signed_at = now();
            $signature->save();
        }

        if ($existing === null || $existing->status !== $signature->status || $existing->provider !== $signature->provider) {
            EurAuditLog::create([
                'brand' => BrandRegistry::currentId(),
                'user_id' => auth('api')->id(),
                'action' => 'signed',
                'subject_type' => 'eur_signature',
                'subject_id' => $signature->id,
                'payload' => [
                    'year' => $signature->year,
                    'month' => $signature->month,
                    'provider' => $signature->provider,
                    'status' => $signature->status,
                    'is_test' => $this->isTest($signature->provider),
                ],
            ]);
        }

        return response()->json([
            'signature' => $this->serialize($signature->refresh()),
            'is_test' => $this->isTest($signature->provider),
        ]);
    }

    private function isTest(string $provider): bool
    {
        return str_contains(mb_strtolower($provider), 'test');
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EurSignature $signature): array
    {
        return [
            'id' => (int) $signature->id,
            'year' => (int) $signature->year,
            'month' => (int) $signature->month,
            'eur_document_id' => $signature->eur_document_id === null ? null : (int) $signature->eur_document_id,
            'provider' => $signature->provider,
            'status' => $signature->status,
            'signed_at' => $signature->signed_at?->toIso8601String(),
            'note' => $signature->note,
            'is_test' => $this->isTest($signature->provider),
        ];
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
