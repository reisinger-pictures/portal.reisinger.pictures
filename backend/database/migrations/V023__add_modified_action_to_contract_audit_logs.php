<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void
    {
        DB::statement("ALTER TABLE contract_audit_logs MODIFY COLUMN action ENUM('opened', 'heartbeat', 'signed', 'modified') NOT NULL");
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE contract_audit_logs MODIFY COLUMN action ENUM('opened', 'heartbeat', 'signed') NOT NULL");
    }
};
