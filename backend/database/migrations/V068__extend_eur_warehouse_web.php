<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EAR · WEB-Datenmodell (Portal #53, D-83b).
 *
 * Schema: `eur_warehouse_entries` erhält die drei fehlenden WEB-Felder
 * `supplier` (Lieferant), `expense_kind` (Ausgaben-Art) und `business_share`
 * (betrieblicher Anteil in ganzen Prozent). Alle drei sind nullable, damit die
 * Migration rein additiv bleibt: das WEB existiert seit V062 und kann bereits
 * Zeilen enthalten. Die Pflichtfelder nach EUE-047 werden an der
 * Schreibgrenze geprüft (422), nicht als nachträgliche NOT-NULL-Constraint.
 * Der Betriebsanteil wird aus der gepinnten Ausgabenarten-Zuordnung des
 * Referenzartefakts abgeleitet, nicht vom Client übernommen (EUE-045).
 *
 * Backfill: keiner. Bestehende Zeilen behalten `null` und werden in der
 * Antwort als null ausgeliefert; es gibt keine Annahme über einen früheren
 * Lieferanten oder eine frühere Ausgaben-Art.
 *
 * Rollback: `down()` ist per Repository-Policy absichtlich leer; keine
 * bestehende Spalte wird verändert oder entfernt.
 *
 * Schema-/Backfill-/Rollback-Entscheidung:
 * features/eur/04-data-model.md, Abschnitt „Schema-Entscheidung V068“.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eur_warehouse_entries')) {
            return;
        }

        $newColumns = array_values(array_filter(
            ['supplier', 'expense_kind', 'business_share'],
            static fn (string $column): bool => ! Schema::hasColumn('eur_warehouse_entries', $column),
        ));

        if ($newColumns === []) {
            return;
        }

        Schema::table('eur_warehouse_entries', function (Blueprint $table) use ($newColumns): void {
            foreach ($newColumns as $column) {
                if ($column === 'business_share') {
                    $table->integer($column)->nullable()->after('amount_cents');
                } else {
                    $table->string($column, 190)->nullable()->after('description');
                }
            }
        });
    }
};
