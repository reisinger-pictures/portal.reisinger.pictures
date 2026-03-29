<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('locations', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('type', 20)->index(); // 'city' or 'country'
            $table->string('name');
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('iso_country', 2)->nullable();
        });
    }

    public function down(): void {
        Schema::dropIfExists('locations');
    }
};
