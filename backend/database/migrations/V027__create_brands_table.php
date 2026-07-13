<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('brands', function (Blueprint $table) {
            $table->string('id', 20)->primary();
            $table->string('name');
            $table->string('theme', 50)->default('rp');
            $table->string('portal_name');
            $table->text('impressum_url')->nullable();
            $table->string('logo_path')->nullable();
            $table->json('hostnames');
            $table->json('features');
            $table->boolean('is_active')->default(true);
            $table->timestamps();
        });

        DB::table('brands')->insert([
            'id' => 'rp',
            'name' => 'Reisinger Pictures',
            'theme' => 'rp',
            'portal_name' => 'Reisinger Foto Portal',
            'impressum_url' => 'https://reisinger.pictures/impressum/',
            'logo_path' => '/brands/rp/android-chrome-192x192.png',
            'hostnames' => json_encode([
                'portal.reisinger.pictures',
                'rp.localhost',
                'reisinger.pictures',
            ]),
            'features' => json_encode([
                'coupons' => true,
                'orgs' => true,
                'volume_licensing' => false,
            ]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        DB::table('brands')->insert([
            'id' => 'srp',
            'name' => 'SRP Reisinger',
            'theme' => 'srp',
            'portal_name' => 'Reisinger Foto Portal',
            'impressum_url' => 'https://buy.reisinger.pictures/impressum/',
            'logo_path' => '/brands/srp/android-chrome-192x192.png',
            'hostnames' => json_encode([
                'buy.reisinger.pictures',
                'srp.localhost',
            ]),
            'features' => json_encode([
                'coupons' => false,
                'orgs' => false,
                'volume_licensing' => true,
            ]),
            'is_active' => true,
            'created_at' => now(),
            'updated_at' => now(),
        ]);
    }

    public function down(): void
    {
        Schema::dropIfExists('brands');
    }
};
