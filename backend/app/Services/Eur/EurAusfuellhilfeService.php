<?php

declare(strict_types=1);

namespace App\Services\Eur;

use App\Eur\Domain\E1Statement;
use App\Eur\Domain\IncomeTaxService;
use App\Eur\Domain\VatReturnService;
use App\Eur\Parity\Money;
use App\Eur\Parity\RuleValues;
use App\Eur\Reference\E1aHelpTexts;
use App\Eur\Reference\EurYear;
use App\Eur\Reference\IncomeTaxTariff;
use App\Eur\Reference\ReferenceTables;
use App\Models\EurAssetYear;
use App\Models\EurEntry;
use App\Models\EurHour;
use App\Models\EurTravelTrip;
use App\Models\EurWarehouseEntry;
use App\Services\SettingResolver;

/**
 * EAR · Werte für die manuelle FinanzOnline-Eingabe (D-91, #62/#63).
 *
 * Der Dienst liefert ausschließlich vorhandene Domänenwerte in der Reihenfolge
 * der gepinnten ODS-Ausfüllhilfe. Es gibt keinen ELSTER/FinanzOnline-Client,
 * keine elektronische Übermittlung und keine neue Steuerregel. Alle Beträge
 * bleiben ganze Cent; `null` bedeutet „leer" und wird nie als 0 ausgewiesen.
 * Die Marke kommt aus den brand-scoped Eloquent-Abfragen, nicht aus Parametern.
 */
final class EurAusfuellhilfeService
{
    private const NOTICE = 'Manuelle Eingabe in FinanzOnline. Keine elektronische Übermittlung.';

    /** @var array<string, array{code: string, text_de: string}> */
    private const DEVIATIONS = [
        'D-79' => ['code' => 'D-79', 'text_de' => '110,22 € statt 101,95 € (ODS-Defekt)'],
        'D-85' => ['code' => 'D-85', 'text_de' => 'Private Eigenbelege ohne Buchung'],
        'D-86' => ['code' => 'D-86', 'text_de' => 'Eine Reise = ein Beleg'],
        'D-89' => ['code' => 'D-89', 'text_de' => 'Gruppe = eine Abrechnung'],
        'D-90' => ['code' => 'D-90', 'text_de' => 'Positionen statt einzelner Belege'],
        'D-88' => ['code' => 'D-88', 'text_de' => 'U23 = Endsteuer des Gesamteinkommens'],
        'D-87' => ['code' => 'D-87', 'text_de' => 'KZ 066 unentschieden, keine Vorsteuer'],
    ];

    public function __construct(
        private readonly EurOverviewService $overview,
        private readonly EurSvBookingReminder $reminder,
        private readonly SettingResolver $settings,
        private readonly RuleValues $rules,
    ) {}

