<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Add the `photo_package` coupon type columns to the `coupons` table.
 *
 * - `package_quantity` (N): number of photos included in the bundle.
 * - `package_price_cents` (Y): flat price in cents (Stripe-conform).
 *
 * Both are nullable: only the `photo_package` type uses them; `fixed` /
 * `percentage` leave them NULL. `down()` drops both columns.
 *
 * @see features/ecommerce/08-srp-coupon-system.md §3a
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->unsignedInteger('package_quantity')->nullable()->after('max_items');
            $table->integer('package_price_cents')->nullable()->after('package_quantity');
        });
    }

    public function down(): void
    {
        Schema::table('coupons', function (Blueprint $table) {
            $table->dropColumn(['package_quantity', 'package_price_cents']);
        });
    }
};
