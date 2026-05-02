<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        // 1. Spalten löschen (Dateisystem-Logik für leere DB entfernt)
        Schema::table('photos', function (Blueprint $table) {
            $table->dropColumn('filename');
        });

        // 2. UI-Flags auf Non-Nullable Boolean umstellen
        $tables = ['galleries', 'gallery_groups'];
        foreach ($tables as $t) {
            DB::table($t)->whereNull('is_free_download')->update(['is_free_download' => false]);
            DB::table($t)->whereNull('is_editorial_only')->update(['is_editorial_only' => false]);
            DB::table($t)->whereNull('is_hidden')->update(['is_hidden' => false]);

            Schema::table($t, function (Blueprint $table) {
                $table->boolean('is_free_download')->default(false)->change();
                $table->boolean('is_editorial_only')->default(false)->change();
                $table->boolean('is_hidden')->default(false)->change();
            });
        }

        DB::table('photos')->whereNull('is_editorial_only')->update(['is_editorial_only' => false]);
        DB::table('photos')->whereNull('is_hidden')->update(['is_hidden' => false]);

        Schema::table('photos', function (Blueprint $table) {
            $table->boolean('is_editorial_only')->default(false)->change();
            $table->boolean('is_hidden')->default(false)->change();
        });
    }

    public function down(): void
    {
        Schema::table('photos', function (Blueprint $table) {
            $table->string('filename')->nullable()->after('gallery_id');
            $table->boolean('is_editorial_only')->nullable()->default(null)->change();
            $table->boolean('is_hidden')->nullable()->default(null)->change();
        });
        Schema::table('photo_metadata_versions', function (Blueprint $table) {
            $table->string('filename')->nullable()->after('photo_id');
        });

        $tables = ['galleries', 'gallery_groups'];
        foreach ($tables as $t) {
            Schema::table($t, function (Blueprint $table) {
                $table->boolean('is_free_download')->nullable()->default(null)->change();
                $table->boolean('is_editorial_only')->nullable()->default(null)->change();
                $table->boolean('is_hidden')->nullable()->default(null)->change();
            });
        }
    }
};
