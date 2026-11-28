<?php

declare(strict_types=1);

namespace App\Services\Eur;

use App\Eur\Data\RuleCatalogue;
use App\Eur\Data\VatRequirements;
use App\Eur\Reference\EurYear;
use RuntimeException;

/**
 * EAR · Die beiden USt-Erklärungsbereiche (EUE-088).
 *
 * Wortlaut und Titel stammen ausschließlich aus den committeten Datenklassen
 * (generiert aus den gepinnten Artefakten, Issue #74): dem Regelkatalog
 * `App\Eur\Data\RuleCatalogue` und der Ausfüllhilfe
 * `App\Eur\Data\VatRequirements` (`vat-requirements.md`). Es wird kein
 * Steuerrechtstext erfunden und keine zweite Regelquelle angelegt. Findet sich
 * ein Text nicht in den Daten, bricht der Aufbau ab (fail closed). Zur
 * Laufzeit wird keine Datei geöffnet.
 */
final class EurVatDeclarationService
{
    /**
     * @return array{voranmeldung: array{key: string, title: string, title_upper: string, title_cell: string, year_cell: string, wirtschaftsjahr: int, text: string, source: string}, erklaerung: array{key: string, title: string, title_upper: string, title_cell: string, year_cell: string, wirtschaftsjahr: int, text: string, source: string}}
     */
    public function blocks(int $year): array
    {
        EurYear::assert($year);

        $rule = $this->rule('EUE-088');
        $titles = $this->titles((string) ($rule['logik'] ?? ''));
        $text = $this->helpText();
        $source = 'vat-requirements.md#3-quartale-und-erklärungen';

        $make = static fn (string $key, string $title, string $cell): array => [
            'key' => $key,
            'title' => $title,
            'title_upper' => mb_strtoupper($title),
            'title_cell' => $cell,
            'year_cell' => 'AD4',
            'wirtschaftsjahr' => $year,
            'text' => $text,
            'source' => $source,
        ];

        return [
            'voranmeldung' => $make('voranmeldung', $titles[0], 'C2'),
            'erklaerung' => $make('erklaerung', $titles[1], 'AB2'),
        ];
    }

    /** @return array<string, mixed> */
    private function rule(string $id): array
    {
        if (! array_key_exists($id, RuleCatalogue::RULES)) {
            throw new RuntimeException(sprintf('Regel %s fehlt im gepinnten Katalog.', $id));
        }

        return RuleCatalogue::RULES[$id];
    }

    /** @return array{0: string, 1: string} */
    private function titles(string $logik): array
    {
        preg_match_all("/UPPER\\('([^']+)'\\)/u", $logik, $matches);

        $titles = $matches[1] ?? [];

        if (count($titles) < 2) {
            throw new RuntimeException('Die beiden USt-Titel fehlen im gepinnten Regeltext.');
        }

        return [$titles[0], $titles[1]];
    }

    /**
     * Der Wortlaut der Ausfüllhilfe zu EUE-088, ohne redaktionelle Änderung.
     */
    private function helpText(): string
    {
        $lines = explode("\n", VatRequirements::TEXT);

        foreach ($lines as $index => $line) {
            $line = trim($line);

            // Nur der Bullet in Abschnitt 3, nicht die Dokumentüberschrift,
            // die EUE-088 ebenfalls enthält. Die Fortsetzung steht in der
            // nächsten, eingerückten Zeile.
            if (! str_starts_with($line, '- **') || ! str_contains($line, 'EUE-088')) {
                continue;
            }

            $text = trim(str_replace('**', '', preg_replace('/^-\s*/u', '', $line) ?? '') ?? '');
            $continuation = trim($lines[$index + 1] ?? '');

            if ($continuation !== '' && ! str_starts_with($lines[$index + 1], '-')) {
                $text .= ' '.$continuation;
            }

            if ($text !== '') {
                return $text;
            }
        }

        throw new RuntimeException('EUE-088 fehlt in der gepinnten USt-Ausfüllhilfe.');
    }
}
