<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        // ═══════════════════════════════════════════════════════════════
        // Step A (V025): Add guest_id to download_logs, nullable tax_rate
        // ═══════════════════════════════════════════════════════════════

        Schema::table('download_logs', function (Blueprint $table) {
            $table->string('guest_id', 36)->nullable()->after('user_id')->index();
        });

        Schema::table('invoice_snapshots', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->nullable()->default(null)->change();
        });

        // ═══════════════════════════════════════════════════════════════
        // Step B (V026): Add birthdate to customers
        // ═══════════════════════════════════════════════════════════════

        Schema::table('customers', function (Blueprint $table) {
            $table->date('birthdate')->nullable()->after('uid');
        });

        // ═══════════════════════════════════════════════════════════════
        // Step C (V027): Add status indexes
        // ═══════════════════════════════════════════════════════════════

        Schema::table('orders', function (Blueprint $table) {
            $table->index('status', 'orders_status_index');
        });

        Schema::table('photographer_statements', function (Blueprint $table) {
            $table->index('status', 'photographer_statements_status_index');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->index('status', 'contracts_status_index');
        });

        Schema::table('contract_signers', function (Blueprint $table) {
            $table->index('status', 'contract_signers_status_index');
        });

        // ═══════════════════════════════════════════════════════════════
        // Step D (V029): Rename tenant → org (tables, columns, FKs)
        // ═══════════════════════════════════════════════════════════════

        // D1. Drop ALL foreign keys referencing tenants/tenant_id BEFORE renaming
        // (FK constraint names still use original table names)

        // gallery_group_tenant → gallery_group_org pivot
        Schema::table('gallery_group_tenant', function (Blueprint $table) {
            $table->dropForeign('gallery_group_tenant_tenant_id_foreign');
        });

        // tenant_invites
        Schema::table('tenant_invites', function (Blueprint $table) {
            $table->dropForeign('tenant_invites_tenant_id_foreign');
        });

        // galleries
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropForeign('galleries_tenant_id_foreign');
        });

        // gallery_groups
        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->dropForeign('gallery_groups_tenant_id_foreign');
        });

        // users
        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_tenant_id_foreign');
            $table->dropIndex('users_tenant_id_index');
        });

        // D2. Rename tables
        Schema::rename('tenants', 'orgs');
        Schema::rename('tenant_invites', 'org_invites');
        Schema::rename('gallery_group_tenant', 'gallery_group_org');

        // D3. Rename columns and re-add foreign keys
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('tenant_id', 'org_id');
            $table->index('org_id');
            $table->foreign('org_id')->references('id')->on('orgs')->nullOnDelete();
        });

        Schema::table('galleries', function (Blueprint $table) {
            $table->renameColumn('tenant_id', 'org_id');
            $table->foreign('org_id')->references('id')->on('orgs')->cascadeOnDelete();
        });

        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->renameColumn('tenant_id', 'org_id');
            $table->foreign('org_id')->references('id')->on('orgs')->cascadeOnDelete();
        });

        Schema::table('org_invites', function (Blueprint $table) {
            $table->renameColumn('tenant_id', 'org_id');
            $table->foreign('org_id')->references('id')->on('orgs')->cascadeOnDelete();
        });

        Schema::table('gallery_group_org', function (Blueprint $table) {
            $table->renameColumn('tenant_id', 'org_id');
            $table->foreign('org_id')->references('id')->on('orgs')->cascadeOnDelete();
            $table->index(['org_id', 'gallery_group_id']);
        });

        // ═══════════════════════════════════════════════════════════════
        // Step E (V030): Create gallery_org pivot, migrate data,
        //               drop galleries.org_id
        // ═══════════════════════════════════════════════════════════════

        Schema::create('gallery_org', function (Blueprint $table) {
            $table->foreignUuid('gallery_id')->constrained()->cascadeOnDelete();
            $table->foreignUuid('org_id')->constrained('orgs')->cascadeOnDelete();
            $table->primary(['gallery_id', 'org_id']);
            $table->index(['org_id', 'gallery_id']);
        });

        DB::statement('INSERT INTO gallery_org (gallery_id, org_id) SELECT id, org_id FROM galleries WHERE org_id IS NOT NULL');

        Schema::table('galleries', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
            $table->dropColumn('org_id');
        });

        // ═══════════════════════════════════════════════════════════════
        // Step F (V031): Migrate gallery_groups.org_id → gallery_group_org,
        //                drop gallery_groups.org_id
        // ═══════════════════════════════════════════════════════════════

        DB::statement('INSERT INTO gallery_group_org (gallery_group_id, org_id) SELECT id, org_id FROM gallery_groups WHERE org_id IS NOT NULL');

        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
            $table->dropColumn('org_id');
        });
    }

    public function down(): void
    {
        // ═══════════════════════════════════════════════════════════════
        // Reverse Step F (V031): Re-add gallery_groups.org_id,
        //                        restore data from pivot
        // ═══════════════════════════════════════════════════════════════

        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->foreignUuid('org_id')->nullable()->constrained('orgs')->cascadeOnDelete();
        });

        DB::statement('UPDATE gallery_groups gg SET gg.org_id = (SELECT ggo.org_id FROM gallery_group_org ggo WHERE ggo.gallery_group_id = gg.id LIMIT 1)');

        // ═══════════════════════════════════════════════════════════════
        // Reverse Step E (V030): Re-add galleries.org_id, restore data,
        //                        drop gallery_org pivot
        // ═══════════════════════════════════════════════════════════════

        Schema::table('galleries', function (Blueprint $table) {
            $table->foreignUuid('org_id')->nullable()->constrained('orgs')->cascadeOnDelete();
        });

        DB::statement('UPDATE galleries g SET g.org_id = (SELECT go.org_id FROM gallery_org go WHERE go.gallery_id = g.id LIMIT 1)');

        Schema::dropIfExists('gallery_org');

        // ═══════════════════════════════════════════════════════════════
        // Reverse Step D (V029): Rename org → tenant
        // ═══════════════════════════════════════════════════════════════

        // Drop new FKs and indexes created in step D
        Schema::table('gallery_group_org', function (Blueprint $table) {
            $table->dropForeign('gallery_group_org_org_id_foreign');
            $table->dropIndex(['org_id', 'gallery_group_id']);
        });

        Schema::table('org_invites', function (Blueprint $table) {
            $table->dropForeign('org_invites_org_id_foreign');
        });

        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->dropForeign('gallery_groups_org_id_foreign');
        });

        Schema::table('galleries', function (Blueprint $table) {
            $table->dropForeign('galleries_org_id_foreign');
        });

        Schema::table('users', function (Blueprint $table) {
            $table->dropForeign('users_org_id_foreign');
            $table->dropIndex('users_org_id_index');
        });

        // Rename columns back
        Schema::table('users', function (Blueprint $table) {
            $table->renameColumn('org_id', 'tenant_id');
            $table->index('tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->nullOnDelete();
        });

        Schema::table('galleries', function (Blueprint $table) {
            $table->renameColumn('org_id', 'tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->renameColumn('org_id', 'tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::table('org_invites', function (Blueprint $table) {
            $table->renameColumn('org_id', 'tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        Schema::table('gallery_group_org', function (Blueprint $table) {
            $table->renameColumn('org_id', 'tenant_id');
            $table->foreign('tenant_id')->references('id')->on('tenants')->cascadeOnDelete();
        });

        // Rename tables back
        Schema::rename('orgs', 'tenants');
        Schema::rename('org_invites', 'tenant_invites');
        Schema::rename('gallery_group_org', 'gallery_group_tenant');

        // ═══════════════════════════════════════════════════════════════
        // Reverse Step C (V027): Drop status indexes
        // ═══════════════════════════════════════════════════════════════

        Schema::table('orders', function (Blueprint $table) {
            $table->dropIndex('orders_status_index');
        });

        Schema::table('photographer_statements', function (Blueprint $table) {
            $table->dropIndex('photographer_statements_status_index');
        });

        Schema::table('contracts', function (Blueprint $table) {
            $table->dropIndex('contracts_status_index');
        });

        Schema::table('contract_signers', function (Blueprint $table) {
            $table->dropIndex('contract_signers_status_index');
        });

        // ═══════════════════════════════════════════════════════════════
        // Reverse Step B (V026): Drop birthdate from customers
        // ═══════════════════════════════════════════════════════════════

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('birthdate');
        });

        // ═══════════════════════════════════════════════════════════════
        // Reverse Step A (V025): Drop guest_id, revert tax_rate
        // ═══════════════════════════════════════════════════════════════

        Schema::table('download_logs', function (Blueprint $table) {
            $table->dropColumn('guest_id');
        });

        Schema::table('invoice_snapshots', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->default(20.00)->change();
        });
    }
};
