<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::table('galleries', function (Blueprint $table) {
            $table->text('default_keywords')->nullable()->change();
        });
        Schema::table('photos', function (Blueprint $table) {
            $table->text('keywords')->nullable()->change();
        });
        Schema::table('photo_metadata_versions', function (Blueprint $table) {
            $table->text('keywords')->nullable()->change();
        });
    }

    public function down(): void {
        Schema::table('galleries', function (Blueprint $table) {
            $table->string('default_keywords', 255)->nullable()->change();
        });
        Schema::table('photos', function (Blueprint $table) {
            $table->string('keywords', 255)->nullable()->change();
        });
        Schema::table('photo_metadata_versions', function (Blueprint $table) {
            $table->string('keywords', 255)->nullable()->change();
        });
    }
};
