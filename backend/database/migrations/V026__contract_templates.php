<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE contracts ADD COLUMN type ENUM('contract', 'template') NOT NULL DEFAULT 'contract' AFTER closes_at");
        Schema::table('contracts', function (Blueprint $table) {
            $table->uuid('template_id')->nullable()->after('type');
            $table->foreign('template_id')->references('id')->on('contracts')->nullOnDelete();
            $table->index('template_id');
            $table->timestamp('expires_at')->nullable()->after('template_id');
        });
    }

    public function down(): void
    {
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropColumn('template_id');
            $table->dropColumn('expires_at');
        });
        DB::statement("ALTER TABLE contracts DROP COLUMN type");
    }
};
