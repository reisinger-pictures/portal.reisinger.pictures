<?php

declare(strict_types=1);

namespace App\Services\Ea;

use Barryvdh\DomPDF\Facade\Pdf;

/**
 * EAR · PDF-Erzeugung (Monatsbericht, Eigenbeleg, Anlagenverzeichnis).
 *
 * Die Daten liegen bereits als ganze Cent vor; die Umrechnung in eine
 * Euro-Anzeige passiert ausschließlich hier an der Lesegrenze. Der Dienst
 * erzeugt HTML und übergibt es dem im Projekt bereits genutzten dompdf-
 * Renderer; er liest selbst keine Datenbank und kennt keine Marke — die
 * brand-scoped Abfrage liegt im Controller.
 */
final class EaPdfService
{
    /**
     * @param  array<string, mixed>  $statement  Ergebnis von MonthStatement::build()
     */
    public function monthly(array $statement): string
    {
        $year = (int) ($statement['year'] ?? 0);
        $month = (int) ($statement['month'] ?? 0);
        $totals = is_array($statement['totals'] ?? null) ? $statement['totals'] : [];
        $lines = is_array($statement['lines'] ?? null) ? $statement['lines'] : [];
        $e1 = is_array($statement['e1_by_code'] ?? null) ? $statement['e1_by_code'] : [];
        $vat = is_array($statement['vat'] ?? null) ? $statement['vat'] : [];

        $rows = '';

        foreach ($lines as $line) {
            if (! is_array($line)) {
                continue;
            }

            $rows .= '<tr>'
                .'<td>'.$this->text($line['type'] ?? '').'</td>'
                .'<td>'.$this->text($line['day'] ?? '').'</td>'
                .'<td>'.$this->text($line['description'] ?? '').'</td>'
                .'<td class="num">'.$this->euro((int) ($line['amount_cents'] ?? 0)).'</td>'
                .'</tr>';
        }

        $codes = '';

        foreach (['E1A', 'E1B'] as $group) {
            foreach (is_array($e1[$group] ?? null) ? $e1[$group] : [] as $code => $amount) {
                $codes .= '<tr><td>'.$this->text($group).'</td><td>'.$this->text($code).'</td>'
                    .'<td class="num">'.$this->euro((int) $amount).'</td></tr>';
            }
        }

        $html = $this->document('Monatsbericht '.$month.'/'.$year,
            '<h1>Monatsbericht</h1>'
            .'<p class="meta">Zeitraum: '.$this->text($month).'/'.$this->text($year).'</p>'
            .$this->table(['Kennzahl', 'Wert'], [
                '<tr><td>Betriebliche Einnahmen</td><td class="num">'.$this->euro((int) ($totals['income_cents'] ?? 0)).'</td></tr>'
                .'<tr><td>Betriebliche Ausgaben</td><td class="num">'.$this->euro((int) ($totals['expense_cents'] ?? 0)).'</td></tr>'
                .'<tr><td>Gewinn</td><td class="num">'.$this->euro(
                    (int) ($totals['income_cents'] ?? 0) - (int) ($totals['expense_cents'] ?? 0),
                ).'</td></tr>'
                .'<tr><td>Wareneingang</td><td class="num">'.$this->euro((int) ($totals['warehouse_cents'] ?? 0)).'</td></tr>'
                .'<tr><td>Umsatzsteuer</td><td class="num">'.$this->euro((int) ($totals['vat_cents'] ?? 0)).'</td></tr>'
                .'<tr><td>Stunden</td><td class="num">'.$this->text($totals['hours_minutes'] ?? 0).'</td></tr>',
            ])
            .'<h2>Buchungen</h2>'
            .$this->table(['Art', 'Tag', 'Beschreibung', 'Betrag'], $rows)
            .'<h2>Kennzahlen</h2>'
            .$this->table(['Gruppe', 'Kennzahl', 'Betrag'], $codes)
            .$this->vatSection($vat)
        );

        return $this->render($html);
    }

