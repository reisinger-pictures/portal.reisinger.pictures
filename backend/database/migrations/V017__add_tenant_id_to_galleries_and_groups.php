<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->nullable()->after('parent_id')->constrained('tenants')->onDelete('set null');
        });
        Schema::table('galleries', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->nullable()->after('gallery_group_id')->constrained('tenants')->onDelete('set null');
        });
    }

    public function down(): void {
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->dropForeign(['tenant_id']);
            $table->dropColumn('tenant_id');
        });
    }
};
