<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        $rows = DB::table('settings')->where('key', 'calc_outdoor_multiplier')->get(['brand', 'value']);
        foreach ($rows as $row) {
            $imagesPerHour = DB::table('settings')->where('key', 'calc_images_per_hour')->where('brand', $row->brand)->value('value') ?? '6';
            $newValue = (string)(int)round((float)$imagesPerHour / (float)$row->value);
            DB::table('settings')->updateOrInsert(['key' => 'calc_outdoor_images_per_hour', 'brand' => $row->brand], ['value' => $newValue]);
            DB::table('settings')->where('key', 'calc_outdoor_multiplier')->where('brand', $row->brand)->delete();
        }
    }

    public function down(): void {
    }
};
