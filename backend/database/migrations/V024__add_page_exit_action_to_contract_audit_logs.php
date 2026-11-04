<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('contract_audit_logs', fn (Blueprint $t) => $t->enum('action', ['opened', 'heartbeat', 'signed', 'modified', 'page_exit'])->change());
        } elseif (DB::connection()->getDriverName() === 'pgsql') {
            DB::statement('ALTER TABLE contract_audit_logs DROP CONSTRAINT contract_audit_logs_action_check');
            DB::statement('ALTER TABLE contract_audit_logs ALTER COLUMN action TYPE VARCHAR(255)');
            DB::statement("ALTER TABLE contract_audit_logs ADD CONSTRAINT contract_audit_logs_action_check CHECK (action IN ('opened', 'heartbeat', 'signed', 'modified', 'page_exit'))");
        } else {
            DB::statement("ALTER TABLE contract_audit_logs MODIFY COLUMN action ENUM('opened', 'heartbeat', 'signed', 'modified', 'page_exit') NOT NULL");
        }
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE contract_audit_logs MODIFY COLUMN action ENUM('opened', 'heartbeat', 'signed', 'modified') NOT NULL");
    }
};
