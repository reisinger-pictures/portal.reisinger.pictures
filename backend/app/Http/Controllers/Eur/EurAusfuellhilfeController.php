<?php

declare(strict_types=1);

namespace App\Http\Controllers\Eur;

use App\Eur\Reference\EurYear;
use App\Http\Controllers\Controller;
use App\Services\Eur\EurAusfuellhilfeService;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

/**
 * EAR · Ausfüllhilfe-Werte für die manuelle FinanzOnline-Eingabe (D-91).
 *
 * Die Antwort ist brand-scoped und enthält ausschließlich ganze Cent, leere
 * Werte sind `null`. Optionale Eingabefelder des Formulars werden nur für
 * diesen Request übernommen und nicht gespeichert.
 */
class EurAusfuellhilfeController extends Controller
{
    public function __construct(private readonly EurAusfuellhilfeService $service) {}

    public function show(Request $request): JsonResponse
    {
        $data = $request->validate([
            'year' => ['nullable', 'integer', ...$this->yearRule()],
            'e39_cents' => 'nullable|integer|min:0',
            'e40_cents' => 'nullable|integer|min:0',
            'arbeitszimmer_cents' => 'nullable|integer|min:0',
            'employment_income_cents' => 'nullable|integer|min:0',
            'rental_income_cents' => 'nullable|integer|min:0',
            'rental_forecast_cents' => 'nullable|integer|min:0',
            'prepayment_cents' => 'nullable|integer|min:0',
            'wage_tax_cents' => 'nullable|integer|min:0',
        ]);

        $year = (int) ($data['year'] ?? EurYear::DEFAULT);

        return response()->json($this->service->build($year, $data));
    }

    /** @return array<int, mixed> */
    private function yearRule(): array
    {
        return [function (string $attribute, mixed $value, Closure $fail): void {
            try {
                EurYear::assert((int) $value);
            } catch (InvalidArgumentException) {
                $fail(EurYear::warning((int) $value)['warning']);
            }
        }];
    }
}