    /**
     * @param  array<string, mixed>  $input  nur Eingabefelder des Formulars, nie persistiert
     * @return array<string, mixed>
     */
    public function build(int $year, array $input = []): array
    {
        EurYear::assert($year);

        $entries = EurEntry::forCurrentBrand()->where('year', $year)->get()->all();
        $warehouse = EurWarehouseEntry::forCurrentBrand()->where('year', $year)->get()->all();
        $trips = EurTravelTrip::forCurrentBrand()->where('year', $year)->get()->all();
        $assetYears = EurAssetYear::forCurrentBrand()->where('year', $year)->with('asset')->get()->all();
        $hours = EurHour::forCurrentBrand()->where('year', $year)->get()->all();

        $journal = $this->journalRows($entries);
        $vat = VatReturnService::forYear($year, $journal);
        $annual = E1Statement::annual([
            'A' => $journal,
            'WEB' => $this->warehouseRows($warehouse),
            'AV' => $this->assetRows($assetYears),
            'FR' => $this->travelRows($trips),
        ], $year);

        $tariff = IncomeTaxTariff::forYear($year);
        $taxService = new IncomeTaxService($tariff);
        $bookedSv = (int) ($annual['expense']['9225'] ?? 0);
        $mode = (string) ($this->settings->get('eur_sv_mode', 'gsvg') ?? 'gsvg');
        $profit = (int) $annual['profit_cents'];
        $allowance = $tariff->basicAllowanceCents($profit);
        $taxable = $profit - $allowance;

        $employmentIncome = $this->inputCents($input, 'employment_income_cents');
        $rentalResult = $this->inputCents($input, 'rental_income_cents');
        $allocation = $taxService->allocate(
            IncomeTaxService::MODE_TOTAL,
            $taxable + $employmentIncome + $rentalResult,
            $employmentIncome,
            $rentalResult,
            $this->inputCents($input, 'prepayment_cents'),
            $this->inputCents($input, 'wage_tax_cents'),
        );

        $svMonthly = $this->overview->svMonthly($year, $profit, $bookedSv);
        $floor = $this->overview->svMonthly($year, 0, 0)[0] ?? [];
        $minimumSv = $this->rowSum($floor);
        $annualSv = 0;

        foreach ($svMonthly as $row) {
            $annualSv += $this->rowSum($row);
        }

        $monthly = $taxService->monthlyResults($year, IncomeTaxService::MODE_TOTAL, [
            'tax_cents' => (int) $allocation['tax_cents'],
            'employment_tax_cents' => (int) $allocation['employment_tax_cents'],
            'business_tax_cents' => (int) $allocation['business_tax_cents'],
            'rental_tax_cents' => (int) $allocation['rental_tax_cents'],
            'sv_cents' => $annualSv,
            'minimum_sv_cents' => $minimumSv,
        ], $this->monthInputs($journal, $hours));

        $order = 0;
        $sections = [];

        $sections[] = [
            'id' => 'E1A',
            'title_de' => 'Einkommensteuererklärung E1A',
            'fields' => $this->e1aFields($order, $year, $annual, $tariff, $profit, $allowance, $taxable, $input),
        ];
        $sections[] = [
            'id' => 'E1B',
            'title_de' => 'E1B Vermietung und Verpachtung',
            'fields' => $this->e1bFields($order, $annual),
        ];
        $sections[] = [
            'id' => 'UST',
            'title_de' => 'Umsatzsteuer',
            'fields' => $this->vatFields($order, $vat),
        ];
        $sections[] = [
            'id' => 'UEBERSICHT',
            'title_de' => 'Übersicht und Einkommensteuer',
            'fields' => $this->overviewFields(
                $order,
                $allocation,
                $monthly,
                $svMonthly,
                $mode,
                $bookedSv,
                $this->reminder->forYear($year, collect($entries)),
                $input,
            ),
        ];
        $sections[] = [
            'id' => 'ABGLEICH',
            'title_de' => 'Jahres- und Monatskontrolle',
            'fields' => $this->reconciliationFields($order, $annual, $assetYears, $journal, $hours),
        ];

        return [
            'year' => $year,
            'units' => ['money' => 'cents', 'time' => 'minutes', 'rate' => 'per-10000'],
            'notice' => self::NOTICE,
            'sections' => $sections,
            'empty_is_null' => true,
        ];
    }

