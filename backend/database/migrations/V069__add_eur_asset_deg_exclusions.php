<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EAR · DEG-Ausschlussmerkmale (Portal #53, DEG-006/007/008).
 *
 * Schema: `eur_assets` erhält vier Booleans mit `default false` und das
 * nullable Enum `immaterial_exception`. Bestehende Zeilen bleiben damit in der
 * degressiven Methode zulässig; es wird kein Merkmal aus Name/Kategorie
 * abgeleitet. Die Prüfung läuft an der Schreibgrenze (422), nicht als
 * Datenbank-Constraint — die zulässigen Ausnahmen stehen im gepinnten
 * DEG-Artefakt und dürfen nicht in der Datenbank dupliziert werden.
 *
 * Backfill: keiner. Bereits berechnete AfA-Pläne und ein vollzogener Wechsel
 * werden nicht verändert.
 *
 * Rollback: `down()` ist per Repository-Policy absichtlich leer; die Spalten
 * sind additiv und haben Defaultwerte.
 *
 * Schema-/Backfill-/Rollback-Entscheidung:
 * features/eur/04-data-model.md, Abschnitt „Schema-Entscheidung V069".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eur_assets')) {
            return;
        }

        $add = array_values(array_filter(
            ['is_used', 'is_immaterial', 'immaterial_exception', 'special_depreciation', 'is_electric_vehicle'],
            static fn (string $column): bool => ! Schema::hasColumn('eur_assets', $column),
        ));

        if ($add === []) {
            return;
        }

        Schema::table('eur_assets', function (Blueprint $table) use ($add): void {
            foreach ($add as $column) {
                if ($column === 'immaterial_exception') {
                    $table->string($column, 32)->nullable()->after('is_immaterial');
                } else {
                    $table->boolean($column)->default(false)->after('is_private_investment');
                }
            }
        });
    }
};
