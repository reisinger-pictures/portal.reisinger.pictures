<?php

declare(strict_types=1);

namespace Tests\Unit\Ea\Data;

use App\Console\Commands\ExportEaData;
use Tests\TestCase;

/**
 * EAR · Issue #74 — Generator und committete Datenklassen dürfen nicht
 * auseinanderlaufen.
 *
 * Der Generator ist die einzige Stelle, die den Artefaktbaum kennt; die
 * committeten Klassen tragen einen neutralen Docblock ohne Artefaktpfad.
 * Deshalb muss `php artisan ea:export-data` byte-identisch zum
 * Repository-Stand rendern. Der Test liest die Strings aus
 * {@see ExportEaData::sources()} und vergleicht sie mit den Dateien; er
 * schreibt selbst nichts.
 */
class GeneratedDataRoundtripTest extends TestCase
{
    public function test_committed_data_classes_are_exactly_what_the_generator_renders(): void
    {
        $sources = app(ExportEaData::class)->sources();

        $this->assertCount(6, $sources);

        foreach ($sources as $file => $source) {
            $path = app_path('Ea/Data/'.$file);
            $this->assertFileExists($path);
            $this->assertSame(
                (string) file_get_contents($path),
                $source,
                $file.' weicht vom Generator ab; `php artisan ea:export-data` ausführen.',
            );
        }
    }

    /**
     * Der erzeugte Docblock verweist nur auf das Kommando: kein `features/`-Pfad
     * und keine Formulierung, die den Artefaktbaum zurück in `app/` trägt.
     */
    public function test_the_rendered_docblocks_name_no_artifact_path(): void
    {
        foreach (app(ExportEaData::class)->sources() as $file => $source) {
            $this->assertStringContainsString('GENERIERT von `php artisan ea:export-data`', $source, $file);
            $this->assertStringNotContainsString('features/', $source, $file.' baut einen features/-Pfad ein.');
        }
    }
}
