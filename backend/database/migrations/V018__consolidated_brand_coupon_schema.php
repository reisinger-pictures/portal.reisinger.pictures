<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

/**
 * Consolidated schema: Brand multitenancy + Coupon system (SRP-01).
 *
 * V017 is the last migration in production. All V018–V022 were never deployed
 * and are merged into this single consolidated migration.
 *
 * Order of operations:
 *  1. Add brand ENUM('rp','srp') to all 12 brand-scoped tables
 *  2. Backfill brand to 'rp' + deduplicate settings
 *  3. Add brand indexes + settings indices
 *  4. Fix user brand isolation (U-01/U-02)
 *  5. Create coupons table (with extended schema incl. organisation scope + per_sub_gallery)
 *  6. Add coupon FK + discount to orders
 *  7. Create coupon_user_usage table
 *  8. Backfill photo_metadata_versions for photos without audit snapshots (was V019)
 */
return new class extends Migration {
    private const BRAND_TABLES = [
        'orders', 'invoice_snapshots', 'users', 'galleries', 'gallery_groups', 'tenants',
        'products', 'license_use_cases', 'license_modifiers', 'settings', 'customers', 'text_snippets',
    ];

    public function up(): void
    {
        // ══════════════════════════════════════════════
        //  Part 1: Brand multitenancy (was V018)
        // ══════════════════════════════════════════════

        // 1a. Drop single-column PRIMARY KEY on settings.key
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('settings', fn (Blueprint $t) => $t->dropPrimary(['key']));
        } else {
            $hasSettingsPrimary = collect(DB::select("SHOW INDEX FROM `settings`"))
                ->contains(fn ($i) => $i->Key_name === 'PRIMARY');
            if ($hasSettingsPrimary) {
                DB::statement("ALTER TABLE `settings` DROP PRIMARY KEY");
            }
        }

        // 1b. Add brand column to all 12 tables
        foreach (self::BRAND_TABLES as $table) {
            if (!Schema::hasColumn($table, 'brand')) {
                if (DB::connection()->getDriverName() === 'sqlite') {
                    Schema::table($table, fn (Blueprint $t) => $t->enum('brand', ['rp', 'srp'])->nullable());
                } else {
                    DB::statement("ALTER TABLE `{$table}` ADD COLUMN `brand` ENUM('rp','srp') NULL DEFAULT NULL");
                }
            }
        }

        // 1c. Deduplicate settings (collapse duplicate keys → single survivor)
        foreach (self::BRAND_TABLES as $table) {
            if (Schema::hasColumn($table, 'key')) {
                $dupKeys = DB::table($table)
                    ->select('key')
                    ->groupBy('key')
                    ->havingRaw('COUNT(*) > 1')
                    ->pluck('key');
                foreach ($dupKeys as $dupKey) {
                    $rows = DB::table($table)->where('key', $dupKey)->get(['value']);
                    $survivorValue = (string) $rows
                        ->sortByDesc(fn ($r) => strlen((string) $r->value))
                        ->first()->value;

                    DB::table($table)
                        ->where('key', $dupKey)
                        ->where('value', '!=', $survivorValue)
                        ->delete();

                    while (DB::table($table)->where('key', $dupKey)->count() > 1) {
                        if (DB::connection()->getDriverName() === 'sqlite') {
                            DB::delete(
                                "DELETE FROM `{$table}` WHERE `key` = ? AND `value` = ? AND rowid = (SELECT rowid FROM `{$table}` WHERE `key` = ? AND `value` = ? LIMIT 1)",
                                [$dupKey, $survivorValue, $dupKey, $survivorValue]
                            );
                        } else {
                            DB::statement(
                                "DELETE FROM `{$table}` WHERE `key` = ? AND `value` = ? LIMIT 1",
                                [$dupKey, $survivorValue]
                            );
                        }
                    }
                }
            }

            // 1d. Backfill brand to 'rp' for existing null-brand rows
            DB::table($table)->whereNull('brand')->update(['brand' => 'rp']);
        }

        // 1e. Add brand indexes
        foreach (self::BRAND_TABLES as $table) {
            $indexName = "{$table}_brand_index";
            if (DB::connection()->getDriverName() === 'sqlite') {
                Schema::table($table, fn (Blueprint $t) => $t->index('brand', $indexName));
            } else {
                if (!collect(DB::select("SHOW INDEX FROM `{$table}`"))->contains(fn ($i) => $i->Key_name === $indexName)) {
                    DB::statement("ALTER TABLE `{$table}` ADD INDEX `{$indexName}` (`brand`)");
                }
            }
        }

        // 1f. Add settings.key index + composite unique (key, brand)
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('settings', fn (Blueprint $t) => $t->index('key', 'settings_key_index'));
            Schema::table('settings', fn (Blueprint $t) => $t->unique(['key', 'brand'], 'settings_key_brand_unique'));
        } else {
            if (!collect(DB::select("SHOW INDEX FROM `settings`"))->contains(fn ($i) => $i->Key_name === 'settings_key_index')) {
                DB::statement("ALTER TABLE `settings` ADD INDEX `settings_key_index` (`key`)");
            }
            if (!collect(DB::select("SHOW INDEX FROM `settings`"))->contains(fn ($i) => $i->Key_name === 'settings_key_brand_unique')) {
                DB::statement("ALTER TABLE `settings` ADD UNIQUE INDEX `settings_key_brand_unique` (`key`, `brand`)");
            }
        }

        // ══════════════════════════════════════════════
        //  Part 2: Fix user brand isolation (was V019)
        // ══════════════════════════════════════════════

        DB::table('users')
            ->whereNull('brand')
            ->where('email', '!=', 'florian@reisinger.pictures')
            ->update(['brand' => 'rp']);

        DB::table('users')
            ->where('email', 'florian@reisinger.pictures')
            ->whereNotNull('brand')
            ->update(['brand' => null]);

        // ══════════════════════════════════════════════
        //  Part 3: Create coupons table (was V020 + V022)
        // ══════════════════════════════════════════════

        Schema::create('coupons', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->enum('brand', ['rp', 'srp'])->notNull();
            $table->string('code', 50);
            $table->enum('type', ['fixed', 'percentage', 'free_items']);
            $table->decimal('value', 10, 2);
            $table->enum('scope_type', ['global', 'gallery', 'meta_gallery', 'photographer', 'organisation'])->default('global');
            $table->char('scope_id', 36)->nullable();
            $table->char('scope_gallery_id', 36)->nullable();
            $table->boolean('per_sub_gallery')->default(false);
            $table->unsignedInteger('max_uses_global')->nullable();
            $table->unsignedInteger('max_uses_per_account')->nullable();
            $table->unsignedInteger('used_count')->default(0);
            $table->timestamp('expires_at')->nullable();
            $table->boolean('active')->default(true);
            $table->char('created_by', 36)->nullable();
            $table->timestamps();

            $table->unique(['brand', 'code'], 'coupons_brand_code_unique');
            $table->index(['scope_type', 'scope_id'], 'coupons_scope_index');
            // scope_id, scope_gallery_id, and created_by are char(36) to hold UUID
            // values from galleries, gallery_groups, and users respectively.
            // FKs are omitted because these entities use UUID primary keys which
            // are incompatible with auto-increment FKs in MySQL/MariaDB.
        });

        // ══════════════════════════════════════════════
        //  Part 4: Add coupon fields to orders (was V021)
        // ══════════════════════════════════════════════

        if (!Schema::hasColumn('orders', 'coupon_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->unsignedBigInteger('coupon_id')->nullable()->after('stripe_fee_cents');
                $table->integer('coupon_discount_cents')->default(0)->after('coupon_id');

                $table->foreign('coupon_id')
                    ->references('id')->on('coupons')
                    ->onDelete('set null');
            });
        }

        // ══════════════════════════════════════════════
        //  Part 5: Create coupon_user_usage table (was V022)
        // ══════════════════════════════════════════════

        Schema::create('coupon_user_usage', function (Blueprint $table) {
            $table->bigIncrements('id');
            $table->unsignedBigInteger('coupon_id');
            $table->char('user_id', 36);
            $table->unsignedInteger('used_count')->default(0);

            $table->foreign('coupon_id')
                ->references('id')->on('coupons')
                ->onDelete('cascade');

            // FK omitted: user_id is char(36) (UUID) but users.id is also UUID.
            // MariaDB doesn't support UUID FKs reliably across all engines.

            $table->unique(['coupon_id', 'user_id'], 'coupon_user_usage_unique');
        });

        // ══════════════════════════════════════════════
        //  Part 6: Add updated_at to users, photos, galleries (was M-08)
        // ══════════════════════════════════════════════

        foreach (['users', 'photos', 'galleries'] as $table) {
            if (!Schema::hasColumn($table, 'updated_at')) {
                Schema::table($table, function (Blueprint $t) {
                    $t->timestamp('updated_at')->nullable();
                });
            }
        }

        // ══════════════════════════════════════════════
        //  Part 7: Backfill photo_metadata_versions (was V019)
        // ══════════════════════════════════════════════

        if (DB::connection()->getDriverName() === 'sqlite') {
            $photosWithoutVersions = DB::table('photos')
                ->select(
                    'photos.id', 'photos.user_id', 'photos.title', 'photos.headline',
                    'photos.description', 'photos.keywords', 'photos.location', 'photos.city',
                    'photos.state', 'photos.country', 'photos.iso_country', 'photos.created_at'
                )
                ->leftJoin('photo_metadata_versions', 'photo_metadata_versions.photo_id', '=', 'photos.id')
                ->whereNull('photo_metadata_versions.id')
                ->get();

            foreach ($photosWithoutVersions as $photo) {
                DB::table('photo_metadata_versions')->insert([
                    'id' => (string) Str::uuid(),
                    'photo_id' => $photo->id,
                    'user_id' => $photo->user_id,
                    'title' => $photo->title,
                    'headline' => $photo->headline,
                    'description' => $photo->description,
                    'keywords' => $photo->keywords,
                    'location' => $photo->location,
                    'city' => $photo->city,
                    'state' => $photo->state,
                    'country' => $photo->country,
                    'iso_country' => $photo->iso_country,
                    'created_at' => $photo->created_at,
                ]);
            }
        } else {
            DB::statement("
                INSERT INTO photo_metadata_versions (id, photo_id, user_id, title, headline, description, keywords, location, city, state, country, iso_country, created_at)
                SELECT
                    UUID() AS id,
                    p.id AS photo_id,
                    p.user_id,
                    p.title,
                    p.headline,
                    p.description,
                    p.keywords,
                    p.location,
                    p.city,
                    p.state,
                    p.country,
                    p.iso_country,
                    p.created_at
                FROM photos p
                LEFT JOIN photo_metadata_versions v ON v.photo_id = p.id
                WHERE v.id IS NULL
            ");
        }
    }

    public function down(): void
    {
        Schema::dropIfExists('coupon_user_usage');

        if (Schema::hasColumn('orders', 'coupon_id')) {
            Schema::table('orders', function (Blueprint $table) {
                $table->dropForeign(['coupon_id']);
                $table->dropColumn('coupon_id');
                $table->dropColumn('coupon_discount_cents');
            });
        }

        Schema::dropIfExists('coupons');

        // Part 6 rollback: remove updated_at columns
        foreach (['users', 'photos', 'galleries'] as $table) {
            if (Schema::hasColumn($table, 'updated_at')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('updated_at'));
            }
        }

        // Revert user brand isolation
        DB::table('users')
            ->where('email', 'florian@reisinger.pictures')
            ->whereNull('brand')
            ->update(['brand' => 'rp']);

        // Remove brand from all 12 tables + restore settings PK
        $hasComposite = collect(DB::select("SHOW INDEX FROM `settings`"))
            ->contains(fn ($i) => $i->Key_name === 'settings_key_brand_unique');
        if ($hasComposite) {
            DB::statement("ALTER TABLE `settings` DROP INDEX `settings_key_brand_unique`");
        }
        if (collect(DB::select("SHOW INDEX FROM `settings`"))->contains(fn ($i) => $i->Key_name === 'settings_key_index')) {
            DB::statement("ALTER TABLE `settings` DROP INDEX `settings_key_index`");
        }

        foreach (self::BRAND_TABLES as $table) {
            $indexName = "{$table}_brand_index";
            if (collect(DB::select("SHOW INDEX FROM `{$table}`"))->contains(fn ($i) => $i->Key_name === $indexName)) {
                DB::statement("ALTER TABLE `{$table}` DROP INDEX `{$indexName}`");
            }
            if (Schema::hasColumn($table, 'brand')) {
                Schema::table($table, fn (Blueprint $t) => $t->dropColumn('brand'));
            }
        }

        // Part 6 rollback: remove backfilled versions
        DB::statement("
            DELETE v
            FROM photo_metadata_versions v
            INNER JOIN (
                SELECT photo_id
                FROM photo_metadata_versions
                GROUP BY photo_id
                HAVING COUNT(*) = 1
            ) singles ON singles.photo_id = v.photo_id
            INNER JOIN photos p ON p.id = v.photo_id
            WHERE v.created_at = p.created_at
        ");
    }
};
