<?php

declare(strict_types=1);

namespace Tests\Unit\Ea\Data;

use PHPUnit\Framework\TestCase;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;

/**
 * EAR · Issue #74 — kein Laufzeitcode darf die Clean-Room-Artefakte öffnen.
 *
 * Zwei Regeln über jede PHP-Datei unter `app/`:
 *
 * 1. Außer den Exporteur-Dateien (`ExportEaData`, `EaDataBuilder`) enthält
 *    keine Datei den Token `cleanroom` — case-insensitive und **auch in
 *    Kommentaren und Docblocks**. Den Pfad kennt nur der Generator; alle
 *    anderen Dateien lesen die generierten PHP-Konstanten.
 * 2. Außerhalb dieser zwei Dateien darf kein String-Literal einen Pfad mit
 *    `features/` bilden, während dieselbe Datei eine Datei-API benutzt
 *    (`file_get_contents`, `fopen`, `SplFileObject`, `Storage::`, `include`,
 *    `require`, `json_decode`). Das ist der Fehler aus #74: ein Laufzeitpfad
 *    in den Artefaktbaum, den der Container nicht hat (500 auf `/steuern`).
 *
 * Grenze: das ist ein Datei-Inhalt- plus Token-Scan, kein Taint-Tracking. Er
 * erkennt keinen Pfad, der über mehrere Dateien zusammengesetzt wird, und
 * keinen Dateizugriff über eine abstrahierte Helper-Funktion. Er ersetzt also
 * kein vollständiges Datenfluss-Analysewerkzeug, sondern hält die konkrete
 * Regel aus #74 billig und lesbar prüfbar.
 *
 * Zusätzlich: die sechs ehemaligen Leser enthalten keinen Dateizugriff mehr;
 * ihre Werte kommen aus `App\Ea\Data\*`. Das bleibt als Regressionsanker
 * bestehen, weil ihre Werte auch ohne `features/`-Literal gelesen werden
 * könnten (etwa über einen relativen Pfad).
 */
class NoRuntimeCleanroomReadsTest extends TestCase
{
    private const TOKEN = 'cleanroom';

    private const PATH_MARKER = 'features/';

    /** @var list<string> */
    private const EXPORTERS = [
        'app/Console/Commands/ExportEaData.php',
        'app/Ea/Data/EaDataBuilder.php',
    ];

    /** @var list<string> */
    private const FORMER_READERS = [
        'app/Ea/Parity/RuleValues.php',
        'app/Ea/Reference/ReferenceTables.php',
        'app/Ea/Reference/KzCatalogue.php',
        'app/Ea/Reference/E1aHelpTexts.php',
        'app/Ea/Reference/DegressiveRules.php',
        'app/Services/Ea/EaVatDeclarationService.php',
    ];

    /**
     * Datei-APIs, deren Vorkommen zusammen mit einem `features/`-Literal auf
     * einen Laufzeit-Leser des Dokumentationsbaums deutet.
     *
     * @var array<string, string> Label => Regex
     */
    private const FILE_APIS = [
        'file_get_contents' => '/\bfile_get_contents\s*\(/',
        'fopen' => '/\bfopen\s*\(/',
        'SplFileObject' => '/\bSplFileObject\b/',
        'Storage::' => '/\bStorage::/',
        'include' => '/\binclude\b/',
        'require' => '/\brequire\b/',
        'json_decode' => '/\bjson_decode\s*\(/',
    ];

    public function test_only_the_generator_names_the_cleanroom_token(): void
    {
        $hits = [];

        foreach ($this->phpFiles() as $path) {
            $relative = $this->relative($path);

            if (in_array($relative, self::EXPORTERS, true)) {
                continue;
            }

            foreach ($this->cleanroomLines((string) file_get_contents($path)) as $line) {
                $hits[] = $relative.':'.$line.': nennt "'.self::TOKEN.'"';
            }
        }

        $this->assertSame(
            [],
            $hits,
            'Nur der Generator darf den Artefaktbaum kennen; Laufzeitcode liest die '
            ."generierten App\\Ea\\Data-Konstanten:\n".implode("\n", $hits),
        );
    }

    public function test_no_other_source_combines_a_features_path_with_a_file_api(): void
    {
        $hits = [];

        foreach ($this->phpFiles() as $path) {
            $relative = $this->relative($path);

            if (in_array($relative, self::EXPORTERS, true)) {
                continue;
            }

            $source = (string) file_get_contents($path);
            $literals = $this->featurePathLiterals($source);

            if ($literals === []) {
                continue;
            }

            $apis = $this->fileAccessApis($source);

            if ($apis === []) {
                continue;
            }

            $hits[] = $relative.': Pfad-Literal '.implode(', ', $literals)
                .' zusammen mit '.implode(', ', $apis);
        }

        $this->assertSame(
            [],
            $hits,
            'Ein Pfad in den Dokumentationsbaum zusammen mit einer Datei-API ist ein '
            ."Laufzeit-Leser (Issue #74):\n".implode("\n", $hits),
        );
    }

