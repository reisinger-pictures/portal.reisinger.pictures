<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Brand as a DB-native ENUM ('rp','atr') primary concept.
 *
 * - Adds `brand` ENUM('rp','atr') NULL to all brand-scoped tables: orders, invoice_snapshots,
 *   users, galleries, gallery_groups, tenants.
 * - Backfills existing rows to 'rp' (B2B = historical default). NULL stays NULL on the new
 *   tables only where a row is genuinely cross-brand; in practice every existing row becomes
 *   'rp' since all historical data belongs to the B2B brand.
 * - Indexes every brand column for brand-scoping queries.
 *
 * See features/infrastructure/12-brand-registry-and-settings-fixes.md.
 */
return new class extends Migration {
    private const TABLES = ['orders', 'invoice_snapshots', 'users', 'galleries', 'gallery_groups', 'tenants'];

    public function up(): void
    {
        // 1. Add nullable ENUM column (native ENUM on MariaDB/MySQL).
        foreach (self::TABLES as $table) {
            if (!Schema::hasColumn($table, 'brand')) {
                DB::statement("ALTER TABLE `{$table}` ADD COLUMN `brand` ENUM('rp','atr') NULL DEFAULT NULL");
            }
        }

        // 2. Backfill existing rows to 'rp' (B2B default). NULL is reserved for explicit
        //    cross-brand (e.g. Super-Admin users created later) — existing rows are all B2B.
        foreach (self::TABLES as $table) {
            DB::table($table)->whereNull('brand')->update(['brand' => 'rp']);
        }

        // 3. Index every brand column.
        foreach (self::TABLES as $table) {
            $indexName = "{$table}_brand_index";
            $hasIndex = collect(DB::select("SHOW INDEX FROM `{$table}`"))
                ->contains(fn ($i) => $i->Key_name === $indexName);
            if (!$hasIndex) {
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` (`brand`)");
            }
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('brand');
            });
        }
    }
};
