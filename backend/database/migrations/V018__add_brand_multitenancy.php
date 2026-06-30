<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated multi-brand/multi-tenant schema (merges former V018 + V019 + V020).
 *
 * V017 is the last migration in production; none of the former V018/V019/V020 ran in
 * production, so they were merged into this single idempotent V018 to keep the migration
 * history clean. See features/infrastructure/12-brand-registry-and-settings-fixes.md and
 * 14-per-brand-catalog.md.
 *
 * What this migration does, in order:
 *  1. Drops the single-column PRIMARY KEY on `settings.key` (added in V001) so that two
 *     brands can share a key (required for per-brand settings isolation).
 *  2. Adds `brand ENUM('rp','srp') NULL DEFAULT NULL` to ALL 12 brand-scoped tables
 *     (union of the former V018 + V019 table sets).
 *  3. Backfills every existing row to 'rp' (B2B = historical default). NULL stays NULL
 *     only where a row is genuinely cross-brand (e.g. Super-Admin users).
 *  4. Adds a `{table}_brand_index` on each of the 12 tables.
 *  5. Adds a plain `settings_key_index` on `settings.key` (replaces the lookup the former
 *     primary key provided).
 *  6. Adds the composite unique `settings_key_brand_unique` on `(key, brand)` — the new
 *     effective unique key for settings.
 *
 * `down()` is the clean inverse and intentionally does NOT recreate the legacy single-column
 * `key` PRIMARY KEY (the composite unique is the new contract).
 */
return new class extends Migration {
    /**
     * All 12 brand-scoped tables (union of the former V018 + V019 sets).
     * orders, invoice_snapshots, users, galleries, gallery_groups, tenants  (former V018)
     * products, license_use_cases, license_modifiers, settings, customers, text_snippets (former V019)
     */
    private const TABLES = [
        'orders', 'invoice_snapshots', 'users', 'galleries', 'gallery_groups', 'tenants',
        'products', 'license_use_cases', 'license_modifiers', 'settings', 'customers', 'text_snippets',
    ];

    private const SETTINGS_TABLE = 'settings';
    private const SETTINGS_KEY_INDEX = 'settings_key_index';
    private const SETTINGS_KEY_BRAND_UNIQUE = 'settings_key_brand_unique';

    public function up(): void
    {
        // 1. Drop the single-column PRIMARY KEY on settings.key (added in V001) so multiple
        //    brands can share a key. Must happen before the composite unique is added.
        $hasSettingsPrimary = collect(DB::select("SHOW INDEX FROM `" . self::SETTINGS_TABLE . "`"))
            ->contains(fn ($i) => $i->Key_name === 'PRIMARY');
        if ($hasSettingsPrimary) {
            DB::statement("ALTER TABLE `" . self::SETTINGS_TABLE . "` DROP PRIMARY KEY");
        }

        // 2. Add nullable ENUM column (native ENUM on MariaDB/MySQL) to all 12 tables.
        foreach (self::TABLES as $table) {
            if (!Schema::hasColumn($table, 'brand')) {
                DB::statement("ALTER TABLE `{$table}` ADD COLUMN `brand` ENUM('rp','srp') NULL DEFAULT NULL");
            }
        }

        // 3. Backfill existing rows to 'rp' (B2B default). NULL is reserved for explicit
        //    cross-brand (e.g. Super-Admin users) — existing data rows are all B2B.
        foreach (self::TABLES as $table) {
            DB::table($table)->whereNull('brand')->update(['brand' => 'rp']);
        }

        // 4. Index every brand column.
        foreach (self::TABLES as $table) {
            $indexName = "{$table}_brand_index";
            $hasIndex = collect(DB::select("SHOW INDEX FROM `{$table}`"))
                ->contains(fn ($i) => $i->Key_name === $indexName);
            if (!$hasIndex) {
                DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` (`brand`)");
            }
        }

        // 5. Add a plain index on settings.key (replaces the lookup the former primary provided).
        $hasKeyIndex = collect(DB::select("SHOW INDEX FROM `" . self::SETTINGS_TABLE . "`"))
            ->contains(fn ($i) => $i->Key_name === self::SETTINGS_KEY_INDEX);
        if (!$hasKeyIndex) {
            DB::statement("ALTER TABLE `" . self::SETTINGS_TABLE . "` ADD INDEX `" . self::SETTINGS_KEY_INDEX . "` (`key`)");
        }

        // 6. Add the composite unique index on (key, brand) — the new effective unique key.
        $hasComposite = collect(DB::select("SHOW INDEX FROM `" . self::SETTINGS_TABLE . "`"))
            ->contains(fn ($i) => $i->Key_name === self::SETTINGS_KEY_BRAND_UNIQUE);
        if (!$hasComposite) {
            DB::statement("ALTER TABLE `" . self::SETTINGS_TABLE . "` ADD UNIQUE INDEX `" . self::SETTINGS_KEY_BRAND_UNIQUE . "` (`key`, `brand`)");
        }
    }

    public function down(): void
    {
        // Clean inverse — drop composite unique, plain key index, brand indexes, then brand column.
        $hasComposite = collect(DB::select("SHOW INDEX FROM `" . self::SETTINGS_TABLE . "`"))
            ->contains(fn ($i) => $i->Key_name === self::SETTINGS_KEY_BRAND_UNIQUE);
        if ($hasComposite) {
            DB::statement("ALTER TABLE `" . self::SETTINGS_TABLE . "` DROP INDEX `" . self::SETTINGS_KEY_BRAND_UNIQUE . "`");
        }

        $hasKeyIndex = collect(DB::select("SHOW INDEX FROM `" . self::SETTINGS_TABLE . "`"))
            ->contains(fn ($i) => $i->Key_name === self::SETTINGS_KEY_INDEX);
        if ($hasKeyIndex) {
            DB::statement("ALTER TABLE `" . self::SETTINGS_TABLE . "` DROP INDEX `" . self::SETTINGS_KEY_INDEX . "`");
        }

        foreach (self::TABLES as $table) {
            $indexName = "{$table}_brand_index";
            $hasIndex = collect(DB::select("SHOW INDEX FROM `{$table}`"))
                ->contains(fn ($i) => $i->Key_name === $indexName);
            if ($hasIndex) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
            }
        }

        foreach (self::TABLES as $table) {
            Schema::table($table, function (Blueprint $t) {
                $t->dropColumn('brand');
            });
        }

        // NOTE: intentionally does NOT recreate the legacy single-column `key` PRIMARY KEY.
        // The composite unique (key, brand) is the new contract; a `down()` rollback returns
        // the schema to a non-unique key column, matching the pre-V018 functional state.
    }
};
