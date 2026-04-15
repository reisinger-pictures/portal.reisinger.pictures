<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('download_logs', function (Blueprint $table) {
            $table->integer('photo_count')->default(1)->after('resolution_tier');
        });

        Schema::create('products', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('price', 10, 2);
            $table->timestamps();
        });
    }

    public function down(): void {
        Schema::dropIfExists('products');
        Schema::table('download_logs', function (Blueprint $table) {
            $table->dropColumn('photo_count');
        });
    }
};
