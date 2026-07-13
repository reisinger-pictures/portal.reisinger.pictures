<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration
{
    private const TABLES = [
        'orders' => ['nullable' => true, 'default' => null],
        'invoice_snapshots' => ['nullable' => true, 'default' => null],
        'users' => ['nullable' => true, 'default' => null],
        'galleries' => ['nullable' => true, 'default' => null],
        'gallery_groups' => ['nullable' => true, 'default' => null],
        'orgs' => ['nullable' => true, 'default' => null],
        'products' => ['nullable' => true, 'default' => null],
        'license_use_cases' => ['nullable' => true, 'default' => null],
        'license_modifiers' => ['nullable' => true, 'default' => null],
        'settings' => ['nullable' => false, 'default' => 'rp'],
        'customers' => ['nullable' => true, 'default' => null],
        'text_snippets' => ['nullable' => true, 'default' => null],
        'coupons' => ['nullable' => false, 'default' => 'rp'],
    ];

    public function up(): void
    {
        foreach (self::TABLES as $table => $config) {
            $nullable = $config['nullable'] ? 'NULL' : 'NOT NULL';
            $default = $config['default'] !== null ? " DEFAULT '{$config['default']}'" : '';
            DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `brand` VARCHAR(20) {$nullable}{$default}");
        }
    }

    public function down(): void
    {
        foreach (self::TABLES as $table => $config) {
            $nullable = $config['nullable'] ? 'NULL' : 'NOT NULL';
            $default = $config['default'] !== null ? " DEFAULT '{$config['default']}'" : '';
            DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `brand` ENUM('rp','srp') {$nullable}{$default}");
        }
    }
};
