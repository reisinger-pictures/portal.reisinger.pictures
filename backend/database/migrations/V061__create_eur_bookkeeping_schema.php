<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EAR · Buchhaltungs-Datenmodell (Portal #27).
 *
 * Schema: Buchungen (`eur_entries`), Kunden/Projekte, Anlagegüter
 * (`eur_assets`) und deren Jahresplan (`eur_asset_years`) sowie ein
 * Audit-Log. Alle Buchhaltungstabellen tragen `brand` und werden über
 * `BrandRegistry` gescoped; fremde IDs sind damit nicht adressierbar.
 *
 * Einheiten: Geld ausschließlich als ganzzahlige `*_cents`, Zeit als ganze
 * Minuten. Anteile sind ganze Prozent in 5-%-Schritten. Es gibt keine
 * Float-Spalten.
 *
 * Backfill: keine — die Tabellen sind neu und starten leer; die bestehenden
 * Einstellungen (`settings`) bleiben die einzige Firmendatenquelle.
 *
 * Rollback: `down()` ist per Repository-Migrationspolicy absichtlich leer.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eur_customers')) {
            Schema::create('eur_customers', function (Blueprint $table): void {
                $table->id();
                $table->string('brand', 16)->index();
                $table->string('name', 190);
                $table->string('external_id', 190)->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->index(['brand', 'name']);
            });
        }

        if (! Schema::hasTable('eur_projects')) {
            Schema::create('eur_projects', function (Blueprint $table): void {
                $table->id();
                $table->string('brand', 16)->index();
                $table->foreignId('eur_customer_id')->nullable()->constrained('eur_customers')->nullOnDelete();
                $table->string('name', 190);
                $table->string('external_id', 190)->nullable();
                $table->boolean('active')->default(true);
                $table->timestamps();
                $table->index(['brand', 'name']);
            });
        }

        if (! Schema::hasTable('eur_entries')) {
            Schema::create('eur_entries', function (Blueprint $table): void {
                $table->id();
                $table->string('brand', 16)->index();
                $table->integer('year');
                $table->integer('month');
                $table->string('direction', 8);
                $table->string('kind_type', 190);
                $table->string('e1a_code', 8)->nullable();
                $table->integer('business_share');
                $table->bigInteger('amount_cents');
                $table->bigInteger('business_amount_cents');
                $table->string('vat_kind', 64)->nullable();
                $table->string('payment_method', 64)->nullable();
                $table->foreignId('eur_project_id')->nullable()->constrained('eur_projects')->nullOnDelete();
                $table->foreignId('eur_customer_id')->nullable()->constrained('eur_customers')->nullOnDelete();
                $table->string('receipt_no', 64)->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['brand', 'year', 'month']);
                $table->index(['brand', 'direction']);
            });
        }

        if (! Schema::hasTable('eur_assets')) {
            Schema::create('eur_assets', function (Blueprint $table): void {
                $table->id();
                $table->string('brand', 16)->index();
                $table->string('name', 190);
                $table->string('invoice_no', 64);
                $table->string('supplier', 190);
                $table->date('purchase_date');
                $table->bigInteger('cost_cents');
                $table->integer('useful_life_years');
                $table->integer('business_share');
                $table->string('method', 16);
                $table->integer('degressive_rate')->nullable();
                $table->string('status', 16)->default('active');
                $table->date('disposed_on')->nullable();
                $table->bigInteger('disposal_proceeds_cents')->nullable();
                $table->text('notes')->nullable();
                $table->timestamps();

                $table->index(['brand', 'status']);
            });
        }

        if (! Schema::hasTable('eur_asset_years')) {
            Schema::create('eur_asset_years', function (Blueprint $table): void {
                $table->id();
                $table->string('brand', 16)->index();
                $table->foreignId('eur_asset_id')->constrained('eur_assets')->cascadeOnDelete();
                $table->integer('year');
                $table->string('method', 16);
                $table->bigInteger('depreciation_cents');
                $table->bigInteger('book_value_cents');
                $table->integer('private_use_percent')->default(0);
                $table->bigInteger('private_use_cents')->default(0);
                $table->bigInteger('business_use_cents')->default(0);
                $table->string('status', 16)->default('planned');
                $table->timestamps();

                $table->unique(['eur_asset_id', 'year']);
            });
        }

        if (! Schema::hasTable('eur_audit_logs')) {
            Schema::create('eur_audit_logs', function (Blueprint $table): void {
                $table->id();
                $table->string('brand', 16)->index();
                $table->uuid('user_id')->nullable();
                $table->foreign('user_id')->references('id')->on('users')->nullOnDelete();
                $table->string('action', 64);
                $table->string('subject_type', 64);
                $table->unsignedBigInteger('subject_id')->nullable();
                $table->json('payload')->nullable();
                $table->timestamps();

                $table->index(['brand', 'subject_type', 'subject_id']);
            });
        }
    }
};
