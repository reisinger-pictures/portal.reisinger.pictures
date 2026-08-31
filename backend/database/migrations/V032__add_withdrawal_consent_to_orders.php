<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->boolean('withdrawal_waived')->default(false)->after('quote_status');
            $table->timestamp('withdrawal_consent_at')->nullable()->after('withdrawal_waived');
        });
    }

    public function down(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->dropColumn(['withdrawal_waived', 'withdrawal_consent_at']);
        });
    }
};
