<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EAR · Anlagegruppen als eigene Abrechnungseinheit (Portal #57, 2026-11-24).
 *
 * Eine Gruppe bündelt mehrere wirtschaftlich zusammengehörige Anlagegüter
 * (z. B. Objektiv + Streulichtblende) zu EINER Abrechnungseinheit: der
 * Betriebsanteil liegt am Gruppenstamm und überschreibt den Anteil der
 * Mitglieder. Belege bleiben bewusst an den einzelnen Anlagegütern (Eigentümer-
 * Entscheidung 2026-11-24), es gibt also keine gruppenbezogenen Belege.
 *
 * Schema: neue Tabelle `eur_asset_groups` (Brand, Name, Betriebsanteil),
 * nullable `eur_asset_group_id` an `eur_assets` sowie die neue Tabelle
 * `eur_asset_group_years` für den einen privaten Eigenbeleg je Gruppe und Jahr.
 * Alle Geldbeträge sind ganze Cent (bigint).
 *
 * Backfill: keiner. Bestehende Anlagegüter bleiben einzeln abgerechnet, bis
 * ein Eigentümer sie ausdrücklich einer Gruppe zuordnet.
 *
 * Rollback: `down()` ist nach Repository-Policy absichtlich leer.
 *
 * Schema-/Backfill-/Rollback-Entscheidung:
 * features/eur/04-data-model.md, Abschnitt „Schema-Entscheidung V073".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eur_asset_groups')) {
            Schema::create('eur_asset_groups', function (Blueprint $table): void {
                $table->id();
                $table->string('brand', 16)->index();
                $table->string('name', 190);
                $table->integer('business_share');
                $table->timestamps();

                $table->unique(['brand', 'name'], 'eur_asset_groups_brand_name_unique');
            });
        }

        if (Schema::hasTable('eur_assets') && ! Schema::hasColumn('eur_assets', 'eur_asset_group_id')) {
            Schema::table('eur_assets', function (Blueprint $table): void {
                $table->foreignId('eur_asset_group_id')->nullable()->after('scope')
                    ->constrained('eur_asset_groups')->nullOnDelete();
                $table->index(['brand', 'eur_asset_group_id'], 'eur_assets_brand_group_index');
            });
        }

        if (! Schema::hasTable('eur_asset_group_years')) {
            Schema::create('eur_asset_group_years', function (Blueprint $table): void {
                $table->id();
                $table->string('brand', 16)->index();
                $table->foreignId('eur_asset_group_id')->constrained('eur_asset_groups')->cascadeOnDelete();
                $table->integer('year');
                $table->bigInteger('depreciation_cents');
                $table->bigInteger('book_value_cents');
                $table->string('eigenbeleg_no', 64)->nullable();
                $table->bigInteger('theoretical_depreciation_cents')->nullable();
                $table->integer('usage_percent')->nullable();
                $table->bigInteger('eigenbeleg_cents')->nullable();
                $table->timestamp('eigenbeleg_created_at')->nullable();
                $table->string('status', 16)->default('planned');
                $table->timestamps();

                $table->unique(['eur_asset_group_id', 'year'], 'eur_asset_group_years_group_year_unique');
            });
        }
    }

    public function down(): void
    {
        // Per Repository-Policy werden deployte Migrationen nicht zurückgerollt.
    }
};
