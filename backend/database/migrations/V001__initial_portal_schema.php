<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\DB;

return new class extends Migration {
    public function up(): void {
        // --- Cache Tables ---
        Schema::create('cache', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->mediumText('value');
            $table->bigInteger('expiration')->index();
        });
        Schema::create('cache_locks', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->string('owner');
            $table->bigInteger('expiration')->index();
        });

        // --- Job Tables ---
        Schema::create('jobs', function (Blueprint $table) {
            $table->id();
            $table->string('queue')->index();
            $table->longText('payload');
            $table->unsignedTinyInteger('attempts');
            $table->unsignedInteger('reserved_at')->nullable();
            $table->unsignedInteger('available_at');
            $table->unsignedInteger('created_at');
        });
        Schema::create('job_batches', function (Blueprint $table) {
            $table->string('id')->primary();
            $table->string('name');
            $table->integer('total_jobs');
            $table->integer('pending_jobs');
            $table->integer('failed_jobs');
            $table->longText('failed_job_ids');
            $table->mediumText('options')->nullable();
            $table->integer('cancelled_at')->nullable();
            $table->integer('created_at');
            $table->integer('finished_at')->nullable();
        });
        Schema::create('failed_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->unique();
            $table->text('connection');
            $table->text('queue');
            $table->longText('payload');
            $table->longText('exception');
            $table->timestamp('failed_at')->useCurrent();
        });

        Schema::create('users', function (Blueprint $table) {
            $table->id();
            $table->string('email')->unique();
            $table->string('name');
            $table->string('password')->nullable();
            $table->string('metadata_copyright')->nullable();
            $table->boolean('can_edit_metadata')->default(false);
            $table->timestamp('created_at')->useCurrent();
        });

        // --- Password Reset Tokens ---
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        Schema::create('roles', function (Blueprint $table) {
            $table->id();
            $table->string('name', 50)->unique();
        });

        DB::table('roles')->insert([
            ['name' => 'admin'], ['name' => 'photographer'], ['name' => 'client']
        ]);

        Schema::create('user_roles', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->primary(['user_id', 'role_id']);
        });

        Schema::create('gallery_groups', function (Blueprint $table) {
            $table->id();
            $table->foreignId('parent_id')->nullable()->constrained('gallery_groups')->onDelete('set null');
            $table->string('name');
            $table->string('slug')->unique();
            $table->boolean('is_public')->nullable()->default(null);
            $table->timestamp('created_at')->useCurrent();
        });

        // WICHTIG: domain_mappings muss NACH gallery_groups und roles angelegt werden!
        Schema::create('domain_mappings', function (Blueprint $table) {
            $table->id();
            $table->string('domain')->unique();
            $table->foreignId('role_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('gallery_group_id')->nullable()->constrained('gallery_groups')->onDelete('set null');
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('galleries', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_group_id')->nullable()->constrained()->onDelete('set null');
            $table->string('name');
            $table->string('slug')->unique();
            $table->enum('type', ['selection', 'delivery'])->default('delivery');
            $table->boolean('is_live')->default(false);
            $table->boolean('is_public')->default(false);
            $table->string('password_hash')->nullable();
            
            // Metadaten-Berechtigungen & Defaults
            $table->boolean('allow_client_metadata_edit')->default(false);
            $table->boolean('apply_metadata_to_photos')->default(false);
            $table->string('default_title')->nullable();
            $table->text('default_description')->nullable();
            $table->string('default_keywords')->nullable();
            $table->string('default_location')->nullable();
            $table->string('default_city')->nullable();
            $table->string('default_state')->nullable();
            $table->string('default_country')->nullable();
            $table->string('default_iso_country', 2)->nullable();

            $table->timestamp('expires_at')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::table('users', function (Blueprint $table) {
            $table->foreignId('current_ftp_gallery_id')->nullable()->constrained('galleries')->onDelete('set null');
        });

        Schema::create('photos', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->onDelete('cascade');
            $table->string('filename');
            $table->string('lr_uuid', 64);
            $table->integer('width')->default(0);
            $table->integer('height')->default(0);
            
            // IPTC Metadaten
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('keywords')->nullable();
            $table->string('location')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('iso_country', 2)->nullable();
            
            $table->timestamp('created_at')->useCurrent();
            $table->unique(['gallery_id', 'lr_uuid']);
        });

        Schema::create('photo_metadata_versions', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photo_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('title')->nullable();
            $table->text('description')->nullable();
            $table->string('keywords')->nullable();
            $table->string('location')->nullable();
            $table->string('city')->nullable();
            $table->string('state')->nullable();
            $table->string('country')->nullable();
            $table->string('iso_country', 2)->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('ratings', function (Blueprint $table) {
            $table->id();
            $table->foreignId('photo_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('cascade');
            $table->uuid('guest_id')->nullable()->index();
            $table->string('guest_name')->nullable();
            $table->tinyInteger('rating');
            $table->text('comment')->nullable();
            $table->unique(['photo_id', 'user_id', 'guest_id']);
        });

        Schema::create('user_gallery_groups', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('gallery_group_id')->constrained()->onDelete('cascade');
            $table->boolean('wants_notifications')->default(false);
            $table->primary(['user_id', 'gallery_group_id']);
        });

        Schema::create('user_galleries', function (Blueprint $table) {
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->foreignId('gallery_id')->constrained()->onDelete('cascade');
            $table->boolean('wants_notifications')->default(false);
            $table->primary(['user_id', 'gallery_id']);
        });

        Schema::create('download_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('user_id')->nullable()->constrained()->onDelete('set null');
            $table->string('user_name_snapshot')->nullable();
            $table->foreignId('gallery_id')->nullable()->constrained()->onDelete('set null');
            $table->string('gallery_name_snapshot')->nullable();
            $table->enum('item_type', ['single_image', 'full_zip']);
            $table->string('item_identifier');
            $table->text('user_agent')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('gallery_invites', function (Blueprint $table) {
            $table->id();
            $table->foreignId('gallery_id')->constrained()->onDelete('cascade');
            $table->string('token', 64)->unique();
            $table->string('name')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });

        Schema::create('settings', function (Blueprint $table) {
            $table->string('key')->primary();
            $table->text('value')->nullable();
        });

        

        
    }

    public function down(): void {
        Schema::dropIfExists('jobs');
        Schema::dropIfExists('job_batches');
        Schema::dropIfExists('failed_jobs');
        Schema::dropIfExists('cache');
        Schema::dropIfExists('cache_locks');

        
        Schema::dropIfExists('settings');
        Schema::dropIfExists('gallery_invites');
        Schema::dropIfExists('download_logs');
        Schema::dropIfExists('user_galleries');
        Schema::dropIfExists('user_gallery_groups');
        Schema::dropIfExists('ratings');
        Schema::dropIfExists('photo_metadata_versions');
        Schema::dropIfExists('photos');
        Schema::table('users', function (Blueprint $table) { $table->dropForeign(['current_ftp_gallery_id']); });
        Schema::dropIfExists('galleries');
        Schema::dropIfExists('domain_mappings');
        Schema::dropIfExists('gallery_groups');
        Schema::dropIfExists('user_roles');
        Schema::dropIfExists('roles');
        Schema::dropIfExists('password_reset_tokens');
        Schema::dropIfExists('users');
    }
};
