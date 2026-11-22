<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EAR · Anlagen: Kategorie, Privateinlagen, Wechselmarke und Buchungsbezug (#34).
 *
 * Schema-/Backfill-/Rollback-Entscheidung:
 * features/eur/04-data-model.md, Abschnitt „Schema-Entscheidung V063".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (Schema::hasTable('eur_assets')) {
            $add = array_values(array_filter(
                ['category', 'is_private_investment', 'switched_to_linear_at', 'switch_reason'],
                static fn (string $column): bool => ! Schema::hasColumn('eur_assets', $column),
            ));

            if ($add !== []) {
                Schema::table('eur_assets', function (Blueprint $table) use ($add): void {
                    foreach ($add as $column) {
                        match ($column) {
                            'category' => $table->string($column, 190)->nullable(),
                            'is_private_investment' => $table->boolean($column)->default(false),
                            'switched_to_linear_at' => $table->timestamp($column)->nullable(),
                            'switch_reason' => $table->text($column)->nullable(),
                        };
                    }
                });
            }
        }

        if (Schema::hasTable('eur_asset_years')) {
            $add = array_values(array_filter(
                ['eur_entry_id', 'booked_at'],
                static fn (string $column): bool => ! Schema::hasColumn('eur_asset_years', $column),
            ));

            if ($add !== []) {
                Schema::table('eur_asset_years', function (Blueprint $table) use ($add): void {
                    if (in_array('eur_entry_id', $add, true)) {
                        $table->foreignId('eur_entry_id')->nullable()->constrained('eur_entries')->nullOnDelete();
                    }
                    if (in_array('booked_at', $add, true)) {
                        $table->timestamp('booked_at')->nullable();
                    }
                });
            }
        }
    }
};
