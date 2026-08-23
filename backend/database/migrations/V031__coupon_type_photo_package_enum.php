<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

/**
 * Add `photo_package` to the `coupons.type` ENUM (MySQL/MariaDB).
 *
 * V030 added the `photo_package` columns (`package_quantity`,
 * `package_price_cents`) but missed extending the `type` ENUM created in
 * V018 (`fixed`, `percentage`, `free_items`). SQLite stores Laravel enums
 * as plain VARCHAR, so local E2E/PHPUnit runs passed silently — MariaDB
 * (CI/prod) rejected every `photo_package` insert with
 * "Data truncated for column 'type'" (HTTP 500).
 *
 * No-op on SQLite (VARCHAR — no enum constraint to update).
 *
 * @see features/ecommerce/08-srp-coupon-system.md §3a
 */
return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        DB::statement(
            "ALTER TABLE `coupons` MODIFY `type` ENUM('fixed', 'percentage', 'free_items', 'photo_package') NOT NULL"
        );
    }

    public function down(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            return;
        }

        // Refuse to drop the value while photo_package rows still exist.
        $remaining = DB::table('coupons')->where('type', 'photo_package')->count();
        if ($remaining > 0) {
            throw new RuntimeException(
                "Cannot revert coupon type enum: {$remaining} row(s) with type 'photo_package' exist."
            );
        }

        DB::statement(
            "ALTER TABLE `coupons` MODIFY `type` ENUM('fixed', 'percentage', 'free_items') NOT NULL"
        );
    }
};
