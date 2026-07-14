<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::dropIfExists('brands');
    }

    public function down(): void
    {
        // Brand config is now managed via config/brands.php.
        // Re-create the table is intentionally omitted — use V027__create_brands_table.php if needed.
    }
};
