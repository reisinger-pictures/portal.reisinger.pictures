<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        Schema::create('customers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name')->nullable();
            $table->string('company')->nullable();
            $table->string('email')->nullable();
            $table->string('street')->nullable();
            $table->string('zip')->nullable();
            $table->string('city')->nullable();
            $table->string('country')->nullable();
            $table->string('uid')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('text_snippets', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('title');
            $table->string('shortcut')->nullable()->unique();
            $table->text('content_html')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('locations', function (Blueprint $table) {
            $table->string('postal_code', 20)->nullable()->after('iso_country');
        });
    }

    public function down(): void {
        Schema::table('locations', function (Blueprint $table) {
            $table->dropColumn('postal_code');
        });
        Schema::dropIfExists('text_snippets');
        Schema::dropIfExists('customers');
    }
};
