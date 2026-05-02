<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('license_use_cases', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->integer('base_price');
            $table->string('flatrate_tier', 20)->nullable();
            $table->integer('sort_order')->default(0);
            $table->boolean('is_commercial')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('license_modifiers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('percent_surcharge', 8, 2);
            $table->boolean('is_included_in_flatrate')->default(false);
            $table->integer('sort_order')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });

        DB::table('license_use_cases')->insert([
            ['id' => Str::uuid(), 'name' => 'Tageszeitungen / Zeitschriften', 'description' => 'Redaktionelle Nutzung Print (kleiner als 2-spaltig).', 'base_price' => 8000, 'flatrate_tier' => 'print', 'sort_order' => 10, 'is_commercial' => false],
            ['id' => Str::uuid(), 'name' => 'Corporate Publishing / PR', 'description' => 'Kundenmagazine, Geschäftsberichte.', 'base_price' => 15000, 'flatrate_tier' => 'print', 'sort_order' => 20, 'is_commercial' => false],
            ['id' => Str::uuid(), 'name' => 'Web & Social Media', 'description' => 'Redaktionelle Online-Nutzung.', 'base_price' => 4500, 'flatrate_tier' => 'web', 'sort_order' => 30, 'is_commercial' => false],
            ['id' => Str::uuid(), 'name' => 'Werbung / Kampagne', 'description' => 'Kommerzielle Kampagnen, Inserate, Plakate.', 'base_price' => 45000, 'flatrate_tier' => 'original', 'sort_order' => 40, 'is_commercial' => true],
        ]);

        DB::table('license_modifiers')->insert([
            ['id' => Str::uuid(), 'name' => 'Titelseite / Sondertitel', 'description' => 'Prominente Platzierung (Cover)', 'percent_surcharge' => 100.00, 'is_included_in_flatrate' => true, 'sort_order' => 10],
            ['id' => Str::uuid(), 'name' => 'Europarechte', 'description' => 'Erweiterung auf Europa', 'percent_surcharge' => 50.00, 'is_included_in_flatrate' => false, 'sort_order' => 20],
            ['id' => Str::uuid(), 'name' => 'Weltweite Nutzungsrechte', 'description' => 'Uneingeschränkte räumliche Nutzung', 'percent_surcharge' => 100.00, 'is_included_in_flatrate' => false, 'sort_order' => 30],
            ['id' => Str::uuid(), 'name' => 'Unbegrenzte Nutzungsdauer', 'description' => 'Zeitlich uneingeschränkt', 'percent_surcharge' => 50.00, 'is_included_in_flatrate' => true, 'sort_order' => 40],
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('license_modifiers');
        Schema::dropIfExists('license_use_cases');
    }
};
