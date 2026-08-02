<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('photo_jobs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('brand', 4)->nullable()->default('rp');
            $table->index('brand');
            $table->foreignUuid('owner_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('title', 255);
            $table->string('lightroom_catalog', 255)->nullable();
            $table->unsignedInteger('total_count')->default(0);
            $table->unsignedInteger('selected_count')->default(0);
            $table->foreignUuid('target_gallery_id')->nullable()->constrained('galleries')->nullOnDelete();
            $table->boolean('is_private')->default(false);
            $table->enum('status', ['shooting', 'culling', 'bearbeitung', 'export', 'veroeffentlicht'])->default('shooting');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();
        });

        Schema::create('projects', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('brand', 4)->nullable()->default('rp');
            $table->index('brand');
            $table->foreignUuid('owner_id')->constrained('users')->onDelete('cascade');
            $table->foreignUuid('assignee_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('client_name', 255);
            $table->string('email', 255)->nullable();
            $table->string('phone', 255)->nullable();
            $table->string('package', 255)->nullable();
            $table->unsignedInteger('price_cents')->nullable();
            $table->enum('payment_status', ['open', 'partly_paid', 'paid'])->default('open');
            $table->enum('status', ['anfrage', 'angebot', 'beauftragt', 'rechnung', 'bezahlt'])->default('anfrage');
            $table->unsignedInteger('position')->default(0);
            $table->foreignUuid('linked_photo_job_id')->nullable()->constrained('photo_jobs')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('workflow_logs', function (Blueprint $table) {
            $table->id();
            $table->enum('item_type', ['project', 'photo_job']);
            $table->uuid('item_id');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->foreignUuid('user_id')->nullable()->constrained()->nullOnDelete();
            $table->timestamp('created_at')->useCurrent();

            $table->index(['item_type', 'item_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('workflow_logs');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('photo_jobs');
    }
};