<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Volume-Licensing-Presets: konfigurierbare Preisstaffeln pro Brand.
 *
 * - `volume_presets`: ein benanntes Preismodell je Brand (genau ein `is_default`-Preset
 *   je Brand, im Service erzwungen — MySQL unterstützt keine partiellen Indizes).
 * - `volume_preset_tiers`: beliebig viele Staffeln je Preset (`min_quantity` = ab wie
 *   vielen Bildern die Staffel gilt, `price_cents` = Einheitspreis in Cent).
 * - `galleries.volume_preset_id`: optionale Zuordnung einer Galerie zu einem Preset
 *   (null = Brand-Default).
 *
 * Die bisherigen Flach-Settings `srp_price_per_image_tier*`/`srp_tier_threshold*` werden
 * NICHT hier migriert — das übernimmt `VolumePresetService::ensureDefaultPresetForBrand()`
 * beim ersten Zugriff (kein Daten-Rewrite in der Migration).
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::create('volume_presets', function (Blueprint $table) {
            $table->id();
            $table->string('brand', 20);
            $table->string('name');
            $table->boolean('is_default')->default(false);
            $table->timestamps();

            $table->index(['brand', 'is_default']);
        });

        Schema::create('volume_preset_tiers', function (Blueprint $table) {
            $table->id();
            $table->foreignId('volume_preset_id')->constrained()->cascadeOnDelete();
            $table->integer('position');
            $table->integer('min_quantity');
            $table->integer('price_cents');
            $table->timestamps();

            $table->unique(['volume_preset_id', 'position']);
        });

        Schema::table('galleries', function (Blueprint $table) {
            $table->foreignId('volume_preset_id')
                ->nullable()
                ->after('licensing_mode')
                ->constrained('volume_presets')
                ->nullOnDelete();
        });
    }

    public function down(): void
    {
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropConstrainedForeignId('volume_preset_id');
        });
        Schema::dropIfExists('volume_preset_tiers');
        Schema::dropIfExists('volume_presets');
    }
};
