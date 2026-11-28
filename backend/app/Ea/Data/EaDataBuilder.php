<?php

declare(strict_types=1);

namespace App\Ea\Data;

use App\Ea\Parity\Money;
use RuntimeException;

/**
 * EAR · Übersetzt die gepinnten Clean-Room-Artefakte in die committeten
 * PHP-Datenklassen (Issue #74).
 *
 * Diese Klasse ist die EINZIGE Stelle, die den Artefaktbaum liest. Sie wird
 * ausschließlich vom Generator (`php artisan ea:export-data`) und vom
 * Paritätstest aufgerufen, nie zur Laufzeit des Portals: die Regeln selbst
 * stehen als Programmcode in `App\Ea\Data\*`. Der Wurzelpfad kommt als
 * Konstruktorargument, damit hier kein Pfad festgeschrieben ist.
 *
 * Die Umrechnung in die Zielwerte (ganze Cent, Basispunkte, ganze Prozent)
 * läuft über `App\Ea\Parity\Money` — dieselbe eine Rundungsstelle, die auch
 * der bisherige Leser benutzt hat. Der Generator und der Paritätstest teilen
 * sich diese Implementierung, damit der Test nicht eine Kopie prüft.
 */
final class EaDataBuilder
{
    public const DOMAIN_RULES = 'domain-rules.json';

    public const DOMAIN_MARKDOWN = 'DOMAIN.md';

    public const TABLES = 'artifacts/analysis/tables.json';

    public const HELP_TEXTS = 'artifacts/verification/e1a-ausfuellhilfe.md';

    public const DEGRESSIVE_RULES = 'artifacts/business-rules/degressive-afa-rules.json';

    public const VAT_REQUIREMENTS = 'artifacts/verification/requirements/vat-requirements.md';

    public function __construct(private readonly string $root) {}

    /**
     * @return array<string, array{logik: string, parameter: list<array{name: string, jahre: list<int>, wert: int|float|string}>}>
     */
    public function ruleCatalogue(): array
    {
        $data = $this->readJson(self::DOMAIN_RULES);
        $rules = [];

        foreach ($data['bereiche'] ?? [] as $bereich) {
            foreach ($bereich['regeln'] ?? [] as $rule) {
                $id = (string) ($rule['id'] ?? '');

                if ($id === '') {
                    throw new RuntimeException('Regel ohne ID im gepinnten Regelkatalog.');
                }

                $rules[$id] = [
                    'logik' => (string) ($rule['logik'] ?? ''),
                    'parameter' => $this->parameters($rule, $id),
                ];
            }
        }

        return $rules;
    }

    /**
     * @return array<string, mixed>
     */
    public function referenceTables(): array
    {
        $tables = $this->readJson(self::TABLES);

        return [
            'expense_kinds' => $this->kinds($tables, 'ausgaben_arten'),
            'income_kinds' => $this->kinds($tables, 'einnahmen_arten'),
            'vat_kinds' => array_values(array_map(
                static fn (mixed $kind): string => (string) $kind,
                $this->list($tables, 'ust_arten')
            )),
            'vehicle_types' => array_values(array_map(
                static fn (array $row): array => [
                    'name' => (string) ($row['name'] ?? ''),
                    'category' => (string) ($row['typ'] ?? ''),
                ],
                $this->list($tables, 'fahrzeuge')
            )),
            'payment_methods' => array_values(array_map(
                static fn (array $row): array => [
                    'kind' => (string) ($row['art'] ?? ''),
                    'opening_balance_cents' => isset($row['startsaldo'])
                        ? Money::eurosToCents($row['startsaldo'])
                        : null,
                ],
                $this->list($tables, 'zahlarten')
            )),
            'projects' => array_values(array_map(
                static fn (mixed $project): string => (string) $project,
                $this->list($tables, 'projekte')
            )),
            'travel_allowances' => array_values(array_map(
                static fn (array $row): array => [
                    'country' => (string) ($row['land'] ?? ''),
                    'day_cents' => Money::eurosToCents($row['tag'] ?? 0),
                    'night_cents' => Money::eurosToCents($row['nacht'] ?? 0),
                ],
                $this->list($tables, 'reise_pauschalen')
            )),
            'domestic_flat_rates' => $this->domesticFlatRates($tables),
            'income_tax_stages' => array_values(array_map(
                static fn (array $stage): array => [
                    'limit_cents' => Money::eurosToCents($stage['grenze'] ?? 0),
                    'rate_per_10000' => Money::fractionToPer10000($stage['satz'] ?? 0),
                ],
                $this->list($tables, 'est_tarif')
            )),
            'basic_allowance' => $this->basicAllowance($tables),
            'social_insurance' => $this->socialInsurance($tables),
        ];
    }

