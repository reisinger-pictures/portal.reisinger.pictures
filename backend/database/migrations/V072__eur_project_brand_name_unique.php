<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;

/**
 * EAR · Eindeutiger Projektstamm pro Brand (EUE-026, Owner-Entscheidung 2026-11-24).
 *
 * Projekte werden jahresübergreifend als eine Zeile pro Brand gespeichert;
 * Buchungen und Stunden tragen ihr eigenes Jahr. Ein Projektname ist damit pro
 * Brand genau einmal erlaubt. `eur_projects_brand_name_index` aus V061 wird
 * durch den Unique-Index `eur_projects_brand_name_unique` ersetzt — der alte
 * Index ist mit dem neuen redundanzfrei abgedeckt (`brand` als linke Spalte).
 *
 * Fail-closed preflight: vorhandene doppelte `(brand, name)`-Paare würden den
 * Unique-Index scheitern lassen. Die Migration bricht dann mit einer benannten
 * FATAL-Meldung ab und führt keine Zeilen zusammen, benennt oder löscht
 * nichts. Die Bereinigung bleibt eine bewusste Entscheidung mit eigener
 * Migration. Auf einer frischen Datenbank gibt es keine Projekte.
 *
 * Backfill: keiner; der Unique-Index ist rein strukturell.
 *
 * Rollback: `down()` ist nach Repository-Policy absichtlich leer. Ein
 * Entfernen des Index würde doppelte Namen wieder zulassen, aber keine Zeile
 * verändern.
 *
 * Schema-/Backfill-/Rollback-Entscheidung:
 * features/eur/04-data-model.md, Abschnitt „Schema-Entscheidung V072".
 */
return new class extends Migration
{
    private const TABLE = 'eur_projects';

    private const OLD_INDEX = 'eur_projects_brand_name_index';

    private const NEW_INDEX = 'eur_projects_brand_name_unique';

    private const MAX_REPORT_GROUPS = 5;

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            throw new RuntimeException('V072 requires table ['.self::TABLE.'].');
        }

        // Eine abgeschlossene Wiederholung ist ein No-op.
        if (Schema::hasIndex(self::TABLE, self::NEW_INDEX)) {
            return;
        }

        $duplicates = DB::table(self::TABLE)
            ->select('brand', 'name')
            ->selectRaw('COUNT(*) as occurrences')
            ->groupBy('brand', 'name')
            ->havingRaw('COUNT(*) > 1')
            ->orderBy('brand')
            ->orderBy('name')
            ->limit(self::MAX_REPORT_GROUPS)
            ->get()
            ->all();

        if ($duplicates !== []) {
            $groups = array_map(
                static fn (object $row): string => sprintf(
                    '%s/%s (%d)',
                    (string) $row->brand,
                    (string) $row->name,
                    (int) $row->occurrences,
                ),
                $duplicates,
            );

            $report = 'FATAL V072: duplicate (brand, name) in '.self::TABLE
                .'; refusing to add UNIQUE. Groups: '.implode(', ', $groups);
            Log::error('V072 project identity preflight failed', ['report' => $report]);

            throw new RuntimeException($report);
        }

        if (Schema::hasIndex(self::TABLE, self::OLD_INDEX)) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->dropIndex(self::OLD_INDEX);
            });
        }

        Schema::table(self::TABLE, function (Blueprint $table): void {
            $table->unique(['brand', 'name'], self::NEW_INDEX);
        });
    }

    public function down(): void
    {
        // Per Repository-Policy werden deployte Migrationen nicht zurückgerollt.
    }
};
