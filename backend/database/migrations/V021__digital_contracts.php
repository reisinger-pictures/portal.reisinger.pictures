<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('contracts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->enum('status', ['draft', 'active', 'closed', 'cancelled'])->default('draft');
            $table->json('billing_details')->nullable();
            $table->json('items');
            $table->json('discounts');
            $table->longText('terms_html')->nullable();
            $table->json('available_roles');
            $table->boolean('allow_multiple_roles_per_signer')->default(false);
            $table->string('join_token', 64)->nullable()->unique();
            $table->timestamp('closes_at')->nullable();
            $table->string('brand', 4)->nullable()->default('rp');
            $table->index('brand');
            $table->timestamps();
        });

        Schema::create('contract_signers', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('contract_id')->constrained()->onDelete('cascade');
            $table->string('name', 255);
            $table->string('email', 255);
            $table->json('roles');
            $table->string('personal_token', 64)->unique();
            $table->enum('status', ['invited', 'joined', 'signed'])->default('joined');
            $table->timestamp('signed_at')->nullable();
            $table->timestamps();

            $table->index('personal_token');
        });

        Schema::create('contract_audit_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignUuid('contract_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('contract_signer_id')->nullable()->constrained('contract_signers')->onDelete('cascade');
            $table->enum('action', ['opened', 'heartbeat', 'signed']);
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();

            $table->index('contract_signer_id');
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('contract_audit_logs');
        Schema::dropIfExists('contract_signers');
        Schema::dropIfExists('contracts');
    }
};
