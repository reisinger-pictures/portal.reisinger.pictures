<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Restructures the photo job workflow: 'shooting' → 'importiert', 'export' →
 * 'exportiert', and removes 'veroeffentlicht'. New status list:
 * importiert, culling, bearbeitung, exportiert, abgebrochen.
 * Initial status becomes 'importiert'.
 *
 * The column is first relaxed to VARCHAR(20), because strict mode rejects new
 * enum values written into the old ENUM column; after the data migration the
 * column is constrained back to the new ENUM.
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('photo_jobs', fn (Blueprint $t) => $t->string('status', 20)->default('importiert')->change());
        } else {
            DB::statement("ALTER TABLE photo_jobs MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'importiert'");
        }
        DB::statement("UPDATE photo_jobs SET status = 'importiert' WHERE status = 'shooting'");
        DB::statement("UPDATE photo_jobs SET status = 'exportiert' WHERE status = 'export'");
        DB::statement("UPDATE photo_jobs SET status = 'exportiert' WHERE status = 'veroeffentlicht'");
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('photo_jobs', fn (Blueprint $t) => $t->enum('status', ['importiert', 'culling', 'bearbeitung', 'exportiert', 'abgebrochen'])->default('importiert')->change());
        } else {
            DB::statement("ALTER TABLE photo_jobs MODIFY COLUMN status ENUM('importiert','culling','bearbeitung','exportiert','abgebrochen') NOT NULL DEFAULT 'importiert'");
        }
    }

    public function down(): void
    {
    }
};
