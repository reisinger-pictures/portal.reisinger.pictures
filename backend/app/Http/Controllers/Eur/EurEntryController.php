<?php

namespace App\Http\Controllers\Eur;

use App\Eur\Domain\JournalRules;
use App\Eur\Reference\EurYear;
use App\Eur\Support\Share;
use App\Http\Controllers\Controller;
use App\Models\EurAuditLog;
use App\Models\EurCustomer;
use App\Models\EurEntry;
use App\Models\EurProject;
use App\Support\BrandRegistry;
use Closure;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;
use InvalidArgumentException;

/**
 * EA Rechnung · Buchungen (Einnahmen und Ausgaben).
 *
 * Geld kommt und geht ausschließlich als ganze Cent; der Betriebsanteil ist
 * eine ganze Prozentzahl in 5-%-Schritten. Der betriebliche Betrag wird hier
 * einmal an der Schreibgrenze berechnet, damit Auswertungen keinen Float
 * bilden. Jeder Schreibzugriff erzeugt einen Audit-Eintrag ohne Dateiinhalte.
 */
class EurEntryController extends Controller
{
    public function index(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'year' => $this->yearRule(false),
            'month' => 'nullable|integer|min:1|max:12',
            'direction' => 'nullable|in:income,expense',
        ]);

        $query = EurEntry::forCurrentBrand()->with(['project', 'customer']);

        if (isset($validated['year'])) {
            $query->where('year', $validated['year']);
        }

        if (isset($validated['month'])) {
            $query->where('month', $validated['month']);
        }

        if (isset($validated['direction'])) {
            $query->where('direction', $validated['direction']);
        }

        $entries = $query->orderByDesc('year')->orderByDesc('month')->orderByDesc('id')->get();

        return response()->json([
            'entries' => $entries->map(fn (EurEntry $entry): array => $this->serialize($entry))->values(),
            'totals' => [
                'income_cents' => (int) $entries->where('direction', 'income')->sum('business_amount_cents'),
                'expense_cents' => (int) $entries->where('direction', 'expense')->sum('business_amount_cents'),
            ],
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $data = $this->validateEntry($request, null);
        $data['brand'] = BrandRegistry::currentId();
        $data['business_amount_cents'] = $this->businessAmount($data);

        $entry = EurEntry::create($data);
        $this->audit('created', $entry);

        return response()->json(['entry' => $this->serialize($entry->refresh())], 201);
    }

    /**
     * CSV-Import (Portal #31). Jede Zeile läuft durch exakt dieselben Regeln
     * wie `store()`; ungültige Zeilen werden übersprungen und mit Zeilennummer
     * gemeldet. Beträge werden als ganze Cent aus der Euro-Zeichenkette mit
     * BC Math gebildet — es gibt keinen Float-Pfad.
     *
     * @return array<string, string>
     */
    private const CSV_ALIASES = [
        'year' => ['jahr', 'wirtschaftsjahr', 'buchungsjahr', 'year'],
        'month' => ['monat', 'month'],
        'day' => ['tag', 'day'],
        'date' => ['datum', 'buchungsdatum', 'buchungstag', 'date'],
        'direction' => ['richtung', 'art', 'e/a', 'einnahme/ausgabe', 'typ', 'direction'],
        'category' => ['kategorie', 'buchungsart', 'einnahmenart', 'ausgabenart', 'category'],
        'description' => ['beschreibung', 'bezeichnung', 'verwendungszweck', 'kommentar', 'text', 'description'],
        'kind_type' => ['leistung', 'art der buchung', 'kind_type', 'buchungstyp'],
        'amount' => ['betrag', 'betrag(eur)', 'brutto', 'bruttobetrag', 'betrag in eur', 'amount', 'amount_cents'],
        'business_share' => ['betriebsanteil', 'betriebsanteil(%)', 'betriebsanteil prozent', 'anteil', 'anteil(%)', 'business_share'],
        'e1a_code' => ['e1a', 'e1a kennzahl', 'kennzahl', 'kz', 'e1a_code'],
        'vat_kind' => ['ust art', 'umsatzsteuer art', 'vat_kind'],
        'receipt_no' => ['belegnr', 'belegnr.', 'belegnummer', 'beleg no', 'receipt_no'],
        'payment_method' => ['zahlart', 'zahlungsart', 'payment_method'],
        'note' => ['notiz', 'note'],
    ];

    public function import(Request $request): JsonResponse
    {
        $data = $request->validate([
            'file' => 'required|file|mimes:csv,txt|max:2048',
            'year' => $this->yearRule(false),
            'month' => 'nullable|integer|min:1|max:12',
        ]);

        $defaultYear = isset($data['year']) ? (int) $data['year'] : EurYear::currentOrLast();
        $defaultMonth = isset($data['month']) ? (int) $data['month'] : null;
        $file = $request->file('file');
        $path = $file->getRealPath() ?: $file->getPathname();
        $contents = file_get_contents($path);
        if (! is_string($contents)) {
            abort(422, 'Die CSV-Datei konnte nicht gelesen werden.');
        }

        $parsed = $this->parseCsv($contents);
        $created = 0;
        $skipped = 0;
        $errors = [];

        foreach ($parsed['rows'] as $row) {
            $values = $this->csvRow($parsed['headers'], $row['cells']);
            $candidate = $this->csvEntryData($values, $defaultYear, $defaultMonth);

            try {
                $validated = $this->validateEntry(Request::create('/eur/import-row', 'POST', $candidate), null);
            } catch (ValidationException $e) {
                $messages = [];
                foreach ($e->errors() as $fieldErrors) {
                    foreach ($fieldErrors as $message) {
                        $messages[] = $message;
                    }
                }

                $skipped++;
                $errors[] = ['line' => $row['line'], 'message' => implode('; ', $messages)];

                continue;
            }

            $validated['brand'] = BrandRegistry::currentId();
            $validated['business_amount_cents'] = $this->businessAmount($validated);
            $entry = EurEntry::create($validated);
            $this->audit('created', $entry);
            $created++;
        }

        return response()->json([
            'created' => $created,
            'skipped' => $skipped,
            'errors' => $errors,
        ]);
    }

    public function update(Request $request, int $id): JsonResponse
    {
        $entry = EurEntry::forCurrentBrand()->findOrFail($id);
        $data = $this->validateEntry($request, $entry);
        $data['business_amount_cents'] = $this->businessAmount($data);

        $entry->fill($data)->save();
        $this->audit('updated', $entry);

        return response()->json(['entry' => $this->serialize($entry->refresh())]);
    }

    public function destroy(int $id): JsonResponse
    {
        $entry = EurEntry::forCurrentBrand()->findOrFail($id);
        $entry->delete();
        $this->audit('deleted', $entry);

        return response()->json(['success' => true]);
    }

    /**
     * Kleiner, abhängigkeitsfreier Parser: Semikolon oder Komma, deutsche
     * Kopfzeile, BOM, CRLF und Leerzeilen. Die Kopfzeile bestimmt das
     * Trennzeichen; die erste nicht-leere Zeile ist immer der Header.
     *
     * @return array{headers: list<string>, rows: list<array{line: int, cells: list<string>}>}
     */
    private function parseCsv(string $contents): array
    {
        $contents = preg_replace('/^\xEF\xBB\xBF/', '', $contents) ?? $contents;
        $lines = preg_split('/\r\n|\n|\r/', $contents) ?: [];

        $headers = [];
        $delimiter = ';';
        $rows = [];

        foreach ($lines as $index => $line) {
            if (trim($line) === '') {
                continue;
            }

            if ($headers === []) {
                $delimiter = substr_count($line, ';') >= substr_count($line, ',') ? ';' : ',';
                $headers = array_values(array_map(
                    static fn (mixed $cell): string => self::normalizeHeader((string) $cell),
                    str_getcsv($line, $delimiter),
                ));

                continue;
            }

            $cells = array_values(array_map(
                static fn (mixed $cell): string => trim((string) $cell),
                str_getcsv($line, $delimiter),
            ));
            $rows[] = ['line' => $index + 1, 'cells' => $cells];
        }

        return ['headers' => $headers, 'rows' => $rows];
    }

    private static function normalizeHeader(string $header): string
    {
        return str_replace([' ', "\u{00A0}", '_', '-'], '', mb_strtolower(trim($header)));
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>  $cells
     * @return array<string, string>
     */
    private function csvRow(array $headers, array $cells): array
    {
        $row = [];

        foreach ($headers as $index => $header) {
            if ($header === '' || ! array_key_exists($index, $cells)) {
                continue;
            }

            foreach (self::CSV_ALIASES as $field => $aliases) {
                foreach ($aliases as $alias) {
                    if ($header === self::normalizeHeader($alias)) {
                        $row[$field] = $cells[$index];

                        continue 3;
                    }
                }
            }
        }

        return $row;
    }

    /**
     * @param  array<string, string>  $row
     * @return array<string, mixed>
     */
    private function csvEntryData(array $row, int $defaultYear, ?int $defaultMonth): array
    {
        $year = $defaultYear;
        $month = $defaultMonth;
        $day = null;
        $parsedDate = $this->parseCsvDate($row['date'] ?? '');

        if ($parsedDate !== null) {
            [$dateYear, $dateMonth, $dateDay] = $parsedDate;
            $year = $dateYear ?? $year;
            $month = $dateMonth ?? $month;
            $day = $dateDay ?? $day;
        }

        if (isset($row['year']) && $row['year'] !== '') {
            $year = (int) $row['year'];
        }

        if (isset($row['month']) && $row['month'] !== '') {
            $month = $this->parseCsvMonth($row['month']);
        }

        if ($day === null && isset($row['day']) && $row['day'] !== '') {
            $day = (int) $row['day'];
        }

        return [
            'year' => $year,
            'month' => $month,
            'day' => $day,
            'direction' => $this->parseCsvDirection($row['direction'] ?? ''),
            'kind_type' => $row['kind_type'] ?? $row['category'] ?? $row['description'] ?? 'Buchung',
            'category' => $row['category'] ?? null,
            'description' => $row['description'] ?? null,
            'e1a_code' => $row['e1a_code'] ?? null,
            'business_share' => isset($row['business_share']) && $row['business_share'] !== ''
                ? $this->parseCsvShare($row['business_share'])
                : 100,
            'amount_cents' => $this->parseCsvCents($row['amount'] ?? ''),
            'vat_kind' => $row['vat_kind'] ?? null,
            'payment_method' => $row['payment_method'] ?? null,
            'receipt_no' => $row['receipt_no'] ?? null,
            'note' => $row['note'] ?? null,
        ];
    }

    /**
     * @return array{0: ?int, 1: ?int, 2: ?int}|null Jahr, Monat, Tag
     */
    private function parseCsvDate(string $raw): ?array
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        if (preg_match('/^(\d{1,2})\.(\d{1,2})\.(\d{4})$/', $value, $matches) === 1) {
            return [(int) $matches[3], (int) $matches[2], (int) $matches[1]];
        }

        if (preg_match('/^(\d{4})-(\d{1,2})-(\d{1,2})$/', $value, $matches) === 1) {
            return [(int) $matches[1], (int) $matches[2], (int) $matches[3]];
        }

        if (preg_match('/^(\d{1,2})[.\/-](\d{1,2})[.\/-](\d{2})$/', $value, $matches) === 1) {
            return [2000 + (int) $matches[3], (int) $matches[2], (int) $matches[1]];
        }

        if (preg_match('/^\d{1,2}$/', $value) === 1) {
            return [null, null, (int) $value];
        }

        return null;
    }

    private function parseCsvMonth(string $raw): ?int
    {
        $value = mb_strtolower(trim($raw));
        if ($value === '') {
            return null;
        }

        if (ctype_digit($value)) {
            return (int) $value;
        }

        foreach (JournalRules::MONTHS as $index => $name) {
            if (mb_strtolower($name) === $value) {
                return $index + 1;
            }
        }

        return null;
    }

    private function parseCsvDirection(string $raw): string
    {
        return match (mb_strtolower(trim($raw))) {
            'e', 'ein', 'einnahme', 'einnahmen', 'income', 'in', '+' => 'income',
            'a', 'aus', 'ausgabe', 'ausgaben', 'expense', 'out', '-' => 'expense',
            default => '',
        };
    }

    /** Prozentzahl 0–100 aus `60 %` / `60`; null ist ein Zeilenfehler. */
    private function parseCsvShare(string $raw): ?int
    {
        $value = str_replace(['%', ' ', "\u{00A0}"], '', trim($raw));

        return preg_match('/^\d{1,3}$/', $value) === 1 ? (int) $value : null;
    }

    /** Euro-Betrag (deutsche oder internationale Schreibweise) in ganze Cent. */
    private function parseCsvCents(string $raw): ?int
    {
        $value = trim($raw);
        if ($value === '') {
            return null;
        }

        $negative = str_starts_with($value, '-') || str_starts_with($value, '(');
        $value = str_replace(['€', 'EUR', ' ', "\u{00A0}", "\u{202F}", "'"], '', $value);
        $value = trim($value, '-+()');
        if ($value === '') {
            return null;
        }

        if (str_contains($value, ',') && str_contains($value, '.')) {
            $value = strrpos($value, ',') > strrpos($value, '.')
                ? str_replace(['.', ','], ['', '.'], $value)
                : str_replace(',', '', $value);
        } elseif (str_contains($value, ',')) {
            $value = str_replace(',', '.', $value);
        }

        if (preg_match('/^\d+(?:\.\d{1,2})?$/', $value) !== 1) {
            return null;
        }

        [$euros, $fraction] = array_pad(explode('.', $value, 2), 2, '');
        $cents = (int) bcadd(bcmul($euros, '100', 0), str_pad($fraction, 2, '0'), 0);

        return $negative ? -$cents : $cents;
    }

    /**
     * @return array<string, mixed>
     */
    private function validateEntry(Request $request, ?EurEntry $entry): array
    {
        $data = $request->validate([
            'year' => $this->yearRule(true),
            'month' => 'required|integer|min:1|max:12',
            'day' => 'nullable|integer|min:1|max:31',
            'direction' => 'required|in:income,expense',
            'kind_type' => 'required|string|max:190',
            'category' => 'nullable|string|max:190',
            'description' => 'nullable|string|max:190',
            'e1a_code' => 'nullable|string|max:8',
            'business_share' => 'required|integer|min:0|max:100|multiple_of:5',
            'amount_cents' => 'required|integer|min:0',
            'vat_kind' => 'nullable|string|max:64',
            'payment_method' => 'nullable|string|max:64',
            'eur_project_id' => 'nullable|integer',
            'eur_customer_id' => 'nullable|integer',
            'receipt_no' => 'nullable|string|max:64',
            'note' => 'nullable|string|max:2000',
        ]);

        if (! empty($data['eur_project_id'])) {
            EurProject::forCurrentBrand()->findOrFail((int) $data['eur_project_id']);
        }

        if (! empty($data['eur_customer_id'])) {
            EurCustomer::forCurrentBrand()->findOrFail((int) $data['eur_customer_id']);
        }

        return $data;
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function businessAmount(array $data): int
    {
        $amount = (int) $data['amount_cents'];
        $share = (int) $data['business_share'];

        return Share::apply($amount, $share);
    }

    private function audit(string $action, EurEntry $entry): void
    {
        EurAuditLog::create([
            'brand' => BrandRegistry::currentId(),
            'user_id' => auth('api')->id(),
            'action' => $action,
            'subject_type' => 'eur_entry',
            'subject_id' => $entry->id,
            'payload' => [
                'year' => $entry->year,
                'month' => $entry->month,
                'direction' => $entry->direction,
                'amount_cents' => $entry->amount_cents,
                'business_share' => $entry->business_share,
                'business_amount_cents' => $entry->business_amount_cents,
            ],
        ]);
    }

    /**
     * @return array<string, mixed>
     */
    private function serialize(EurEntry $entry): array
    {
        return [
            'id' => (int) $entry->id,
            'year' => (int) $entry->year,
            'month' => (int) $entry->month,
            'day' => $entry->day === null ? null : (int) $entry->day,
            'direction' => $entry->direction,
            'kind_type' => $entry->kind_type,
            'category' => $entry->category,
            'description' => $entry->description,
            'e1a_code' => $entry->e1a_code,
            'business_share' => (int) $entry->business_share,
            'amount_cents' => (int) $entry->amount_cents,
            'business_amount_cents' => (int) $entry->business_amount_cents,
            'vat_kind' => $entry->vat_kind,
            'payment_method' => $entry->payment_method,
            'project' => $entry->project?->name,
            'customer' => $entry->customer?->name,
            'receipt_no' => $entry->receipt_no,
            'note' => $entry->note,
            'checks' => class_exists(JournalRules::class)
                ? JournalRules::checks(array_merge($entry->toArray(), ['kind' => $entry->direction]))
                : [],
        ];
    }

    /**
     * @return array<int, mixed>
     */
    private function yearRule(bool $required): array
    {
        return [
            $required ? 'required' : 'nullable',
            'integer',
            function (string $attribute, mixed $value, Closure $fail): void {
                try {
                    EurYear::assertBookable((int) $value);
                } catch (InvalidArgumentException $exception) {
                    $fail($exception->getMessage());
                }
            },
        ];
    }
}
