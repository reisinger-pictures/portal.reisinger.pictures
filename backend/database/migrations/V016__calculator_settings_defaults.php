<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        DB::table('settings')->insertOrIgnore([
            ['key' => 'calc_base_price', 'value' => '50'],
            ['key' => 'calc_hourly_rate', 'value' => '80'],
            ['key' => 'calc_images_per_hour', 'value' => '6']
        ]);
    }

    public function down(): void {
        DB::table('settings')->whereIn('key', ['calc_base_price', 'calc_hourly_rate', 'calc_images_per_hour'])->delete();
    }
};
