<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // 1. pricing_factors & license_options zu UUID
        Schema::dropIfExists('pricing_factors');
        Schema::create('pricing_factors', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('type', ['usage', 'resolution', 'duration'])->index();
            $table->string('name');
            $table->decimal('multiplier', 5, 2);
            $table->timestamps();
        });

        Schema::dropIfExists('license_options');
        Schema::create('license_options', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('usage_frequency', ['einmalig', 'mehrmalig'])->default('einmalig');
            $table->decimal('usage_frequency_multiplier', 5, 2)->default(1.00);
            $table->timestamps();
        });

        // 2. invoice_snapshots: invoice_number zum PK
        Schema::table('invoice_snapshots', function (Blueprint $table) {
            $table->dropPrimary();
            $table->dropColumn('id');
            $table->dropUnique(['invoice_number']); // Entferne alten Unique-Index aus V004
            $table->primary('invoice_number');
        });

        // 3. invoice_sequences: year zum PK
        DB::statement('ALTER TABLE invoice_sequences MODIFY id BIGINT UNSIGNED NOT NULL');
        
        Schema::table('invoice_sequences', function (Blueprint $table) {
            $table->dropPrimary();
            $table->dropColumn('id');
            $table->dropUnique(['year']); // Entferne alten Unique-Index aus V004
            $table->primary('year');
        });

        // 4. photos & photo_metadata_versions: mime_type, headline
        Schema::table('photos', function (Blueprint $table) {
            $table->string('mime_type')->nullable()->after('filename');
            $table->string('headline')->nullable()->after('title');
        });
        Schema::table('photo_metadata_versions', function (Blueprint $table) {
            $table->string('headline')->nullable()->after('title');
        });

        // 5. galleries: cached_full_path
        Schema::table('galleries', function (Blueprint $table) {
            $table->string('cached_full_path')->nullable()->after('slug');
        });

        // 6. download_logs: order_id
        Schema::table('download_logs', function (Blueprint $table) {
            $table->foreignUuid('order_id')->nullable()->after('gallery_name_snapshot')->constrained('orders')->onDelete('set null');
        });

        // 7. photographer_gallery_groups: CASCADE zu SET NULL
        Schema::table('photographer_gallery_groups', function (Blueprint $table) {
            // Drop ALL Foreign Keys first to avoid MariaDB Error 150
            $table->dropForeign(['user_id']);
            $table->dropForeign(['gallery_group_id']);
            $table->dropPrimary();
        });
        
        Schema::table('photographer_gallery_groups', function (Blueprint $table) {
            $table->uuid('gallery_group_id')->nullable()->change();
        });

        Schema::table('photographer_gallery_groups', function (Blueprint $table) {
            $table->unique(['user_id', 'gallery_group_id']);
            // Re-create the foreign keys with the new ON DELETE behaviour
            $table->foreign('user_id')->references('id')->on('users')->onDelete('cascade');
            $table->foreign('gallery_group_id')->references('id')->on('gallery_groups')->onDelete('set null');
        });
    }

    public function down(): void {
        // Rollback logik
    }
};
