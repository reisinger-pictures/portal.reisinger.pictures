<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EAR · Reise-Verdrahtung (Portal #53, EUE-067/073/077/081).
 *
 * Schema: `eur_travel_trips` erhält Belegnummer, Start-/Endkilometerstand,
 * Veranlassung und die serverseitig gesetzte Reisekennzahl 9160. Alle Spalten
 * sind nullable, damit die Migration rein additiv bleibt; die Pflicht zur
 * Belegnummer und die Entweder-oder-Regel für die Distanz werden an der
 * Schreibgrenze geprüft, nicht als nachträgliche Datenbank-Constraints.
 *
 * Backfill: keiner. Bestehende Reisen behalten null und ihre bisherigen
 * Cent-Beträge; es wird keine Belegnummer aus Notizen erfunden.
 *
 * Rollback: `down()` ist per Repository-Policy absichtlich leer.
 *
 * Schema-/Backfill-/Rollback-Entscheidung:
 * features/eur/04-data-model.md, Abschnitt „Schema-Entscheidung V070".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eur_travel_trips')) {
            return;
        }

        $add = array_values(array_filter(
            ['receipt_no', 'start_km', 'end_km', 'purpose', 'e1a_code'],
            static fn (string $column): bool => ! Schema::hasColumn('eur_travel_trips', $column),
        ));

        if ($add === []) {
            return;
        }

        Schema::table('eur_travel_trips', function (Blueprint $table) use ($add): void {
            foreach ($add as $column) {
                if ($column === 'receipt_no') {
                    $table->string($column, 64)->nullable()->after('month');
                } elseif ($column === 'purpose') {
                    $table->string($column, 32)->nullable()->after('country');
                } elseif ($column === 'e1a_code') {
                    $table->string($column, 8)->nullable()->after('purpose');
                } else {
                    $table->bigInteger($column)->nullable()->after('kilometres');
                }
            }
        });
    }
};
