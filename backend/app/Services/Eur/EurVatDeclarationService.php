<?php

declare(strict_types=1);

namespace App\Services\Eur;

use App\Eur\Reference\EurYear;
use RuntimeException;

/**
 * EAR · Die beiden USt-Erklärungsbereiche (EUE-088).
 *
 * Wortlaut und Titel stammen ausschließlich aus den gepinnten Artefakten:
 * dem Regelkatalog und der Ausfüllhilfe `vat-requirements.md`. Es wird keine
 * Steuerrechtstext erfunden und keine zweite Regelquelle angelegt. Findet
 * sich ein Text nicht im Artefakt, bricht der Aufbau ab (fail closed).
 */
final class EurVatDeclarationService
{
    private const CATALOG = __DIR__.'/../../../../features/eur/cleanroom/domain-rules.json';

    private const HELP = __DIR__.'/../../../../features/eur/cleanroom/artifacts/verification/requirements/vat-requirements.md';

    /**
     * @return array{voranmeldung: array{key: string, title: string, title_upper: string, title_cell: string, year_cell: string, wirtschaftsjahr: int, text: string, source: string}, erklaerung: array{key: string, title: string, title_upper: string, title_cell: string, year_cell: string, wirtschaftsjahr: int, text: string, source: string}}
     */
    public function blocks(int $year): array
    {
        EurYear::assert($year);

        $rule = $this->rule('EUE-088');
        $titles = $this->titles((string) ($rule['logik'] ?? ''));
        $text = $this->helpText();
        $source = 'features/eur/cleanroom/artifacts/verification/requirements/vat-requirements.md#3-quartale-und-erklärungen';

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
        $catalog = json_decode((string) file_get_contents(self::CATALOG), true, 512, JSON_THROW_ON_ERROR);

        foreach ($catalog['bereiche'] ?? [] as $bereich) {
            foreach ($bereich['regeln'] ?? [] as $rule) {
                if (($rule['id'] ?? null) === $id) {
                    return $rule;
                }
            }
        }

        throw new RuntimeException(sprintf('Regel %s fehlt im gepinnten Katalog.', $id));
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
        $content = file_get_contents(self::HELP);

        if ($content === false) {
            throw new RuntimeException('Gepinnte USt-Ausfüllhilfe nicht lesbar.');
        }

        $lines = explode("\n", $content);

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