    /**
     * @param  list<array<string, mixed>>  $entries
     * @return list<array<string, mixed>>
     */
    private function journalRows(array $entries): array
    {
        $rows = [];

        foreach ($entries as $entry) {
            $row = $this->toArray($entry);

            if ($row === null) {
                continue;
            }

            $rows[] = [
                'code' => (string) ($row['e1a_code'] ?? ''),
                'kind' => (string) ($row['direction'] ?? ''),
                'business_amount_cents' => (int) ($row['business_amount_cents'] ?? 0),
                'month' => (int) ($row['month'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * EUE-045/047: der gespeicherte Ausgaben-Art-Name wird über die gepinnte
     * Tabelle auf seine E1A-Kennzahl abgebildet; ohne Zuordnung gilt 9100.
     *
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function warehouseRows(array $rows): array
    {
        $codes = [];

        foreach (ReferenceTables::load()->expenseKinds() as $kind) {
            if (preg_match('/\A\d+/', (string) ($kind['e1a'] ?? ''), $matches) === 1) {
                $codes[(string) ($kind['type'] ?? '')] = $matches[0];
            }
        }

        $result = [];

        foreach ($rows as $row) {
            $row = $this->toArray($row);

            if ($row === null) {
                continue;
            }

            $share = (int) ($row['business_share'] ?? 100);
            $result[] = [
                'code' => $codes[(string) ($row['expense_kind'] ?? '')] ?? '9100',
                'kind' => 'expense',
                'business_amount_cents' => Money::applyShare(
                    (int) ($row['amount_cents'] ?? 0),
                    bcdiv((string) $share, '100', 8),
                ),
            ];
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function assetRows(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $row = $this->toArray($row);

            if ($row === null) {
                continue;
            }

            // D-85: das private Verzeichnis erzeugt nur einen Eigenbeleg,
            // nie eine steuerliche Abschreibung.
            if (($row['scope'] ?? null) === 'private') {
                continue;
            }

            // Bereits gebuchte Jahresabschlüsse liegen als A-Zeile im Journal;
            // sonst würde dieselbe AfA in 9130/9134 doppelt gezählt.
            if (isset($row['eur_entry_id']) && $row['eur_entry_id'] !== null) {
                continue;
            }

            $result[] = [
                'code' => ($row['method'] ?? null) === 'degressive' ? '9134' : '9130',
                'kind' => 'expense',
                'business_amount_cents' => (int) ($row['business_use_cents'] ?? 0),
            ];
        }

        return $result;
    }

    /**
     * @param  list<array<string, mixed>>  $rows
     * @return list<array<string, mixed>>
     */
    private function travelRows(array $rows): array
    {
        $result = [];

        foreach ($rows as $row) {
            $row = $this->toArray($row);

            if ($row === null) {
                continue;
            }

            $code = (string) ($row['e1a_code'] ?? '');

            $result[] = [
                'code' => $code !== '' ? $code : '9160',
                'kind' => 'expense',
                'business_amount_cents' => (int) ($row['business_cents'] ?? 0),
            ];
        }

        return $result;
    }

    /** @return array<int, array{gross_profit_cents: int, hours_minutes: int}> */
    private function monthInputs(array $journal, array $hours): array
    {
        $profits = [];
        $minutes = [];

        foreach ($journal as $row) {
            $month = (int) ($row['month'] ?? 0);

            if ($month < 1 || $month > 12) {
                continue;
            }

            $amount = (int) ($row['business_amount_cents'] ?? 0);
            $profits[$month] = ($profits[$month] ?? 0)
                + (($row['kind'] ?? '') === 'income' ? $amount : -$amount);
        }

        foreach ($hours as $row) {
            $row = $this->toArray($row);

            if ($row === null) {
                continue;
            }

            $month = (int) ($row['month'] ?? 0);

            if ($month >= 1 && $month <= 12) {
                $minutes[$month] = ($minutes[$month] ?? 0) + (int) ($row['minutes'] ?? 0);
            }
        }

        $result = [];

        foreach ($profits as $month => $profit) {
            $result[$month] = [
                'gross_profit_cents' => $profit,
                'hours_minutes' => $minutes[$month] ?? 0,
            ];
        }

        return $result;
    }

    /**
     * @param  array<string, mixed>  $annual
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    private function e1aFields(
        int &$order,
        int $year,
        array $annual,
        IncomeTaxTariff $tariff,
        int $profit,
        int $allowance,
        int $taxable,
        array $input,
    ): array {
        $fields = [];
        $cells = ['9040' => 'E11', '9050' => 'E12', '9060' => 'E13', '9090' => 'E14'];

        foreach ($cells as $code => $cell) {
            $fields[] = $this->money(
                $order,
                'E1A',
                $cell,
                (string) $code,
                $this->label((string) $code),
                (int) ($annual['income'][$code] ?? 0),
                'e1.income.'.$code,
            );
        }

        $expenseCodes = [];

        foreach (E1aHelpTexts::all() as $help) {
            $code = $help['kz'];

            if (in_array($code, ['9040', '9050', '9060', '9090', '9216', '9215', '9217'], true)) {
                continue;
            }

            if (! in_array($code, $expenseCodes, true)) {
                $expenseCodes[] = $code;
            }
        }

        foreach ($expenseCodes as $index => $code) {
            $deviations = match ($code) {
                '9160' => ['D-86'],
                '9130', '9134', '9135' => ['D-89', 'D-90'],
                '9210' => ['D-85'],
                default => [],
            };
            $fields[] = $this->money(
                $order,
                'E1A',
                'E'.(17 + $index),
                $code,
                $this->label((string) $code),
                (int) ($annual['expense'][$code] ?? 0),
                'e1.expense.'.$code,
                $deviations,
            );
        }

        $basic = $tariff->basicAllowance();

        $fields[] = $this->money($order, 'E1A', 'J6', null, 'Höchstgrenze Grundfreibetrag',
            (int) $basic['limit_cents'], 'reference.income_tax.basic_allowance.limit_cents');
        $fields[] = $this->rate($order, 'E1A', 'J7', null, 'Satz Grundfreibetrag',
            (int) $basic['rate_per_10000'], 'reference.income_tax.basic_allowance.rate_per_10000');
        $fields[] = $this->money($order, 'E1A', 'K4', null, 'Jahresgewinn', $profit, 'e1.profit_cents');
        $fields[] = $this->money($order, 'E1A', 'K5', null, 'Grundfreibetrag', $allowance,
            'reference.income_tax.basic_allowance_cents');
        $fields[] = $this->money($order, 'E1A', 'K9', '330', 'Steuerpflichtiger Gewinn', $taxable,
            'reference.income_tax.taxable_cents');

        $arbeitszimmer = (int) ($annual['expense']['9275'] ?? 0);
        $inputArbeitszimmer = $this->inputCents($input, 'arbeitszimmer_cents');
        $suppressed = $arbeitszimmer > 0 || $inputArbeitszimmer > 0;
        $e39 = $this->inputCents($input, 'e39_cents');
        $e40 = $this->inputCents($input, 'e40_cents');
        $small = $this->rules->parameterFor('EUE-101', 'Kleine Pauschale bis', $year, 'cents');
        $large = $this->rules->parameterFor('EUE-101', 'Große Pauschale bis', $year, 'cents');
        $maximum = $this->rules->parameterFor('EUE-102', 'Eingabe Maximum', $year, 'cents');

        $fields[] = $this->money($order, 'E1A', 'E39', '9216', 'Ergonomisches Mobiliar',
            $suppressed ? 0 : $e39, $this->inputSource('e39_cents'), ['D-85']);
        $fields[] = $this->money($order, 'E1A', 'E40', '9215/9217', 'Arbeitsplatzpauschale',
            $suppressed ? 0 : $e40, $this->inputSource('e40_cents'), ['D-85']);

        $e39Code = ! $suppressed && $e39 > 0 && $e39 <= $maximum ? '9216' : null;
        $e40Code = null;

        if (! $suppressed && $e40 > 0) {
            $e40Code = $e40 <= $small ? '9215' : ($e40 <= $large ? '9217' : null);
        }

        $fields[] = $this->text($order, 'E1A', 'C39', $e39Code, 'Kennzahl Ergonomisches Mobiliar',
            $e39Code, $this->inputSource('e39_cents'));
        $fields[] = $this->text($order, 'E1A', 'C40', $e40Code, 'Kennzahl Arbeitsplatzpauschale',
            $e40Code, $this->inputSource('e40_cents'));

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $annual
     * @return list<array<string, mixed>>
     */
    private function e1bFields(int &$order, array $annual): array
    {
        $rows = [
            ['E7', '9460', 'Einnahmen', '9460'],
            ['E10', '9500', 'Werbungskosten', '9500'],
            ['E12', '9510', 'Werbungskosten laut E12', '9510'],
            ['E13', '9520', 'Werbungskosten laut E13', '9520'],
            ['E14', '9521', 'Werbungskosten laut E14', '9521'],
            ['E15', null, 'Sonstige Werbungskosten', 'E15'],
            ['E18', null, 'Ergebnis', 'E18'],
        ];

        $fields = [];

        foreach ($rows as [$cell, $kz, $label, $key]) {
            $fields[] = $this->money($order, 'E1B', $cell, $kz, $label,
                (int) ($annual['e1b'][$key] ?? 0), 'e1b.'.$key);
        }

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $vat
     * @return list<array<string, mixed>>
     */
    private function vatFields(int &$order, array $vat): array
    {
        $fields = [];

        foreach (array_values(VatReturnService::CODES) as $index => $code) {
            $fields[] = $this->money($order, 'UST', 'E'.(5 + $index), $code, $this->vatLabel($code),
                (int) ($vat['year_totals'][$code] ?? 0), 'vat.year_totals.'.$code);
        }

        $fields[] = $this->money($order, 'UST', 'E18', null, 'Zahllast',
            (int) ($vat['year_totals']['zahllast'] ?? 0), 'vat.year_totals.zahllast');

        $quarters = [1 => 'H', 2 => 'M', 3 => 'S', 4 => 'X'];

        foreach ($quarters as $quarter => $column) {
            foreach (array_values(VatReturnService::CODES) as $index => $code) {
                $fields[] = $this->money(
                    $order,
                    'UST',
                    $column.(5 + $index),
                    $code,
                    sprintf('Q%d %s', $quarter, $this->vatLabel($code)),
                    (int) ($vat['quarters'][$quarter][$code] ?? 0),
                    'vat.quarters.'.$quarter.'.'.$code,
                );
            }
        }

        $fields[] = $this->text($order, 'UST', 'C2', null, '1. Umsatzsteuervoranmeldung',
            '1. Umsatzsteuervoranmeldung', 'vat.blocks.voranmeldung', ['D-87']);
        $fields[] = $this->text($order, 'UST', 'AB2', null, '2. Umsatzsteuererklärung',
            '2. Umsatzsteuererklärung', 'vat.blocks.erklaerung');

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $allocation
     * @param  array<string, mixed>  $monthly
     * @param  list<array<string, mixed>>  $svMonthly
     * @param  array<string, mixed>  $reminder
     * @param  array<string, mixed>  $input
     * @return list<array<string, mixed>>
     */
    private function overviewFields(
        int &$order,
        array $allocation,
        array $monthly,
        array $svMonthly,
        string $mode,
        int $bookedSv,
        array $reminder,
        array $input,
    ): array {
        $fields = [];
        $map = [
            ['U14', null, 'Gesamteinkommen', 'total_income_cents', 'est.total_income_cents'],
            ['U23', null, 'Einkommensteuer', 'tax_cents', 'est.tax_cents'],
            ['U24', null, 'Betriebliche Einkommensteuer', 'business_tax_cents', 'est.business_tax_cents'],
            ['U25', null, 'Einkommensteuer Vermietung', 'rental_tax_cents', 'est.rental_tax_cents'],
            ['U26', null, 'Einkommensteuer-Vorauszahlung', 'prepayment_cents', $this->inputSource('prepayment_cents')],
            ['U27', null, 'Einkommensteuer Bruttolohn', 'employment_tax_cents', 'est.employment_tax_cents'],
            ['U29', null, 'Est zu zahlen', 'due_cents', 'est.due_cents'],
        ];

        foreach ($map as [$cell, $kz, $label, $key, $source]) {
            $fields[] = $this->money($order, 'Ü', $cell, $kz, $label,
                (int) ($allocation[$key] ?? 0), $source,
                $key === 'tax_cents' ? ['D-88'] : []);
        }

        $fields[] = $this->money($order, 'Ü', 'U13', null, 'Prognose Vermietung',
            $this->inputCents($input, 'rental_forecast_cents'), $this->inputSource('rental_forecast_cents'));

        $annual = is_array($monthly['annual'] ?? null) ? $monthly['annual'] : [];
        $first = is_array($monthly['months'][0] ?? null) ? $monthly['months'][0] : [];

        $fields[] = $this->money($order, 'Ü', 'J12', null, 'Anteilige EST und SV',
            (int) ($first['est_sv_cents'] ?? 0), 'est.monthly.1.est_sv_cents');
        $fields[] = $this->money($order, 'Ü', 'K12', null, 'Reingewinn',
            (int) ($first['net_profit_cents'] ?? 0), 'est.monthly.1.net_profit_cents');
        $fields[] = $this->money($order, 'Ü', 'N5', null, 'Reingewinn je Stunde',
            (int) ($first['net_per_hour_cents'] ?? 0), 'est.monthly.1.net_per_hour_cents');
        $fields[] = $this->money($order, 'Ü', 'I12', null, 'Mindest-SV-Beitrag monatlich',
            (int) ($annual['minimum_sv_cents'] ?? 0), 'est.annual.minimum_sv_cents', ['D-79']);
        $fields[] = $this->money($order, 'Ü', 'I14', null, 'Verlustbasis',
            (int) ($annual['loss_basis_cents'] ?? 0), 'est.annual.loss_basis_cents');
        $fields[] = $this->money($order, 'Ü', 'I16', null, 'Bemessungsgrundlage monatlich',
            (int) ($first['basis_cents'] ?? 0), 'est.monthly.1.basis_cents');

        $firstSv = is_array($svMonthly[0] ?? null) ? $svMonthly[0] : [];

        foreach ([
            ['kv_cents', 'KV', 'Krankenversicherung monatlich'],
            ['pv_cents', 'PV', 'Pensionsversicherung monatlich'],
            ['svs_cents', 'SVS', 'Selbständigenvorsorge monatlich'],
            ['uv_cents', 'UV', 'Unfallversicherung monatlich'],
        ] as [$key, $short, $label]) {
            $fields[] = $this->money($order, 'Ü', $short, null, $label,
                (int) ($firstSv[$key] ?? 0), 'sv.monthly.'.$key,
                $key === 'pv_cents' && $mode === 'fsvg' ? ['D-79'] : []);
        }

        $fields[] = $this->text($order, 'Ü', 'Y5', null, 'SV-Modus', $mode, 'settings.eur_sv_mode');
        $fields[] = $this->money($order, 'Ü', 'AA8', '9225', 'Gebuchte Pflichtversicherungen',
            $bookedSv, 'e1.expense.9225', ['D-79']);
        $fields[] = $this->count($order, 'Ü', null, null, 'Offene SV-Quartale',
            count(is_array($reminder['open_quarters'] ?? null) ? $reminder['open_quarters'] : []),
            'sv.reminder.open_quarters');
        $fields[] = $this->text($order, 'Ü', null, null, 'SV-Erinnerung',
            $reminder['message'] ?? null, 'sv.reminder.message');

        return $fields;
    }

    /**
     * @param  array<string, mixed>  $annual
     * @param  list<array<string, mixed>>  $assetYears
     * @param  list<array<string, mixed>>  $journal
     * @param  list<array<string, mixed>>  $hours
     * @return list<array<string, mixed>>
     */
    private function reconciliationFields(
        int &$order,
        array $annual,
        array $assetYears,
        array $journal,
        array $hours,
    ): array {
        $fields = [];
        $booked = 0;

        foreach ($journal as $row) {
            $booked += (int) ($row['business_amount_cents'] ?? 0);
        }

        $minutes = 0;

        foreach ($hours as $row) {
            $row = $this->toArray($row);

            if ($row !== null) {
                $minutes += (int) ($row['minutes'] ?? 0);
            }
        }

        $priorAfa = (int) EurAssetYear::forCurrentBrand()
            ->where('year', ((int) $annual['year']) - 1)
            ->sum('business_use_cents');
        $residual = 0;

        foreach ($assetYears as $row) {
            $row = $this->toArray($row);

            if ($row !== null) {
                $residual += (int) ($row['book_value_cents'] ?? 0);
            }
        }

        $lines = 0;

        foreach (['income' => $annual['income'], 'expense' => $annual['expense']] as $group) {
            foreach ($group as $amount) {
                $lines += (int) $amount;
            }
        }

        $e1b = 0;

        foreach ($annual['e1b'] as $key => $amount) {
            if ($key !== 'E18') {
                $e1b += (int) $amount;
            }
        }

        $fields[] = $this->money($order, 'ABGLEICH', null, null, 'Betriebseinnahmen',
            (int) $annual['income_cents'], 'e1.income_cents');
        $fields[] = $this->money($order, 'ABGLEICH', null, null, 'Betriebsausgaben',
            (int) $annual['expense_cents'], 'e1.expense_cents');
        $fields[] = $this->money($order, 'ABGLEICH', null, '9040/9230', 'Summe E1A-Zeilen',
            $lines, 'e1.lines_cents');
        $fields[] = $this->money($order, 'ABGLEICH', null, null, 'Summe E1B', $e1b, 'e1b.total_cents');
        $fields[] = $this->money($order, 'ABGLEICH', null, null, 'Journal-Summe ohne Quellen',
            $booked, 'journal.business_amount_cents');
        $fields[] = $this->money($order, 'ABGLEICH', null, null, 'Abweichung E1A plus E1B zum Journal',
            $lines + $e1b - $booked, 'e1.reconciliation_delta_cents');
        $fields[] = $this->money($order, 'ABGLEICH', null, null, 'Vorjahres-AfA',
            $priorAfa, 'assets.prior_year.business_use_cents', ['D-89', 'D-90']);
        $fields[] = $this->money($order, 'ABGLEICH', null, null, 'Restbuchwert',
            $residual, 'assets.book_value_cents', ['D-85', 'D-89']);
        $fields[] = $this->minutes($order, 'ABGLEICH', null, null, 'Gebuchte Stunden',
            $minutes, 'hours.minutes');

        return $fields;
    }

    /** @return array{code: string, text_de: string} */
    private function deviation(string $code): array
    {
        return self::DEVIATIONS[$code] ?? ['code' => $code, 'text_de' => $code];
    }

    /** @param list<string> $codes */
    private function deviations(array $codes): array
    {
        return array_map(fn (string $code): array => $this->deviation($code), $codes);
    }

    /** @return array<string, mixed> */
    private function money(
        int &$order,
        string $sheet,
        ?string $cell,
        ?string $kz,
        string $label,
        int $cents,
        string $source,
        array $codes = [],
    ): array {
        return $this->field($order, $sheet, $cell ?? '', $kz, $label,
            $cents !== 0 ? $cents : null, null, 'EUR', $source, $codes);
    }

    /** @return array<string, mixed> */
    private function count(
        int &$order,
        string $sheet,
        ?string $cell,
        ?string $kz,
        string $label,
        int $value,
        string $source,
    ): array {
        return $this->field($order, $sheet, $cell ?? '', $kz, $label, null, $value, 'COUNT', $source, []);
    }

    /** @return array<string, mixed> */
    private function minutes(
        int &$order,
        string $sheet,
        ?string $cell,
        ?string $kz,
        string $label,
        int $value,
        string $source,
    ): array {
        return $this->field($order, $sheet, $cell ?? '', $kz, $label, null, $value, 'MINUTES', $source, []);
    }

    /** @return array<string, mixed> */
    private function rate(
        int &$order,
        string $sheet,
        string $cell,
        ?string $kz,
        string $label,
        int $rate,
        string $source,
    ): array {
        return $this->field($order, $sheet, $cell, $kz, $label, null, $rate, 'PER_10000', $source, []);
    }

    /** @return array<string, mixed> */
    private function text(
        int &$order,
        string $sheet,
        ?string $cell,
        ?string $kz,
        string $label,
        ?string $value,
        string $source,
        array $codes = [],
    ): array {
        return $this->field($order, $sheet, $cell ?? '', $kz, $label, null, $value, 'TEXT', $source, $codes);
    }

    /** @return array<string, mixed> */
    private function field(
        int &$order,
        string $sheet,
        string $cell,
        ?string $kz,
        string $label,
        ?int $valueCents,
        int|string|null $valueText,
        string $unit,
        string $source,
        array $codes,
    ): array {
        $order++;

        return [
            'order' => $order,
            'sheet' => $sheet,
            'cell' => $cell,
            'kz' => $kz,
            'label_de' => $label,
            'value_cents' => $valueCents,
            'value_text' => $valueText,
            'unit' => $unit,
            'source' => $source,
            'deviations' => $this->deviations($codes),
        ];
    }

    private function label(string $code): string
    {
        $help = E1aHelpTexts::forCode($code);

        return $help === null ? $code : $help['bezeichnung'];
    }

    private function vatLabel(string $code): string
    {
        return match ($code) {
            '000' => 'Gesamtbetrag',
            '016' => 'Kleinunternehmer-Umsatz',
            '057' => 'Reverse Charge',
            '070' => 'Innergemeinschaftliche Erwerbe',
            '072' => 'IG Erwerb 20 %',
            '073' => 'IG Erwerb 10 %',
            '008' => 'IG Erwerb 13 %',
            '125' => 'IG Erwerb 4,9 %',
            default => $code,
        };
    }

    /** @param array<string, mixed> $input */
    private function inputCents(array $input, string $key): int
    {
        $value = $input[$key] ?? null;

        return is_int($value) || (is_string($value) && ctype_digit($value))
            ? max(0, (int) $value)
            : 0;
    }

    private function inputSource(string $key): string
    {
        return 'Eingabefeld, noch nicht gespeichert (request.'.$key.')';
    }

    /** @return array<string, mixed>|null Eloquent-Zeilen und Arrays gleich behandeln */
    private function toArray(mixed $row): ?array
    {
        if (is_array($row)) {
            return $row;
        }

        if (is_object($row) && method_exists($row, 'toArray')) {
            $converted = $row->toArray();

            return is_array($converted) ? $converted : null;
        }

        return null;
    }

    /** @param array<string, mixed> $row */
    private function rowSum(array $row): int
    {
        $sum = 0;

        foreach (['kv_cents', 'pv_cents', 'svs_cents', 'uv_cents'] as $key) {
            $sum += (int) ($row[$key] ?? 0);
        }

        return $sum;
    }
}
