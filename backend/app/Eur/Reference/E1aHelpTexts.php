<?php

declare(strict_types=1);

namespace App\Eur\Reference;

use RuntimeException;

/**
 * EÜR · Ausfüllhilfe je Kennzahl.
 *
 * Einzige Quelle ist das gepinnte Artefakt
 * `features/eur/cleanroom/artifacts/verification/e1a-ausfuellhilfe.md`,
 * Abschnitt 3. Die Tabelle ist reine Ausfüllhilfe, keine zweite Regelquelle:
 * die Spalte „Zelle" wird absichtlich nicht ausgeliefert, es wird keine
 * Berechnung aus den Texten abgeleitet und der Text wird nicht verändert.
 */
final class E1aHelpTexts
{
    private const PATH = __DIR__.'/../../../../features/eur/cleanroom/artifacts/verification/e1a-ausfuellhilfe.md';

    /** @var list<array{kz: string, bezeichnung: string, hilfetext: string}>|null */
    private static ?array $cache = null;

    /**
     * @return list<array{kz: string, bezeichnung: string, hilfetext: string}>
     */
    public static function all(): array
    {
        if (self::$cache !== null) {
            return self::$cache;
        }

        $content = file_get_contents(self::PATH);

        if ($content === false) {
            throw new RuntimeException('Gepinnte Ausfüllhilfe nicht lesbar.');
        }

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

        return self::$cache = $rows;
    }

    /**
     * @return array{kz: string, bezeichnung: string, hilfetext: string}|null
     */
    public static function forCode(string $code): ?array
    {
        foreach (self::all() as $row) {
            if ($row['kz'] === $code) {
                return $row;
            }
        }

        return null;
    }
}