    public function eigenbeleg(int $year, int $month, string $description, int $amountCents): string
    {
        $html = $this->document('Eigenbeleg '.$month.'/'.$year,
            '<h1>Eigenbeleg</h1>'
            .$this->table(['Feld', 'Wert'], [
                '<tr><td>Jahr</td><td>'.$this->text($year).'</td></tr>'
                .'<tr><td>Monat</td><td>'.$this->text($month).'</td></tr>'
                .'<tr><td>Beschreibung</td><td>'.$this->text($description).'</td></tr>'
                .'<tr><td>Betrag</td><td class="num">'.$this->euro($amountCents).'</td></tr>',
            ])
        );

        return $this->render($html);
    }

    /**
     * @param  list<array<string, mixed>>  $assets
     */
    public function assets(int $year, array $assets): string
    {
        $rows = '';
        $booking = 0;
        $private = 0;
        $residual = 0;

        foreach ($assets as $asset) {
            if (! is_array($asset)) {
                continue;
            }

            $plan = [];

            foreach (is_array($asset['years'] ?? null) ? $asset['years'] : [] as $row) {
                if (is_array($row) && (int) ($row['year'] ?? 0) === $year) {
                    $plan = $row;

                    break;
                }
            }

            $business = (int) ($plan['business_use_cents'] ?? 0);
            $privateCents = (int) ($plan['private_use_cents'] ?? 0);
            $rest = (int) ($plan['book_value_cents'] ?? 0);
            $booking += $business;
            $private += $privateCents;
            $residual += $rest;

            $rows .= '<tr>'
                .'<td>'.$this->text($asset['name'] ?? '').'</td>'
                .'<td>'.$this->text($asset['invoice_no'] ?? '').'</td>'
                .'<td>'.$this->text($asset['purchase_date'] ?? '').'</td>'
                .'<td class="num">'.$this->euro((int) ($asset['cost_cents'] ?? 0)).'</td>'
                .'<td class="num">'.$this->euro($business).'</td>'
                .'<td class="num">'.$this->euro($privateCents).'</td>'
                .'<td class="num">'.$this->euro($rest).'</td>'
                .'</tr>';
        }

        $html = $this->document('Anlagenverzeichnis '.$year,
            '<h1>Anlagenverzeichnis</h1>'
            .'<p class="meta">Jahr: '.$this->text($year).'</p>'
            .$this->table(
                ['Anlagegut', 'Rechnungsnummer', 'Anschaffung', 'Kosten', 'AfA betrieblich', 'Privatanteil', 'Restbuchwert'],
                $rows,
            )
            .$this->table(['Summe', 'Wert'], [
                '<tr><td>Betriebliche AfA</td><td class="num">'.$this->euro($booking).'</td></tr>'
                .'<tr><td>Privatanteil</td><td class="num">'.$this->euro($private).'</td></tr>'
                .'<tr><td>Restbuchwert</td><td class="num">'.$this->euro($residual).'</td></tr>',
            ])
        );

        return $this->render($html);
    }

    /**
     * EUE-124: die acht USt-Kennzahlen als eigene Tabelle im Monats-PDF.
     * Leere Kennzahlen werden ausgelassen; nur die Lesegrenze formatiert Euro.
     *
     * @param  array<string, mixed>  $vat
     */
    private function vatSection(array $vat): string
    {
        $rows = '';

        foreach (is_array($vat['kennzahlen'] ?? null) ? $vat['kennzahlen'] : [] as $code => $amount) {
            if ($amount === null) {
                continue;
            }

            $rows .= '<tr><td>'.$this->text($code).'</td>'
                .'<td class="num">'.$this->euro((int) $amount).'</td></tr>';
        }

        if ($rows === '') {
            return '';
        }

        return '<h2>Umsatzsteuer-Kennzahlen</h2>'
            .$this->table(['Kennzahl', 'Betrag'], $rows);
    }

