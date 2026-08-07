<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Consolidated migration replacing V025–V033 (all deleted).
 *
 * The original files 025–033 are merged so that this single migration produces
 * exactly the same END schema as the sum of all of them, running against the
 * V024 production schema. Up() is divided into four logically separate blocks
 * (see section headers). The `brands` table from V027/V029 is intentionally
 * NOT created — it nets to nothing and the end schema has no brands table.
 *
 * down() attempts to reverse to the V024 state as far as reasonable. The
 * V031 brand-merge (srp → rp) is not reversible by nature and is a documented
 * no-op in down().
 */
return new class extends Migration
{
    public function up(): void
    {
        // ══════════════════════════════════════════════════════════════
        // BLOCK 1 — Independent schema extensions (no org/brand dependency)
        // ══════════════════════════════════════════════════════════════

        // ── V025 Step A ───────────────────────────────────────────────
        Schema::table('download_logs', function (Blueprint $table) {
            $table->string('guest_id', 36)->nullable()->after('user_id')->index();
        });

        Schema::table('invoice_snapshots', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->nullable()->default(null)->change();
        });

        // ── V025 Step B ───────────────────────────────────────────────
        Schema::table('customers', function (Blueprint $table) {
            $table->date('birthdate')->nullable()->after('uid');
        });

        // ── V025 Step C ───────────────────────────────────────────────
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

        // ── V026 (contract templates) ─────────────────────────────────
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('contracts', fn (Blueprint $t) => $t->enum('type', ['contract', 'template'])->default('contract'));
        } else {
            DB::statement("ALTER TABLE contracts ADD COLUMN type ENUM('contract', 'template') NOT NULL DEFAULT 'contract' AFTER closes_at");
        }
        Schema::table('contracts', function (Blueprint $table) {
            $table->uuid('template_id')->nullable()->after('type');
            $table->foreign('template_id')->references('id')->on('contracts')->nullOnDelete();
            $table->index('template_id');
            $table->timestamp('expires_at')->nullable()->after('template_id');
        });

        // ── V033 (kanban boards: photo_jobs BEFORE projects) ──────────
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
            $table->enum('status', ['shooting', 'culling', 'bearbeitung', 'export', 'veroeffentlicht', 'abgebrochen'])->default('shooting');
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
            $table->enum('status', ['anfrage', 'angebot', 'beauftragt', 'rechnung', 'bezahlt', 'storniert'])->default('anfrage');
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

        Schema::create('lightroom_catalogs', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->constrained('users')->onDelete('cascade');
            $table->string('name', 255);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['user_id', 'name']);
        });

        // ══════════════════════════════════════════════════════════════
        // BLOCK 2 — ORG REFACTOR (V025 Step D → E → F, kept together)
        // ══════════════════════════════════════════════════════════════

        // ── V025 Step D ───────────────────────────────────────────────

        // D1. Drop ALL foreign keys referencing tenants/tenant_id before renaming
        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('gallery_group_tenant', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        } else {
            Schema::table('gallery_group_tenant', function (Blueprint $table) {
                $table->dropForeign('gallery_group_tenant_tenant_id_foreign');
            });
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('tenant_invites', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        } else {
            Schema::table('tenant_invites', function (Blueprint $table) {
                $table->dropForeign('tenant_invites_tenant_id_foreign');
            });
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('galleries', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        } else {
            Schema::table('galleries', function (Blueprint $table) {
                $table->dropForeign('galleries_tenant_id_foreign');
            });
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('gallery_groups', fn (Blueprint $table) => $table->dropForeign(['tenant_id']));
        } else {
            Schema::table('gallery_groups', function (Blueprint $table) {
                $table->dropForeign('gallery_groups_tenant_id_foreign');
            });
        }

        if (DB::connection()->getDriverName() === 'sqlite') {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign(['tenant_id']);
                $table->dropIndex('users_tenant_id_index');
            });
        } else {
            Schema::table('users', function (Blueprint $table) {
                $table->dropForeign('users_tenant_id_foreign');
                $table->dropIndex('users_tenant_id_index');
            });
        }

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

        // ── V025 Step E ───────────────────────────────────────────────
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

        // ── V025 Step F ───────────────────────────────────────────────
        DB::statement('INSERT INTO gallery_group_org (gallery_group_id, org_id) SELECT id, org_id FROM gallery_groups WHERE org_id IS NOT NULL');

        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->dropForeign(['org_id']);
            $table->dropColumn('org_id');
        });

        // ══════════════════════════════════════════════════════════════
        // BLOCK 3 — BRAND CONVERSION (V028 → V030 → V031, strict order)
        // ══════════════════════════════════════════════════════════════
        // NOTE: The `brands` table (V027 + V029) is intentionally omitted —
        // it nets to zero and the end schema has NO brands table.

        // ── V028: brand ENUM → VARCHAR(20) on 13 tables ───────────────
        $brandTables = [
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

        foreach ($brandTables as $table => $config) {
            $nullable = $config['nullable'] ? 'NULL' : 'NOT NULL';
            $default = $config['default'] !== null ? " DEFAULT '{$config['default']}'" : '';
            if (DB::connection()->getDriverName() === 'sqlite') {
                Schema::table($table, function (Blueprint $t) use ($config) {
                    $brand = $t->string('brand', 20);
                    $config['nullable'] ? $brand->nullable() : $brand->nullable(false);
                    if ($config['default'] !== null) {
                        $brand->default($config['default']);
                    }
                    $brand->change();
                });
            } else {
                DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `brand` VARCHAR(20) {$nullable}{$default}");
            }
        }

        // ── V030: contracts.brand → VARCHAR(20) (special case, NOT in V028) ─
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('brand', 20)->nullable()->default('rp')->change();
        });

        // ── V031: data migration srp → rp (13 UPDATEs + settings UPSERT+DELETE) ─
        $brandUpdateTables = [
            'orders',
            'invoice_snapshots',
            'users',
            'galleries',
            'gallery_groups',
            'orgs',
            'products',
            'license_use_cases',
            'license_modifiers',
            'customers',
            'text_snippets',
            'coupons',
            'contracts',
        ];

        foreach ($brandUpdateTables as $table) {
            DB::statement("UPDATE {$table} SET brand = 'rp' WHERE brand = 'srp'");
        }

        // Settings: update non-conflicting rows first, then delete conflicting
        DB::statement("
            UPDATE settings
            SET brand = 'rp'
            WHERE brand = 'srp'
            AND `key` NOT IN (
                SELECT `key` FROM (SELECT `key` FROM settings WHERE brand = 'rp') AS rp_keys
            )
        ");

        DB::statement("DELETE FROM settings WHERE brand = 'srp'");

        // ══════════════════════════════════════════════════════════════
        // BLOCK 4 — REST
        // ══════════════════════════════════════════════════════════════

        // ── V032 ──────────────────────────────────────────────────────
        Schema::table('galleries', function (Blueprint $table) {
            $table->string('licensing_mode', 20)->nullable()->after('brand');
        });
    }

    public function down(): void
    {
        // Reverse order: Block 4 → Block 3 → Block 2 → Block 1
        // NOTE: The V031 srp→rp data merge is NOT reversible (upstream was a
        // no-op too). The 13 brand columns + contracts.brand stay varchar(20)
        // as-produced in up(); re-converting to ENUM would not restore the
        // original SRP rows. Documented intentional deviation from V024 shape.

        // ══════════════════════════════════════════════════════════════
        // BLOCK 4 reverse — V032
        // ══════════════════════════════════════════════════════════════
        Schema::table('galleries', function (Blueprint $table) {
            $table->dropColumn('licensing_mode');
        });

        // ══════════════════════════════════════════════════════════════
        // BLOCK 3 reverse — V030, then V028 (V031 is no-op)
        // ══════════════════════════════════════════════════════════════

        // V030 down: shrink contracts.brand back to varchar(4)
        Schema::table('contracts', function (Blueprint $table) {
            $table->string('brand', 4)->nullable()->default('rp')->change();
        });

        // V028 down: revert 13 tables to ENUM('rp','srp')
        $brandTables = [
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

        foreach ($brandTables as $table => $config) {
            $nullable = $config['nullable'] ? 'NULL' : 'NOT NULL';
            $default = $config['default'] !== null ? " DEFAULT '{$config['default']}'" : '';
            DB::statement("ALTER TABLE `{$table}` MODIFY COLUMN `brand` ENUM('rp','srp') {$nullable}{$default}");
        }

        // ══════════════════════════════════════════════════════════════
        // BLOCK 2 reverse — V025 org refactor (reverse F → E → D)
        // ══════════════════════════════════════════════════════════════

        // Reverse Step F: re-add gallery_groups.org_id, restore from pivot
        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->foreignUuid('org_id')->nullable()->constrained('orgs')->cascadeOnDelete();
        });

        DB::statement('UPDATE gallery_groups gg SET gg.org_id = (SELECT ggo.org_id FROM gallery_group_org ggo WHERE ggo.gallery_group_id = gg.id LIMIT 1)');

        // Reverse Step E: re-add galleries.org_id, restore from pivot, drop gallery_org
        Schema::table('galleries', function (Blueprint $table) {
            $table->foreignUuid('org_id')->nullable()->constrained('orgs')->cascadeOnDelete();
        });

        DB::statement('UPDATE galleries g SET g.org_id = (SELECT go.org_id FROM gallery_org go WHERE go.gallery_id = g.id LIMIT 1)');

        Schema::dropIfExists('gallery_org');

        // Reverse Step D: drop new FKs/indexes, rename org → tenant
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

        // ══════════════════════════════════════════════════════════════
        // BLOCK 1 reverse — V033, V026, V025 Step C/B/A
        // ══════════════════════════════════════════════════════════════

        // Reverse V033: drop kanban tables
        Schema::dropIfExists('workflow_logs');
        Schema::dropIfExists('lightroom_catalogs');
        Schema::dropIfExists('projects');
        Schema::dropIfExists('photo_jobs');

        // Reverse V026: drop contract template columns
        Schema::table('contracts', function (Blueprint $table) {
            $table->dropForeign(['template_id']);
            $table->dropColumn('template_id');
            $table->dropColumn('expires_at');
        });
        DB::statement("ALTER TABLE contracts DROP COLUMN type");

        // Reverse V025 Step C: drop status indexes
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

        // Reverse V025 Step B: drop customers.birthdate
        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn('birthdate');
        });

        // Reverse V025 Step A: drop guest_id, revert tax_rate
        Schema::table('download_logs', function (Blueprint $table) {
            $table->dropColumn('guest_id');
        });

        Schema::table('invoice_snapshots', function (Blueprint $table) {
            $table->decimal('tax_rate', 5, 2)->default(20.00)->change();
        });
    }
};