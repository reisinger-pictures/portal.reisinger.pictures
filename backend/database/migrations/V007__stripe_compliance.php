<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('orders', function (Blueprint $table) {
            $table->string('ip_address', 45)->nullable()->after('status');
            $table->string('stripe_payment_intent_id')->nullable()->after('ip_address');
        });
    }

    public function down(): void {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['ip_address', 'stripe_payment_intent_id']);
        });
    }
};
