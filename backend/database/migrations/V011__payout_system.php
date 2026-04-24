<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('payout_pools', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->integer('month');
            $table->integer('year');
            $table->foreignUuid('product_id')->nullable()->constrained('products')->onDelete('set null');
            $table->integer('gross_amount_cents')->default(0);
            $table->integer('stripe_fee_cents')->default(0);
            $table->integer('net_pool_cents')->default(0);
            $table->integer('photographer_share_percent')->default(50);
            $table->integer('total_unique_downloads')->default(0);
            $table->decimal('total_shares', 12, 4)->default(0);
            $table->integer('value_per_share_cents')->default(0);
            $table->timestamps();
        });

        Schema::create('photographer_statements', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->string('sequence_number')->unique();
            $table->integer('month');
            $table->integer('year');
            $table->decimal('total_shares_earned', 12, 4)->default(0);
            $table->integer('pool_earnings_cents')->default(0);
            $table->integer('delta_surcharge_earnings_cents')->default(0);
            $table->integer('earned_amount_cents')->default(0);
            $table->integer('rolled_over_amount_cents')->default(0);
            $table->integer('total_payable_cents')->default(0);
            $table->enum('status', ['pending', 'rollover', 'approved', 'paid'])->default('pending');
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('photographer_statements');
        Schema::dropIfExists('payout_pools');
    }
};
