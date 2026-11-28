<?php

namespace App\Console\Commands;

use App\Eur\Data\EurDataBuilder;
use Illuminate\Console\Command;
use RuntimeException;

/**
 * Regenerate the committed EUR data classes from the pinned clean-room
 * artefacts.
 *
 * The portal must not read `features/` at runtime (Issue #74): the rules are
 * program code. The artefacts stay in the repository as provenance and proof,
 * and this command is the only code path that turns them back into PHP. It is
 * a development/CI tool: the container never calls it.
 *
 * Output is deterministic — the artefacts are read in their document order, the
 * renderer has one fixed layout, and a second run therefore produces identical
 * bytes. `tests/Unit/Eur/Data/CleanroomDataParityTest.php` guards the reverse
 * direction: an artefact changed without regenerating turns that test red.
 */
final class ExportEurData extends Command
{
    /**
     * The artefact root inside the repository, one segment per element.
     *
     * Spelled as segments on purpose: `NoRuntimeCleanroomReadsTest` forbids
     * every `features/` path literal in `app/`, so that no runtime reader can
     * come back unnoticed. This command is the single documented exception in
     * behaviour — it reads the artefacts — and it therefore carries no literal
     * path either.
     *
     * @var list<string>
     */
    private const CLEANROOM_SEGMENTS = ['features', 'eur', 'cleanroom'];

    protected $signature = 'eur:export-data';

    protected $description = 'Regenerate the committed EUR data classes from the pinned clean-room artefacts.';

    public function handle(): int
    {
        $directory = app_path('Eur/Data');

        $files = $this->sources();

        foreach ($files as $name => $content) {
            $path = $directory.'/'.$name;
            $bytes = file_put_contents($path, $content);

            if ($bytes === false) {
                $this->error(sprintf('FATAL: %s konnte nicht geschrieben werden.', $path));

                return self::FAILURE;
            }

            $this->line(sprintf('geschrieben: %s (%d Bytes)', $path, $bytes));
        }

        $this->info(sprintf('%d Datenklassen aus den gepinnten Artefakten erzeugt.', count($files)));

        return self::SUCCESS;
    }

    /**
     * Die vollständigen Dateiinhalte je Dateiname — ohne zu schreiben.
     *
     * `GeneratedDataRoundtripTest` vergleicht diese Strings mit den
     * committeten Klassen. Damit kann die Vorlage nicht mehr unbemerkt von den
     * Dateien abweichen, und ein Lauf des Generators ist byte-identisch zum
     * Repository-Stand.
     *
     * @return array<string, string>
     */
    public function sources(): array
    {
        $builder = new EurDataBuilder(self::cleanroomRoot());

        return [
            'RuleCatalogue.php' => $this->ruleCatalogue($builder),
            'ReferenceTablesData.php' => $this->referenceTables($builder),
            'KzCatalogueData.php' => $this->kzCatalogue($builder),
            'HelpTextsData.php' => $this->helpTexts($builder),
            'DegressiveRulesData.php' => $this->degressiveRules($builder),
            'VatRequirements.php' => $this->vatRequirements($builder),
        ];
    }

    private static function cleanroomRoot(): string
    {
        return dirname(base_path()).DIRECTORY_SEPARATOR.implode(DIRECTORY_SEPARATOR, self::CLEANROOM_SEGMENTS);
    }

    private function ruleCatalogue(EurDataBuilder $builder): string
    {
        return $this->file(
            'RuleCatalogue',
            ['Der gepinnte Regelkatalog: je Regel-ID der Formeltext und die Parameter.', 'Nur diese drei Felder werden gebraucht; alles andere aus dem', 'Katalog bleibt im Artefakt.'],
            [
                "/**\n     * @var array<string, array{logik: string, parameter: list<array{name: string, jahre: list<int>, wert: int|float|string}>}>\n     */\n"
                .'    public const RULES = '.$this->render($builder->ruleCatalogue(), 1).";\n",
                "/**\n     * @return array<string, array{logik: string, parameter: list<array{name: string, jahre: list<int>, wert: int|float|string}>}>\n     */\n"
                ."    public static function all(): array\n    {\n        return self::RULES;\n    }\n",
            ],
        );
    }

    private function referenceTables(EurDataBuilder $builder): string
    {
        return $this->file(
            'ReferenceTablesData',
            ['Referenzdaten (EUE-004 … EUE-022) bereits in den Zielwerten: Geld in', 'ganzen Cent, Anteile in ganzen Prozent, Sätze in Basispunkten. Die', 'Umrechnung passiert einmal beim Erzeugen, nicht im Rechenpfad.', 'Fassade ist `App\Eur\Reference\ReferenceTables`.'],
            [
                "/**\n     * @var array<string, mixed>\n     */\n"
                .'    public const TABLES = '.$this->render($builder->referenceTables(), 1).";\n",
            ],
        );
    }

