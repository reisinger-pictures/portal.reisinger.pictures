<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::table('download_logs', function (Blueprint $table) {
            $table->string('guest_id', 36)->nullable()->after('user_id')->index();
        });
    }

    public function down(): void
    {
        Schema::table('download_logs', function (Blueprint $table) {
            $table->dropColumn('guest_id');
        });
    }
};