    /**
     * D-91: druckbare Ausfüllhilfe. Leere Zeilen werden ausgelassen, die
     * letzte Spalte zeigt die Codes der bewussten Abweichungen vom ODS.
     *
     * @param  array<string, mixed>  $data  Ergebnis von EaAusfuellhilfeService::build()
     */
    public function ausfuellhilfe(array $data): string
    {
        $year = (int) ($data['year'] ?? 0);
        $body = '<h1>Werte für FinanzOnline</h1>'
            .'<p class="meta">Jahr: '.$this->text($year).'</p>'
            .'<p class="meta">'.$this->text($data['notice'] ?? '').'</p>';

        foreach (is_array($data['sections'] ?? null) ? $data['sections'] : [] as $section) {
            if (! is_array($section)) {
                continue;
            }

            $rows = '';

            foreach (is_array($section['fields'] ?? null) ? $section['fields'] : [] as $field) {
                if (! is_array($field) || ($field['value_cents'] ?? null) === null
                    && ($field['value_text'] ?? null) === null) {
                    continue;
                }

                $value = ($field['value_cents'] ?? null) !== null
                    ? $this->euro((int) $field['value_cents'])
                    : $this->text($field['value_text']);
                $codes = [];

                foreach (is_array($field['deviations'] ?? null) ? $field['deviations'] : [] as $deviation) {
                    if (is_array($deviation) && isset($deviation['code'])) {
                        $codes[] = (string) $deviation['code'];
                    }
                }

                $rows .= '<tr>'
                    .'<td>'.$this->text($field['cell'] ?? '').'</td>'
                    .'<td>'.$this->text($field['kz'] ?? '').'</td>'
                    .'<td>'.$this->text($field['label_de'] ?? '').'</td>'
                    .'<td class="num">'.$value.'</td>'
                    .'<td>'.$this->text(implode(', ', $codes)).'</td>'
                    .'</tr>';
            }

            if ($rows === '') {
                continue;
            }

            $body .= '<h2>'.$this->text($section['title_de'] ?? ($section['id'] ?? '')).'</h2>'
                .$this->table(['Zelle', 'KZ', 'Bezeichnung', 'Wert', 'Abweichung'], $rows);
        }

        return $this->render($this->document('Ausfuellhilfe '.$year, $body));
    }

    private function document(string $title, string $body): string
    {
        return '<!DOCTYPE html><html lang="de"><head><meta charset="UTF-8">'
            .'<title>'.$this->text($title).'</title><style>'
            .'body{font-family:Helvetica,Arial,sans-serif;font-size:10px;color:#222;}'
            .'h1{font-size:18px;margin:0 0 12px;}h2{font-size:13px;margin:18px 0 6px;}'
            .'.meta{color:#555;margin-bottom:12px;}'
            .'table{border-collapse:collapse;width:100%;margin-bottom:12px;}'
            .'th,td{border:1px solid #bbb;padding:4px 6px;text-align:left;vertical-align:top;}'
            .'th{background:#eee;}td.num{text-align:right;}'
            .'</style></head><body>'.$body.'</body></html>';
    }

    /**
     * @param  list<string>  $headers
     * @param  list<string>|string  $rows
     */
    private function table(array $headers, array|string $rows): string
    {
        $head = '';

        foreach ($headers as $header) {
            $head .= '<th>'.$this->text($header).'</th>';
        }

        $body = is_array($rows) ? implode('', $rows) : $rows;

        return '<table><thead><tr>'.$head.'</tr></thead><tbody>'.$body.'</tbody></table>';
    }

    /** Ausgabe nur an der Lesegrenze: ganze Cent → deutsche Euro-Schreibweise. */
    private function euro(int $cents): string
    {
        $negative = $cents < 0;
        $absolute = abs($cents);
        $value = number_format(intdiv($absolute, 100), 0, ',', '.')
            .','.str_pad((string) ($absolute % 100), 2, '0', STR_PAD_LEFT);

        return ($negative ? '-' : '').$value.' €';
    }

    private function text(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    private function render(string $html): string
    {
        return (string) Pdf::loadHTML($html)->setPaper('a4')->output();
    }
}
