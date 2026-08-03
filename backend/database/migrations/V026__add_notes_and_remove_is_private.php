<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Adds a free-text `notes` column to both kanban board tables and removes the
 * unused `is_private` flag from `photo_jobs`.
 * Notes are used to capture arbitrary internal notes on projects and photo jobs.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('photo_jobs', function (Blueprint $table) {
            $table->text('notes')->nullable();
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->text('notes')->nullable();
        });

        Schema::table('photo_jobs', function (Blueprint $table) {
            $table->dropColumn('is_private');
        });
    }

    public function down(): void
    {
        Schema::table('photo_jobs', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('projects', function (Blueprint $table) {
            $table->dropColumn('notes');
        });

        Schema::table('photo_jobs', function (Blueprint $table) {
            $table->boolean('is_private')->default(false);
        });
    }
};
