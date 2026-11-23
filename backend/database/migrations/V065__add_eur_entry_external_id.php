<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EAR · Idempotente Übernahme automatisch versandter Rechnungen (Portal #16).
 *
 * `eur_entries.receipt_no` ist Freitext, nullable, nicht eindeutig und wird
 * auch von Handbuchungen und dem CSV-Import beschrieben. Es ist daher kein
 * tragfähiger Schlüssel für Replay-Schutz. `external_id` ist der reservierte,
 * technische Schlüssel der Übernahme; die Eindeutigkeit liegt pro Brand bei
 * `(brand, external_id)`.
 *
 * Schema-/Backfill-/Rollback-Entscheidung:
 * features/eur/04-data-model.md, Abschnitt „Schema-Entscheidung V065".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eur_entries')) {
            return;
        }

        if (! Schema::hasColumn('eur_entries', 'external_id')) {
            Schema::table('eur_entries', function (Blueprint $table): void {
                $table->string('external_id', 190)->nullable();
            });
        }

        if (! Schema::hasIndex('eur_entries', 'eur_entries_brand_external_id_unique')) {
            Schema::table('eur_entries', function (Blueprint $table): void {
                $table->unique(['brand', 'external_id'], 'eur_entries_brand_external_id_unique');
            });
        }
    }
};