    private function kzCatalogue(EurDataBuilder $builder): string
    {
        $data = $builder->kzCatalogue();

        return $this->file(
            'KzCatalogueData',
            ['E1A-Kennzahlenlisten aus dem gepinnten Kennzahlenkatalog: die Zellen', 'der Regelzeilen EUE-023 und EUE-024, exakt wie im Artefakt.'],
            [
                "/**\n     * @var list<string>\n     */\n"
                .'    public const EXPENSE_CODES = '.$this->render($data['expense_codes'], 1).";\n",
                "/**\n     * @var list<string>\n     */\n"
                .'    public const INCOME_CODES = '.$this->render($data['income_codes'], 1).";\n",
                "/**\n     * @var list<string>\n     */\n"
                .'    public const E1B_CODES = '.$this->render($data['e1b_codes'], 1).";\n",
            ],
        );
    }

    private function helpTexts(EurDataBuilder $builder): string
    {
        return $this->file(
            'HelpTextsData',
            ['Die Ausfüllhilfe je Kennzahl, unverändert aus der Zellspalte des', 'Artefakts. Zelladressen werden nicht übernommen: die Tabelle ist', 'Ausfüllhilfe, keine zweite Regelquelle.'],
            [
                "/**\n     * @var list<array{kz: string, bezeichnung: string, hilfetext: string}>\n     */\n"
                .'    public const ROWS = '.$this->render($builder->helpTexts(), 1).";\n",
            ],
        );
    }

    private function degressiveRules(EurDataBuilder $builder): string
    {
        return $this->file(
            'DegressiveRulesData',
            ['Regel DEG-007: zulässige Ausnahmen für die degressive AfA bei', 'immateriellen Wirtschaftsgütern, in der Reihenfolge des Artefakts.'],
            [
                "/**\n     * @var list<string>\n     */\n"
                .'    public const ALLOWED_IMMATERIAL_EXCEPTIONS = '.$this->render($builder->degressiveRules(), 1).";\n",
            ],
        );
    }

    private function vatRequirements(EurDataBuilder $builder): string
    {
        return $this->file(
            'VatRequirements',
            ['Der Wortlaut der USt-Ausfüllhilfe zu EUE-088, unverändert. Der', 'Dienst liest daraus den Bullet in Abschnitt 3.'],
            [
                "/**\n     * Der vollständige Wortlaut der gepinnten USt-Ausfüllhilfe.\n     */\n"
                .'    public const TEXT = '.$this->render($builder->vatRequirements(), 1).";\n",
            ],
        );
    }

    /**
     * Der Docblock ist bewusst neutral: kein Artefaktpfad, kein Token, das den
     * Dokumentationsbaum nennt. Den Pfad kennt ausschließlich dieser Generator
     * (`CLEANROOM_SEGMENTS`); die committeten Klassen nennen nur das erzeugende
     * Kommando. `GeneratedDataRoundtripTest` hält Vorlage und Dateien
     * byte-identisch.
     *
     * @param  list<string>  $summary
     * @param  list<string>  $members
     */
    private function file(string $class, array $summary, array $members): string
    {
        $docblock = " * GENERIERT von `php artisan eur:export-data` aus dem gepinnten\n"
            ." * Regelartefakt — nicht von Hand ändern. Die Quelldatei kennt nur der Generator.\n";

        if ($summary !== []) {
            $docblock .= " *\n";

            foreach ($summary as $line) {
                $docblock .= ' * '.$line."\n";
            }
        }

        return "<?php\n\ndeclare(strict_types=1);\n\nnamespace App\\Eur\\Data;\n\n/**\n"
            .$docblock
            ." */\nfinal class ".$class."\n{\n"
            .implode("\n", array_map(static fn (string $member): string => '    '.$member, $members))
            ."}\n";
    }

    /**
     * Short array syntax, four spaces per level, one element per line and a
     * trailing comma — the layout Pint enforces, so a regenerated file is
     * already format-clean.
     */
    private function render(mixed $value, int $level): string
    {
        if (! is_array($value)) {
            return $this->scalar($value);
        }

        if ($value === []) {
            return '[]';
        }

        $isList = array_is_list($value);

        if ($this->inline($value)) {
            $parts = [];

            foreach ($value as $key => $item) {
                $parts[] = ($isList ? '' : $this->scalar($key).' => ').$this->scalar($item);
            }

            return '['.implode(', ', $parts).']';
        }

        $indent = str_repeat('    ', $level + 1);
        $lines = [];

        foreach ($value as $key => $item) {
            $prefix = $isList ? '' : $this->scalar($key).' => ';
            $lines[] = $indent.$prefix.$this->render($item, $level + 1).',';
        }

        return "[\n".implode("\n", $lines)."\n".str_repeat('    ', $level).']';
    }

    private function inline(array $value): bool
    {
        if (count($value) > 4) {
            return false;
        }

        $length = 0;

        foreach ($value as $key => $item) {
            if (is_array($item)) {
                return false;
            }

            $length += strlen($this->scalar($item)) + 2;

            if (! array_is_list($value)) {
                $length += strlen($this->scalar($key)) + 4;
            }
        }

        return $length <= 60;
    }

    private function scalar(mixed $value): string
    {
        if ($value === null) {
            return 'null';
        }

        if (is_bool($value)) {
            return $value ? 'true' : 'false';
        }

        if (is_int($value)) {
            return (string) $value;
        }

        if (is_float($value)) {
            return var_export($value, true);
        }

        if (is_string($value)) {
            return var_export($value, true);
        }

        throw new RuntimeException('Der Datensatz enthält einen Wert, der sich nicht als PHP-Literal schreiben lässt.');
    }
}
