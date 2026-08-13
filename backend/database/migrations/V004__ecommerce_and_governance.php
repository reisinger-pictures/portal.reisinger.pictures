<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void {
        // --- 1. User Flatrate & Billing ---
        Schema::table('users', function (Blueprint $table) {
            $table->string('flatrate_level', 20)->default('none')->after('can_edit_metadata');
            $table->string('billing_name')->nullable()->after('name');
            $table->string('billing_company')->nullable()->after('billing_name');
            $table->string('billing_street')->nullable()->after('billing_company');
            $table->string('billing_zip', 20)->nullable()->after('billing_street');
            $table->string('billing_city')->nullable()->after('billing_zip');
        });

        // --- 2. Hierarchical Governance & Custom Quotes ---
        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->boolean('is_editorial_only')->nullable()->after('is_public');
            $table->boolean('is_free_download')->nullable()->after('is_editorial_only');
            $table->boolean('is_hidden')->nullable()->after('is_editorial_only');
        });

        Schema::table('galleries', function (Blueprint $table) {
            $table->boolean('is_editorial_only')->nullable()->after('is_public');
            $table->boolean('is_free_download')->nullable()->after('is_editorial_only');
            $table->boolean('allow_custom_quotes')->default(false)->after('is_free_download'); // <-- NEU
            $table->boolean('is_hidden')->nullable()->after('is_editorial_only');
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->boolean('is_editorial_only')->nullable()->after('iso_country');
            $table->boolean('is_hidden')->nullable()->after('is_editorial_only');
            $table->timestamp('last_accessed_at')->nullable()->after('is_hidden');
            $table->boolean('is_downscaled')->default(false)->after('last_accessed_at');
        });

        // --- 3. Pricing Matrix & License Options ---
        Schema::create('pricing_factors', function (Blueprint $table) {
            $table->id();
            $table->enum('type', ['usage', 'resolution', 'duration'])->index();
            $table->string('name');
            $table->decimal('multiplier', 5, 2);
            $table->timestamps();
        });

        // <-- NEU
        Schema::create('license_options', function (Blueprint $table) {
            $table->id();
            $table->enum('usage_frequency', ['einmalig', 'mehrmalig'])->default('einmalig');
            $table->decimal('usage_frequency_multiplier', 5, 2)->default(1.00);
            $table->timestamps();
        });

        // --- 4. Accounting, Invoicing & Quotes ---
        Schema::create('invoice_sequences', function (Blueprint $table) {
            $table->id();
            $table->integer('year')->unique();
            $table->integer('current_value')->default(0);
        });

        Schema::create('orders', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('status')->default('pending');
            $table->boolean('is_quote_request')->default(false); // <-- NEU
            $table->string('quote_status')->nullable(); // <-- NEU
            $table->integer('total_amount')->default(0);
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('invoice_snapshots', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('order_id')->constrained()->onDelete('cascade');
            $table->string('invoice_number')->unique();
            $table->json('customer_details');
            $table->integer('total_net');
            $table->integer('total_gross');
            $table->decimal('tax_rate', 5, 2)->default(20.00);
            $table->timestamp('created_at')->useCurrent();
        });

        // --- 5. Download Log Enhancement ---
        Schema::table('download_logs', function (Blueprint $table) {
            $table->string('resolution_tier', 20)->nullable()->after('item_type');
            $table->json('payload')->nullable()->comment('Speichert z.B. Bildanzahl bei ZIP-Downloads')->after('user_agent'); // <-- NEU
            $table->dropColumn('item_identifier'); // <-- NEU
        });

        // --- 6. Tenant Management (Replaces Domain Mappings) ---
        Schema::dropIfExists('domain_mappings');

        Schema::create('tenants', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('name');
            $table->string('domain')->unique()->nullable();
            $table->enum('invoice_frequency', ['immediate', 'monthly', 'quarterly'])->default('immediate');
            $table->timestamps();
        });

        Schema::create('tenant_user', function (Blueprint $table) {
            $table->foreignUuid('tenant_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('user_id')->constrained()->onDelete('cascade');
            $table->primary(['tenant_id', 'user_id']);
        });

        Schema::create('gallery_group_tenant', function (Blueprint $table) {
            $table->foreignUuid('gallery_group_id')->constrained()->onDelete('cascade');
            $table->foreignUuid('tenant_id')->constrained()->onDelete('cascade');
            $table->primary(['gallery_group_id', 'tenant_id']);
        });

        // --- NEU in V1.1: Tenant Invites ---
        Schema::create('tenant_invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('email');
            $table->foreignUuid('tenant_id')->constrained()->onDelete('cascade');
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('created_at')->useCurrent();
        });

        // --- 7. Default Settings ---
        \Illuminate\Support\Facades\DB::table('settings')->insert([
            ['key' => 'base_price', 'value' => '35.00'],
            ['key' => 'term_editorial', 'value' => 'Nur für redaktionelle Berichterstattung zugelassen. Jegliche kommerzielle Nutzung (Werbung, Advertorials, Social Media Ads) ist untersagt.'],
            ['key' => 'term_commercial', 'value' => 'Uneingeschränkte kommerzielle Nutzung (Werbung, Flyer, Social Media Kampagnen) ist gestattet. Weiterverkauf der Rohdaten ist untersagt.'],
            ['key' => 'term_1_year', 'value' => 'Nutzungsrecht befristet auf 1 Jahr ab Rechnungsdatum.'],
            ['key' => 'term_unlimited', 'value' => 'Zeitlich und räumlich unbegrenztes Nutzungsrecht.'],
            ['key' => 'term_web', 'value' => 'Auflösung optimiert für Web & Social Media (max. 2560px Kantenlänge, 72dpi).'],
            ['key' => 'term_print', 'value' => 'Hohe Auflösung für den Druck (bis A4, max. 4000px).'],
            ['key' => 'term_original', 'value' => 'Maximale Originalauflösung (RAW entwickelt).']
        ]);

        // --- 8. Super Admin Role ---
        $roleId = \Illuminate\Support\Str::uuid()->toString();
        \Illuminate\Support\Facades\DB::table('roles')->insert([
            'id' => $roleId,
            'name' => 'super_admin'
        ]);

        // God Mode an unseren initialen Admin vergeben
        $adminUser = \Illuminate\Support\Facades\DB::table('users')->where('email', env('ADMIN_EMAIL', 'admin@example.com'))->first();
        if ($adminUser) {
            \Illuminate\Support\Facades\DB::table('user_roles')->insert([
                'user_id' => $adminUser->id,
                'role_id' => $roleId
            ]);
        }
    }

    public function down(): void {
        Schema::dropIfExists('tenant_invites');
        Schema::dropIfExists('gallery_group_tenant');
        Schema::dropIfExists('tenant_user');
        Schema::table('download_logs', function (Blueprint $table) {
            $table->dropColumn(['resolution_tier', 'payload']);
            $table->string('item_identifier')->default('');
        });

        Schema::dropIfExists('tenants');

        Schema::create('domain_mappings', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('domain')->unique();
            $table->foreignUuid('role_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignUuid('gallery_group_id')->nullable()->constrained('gallery_groups')->onDelete('set null');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::dropIfExists('invoice_snapshots');
        Schema::dropIfExists('orders');
        Schema::dropIfExists('invoice_sequences');
        Schema::dropIfExists('license_options'); // <-- NEU
        Schema::dropIfExists('pricing_factors');

        Schema::table('users', function (Blueprint $table) {
            $table->dropColumn(['flatrate_level', 'billing_name', 'billing_company', 'billing_street', 'billing_zip', 'billing_city']);
        });

        Schema::table('photos', function (Blueprint $table) {
            $table->dropColumn(['is_editorial_only', 'is_hidden', 'last_accessed_at', 'is_downscaled']);
        });
        Schema::table('galleries', function (Blueprint $table) {
            // FIX: is_free_download in der down Methode hinzugefügt, falls Rollback nötig
            $table->dropColumn(['is_editorial_only', 'is_free_download', 'allow_custom_quotes', 'is_hidden']);
        });
        Schema::table('gallery_groups', function (Blueprint $table) {
            $table->dropColumn(['is_editorial_only', 'is_free_download', 'is_hidden']);
        });

        $roleId = \Illuminate\Support\Facades\DB::table('roles')->where('name', 'super_admin')->value('id');
        if ($roleId) {
            \Illuminate\Support\Facades\DB::table('user_roles')->where('role_id', $roleId)->delete();
            \Illuminate\Support\Facades\DB::table('roles')->where('id', $roleId)->delete();
        }
    }
};
