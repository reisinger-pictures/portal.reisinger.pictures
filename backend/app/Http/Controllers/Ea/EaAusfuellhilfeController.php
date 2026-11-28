<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ea;

use App\Ea\Reference\EaYear;
use App\Http\Controllers\Controller;
use App\Services\Ea\EaAusfuellhilfeService;
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
class EaAusfuellhilfeController extends Controller
{
    public function __construct(private readonly EaAusfuellhilfeService $service) {}

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

        $year = (int) ($data['year'] ?? EaYear::currentOrLast());

        return response()->json($this->service->build($year, $data));
    }

    /** @return array<int, mixed> */
    private function yearRule(): array
    {
        return [function (string $attribute, mixed $value, Closure $fail): void {
            try {
                EaYear::assertBookable((int) $value);
            } catch (InvalidArgumentException $exception) {
                $fail($exception->getMessage());
            }
        }];
    }
}
