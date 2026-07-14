<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    /**
     * Migrate all rows with brand='srp' to brand='rp' across 14 tables.
     *
     * Tables with simple UPDATE: orders, invoice_snapshots, users, galleries,
     * gallery_groups, orgs, products, license_use_cases, license_modifiers,
     * customers, text_snippets, coupons, contracts.
     *
     * Settings table (special handling): update rows whose key does NOT already
     * exist with brand='rp', then delete the remaining conflicted rows.
     */
    public function up(): void
    {
        $tables = [
            'orders',
            'invoice_snapshots',
            'users',
            'galleries',
            'gallery_groups',
            'orgs',
            'products',
            'license_use_cases',
            'license_modifiers',
            'customers',
            'text_snippets',
            'coupons',
            'contracts',
        ];

        foreach ($tables as $table) {
            DB::statement("UPDATE {$table} SET brand = 'rp' WHERE brand = 'srp'");
        }

        // Settings: update non-conflicting rows first
        DB::statement("
            UPDATE settings
            SET brand = 'rp'
            WHERE brand = 'srp'
            AND `key` NOT IN (
                SELECT `key` FROM (SELECT `key` FROM settings WHERE brand = 'rp') AS rp_keys
            )
        ");

        // Settings: delete remaining SRP rows that would cause logical duplicates
        DB::statement("DELETE FROM settings WHERE brand = 'srp'");
    }

    /**
     * Not safely reversible – we no longer know which rows were originally SRP
     * after the merge.
     */
    public function down(): void
    {
        //
    }
};
