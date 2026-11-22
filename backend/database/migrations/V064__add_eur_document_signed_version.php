<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * EAR · Extern signierte Fassung eines Belegs (Content-Identity, keine QES).
 *
 * Schema-/Backfill-/Rollback-Entscheidung:
 * features/eur/04-data-model.md, Abschnitt „Signierte Fassung eines Belegs".
 */
return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasTable('eur_documents')) {
            return;
        }

        $add = array_values(array_filter(
            ['signed_storage_path', 'signed_sha256', 'signed_mime_type', 'signed_size_bytes', 'signed_at'],
            static fn (string $column): bool => ! Schema::hasColumn('eur_documents', $column),
        ));

        if ($add === []) {
            return;
        }

        Schema::table('eur_documents', function (Blueprint $table) use ($add): void {
            foreach ($add as $column) {
                match ($column) {
                    'signed_storage_path' => $table->string($column, 255)->nullable(),
                    'signed_sha256' => $table->string($column, 64)->nullable(),
                    'signed_mime_type' => $table->string($column, 40)->nullable(),
                    'signed_size_bytes' => $table->bigInteger($column)->nullable(),
                    'signed_at' => $table->timestamp($column)->nullable(),
                };
            }
        });
    }
};
