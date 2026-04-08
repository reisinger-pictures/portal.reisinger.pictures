<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        \Illuminate\Support\Facades\DB::table('settings')->insertOrIgnore([
            ['key' => 'bank_iban', 'value' => 'ATXX XXXX XXXX XXXX XXXX'],
            ['key' => 'bank_bic', 'value' => 'XXXXXX'],
            ['key' => 'bank_holder', 'value' => 'Max Mustermann']
        ]);
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropColumn('allow_custom_quotes');
        });

        // Relax unique constraint to allow file replacements
        Schema::table('photos', function (Blueprint $table) {
            // MariaDB Workaround: Der FK nutzt aktuell den Unique-Index. 
            // Daher müssen wir den FK zuerst droppen und danach neu anlegen.
            $table->dropForeign(['gallery_id']);
            $table->dropUnique(['gallery_id', 'lr_uuid']);
            
            // FK wiederherstellen (erzeugt jetzt einen eigenen, sauberen Index)
            $table->foreign('gallery_id')->references('id')->on('galleries')->onDelete('cascade');
        });
        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->boolean('restricted_photographers')->nullable()->after('is_hidden');
        });
        Schema::table('galleries', function (Blueprint $table) {
            $table->boolean('restricted_photographers')->nullable()->after('is_hidden');
        });
        Schema::create('photographer_galleries', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('gallery_id')->constrained()->onDelete('cascade');
            $table->primary(['user_id', 'gallery_id']);
        });
        Schema::create('photographer_gallery_groups', function (Blueprint $table) {
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('gallery_group_id')->constrained('gallery_groups')->onDelete('cascade');
            $table->primary(['user_id', 'gallery_group_id']);
        });
    }

    public function down(): void {
        \Illuminate\Support\Facades\DB::table('settings')->whereIn('key', ['bank_iban', 'bank_bic', 'bank_holder'])->delete();
        Schema::table('photos', function (Blueprint $table) {
            $table->dropForeign(['gallery_id']);
            $table->unique(['gallery_id', 'lr_uuid']);
            $table->foreign('gallery_id')->references('id')->on('galleries')->onDelete('cascade');
        Schema::dropIfExists('photographer_gallery_groups');
        Schema::dropIfExists('photographer_galleries');
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropColumn('restricted_photographers');
        });
        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->dropColumn('restricted_photographers');
        });
        });

        Schema::table('galleries', function (Blueprint $table) {
            $table->boolean('allow_custom_quotes')->default(false)->after('is_free_download');
        });
    }
};
