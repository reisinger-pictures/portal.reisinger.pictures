<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated fixes + coupon SRP-01 Phase A extensions.
 *
 * V018 left several structural issues (documented in AGENTS.todo.md §5):
 *  1. settings has unique(key,brand) but no explicit PK
 *  2. Organisation/Tenant model missing columns for core feature
 *  3. Rename customer_manager → org_admin role
 *  4. Coupon SRP-01 Phase A: scope_type ENUM, max_uses_per_account,
 *     created_by, coupon_user_usage table
 *
 * NOTE: FK constraints on coupon_user_usage.user_id and coupons.created_by
 * are skipped — users.id is uuid while coupon tables use MariaDB bigIncrements.
 * Application-level integrity is sufficient.
 *
 * down() is intentionally empty — we never roll back migrations.
 */
return new class extends Migration {
    public function up(): void
    {
        // ══════════════════════════════════════════════
        //  Part 0: Coupon extensions (formerly V022)
        //  SRP-01 Phase A: scope_type ENUM, max_uses_per_account,
        //  created_by, coupon_user_usage table
        // ══════════════════════════════════════════════

        // 0a. Add 'photographer' to scope_type ENUM
        DB::statement("ALTER TABLE `coupons` MODIFY COLUMN `scope_type` ENUM('global', 'gallery', 'meta_gallery', 'photographer', 'organisation') NOT NULL DEFAULT 'global'");

        // 0b. Rename max_uses → max_uses_global (skip if already done by V018)
        if (Schema::hasColumn('coupons', 'max_uses')) {
            DB::statement("ALTER TABLE `coupons` CHANGE `max_uses` `max_uses_global` INT UNSIGNED NULL");
        }

        // 0c. Add max_uses_per_account
        if (!Schema::hasColumn('coupons', 'max_uses_per_account')) {
            DB::statement("ALTER TABLE `coupons` ADD COLUMN `max_uses_per_account` INT UNSIGNED NULL AFTER `max_uses_global`");
        }

        // 0d. Add created_by (nullable FK to users — uuid, no DB constraint)
        if (!Schema::hasColumn('coupons', 'created_by')) {
            DB::statement("ALTER TABLE `coupons` ADD COLUMN `created_by` CHAR(36) NULL AFTER `active`");
        }

        // 0e. Create coupon_user_usage table
        if (!Schema::hasTable('coupon_user_usage')) {
            Schema::create('coupon_user_usage', function (Blueprint $table) {
                $table->bigIncrements('id');
                $table->unsignedBigInteger('coupon_id');
                $table->char('user_id', 36);
                $table->unsignedInteger('used_count')->default(0);
                $table->foreign('coupon_id')
                    ->references('id')->on('coupons')->onDelete('cascade');
                $table->unique(['coupon_id', 'user_id'], 'coupon_user_usage_unique');
            });
        }

        // ══════════════════════════════════════════════
        //  Part 1: Fix settings PK (was dropped in V018 without restore)
        // ══════════════════════════════════════════════

        $hasCompositeUnique = collect(DB::select("SHOW INDEX FROM `settings`"))
            ->contains(fn ($i) => $i->Key_name === 'settings_key_brand_unique');
        $hasPrimary = collect(DB::select("SHOW INDEX FROM `settings`"))
            ->contains(fn ($i) => $i->Key_name === 'PRIMARY');

        if ($hasCompositeUnique && !$hasPrimary) {
            DB::statement("ALTER TABLE `settings` DROP INDEX `settings_key_brand_unique`");
            DB::statement("ALTER TABLE `settings` ADD PRIMARY KEY (`key`, `brand`)");
        }

        // ─── Part 2+3 SKIPPED ────────────────────────
        // V018 uses bigIncrements for coupon_user_usage and coupons (MariaDB compat).
        // users.id is uuid — FK constraints are incompatible. Application-level
        // integrity is sufficient for these tables.

        // ══════════════════════════════════════════════
        //  Part 4a: Rename customer_manager → org_admin role
        // ══════════════════════════════════════════════

        DB::table('roles')
            ->where('name', 'customer_manager')
            ->update(['name' => 'org_admin']);

        // ══════════════════════════════════════════════
        //  Part 4b: Add organisation columns to tenants
        // ══════════════════════════════════════════════

        Schema::table('tenants', function (Blueprint $table) {
            if (!Schema::hasColumn('tenants', 'default_role_id')) {
                $table->foreignUuid('default_role_id')->nullable()->after('domain')
                      ->constrained('roles')->onDelete('set null');
            }
            if (!Schema::hasColumn('tenants', 'default_flatrate_level')) {
                $table->enum('default_flatrate_level', ['none', 'web', 'print', 'original'])
                      ->nullable()->default('none')->after('default_role_id');
            }
            if (!Schema::hasColumn('tenants', 'can_purchase_upgrades')) {
                $table->boolean('can_purchase_upgrades')->default(false)->after('default_flatrate_level');
            }
            if (!Schema::hasColumn('tenants', 'auto_join_policy')) {
                $table->enum('auto_join_policy', ['immediate', 'requires_invite', 'disabled'])
                      ->default('immediate')->after('can_purchase_upgrades');
            }
        });

        // ══════════════════════════════════════════════
        //  Part 5: Add users.tenant_id (FK, nullable) — 1:n User→Org
        // ══════════════════════════════════════════════

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'tenant_id')) {
                $table->foreignUuid('tenant_id')->nullable()->after('brand')
                      ->constrained('tenants')->nullOnDelete();
                $table->index('tenant_id');
            }
        });

        // ══════════════════════════════════════════════
        //  Part 6: Drop tenant_user pivot table (1:n replaces n:m)
        // ══════════════════════════════════════════════

        if (Schema::hasTable('tenant_user')) {
            Schema::dropIfExists('tenant_user');
        }

        // ══════════════════════════════════════════════
        //  Part 7: Remove free_items, add max_items, drop unused columns
        // ══════════════════════════════════════════════

        // 7a. Add max_items column (nullable unsigned integer)
        if (!Schema::hasColumn('coupons', 'max_items')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->unsignedInteger('max_items')->nullable()->after('value');
            });
        }

        // 7b. Drop scope_gallery_id and per_sub_gallery (only used by free_items)
        if (Schema::hasColumn('coupons', 'scope_gallery_id')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->dropColumn('scope_gallery_id');
            });
        }
        if (Schema::hasColumn('coupons', 'per_sub_gallery')) {
            Schema::table('coupons', function (Blueprint $table) {
                $table->dropColumn('per_sub_gallery');
            });
        }

        // 7c. Update the type enum to remove free_items
        DB::statement("ALTER TABLE `coupons` MODIFY COLUMN `type` ENUM('fixed', 'percentage') NOT NULL");

        // ══════════════════════════════════════════════
        //  Part 8: Add can_purchase_upgrades to users (per-user upgrade toggle)
        // ══════════════════════════════════════════════

        Schema::table('users', function (Blueprint $table) {
            if (!Schema::hasColumn('users', 'can_purchase_upgrades')) {
                $table->boolean('can_purchase_upgrades')->default(false)->after('flatrate_level');
            }
        });

        // ══════════════════════════════════════════════
        //  Part 9: Add shared_flatrate_cents to tenants (shared flatrate budget)
        // ══════════════════════════════════════════════

        Schema::table('tenants', function (Blueprint $table) {
            if (!Schema::hasColumn('tenants', 'shared_flatrate_cents')) {
                $table->unsignedInteger('shared_flatrate_cents')->nullable()->after('default_flatrate_level');
            }
        });

        // ══════════════════════════════════════════════
        //  Part 10: Change galleries/gallery_groups FK from SET NULL to CASCADE
        // ══════════════════════════════════════════════

        $galleriesFk = DB::select("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'galleries' AND CONSTRAINT_NAME = 'galleries_tenant_id_foreign'");
        if (!empty($galleriesFk) && $galleriesFk[0]->DELETE_RULE === 'SET NULL') {
            Schema::table('galleries', function (Blueprint $table) {
                $table->dropForeign('galleries_tenant_id_foreign');
                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }

        $groupsFk = DB::select("SELECT DELETE_RULE FROM information_schema.REFERENTIAL_CONSTRAINTS WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = 'gallery_groups' AND CONSTRAINT_NAME = 'gallery_groups_tenant_id_foreign'");
        if (!empty($groupsFk) && $groupsFk[0]->DELETE_RULE === 'SET NULL') {
            Schema::table('gallery_groups', function (Blueprint $table) {
                $table->dropForeign('gallery_groups_tenant_id_foreign');
                $table->foreign('tenant_id')->references('id')->on('tenants')->onDelete('cascade');
            });
        }
    }

    public function down(): void
    {
        // Intentionally empty — migrations are never rolled back.
    }
};
