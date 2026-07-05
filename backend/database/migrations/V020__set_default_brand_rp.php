<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    public function up(): void
    {
        DB::table('galleries')
            ->whereNull('brand')
            ->update(['brand' => 'rp']);

        DB::table('gallery_groups')
            ->whereNull('brand')
            ->update(['brand' => 'rp']);
    }

    public function down(): void
    {
        // Intentionally empty — migrations are never rolled back.
    }
};
