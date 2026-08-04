<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

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
        DB::statement("ALTER TABLE photo_jobs MODIFY COLUMN status VARCHAR(20) NOT NULL DEFAULT 'importiert'");
        DB::statement("UPDATE photo_jobs SET status = 'importiert' WHERE status = 'shooting'");
        DB::statement("UPDATE photo_jobs SET status = 'exportiert' WHERE status = 'export'");
        DB::statement("UPDATE photo_jobs SET status = 'exportiert' WHERE status = 'veroeffentlicht'");
        DB::statement("ALTER TABLE photo_jobs MODIFY COLUMN status ENUM('importiert','culling','bearbeitung','exportiert','abgebrochen') NOT NULL DEFAULT 'importiert'");
    }

    public function down(): void
    {
    }
};
