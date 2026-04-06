<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // --- 1. Hierarchical Governance ---
        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->boolean('is_editorial_only')->nullable()->after('is_public');
            $table->boolean('is_hidden')->nullable()->after('is_editorial_only');
        });

        Schema::table('galleries', function (Blueprint $table) {
            $table->boolean('is_editorial_only')->nullable()->after('is_public');
            $table->boolean('is_hidden')->nullable()->after('is_editorial_only');
        });

        Schema::table('photos', function (Blueprint $table) {
            // Governance
            $table->boolean('is_editorial_only')->nullable()->after('iso_country');
            $table->boolean('is_hidden')->nullable()->after('is_editorial_only');
            // Storage Lifecycle
            $table->timestamp('last_accessed_at')->nullable()->after('is_hidden');
            $table->boolean('is_downscaled')->default(false)->after('last_accessed_at');
        });

        // --- 2. Pricing Matrix ---
        Schema::create('pricing_factors', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['usage', 'resolution', 'duration'])->index();
            $table->string('name');
            $table->decimal('multiplier', 5, 2);
            $table->timestamps();
        });

        // --- 3. Accounting & Invoicing ---
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->integer('year')->unique();
            $table->integer('current_value')->default(0);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('status')->default('pending');
            $table->decimal('total_amount', 10, 2)->default(0);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('invoice_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->onDelete('cascade');
            $table->string('invoice_number')->unique();
            $table->json('customer_details');
            $table->decimal('total_net', 10, 2);
            $table->decimal('total_gross', 10, 2);
            $table->decimal('tax_rate', 5, 2)->default(20.00);
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void {
        Schema::dropIfExists('invoice_snapshots');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('pricing_factors');

        Schema::table('photos', function (Blueprint $table) {
            $table->dropColumn(['is_editorial_only', 'is_hidden', 'last_accessed_at', 'is_downscaled']);
        });
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropColumn(['is_editorial_only', 'is_hidden']);
        });
        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->dropColumn(['is_editorial_only', 'is_hidden']);
        });
    }
};
