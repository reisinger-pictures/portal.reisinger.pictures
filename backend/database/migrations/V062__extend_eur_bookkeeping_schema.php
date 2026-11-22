<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EÜR · Journal-Details, Belege, Stunden, Wareneingang, Anträge und Monate.
 *
 * Schema-/Backfill-/Rollback-Entscheidung:
 * features/eur/04-data-model.md, Abschnitt „Schema-Entscheidung V062“.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eur_entries')) {
            $newColumns = array_values(array_filter(
                ['day', 'category', 'description'],
                static fn (string $column): bool => ! Schema::hasColumn('eur_entries', $column),
            ));

            if ($newColumns !== []) {
                Schema::table('eur_entries', function (Blueprint $table) use ($newColumns): void {
                    foreach ($newColumns as $column) {
                        if ($column === 'day') {
                            $table->integer($column)->nullable();
                        } else {
                            $table->string($column, 190)->nullable();
                        }
                    }
                });
            }
        }

        if (! Schema::hasTable('eur_documents')) {
            Schema::create('eur_documents', function (Blueprint $table): void {
                $table->id();
                $table->string('brand', 16)->index();
                $table->integer('year');
                $table->integer('month')->nullable();
                $table->string('receipt_no', 64)->nullable();
                $table->string('original_name', 190);
                $table->string('storage_path', 255);
                $table->string('mime_type', 120);
                $table->bigInteger('size_bytes');
                $table->string('sha256', 64);
                $table->string('status', 16)->default('uploaded');
                $table->timestamps();

                $table->index(['brand', 'year', 'month']);
                $table->unique(['brand', 'sha256']);
            });
        }

        if (! Schema::hasTable('eur_hours')) {
            Schema::create('eur_hours', function (Blueprint $table): void {
                $table->id();
                $table->string('brand', 16)->index();
                $table->integer('year');
                $table->integer('month');
                $table->integer('day');
                $table->integer('minutes');
                $table->string('project', 190)->nullable();
                $table->string('description', 190)->nullable();
                $table->timestamps();

                $table->index(['brand', 'year', 'month']);
            });
        }

        if (! Schema::hasTable('eur_warehouse_entries')) {
            Schema::create('eur_warehouse_entries', function (Blueprint $table): void {
                $table->id();
                $table->string('brand', 16)->index();
                $table->integer('year');
                $table->integer('month');
                $table->integer('day')->nullable();
                $table->string('description', 190);
                $table->bigInteger('amount_cents');
                $table->string('receipt_no', 64)->nullable();
                $table->timestamps();

                $table->index(['brand', 'year', 'month']);
            });
        }

        if (! Schema::hasTable('eur_applications')) {
            Schema::create('eur_applications', function (Blueprint $table): void {
                $table->id();
                $table->string('brand', 16)->index();
                $table->integer('year');
                $table->string('type', 64);
                $table->string('subject', 190);
                $table->string('status', 16)->default('open');
                $table->text('note')->nullable();
                $table->timestamps();

                $table->index(['brand', 'year', 'status']);
            });
        }

        if (! Schema::hasTable('eur_signatures')) {
            Schema::create('eur_signatures', function (Blueprint $table): void {
                $table->id();
                $table->string('brand', 16)->index();
                $table->integer('year');
                $table->integer('month');
                $table->foreignId('eur_document_id')->nullable()->constrained('eur_documents')->nullOnDelete();
                $table->string('provider', 64);
                $table->string('status', 16)->default('pending');
                $table->timestamp('signed_at')->nullable();
                $table->text('note')->nullable();
                $table->timestamps();

                $table->unique(['brand', 'year', 'month']);
            });
        }

        if (! Schema::hasTable('eur_monthly_reports')) {
            Schema::create('eur_monthly_reports', function (Blueprint $table): void {
                $table->id();
                $table->string('brand', 16)->index();
                $table->integer('year');
                $table->integer('month');
                $table->string('status', 16)->default('open');
                $table->text('note')->nullable();
                $table->timestamp('closed_at')->nullable();
                $table->timestamps();

                $table->unique(['brand', 'year', 'month']);
            });
        }
    }
};
