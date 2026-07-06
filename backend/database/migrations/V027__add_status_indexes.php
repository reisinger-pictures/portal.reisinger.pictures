<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('orders', function (Blueprint $table) {
            $table->index('status', 'orders_status_index');
        });

        Schema::table('photographer_statements', function (Blueprint $table) {
            $table->index('status', 'photographer_statements_status_index');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->index('status', 'contracts_status_index');
        });

        Schema::table('contract_signers', function (Blueprint $table) {
            $table->index('status', 'contract_signers_status_index');
        });
    }

    public function down(): void
    {
        Schema::table('contract_signers', function (Blueprint $table) {
            $table->dropIndex('contract_signers_status_index');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex('contracts_status_index');
        });

        Schema::table('photographer_statements', function (Blueprint $table) {
            $table->dropIndex('photographer_statements_status_index');
        });

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_index');
        });
    }
};
