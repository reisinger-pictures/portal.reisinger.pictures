<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * EAR · Anlagenverzeichnisse je Jahr und private Eigenbelege (Portal #53, D-85).
 *
 * Schema: `eur_assets` und `eur_asset_years` erhalten ein nullable `scope`
 * (16), `eur_asset_years` zusätzlich das nullable `carry_over_from_year`
 * (integer) sowie die nullable Eigenbeleg-Felder `eigenbeleg_no`,
 * `theoretical_depreciation_cents`, `usage_percent`, `eigenbeleg_cents` und
 * `eigenbeleg_created_at`. Alle Geldbeträge sind ganze Cent (bigint). Die
 * neue Tabelle `eur_asset_documents` verknüpft Anlagegut und Beleg am Stamm,
 * ohne Jahresspalte, mit `brand`, Timestamps und einem Unique-Paar.
 *
 * Backfill: `scope` wird aus dem vorhandenen ganzzahligen `business_share`
 * abgeleitet (< 50 → private, sonst business) und nur auf Zeilen mit `scope
 * is null` gesetzt. Das ist keine erfundene INFORMATION: die alte Validierung
 * erlaubte nur 50–100 %, vorhandene Zeilen sind also ausnahmslos business.
 * `carry_over_from_year` und die Eigenbeleg-Felder bleiben null; es werden
 * keine Belege, Eigenbelege oder AfA-Werte erfunden.
 *
 * Rollback: `down()` ist per Repository-Policy absichtlich leer. Alle
 * Änderungen sind additiv und nullable; ein Entfernen verliert nur die
 * Verzeichniszuordnung, die Carry-over-Herkunft, die Eigenbeleg-Dokumentation
 * und die Belegverknüpfung, nicht die Anlagegüter, AfA-Pläne oder Belege.
 *
 * Schema-/Backfill-/Rollback-Entscheidung:
 * features/eur/04-data-model.md, Abschnitt „Schema-Entscheidung V071".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eur_assets') && ! Schema::hasColumn('eur_assets', 'scope')) {
            Schema::table('eur_assets', function (Blueprint $table): void {
                $table->string('scope', 16)->nullable()->after('business_share');
            });
        }

        if (Schema::hasTable('eur_asset_years')) {
            $add = array_values(array_filter(
                [
                    'scope',
                    'carry_over_from_year',
                    'eigenbeleg_no',
                    'theoretical_depreciation_cents',
                    'usage_percent',
                    'eigenbeleg_cents',
                    'eigenbeleg_created_at',
                ],
                static fn (string $column): bool => ! Schema::hasColumn('eur_asset_years', $column),
            ));

            if ($add !== []) {
                Schema::table('eur_asset_years', function (Blueprint $table) use ($add): void {
                    foreach ($add as $column) {
                        match ($column) {
                            'scope' => $table->string($column, 16)->nullable()->after('year'),
                            'carry_over_from_year' => $table->integer($column)->nullable()->after('scope'),
                            'eigenbeleg_no' => $table->string($column, 64)->nullable()->after('carry_over_from_year'),
                            'theoretical_depreciation_cents' => $table->bigInteger($column)->nullable()->after('eigenbeleg_no'),
                            'usage_percent' => $table->integer($column)->nullable()->after('theoretical_depreciation_cents'),
                            'eigenbeleg_cents' => $table->bigInteger($column)->nullable()->after('usage_percent'),
                            'eigenbeleg_created_at' => $table->timestamp($column)->nullable()->after('eigenbeleg_cents'),
                        };
                    }
                });
            }
        }

        if (Schema::hasTable('eur_assets') && Schema::hasColumn('eur_assets', 'scope')) {
            DB::table('eur_assets')
                ->select(['id', 'business_share'])
                ->orderBy('id')
                ->chunkById(500, static function ($assets): void {
                    foreach ($assets as $asset) {
                        $scope = (int) $asset->business_share < 50 ? 'private' : 'business';

                        DB::table('eur_assets')->where('id', $asset->id)->whereNull('scope')->update(['scope' => $scope]);

                        if (Schema::hasTable('eur_asset_years') && Schema::hasColumn('eur_asset_years', 'scope')) {
                            DB::table('eur_asset_years')
                                ->where('eur_asset_id', $asset->id)
                                ->whereNull('scope')
                                ->update(['scope' => $scope]);
                        }
                    }
                });
        }

        if (! Schema::hasTable('eur_asset_documents')) {
            Schema::create('eur_asset_documents', function (Blueprint $table): void {
                $table->id();
                $table->string('brand', 16)->index();
                $table->foreignId('eur_asset_id')->constrained('eur_assets')->cascadeOnDelete();
                $table->foreignId('eur_document_id')->constrained('eur_documents')->cascadeOnDelete();
                $table->timestamps();

                $table->unique(['eur_asset_id', 'eur_document_id']);
            });
        }
    }
};