    /**
     * @return array{expense_codes: list<string>, income_codes: list<string>, e1b_codes: list<string>}
     */
    public function kzCatalogue(): array
    {
        $content = $this->read(self::DOMAIN_MARKDOWN);

        return [
            'expense_codes' => $this->codes($content, 'EUE-023'),
            'income_codes' => $this->codes($content, 'EUE-024'),
            'e1b_codes' => $this->e1bCodes($content),
        ];
    }

    /**
     * @return list<array{kz: string, bezeichnung: string, hilfetext: string}>
     */
    public function helpTexts(): array
    {
        $content = $this->read(self::HELP_TEXTS);
        $rows = [];

        foreach (explode("\n", $content) as $line) {
            if (! str_starts_with(trim($line), '| D')) {
                continue;
            }

            $cells = array_values(array_map('trim', explode('|', $line)));
            $codeCell = $cells[2] ?? '';
            $designation = $cells[3] ?? '';
            $text = $cells[4] ?? '';

            if (str_contains($text, 'Keine Annotation')) {
                $text = '';
            }

            preg_match_all('/\d{4}/', $codeCell, $matches);

            foreach ($matches[0] as $code) {
                $rows[] = ['kz' => $code, 'bezeichnung' => $designation, 'hilfetext' => $text];
            }
        }

        if ($rows === []) {
            throw new RuntimeException('Ausfüllhilfe-Tabelle nicht gefunden.');
        }

        return $rows;
    }

    /**
     * @return list<string>
     */
    public function degressiveRules(): array
    {
        $data = $this->readJson(self::DEGRESSIVE_RULES);

        foreach ($data['bereiche'] ?? [] as $bereich) {
            foreach ($bereich['regeln'] ?? [] as $rule) {
                if (($rule['id'] ?? null) !== 'DEG-007') {
                    continue;
                }

                foreach ($rule['parameter'] ?? [] as $parameter) {
                    if (($parameter['name'] ?? null) !== 'Zulässige Ausnahmen') {
                        continue;
                    }

                    $value = (string) ($parameter['wert'] ?? '');
                    $parts = array_values(array_filter(
                        array_map('trim', explode(',', $value)),
                        static fn (string $part): bool => $part !== '',
                    ));

                    if ($parts === []) {
                        throw new RuntimeException('Regel DEG-007: Parameter "Zulässige Ausnahmen" ist leer.');
                    }

                    return $parts;
                }
            }
        }

        throw new RuntimeException('Regel DEG-007: Parameter "Zulässige Ausnahmen" fehlt im gepinnten Artefakt.');
    }

    /**
     * Der vollständige Wortlaut der USt-Ausfüllhilfe, unverändert.
     */
    public function vatRequirements(): string
    {
        return $this->read(self::VAT_REQUIREMENTS);
    }

    /**
     * @return list<array{name: string, jahre: list<int>, wert: int|float|string}>
     */
    private function parameters(array $rule, string $ruleId): array
    {
        $parameters = [];

        foreach ($rule['parameter'] ?? [] as $parameter) {
            $parameters[] = [
                'name' => (string) ($parameter['name'] ?? ''),
                'jahre' => array_values(array_map(
                    static fn (mixed $year): int => (int) $year,
                    is_array($parameter['jahre'] ?? null) ? $parameter['jahre'] : [],
                )),
                'wert' => self::scalar($parameter['wert'] ?? null, $ruleId),
            ];
        }

        return $parameters;
    }

    private static function scalar(mixed $wert, string $ruleId): int|float|string
    {
        if (is_int($wert) || is_float($wert) || is_string($wert)) {
            return $wert;
        }

        throw new RuntimeException(sprintf('Regel %s: Parameterwert ist kein Skalar.', $ruleId));
    }

