<?php

declare(strict_types=1);

namespace App\Http\Controllers\Ea;

use App\Ea\Domain\MonthStatement;
use App\Ea\Reference\EaYear;
use App\Http\Controllers\Controller;
use App\Models\EaAsset;
use App\Models\EaEntry;
use App\Models\EaHour;
use App\Models\EaMonthlyReport;
use App\Models\EaSignature;
use App\Models\EaWarehouseEntry;
use App\Services\Ea\EaAusfuellhilfeService;
use App\Services\Ea\EaPdfService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Http\Response;
use InvalidArgumentException;

/**
 * EAR · PDF-Ausgaben (Monatsbericht, Eigenbeleg, Anlagenverzeichnis).
 *
 * Alle Abfragen laufen über die brand-scoped Scopes; ein fremder Datensatz
 * erscheint nie im PDF. Der Controller liefert ausschließlich PDF-Bytes mit
 * `application/pdf` — die Formatierung der Cent in Euro liegt im Dienst.
 */
class EaPdfController extends Controller
{
    public function __construct(private readonly EaPdfService $pdf) {}

    public function monthly(Request $request): Response
    {
        $data = $request->validate([
            'year' => ['required', 'integer', ...$this->yearRule()],
            'month' => 'required|integer|min:1|max:12',
        ]);

        $year = (int) $data['year'];
        $month = (int) $data['month'];

        $entries = EaEntry::forCurrentBrand()->where('year', $year)->where('month', $month)->get();
        $warehouse = EaWarehouseEntry::forCurrentBrand()->where('year', $year)->where('month', $month)->get();
        $hours = EaHour::forCurrentBrand()->where('year', $year)->where('month', $month)->get();
        $report = EaMonthlyReport::forCurrentBrand()->where('year', $year)->where('month', $month)->first();
        $signature = EaSignature::forCurrentBrand()->where('year', $year)->where('month', $month)->first();

        $statement = MonthStatement::build(
            $year,
            $month,
            $entries->map(static function (EaEntry $entry): array {
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

        return $this->response(
            $this->pdf->monthly($statement),
            'Monatsbericht-'.$year.'-'.$month.'.pdf',
        );
    }

    public function eigenbeleg(Request $request): Response
    {
        $data = $request->validate([
            'year' => ['required', 'integer', ...$this->yearRule()],
            'month' => 'required|integer|min:1|max:12',
            'description' => 'required|string|max:255',
            'amount_cents' => 'required|integer|min:0',
        ]);

        $year = (int) $data['year'];
        $month = (int) $data['month'];

        return $this->response(
            $this->pdf->eigenbeleg($year, $month, (string) $data['description'], (int) $data['amount_cents']),
            'Eigenbeleg-'.$year.'-'.$month.'.pdf',
        );
    }

    public function assets(Request $request): Response
    {
        $data = $request->validate([
            'year' => ['required', 'integer', ...$this->yearRule()],
        ]);

        $year = (int) $data['year'];
        $assets = EaAsset::forCurrentBrand()->with('years')->orderBy('name')->get();

        $rows = $assets->map(static function (EaAsset $asset): array {
            $row = $asset->toArray();
            $row['years'] = $asset->years->map(static fn ($year): array => $year->toArray())->values()->all();

            return $row;
        })->all();

        return $this->response(
            $this->pdf->assets($year, $rows),
            'Anlagenverzeichnis-'.$year.'.pdf',
        );
    }

    public function ausfuellhilfe(Request $request, EaAusfuellhilfeService $service): Response
    {
        $data = $request->validate([
            'year' => ['required', 'integer', ...$this->yearRule()],
            'e39_cents' => 'nullable|integer|min:0',
            'e40_cents' => 'nullable|integer|min:0',
            'arbeitszimmer_cents' => 'nullable|integer|min:0',
            'employment_income_cents' => 'nullable|integer|min:0',
            'rental_income_cents' => 'nullable|integer|min:0',
            'rental_forecast_cents' => 'nullable|integer|min:0',
            'prepayment_cents' => 'nullable|integer|min:0',
            'wage_tax_cents' => 'nullable|integer|min:0',
        ]);

        $year = (int) $data['year'];
        unset($data['year']);

        return $this->response(
            $this->pdf->ausfuellhilfe($service->build($year, $data)),
            'Ausfuellhilfe-'.$year.'.pdf',
        );
    }

    private function response(string $bytes, string $filename): Response
    {
        return response($bytes, 200, [
            'Content-Type' => 'application/pdf',
            'Content-Disposition' => 'inline; filename="'.$filename.'"',
        ]);
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