    public function test_former_readers_do_not_touch_the_filesystem(): void
    {
        foreach (self::FORMER_READERS as $relative) {
            $path = dirname(__DIR__, 4).'/'.$relative;
            $this->assertFileExists($path);

            $source = (string) file_get_contents($path);

            $this->assertStringNotContainsString('file_get_contents', $source, $relative.' liest noch eine Datei.');
            $this->assertStringNotContainsString('fopen', $source, $relative.' liest noch eine Datei.');
            $this->assertStringNotContainsString('json_decode', $source, $relative.' parst noch eine Datei.');
        }
    }

    /**
     * Die Scanner selbst müssen eine absichtlich eingefügte Verletzung
     * erkennen — sonst wäre ein grüner Lauf über `app/` bedeutungslos. Der
     * Beleg läuft über künstliche Strings, nicht über eine dauerhafte
     * Verletzung im Anwendungscode.
     */
    public function test_the_scanners_detect_an_injected_violation(): void
    {
        $offending = <<<'PHP'
        <?php
        // Der Regelbaum liegt unter features/eur/cleanroom.
        $path = base_path('features/eur/cleanroom/domain-rules.json');
        $rules = json_decode(file_get_contents($path), true);
        PHP;

        $this->assertSame([2, 3], $this->cleanroomLines($offending));
        $this->assertNotSame([], $this->featurePathLiterals($offending));
        $this->assertSame(
            ['file_get_contents', 'json_decode'],
            $this->fileAccessApis($offending),
        );

        // Ein reiner Kommentar-Verweis ist für Regel 1 eine Verletzung, für
        // Regel 2 (String-Literal) nicht — die Trennung ist gewollt.
        $commentOnly = <<<'PHP'
        <?php
        // Verweis auf den Cleanroom-Baum ohne Pfad-Literal.
        $year = 2026;
        PHP;

        $this->assertSame([2], $this->cleanroomLines($commentOnly));
        $this->assertSame([], $this->featurePathLiterals($commentOnly));
        $this->assertSame([], $this->fileAccessApis($commentOnly));

        $clean = <<<'PHP'
        <?php
        $year = 2026;
        PHP;

        $this->assertSame([], $this->cleanroomLines($clean));
        $this->assertSame([], $this->featurePathLiterals($clean));
        $this->assertSame([], $this->fileAccessApis($clean));
    }

    /**
     * @return list<string>
     */
    private function phpFiles(): array
    {
        $root = dirname(__DIR__, 4).'/app';
        $files = [];

        /** @var SplFileInfo $file */
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root)) as $file) {
            if ($file->isFile() && $file->getExtension() === 'php') {
                $files[] = $file->getPathname();
            }
        }

        sort($files);

        return $files;
    }

    private function relative(string $path): string
    {
        return substr($path, strlen(dirname(__DIR__, 4).'/'));
    }

    /**
     * Alle Zeilennummern, die den Token nennen (case-insensitive, überall —
     * auch in Kommentaren und Docblocks).
     *
     * @return list<int>
     */
    private function cleanroomLines(string $source): array
    {
        $lines = [];

        foreach (explode("\n", $source) as $index => $line) {
            if (str_contains(mb_strtolower($line), self::TOKEN)) {
                $lines[] = $index + 1;
            }
        }

        return $lines;
    }

    /**
     * String-Literale, die einen Pfad mit `features/` bilden.
     *
     * @return list<string>
     */
    private function featurePathLiterals(string $source): array
    {
        $literals = [];

        foreach (token_get_all($source) as $token) {
            if (! is_array($token)) {
                continue;
            }

            if (! in_array($token[0], [T_CONSTANT_ENCAPSED_STRING, T_ENCAPSED_AND_WHITESPACE], true)) {
                continue;
            }

            if (str_contains(mb_strtolower($token[1]), self::PATH_MARKER)) {
                $literals[] = trim($token[1]);
            }
        }

        return $literals;
    }

    /**
     * Die Datei-APIs, die in dieser Quelle vorkommen (Label in der Reihenfolge
     * von {@see self::FILE_APIS}).
     *
     * @return list<string>
     */
    private function fileAccessApis(string $source): array
    {
        $found = [];

        foreach (self::FILE_APIS as $label => $pattern) {
            if (preg_match($pattern, $source) === 1) {
                $found[] = $label;
            }
        }

        return $found;
    }
}
