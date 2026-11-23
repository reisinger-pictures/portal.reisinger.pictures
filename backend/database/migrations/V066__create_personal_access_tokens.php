<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

/**
 * JWT → Sanctum (Portal #9, Phase 1): Sanctum-Token-Speicher als neue Tabelle.
 *
 * owner decisions 2026-11-23 (D-72..D-78, features/owner-decisions.md):
 * - D-73: transiente Gastrechte werden als strukturierte Sanctum-Abilities
 *   hinter dem Custom-Guard `sanctum_transient` transportiert.
 * - D-74: der Guard-Name `api` bleibt, nur der Treiber wechselt von jwt zu Sanctum.
 * - D-75: zwei httpOnly-Cookies (`rp_jwt`, `rp_jwt_refresh`) tragen Sanctum-Tokens;
 *   keine PHP-Session, kein /sanctum/csrf-cookie.
 * - D-76: /api/auth/refresh bleibt als Shim (240 min / 7 Tage unverändert).
 * - D-72/D-71: keine Service-zu-Service-Scope-Tokens für den ku-online-Client.
 * - D-77: JWT-Package wird erst in Phase 3/4 entfernt — diese Migration ist rein additiv.
 * - D-78: Rollen werden nie in gespeicherte Abilities kopiert (bleiben DB-Queries).
 *
 * Schema (angelehnt an vendor/laravel/sanctum/database/migrations/):
 * `id`, `tokenable_type` (string), `tokenable_id` (string) plus zusammengesetzter
 * Index, `name` (text), `token` (string 64, unique), `abilities` (text, nullable),
 * `last_used_at` (timestamp, nullable), `expires_at` (timestamp, nullable, index),
 * Timestamps.
 *
 * Abweichung vom Sanctum-Standard `morphs('tokenable')`: diese erzeugt eine
 * `unsignedBigInteger`-Spalte. Die Portal-Actor-IDs sind jedoch `users.id` als
 * 36-Zeichen-UUID (HasUuids) bzw. für DB-less Gäste `guest_<uuid>` (42 Zeichen,
 * vgl. InviteController::redeem und ActorIdentity). Ein Bigint würde diese IDs
 * auf PostgreSQL ablehnen (`invalid input syntax for type bigint`) und auf MySQL
 * still verstümmeln. `string` fasst beide Formen; Sanctum löst den Morph unabhängig
 * vom Spaltentyp über `morphTo` auf (D-73/D-75). Kein Backfill — die Tabelle ist neu
 * und leer, Tokens werden nach dem Cutover neu ausgestellt.
 *
 * Additiv: keine bestehende Tabelle wird verändert. Kein Backfill — die Tokens
 * werden nach dem Cutover neu ausgestellt (D-73/D-75), es gibt nichts zu
 * übernehmen. `down()` ist bewusst leer (backend/AGENTS.md: down() wird nie
 * ausgeführt). Schema-/Backfill-/Rollback-Entscheidung:
 * features/auth/01-roles-and-access.md, Abschnitt „Sanctum-Token-Speicher (V066)“.
 */
return new class extends Migration
{
    private const TABLE = 'personal_access_tokens';

    public function up(): void
    {
        if (! Schema::hasTable(self::TABLE)) {
            Schema::create(self::TABLE, function (Blueprint $table): void {
                $table->id();
                $table->string('tokenable_type');
                $table->string('tokenable_id');
                $table->index(
                    ['tokenable_type', 'tokenable_id'],
                    'personal_access_tokens_tokenable_type_tokenable_id_index'
                );
                $table->text('name');
                $table->string('token', 64);
                $table->text('abilities')->nullable();
                $table->timestamp('last_used_at')->nullable();
                $table->timestamp('expires_at')->nullable();
                $table->timestamps();
            });
        }

        if (! Schema::hasIndex(self::TABLE, 'personal_access_tokens_token_unique')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->unique('token', 'personal_access_tokens_token_unique');
            });
        }

        if (! Schema::hasIndex(self::TABLE, 'personal_access_tokens_expires_at_index')) {
            Schema::table(self::TABLE, function (Blueprint $table): void {
                $table->index('expires_at', 'personal_access_tokens_expires_at_index');
            });
        }
    }

    public function down(): void
    {
        // Bewusst leer: down() wird gemäß backend/AGENTS.md nie ausgeführt;
        // diese Migration ist rein additiv und verändert keine bestehende Tabelle.
    }
};
