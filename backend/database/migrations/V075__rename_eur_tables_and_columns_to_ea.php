<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

/**
 * EA Rechnung · Namensvereinheitlichung `eur` → `ea` (Portal #74–#78).
 *
 * Schema: Die 16 Tabellen der Buchhaltungsfunktion und die sechs Fremdschlüssel-
 * spalten mit `eur_`-Praefix werden auf `ea_` umbenannt. Index- und
 * Constraint-Namen behalten ihre historischen Namen — sie sind kein
 * Anwendungs- und kein API-Vertrag und werden nicht angefasst.
 *
 * Backfill: keiner. `Schema::rename` und `renameColumn` aendern nur Namen,
 * keine Zeilen, Werte oder Typen. Alle bestehenden Buchungen, Anlagen,
 * Belege und Signaturen bleiben inhaltlich unveraendert.
 *
 * Rollback: `down()` benennt Spalten und Tabellen in umgekehrter Reihenfolge
 * zurueck. Vorhandene Zeilen bleiben dabei erhalten.
 *
 * Schema-/Backfill-/Rollback-Entscheidung:
 * features/tech/07-architectural-decisions.md (AD-2) und
 * features/ea/04-data-model.md, Abschnitt „Schema-Entscheidung V075".
 */
return new class extends Migration
{
    /** @var list<string> */
    private array $tables = [
        'eur_applications', 'eur_asset_documents', 'eur_asset_group_years',
        'eur_asset_groups', 'eur_asset_years', 'eur_assets', 'eur_audit_logs',
        'eur_customers', 'eur_documents', 'eur_entries', 'eur_hours',
        'eur_monthly_reports', 'eur_projects', 'eur_signatures',
        'eur_travel_trips', 'eur_warehouse_entries',
    ];

    /** @var array<string, string> */
    private array $columns = [
        'eur_asset_group_id' => 'ea_asset_group_id',
        'eur_asset_id' => 'ea_asset_id',
        'eur_customer_id' => 'ea_customer_id',
        'eur_document_id' => 'ea_document_id',
        'eur_entry_id' => 'ea_entry_id',
        'eur_project_id' => 'ea_project_id',
    ];

    public function up(): void
    {
        // Erst die Spalten auf den noch alten Tabellennamen, dann die Tabellen.
        foreach ($this->renameColumns() as $table => $pairs) {
            Schema::table($table, function ($blueprint) use ($pairs): void {
                foreach ($pairs as $old => $new) {
                    $blueprint->renameColumn($old, $new);
                }
            });
        }

        foreach ($this->tables as $table) {
            if (Schema::hasTable($table)) {
                Schema::rename($table, 'ea'.substr($table, 3));
            }
        }
    }

    public function down(): void
    {
        foreach (array_reverse($this->tables) as $table) {
            $old = 'ea'.substr($table, 3);
            if (Schema::hasTable($old)) {
                Schema::rename($old, $table);
            }
        }

        foreach ($this->renameColumns() as $table => $pairs) {
            Schema::table($table, function ($blueprint) use ($pairs): void {
                foreach (array_reverse($pairs, true) as $old => $new) {
                    $blueprint->renameColumn($new, $old);
                }
            });
        }
    }

    /** @return array<string, array<string, string>> */
    private function renameColumns(): array
    {
        $found = [];
        foreach ($this->tables as $table) {
            if (! Schema::hasTable($table)) {
                continue;
            }
            foreach ($this->columns as $old => $new) {
                if (Schema::hasColumn($table, $old)) {
                    $found[$table][$old] = $new;
                }
            }
        }

        return $found;
    }
};