    /**
     * @return list<array{type: string, e1a: string|null, share_percent: int|null}>
     */
    private function kinds(array $tables, string $key): array
    {
        return array_values(array_map(
            static fn (array $row): array => [
                'type' => (string) ($row['typ'] ?? ''),
                'e1a' => isset($row['e1a']) ? (string) $row['e1a'] : null,
                'share_percent' => isset($row['anteil'])
                    ? Money::fractionToPercent($row['anteil'])
                    : null,
            ],
            $this->list($tables, $key)
        ));
    }

    /**
     * @return array{day_cents: int, night_cents: int}
     */
    private function domesticFlatRates(array $tables): array
    {
        $row = $this->map($tables, 'tag_nacht_pauschale_at');

        return [
            'day_cents' => Money::eurosToCents($row['tag'] ?? 0),
            'night_cents' => Money::eurosToCents($row['nacht'] ?? 0),
        ];
    }

    /**
     * @return array{limit_cents: int, rate_per_10000: int}
     */
    private function basicAllowance(array $tables): array
    {
        $row = $this->map($tables, 'grundfreibetrag');

        return [
            'limit_cents' => Money::eurosToCents($row['grenze'] ?? 0),
            'rate_per_10000' => Money::fractionToPer10000($row['satz'] ?? 0),
        ];
    }

    /**
     * @return array<string, int>
     */
    private function socialInsurance(array $tables): array
    {
        $values = [];

        foreach ($this->map($tables, 'sv_werte') as $key => $value) {
            $values[(string) $key] = str_ends_with((string) $key, '_satz')
                ? Money::fractionToPer10000($value)
                : Money::eurosToCents($value);
        }

        return $values;
    }

    /**
     * @return list<string>
     */
    private function codes(string $content, string $ruleId): array
    {
        $value = $this->row($content, $ruleId)[6] ?? '';

        if (preg_match_all('/\b\d{4}\b/', $value, $matches) === 0) {
            throw new RuntimeException(sprintf('%s: Kennzahlen fehlen im gepinnten Artefakt.', $ruleId));
        }

        return array_values(array_unique($matches[0]));
    }

    /**
     * @return list<string>
     */
    private function e1bCodes(string $content): array
    {
        $evidence = $this->row($content, 'EUE-023')[9] ?? '';

        if (preg_match('/E1B-Kennzahlen\s+([0-9\/]+)/u', $evidence, $matches) !== 1) {
            throw new RuntimeException('EUE-023: E1B-Zuordnung fehlt im gepinnten Artefakt.');
        }

        return array_values(array_filter(explode('/', $matches[1]), static fn (string $code): bool => $code !== ''));
    }

    /**
     * @return list<string>
     */
    private function row(string $content, string $ruleId): array
    {
        foreach (explode("\n", $content) as $line) {
            if (! str_starts_with($line, '| '.$ruleId.' ')) {
                continue;
            }

            return array_map('trim', explode('|', $line));
        }

        throw new RuntimeException(sprintf('%s: Regelzeile fehlt im gepinnten Artefakt.', $ruleId));
    }

    /**
     * @return list<array<string, mixed>>
     */
    private function list(array $tables, string $key): array
    {
        $value = $tables[$key] ?? null;

        if (! is_array($value) || ! array_is_list($value)) {
            throw new RuntimeException(sprintf('Referenztabelle "%s" fehlt oder ist keine Liste.', $key));
        }

        return $value;
    }

    /**
     * @return array<string, mixed>
     */
    private function map(array $tables, string $key): array
    {
        $value = $tables[$key] ?? null;

        if (! is_array($value) || array_is_list($value)) {
            throw new RuntimeException(sprintf('Referenztabelle "%s" fehlt oder ist keine Map.', $key));
        }

        return $value;
    }

    private function readJson(string $relative): array
    {
        $data = json_decode($this->read($relative), true, 512, JSON_THROW_ON_ERROR);

        if (! is_array($data)) {
            throw new RuntimeException(sprintf('Artefakt %s ist kein Dokument.', $relative));
        }

        return $data;
    }

    private function read(string $relative): string
    {
        $path = rtrim($this->root, '/').'/'.ltrim($relative, '/');

        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException(sprintf(
                'Clean-Room-Artefakt fehlt: %s. Die Datenklassen mit `php artisan ea:export-data` neu erzeugen.',
                $path,
            ));
        }

        return (string) file_get_contents($path);
    }
}
