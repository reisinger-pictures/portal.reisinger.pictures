<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ea;

use App\Ea\Reference\EaYear;
use App\Http\Controllers\Controller;
use App\Models\EaAuditLog;
use App\Models\EaDocument;
use App\Models\EaSignature;
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
class EaSignatureController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
        ]);

        $query = EaSignature::forCurrentBrand();

        if (isset($data['year'])) {
            $query->where('year', (int) $data['year']);
        }

        $signatures = $query->orderBy('year')->orderBy('month')->get();

        return response()->json([
            'signatures' => $signatures->map(fn (EaSignature $signature): array => $this->serialize($signature))->values(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => $this->yearRule(),
            'month' => 'required|integer|min:1|max:12',
            'ea_document_id' => 'nullable|integer',
            'provider' => 'required|string|max:64',
            'status' => 'nullable|in:pending,signed,failed',
            'note' => 'nullable|string|max:2000',
        ]);

        if (! empty($data['ea_document_id'])) {
            EaDocument::forCurrentBrand()->findOrFail((int) $data['ea_document_id']);
        }

        $year = (int) ($data['year'] ?? EaYear::currentOrLast());
        $month = (int) $data['month'];
        $status = $data['status'] ?? 'pending';

        $existing = EaSignature::forCurrentBrand()
            ->where('year', $year)
            ->where('month', $month)
            ->first();

        $signature = EaSignature::updateOrCreate(
            [
                'brand' => BrandRegistry::currentId(),
                'year' => $year,
                'month' => $month,
            ],
            [
                'ea_document_id' => isset($data['ea_document_id']) ? (int) $data['ea_document_id'] : null,
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
            EaAuditLog::create([
                'brand' => BrandRegistry::currentId(),
                'user_id' => auth('api')->id(),
                'action' => 'signed',
                'subject_type' => 'ea_signature',
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
    private function serialize(EaSignature $signature): array
    {
        return [
            'id' => (int) $signature->id,
            'year' => (int) $signature->year,
            'month' => (int) $signature->month,
            'ea_document_id' => $signature->ea_document_id === null ? null : (int) $signature->ea_document_id,
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
                EaYear::assertBookable((int) $value);
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        }];
    }
}
