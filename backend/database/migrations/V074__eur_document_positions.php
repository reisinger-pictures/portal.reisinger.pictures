<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EAR · Beleg-Positionen als angereicherte Pivot (Portal #59, D-90, 2026-11-24).
 *
 * Ein Beleg kann mehrere Positionen enthalten und mehrere Belege können eine
 * Abrechnungsgruppe bilden. Beide Richtungen nutzen die bereits bestehende
 * echte Many-to-Many-Pivot `eur_asset_documents` — es entsteht keine neue
 * Positions-Haupttabelle und kein gruppeneigener Belegspeicher (D-89 bleibt).
 *
 * Schema: `eur_asset_documents` erhält die nullable Spalten `label` (string),
 * `amount_cents` (bigint, ganze Cent) und `ordinal` (unsigned integer); der
 * bestehende Unique-Index `(eur_asset_id, eur_document_id)` bleibt unberührt,
 * zusätzlich gibt es den Index `(brand, eur_document_id, ordinal)`.
 * `eur_documents` erhält das nullable `total_cents` (bigint) als optionalen
 * Belegbetrag. Alle neuen Spalten sind nullable.
 *
 * Backfill: keiner. Bestehende Verknüpfungen behalten NULL-Werte und bleiben
 * gültige Belegzuordnungen; es werden keine Beträge erfunden.
 *
 * Rollback: `down()` ist nach Repository-Policy absichtlich leer.
 *
 * Schema-/Backfill-/Rollback-Entscheidung:
 * features/eur/04-data-model.md, Abschnitt „Schema-Entscheidung V074";
 * Eigentümer-Entscheidung D-90 in features/owner-decisions.md, Issue #59.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eur_asset_documents')) {
            Schema::table('eur_asset_documents', function (Blueprint $table): void {
                if (! Schema::hasColumn('eur_asset_documents', 'label')) {
                    $table->string('label', 190)->nullable()->after('eur_document_id');
                }
                if (! Schema::hasColumn('eur_asset_documents', 'amount_cents')) {
                    $table->bigInteger('amount_cents')->nullable()->after('label');
                }
                if (! Schema::hasColumn('eur_asset_documents', 'ordinal')) {
                    $table->unsignedInteger('ordinal')->nullable()->after('amount_cents');
                }

            });
        }

        if (Schema::hasTable('eur_asset_documents') && ! Schema::hasIndex('eur_asset_documents', 'eur_asset_documents_doc_ordinal_index')) {
            Schema::table('eur_asset_documents', function (Blueprint $table): void {
                $table->index(['brand', 'eur_document_id', 'ordinal'], 'eur_asset_documents_doc_ordinal_index');
            });
        }

        if (Schema::hasTable('eur_documents') && ! Schema::hasColumn('eur_documents', 'total_cents')) {
            Schema::table('eur_documents', function (Blueprint $table): void {
                $table->bigInteger('total_cents')->nullable()->after('size_bytes');
            });
        }
    }

    public function down(): void
    {
        // Per Repository-Policy werden deployte Migrationen nicht zurückgerollt.
    }
};
