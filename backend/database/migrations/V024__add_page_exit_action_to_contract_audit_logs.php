<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('contract_audit_logs', fn (Blueprint $t) => $t->enum('action', ['opened', 'heartbeat', 'signed', 'modified', 'page_exit'])->change());
        } else {
            DB::statement("ALTER TABLE contract_audit_logs MODIFY COLUMN action ENUM('opened', 'heartbeat', 'signed', 'modified', 'page_exit') NOT NULL");
        }
    }

    public function down(): void
    {
        DB::statement("ALTER TABLE contract_audit_logs MODIFY COLUMN action ENUM('opened', 'heartbeat', 'signed', 'modified') NOT NULL");
    }
};
