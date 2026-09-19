<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

/**
 * Model-Profile Iteration (P2): Personen-Fotos, Lifecycle-Anker und Profil-Zugang.
 *
 * - model_profiles.answers wird von `json` auf `text` umgestellt. Der Snapshot
 *   wird ab jetzt app-seitig mit Laravel `Crypt` verschlüsselt (`encrypted:array`),
 *   und ein base64-Ciphertext ist gültiges JSON nicht — eine `json`-Spalte würde
 *   in MariaDB/MySQL beim Insert scheitern. Die Such-/Filterfelder
 *   (gender, customer.city, birthdate, Kategorien werden in-memory aus dem
 *   Snapshot abgeleitet) bleiben plaintext.
 * - model_profiles.last_confirmed_at: Lifecycle-Anker (Erst-Submit und jede
 *   Bestätigung/Aktualisierung). last_reminder_stage/last_reminder_at machen den
 *   Reminder-Versand idempotent (06 §3.3).
 * - model_photos: max. 5 Personen-Fotos je Customer, Sichtbarkeit public/internal,
 *   ein Hauptbild (`is_primary`). Dateien liegen verschlüsselt auf der privaten
 *   `local`-Disk.
 * - model_access_tokens: wiederverwendbarer Profil-Magic-Link (24 h), Revoke und
 *   `last_used_at`. „unique-aktiv" wird app-seitig erzwungen: beim Ausstellen wird
 *   ein ggf. bestehender aktiver Token widerrufen.
 *
 * Kein Live-Betrieb: eine Altdaten-Migration der answers ist bewusst nicht nötig.
 */
return new class extends Migration
{
    public function up(): void
    {
        // Encrypted snapshots cannot live in a JSON-typed column.
        Schema::table('model_profiles', function (Blueprint $table) {
            $table->text('answers')->change();
        });

        // MariaDB/MySQL's JSON alias carries an implicit CHECK (json_valid(`answers`)).
        // Encrypted ciphertext is not valid JSON, so exactly that constraint must
        // go once the column is plain TEXT — unrelated CHECKs stay untouched.
        if (in_array(Schema::getConnection()->getDriverName(), ['mysql', 'mariadb'], true)) {
            $constraints = DB::select(
                "SELECT CONSTRAINT_NAME FROM information_schema.CHECK_CONSTRAINTS
                 WHERE CONSTRAINT_SCHEMA = DATABASE() AND TABLE_NAME = ?
                   AND (LOWER(CHECK_CLAUSE) LIKE '%json_valid%' OR CONSTRAINT_NAME = 'answers')",
                ['model_profiles']
            );

            foreach ($constraints as $constraint) {
                DB::statement('ALTER TABLE `model_profiles` DROP CONSTRAINT `'.$constraint->CONSTRAINT_NAME.'`');
            }
        }

        Schema::table('model_profiles', function (Blueprint $table) {
            $table->timestamp('last_confirmed_at')->nullable()->after('submitted_at');
            $table->string('last_reminder_stage', 20)->nullable()->after('last_confirmed_at');
            $table->timestamp('last_reminder_at')->nullable()->after('last_reminder_stage');
        });

        Schema::create('model_photos', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->foreignUuid('model_profile_id')->constrained('model_profiles')->cascadeOnDelete();
            $table->string('path');
            $table->string('original_name')->nullable();
            $table->string('mime_type', 100)->nullable();
            $table->unsignedBigInteger('size_bytes')->nullable();
            $table->enum('visibility', ['public', 'internal'])->default('internal');
            $table->boolean('is_primary')->default(false);
            $table->unsignedInteger('position')->default(0);
            $table->timestamps();

            $table->index(['model_profile_id', 'position']);
        });

        Schema::create('model_access_tokens', function (Blueprint $table) {
            $table->uuid('id')->primary();
            $table->foreignUuid('customer_id')->constrained('customers')->cascadeOnDelete();
            $table->string('token', 64)->unique();
            $table->timestamp('expires_at');
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignUuid('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();

            $table->index(['customer_id', 'revoked_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('model_access_tokens');
        Schema::dropIfExists('model_photos');

        Schema::table('model_profiles', function (Blueprint $table) {
            $table->dropColumn(['last_confirmed_at', 'last_reminder_stage', 'last_reminder_at']);
        });

        Schema::table('model_profiles', function (Blueprint $table) {
            $table->json('answers')->change();
        });
    }
};
