<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * Model-Registrierung über Einladungslink (CRM).
 *
 * Ein Admin lädt eine Managerperson per E-Mail ein; diese registriert ohne
 * Account einen Act mit 1..n Personen. Jede Person ist ein eigener CRM-Customer
 * und erhält ein ModelProfile (versionsgebundener Answers-Snapshot). Der Act
 * bündelt die Personen über act_members; eine Person ist der Manager.
 *
 * - customers.user_id: optionales Portal-Konto je Person.
 * - customers.is_model: Marker für Customer mit ModelProfile.
 * - model_registration_invites: Einmal-Token (used_at atomar) statt eines
 *   Account-gebundenen Flows.
 */
return new class extends Migration
{
    public function up(): void
    {
        Schema::table('customers', function (Blueprint $table) {
            $table->uuid('user_id')->nullable()->after('brand');
            $table->boolean('is_model')->default(false)->after('user_id');
        });

        Schema::create('model_profiles', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->unique()->constrained('customers')->cascadeOnDelete();
            $table->string('catalog_version', 20);
            $table->json('answers');
            $table->string('gender', 20)->nullable();
            $table->boolean('age_proof_required')->default(false);
            $table->string('age_proof_path')->nullable();
            $table->timestamp('age_proof_uploaded_at')->nullable();
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('acts', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('brand', 20);
            $table->index('brand');
            $table->foreignUuid('manager_customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('act_type', 20)->default('single');
            $table->string('catalog_version', 20);
            $table->json('answers')->nullable();
            $table->unsignedInteger('person_count')->default(1);
            $table->timestamp('submitted_at')->nullable();
            $table->timestamps();
        });

        Schema::create('act_members', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('act_id')->constrained('acts')->cascadeOnDelete();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('role', 20)->default('member');
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->unique(['act_id', 'customer_id']);
        });

        Schema::create('model_registration_invites', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->string('token', 64)->unique();
            $table->string('email')->nullable();
            $table->string('label')->nullable();
            $table->string('brand', 20)->nullable()->default('rp');
            $table->index('brand');
            $table->foreignUuid('invited_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('expires_at');
            $table->timestamp('used_at')->nullable();
            $table->uuid('act_id')->nullable();
            $table->uuid('customer_id')->nullable();
            $table->timestamp('created_at')->useCurrent();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_registration_invites');
        Schema::dropIfExists('act_members');
        Schema::dropIfExists('acts');
        Schema::dropIfExists('model_profiles');

        Schema::table('customers', function (Blueprint $table) {
            $table->dropColumn(['user_id', 'is_model']);
        });
    }
};
