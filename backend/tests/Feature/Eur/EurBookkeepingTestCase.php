<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

/**
 * Gemeinsame Auth-/Brand-Helfer für die EAR-Buchhaltungs-API-Tests.
 *
 * Spiegelt die Helfer aus Tests\Feature\EurApiTest (dort privat), damit die
 * fokussierten Tests dieselbe Rolle/Brand-Konstellation nutzen.
 */
abstract class EurBookkeepingTestCase extends TestCase
{
    use RefreshDatabase;

    protected const OTHER_BRAND = 'srp';

    protected function setUp(): void
    {
        parent::setUp();
        BrandRegistry::clearCache();
    }

    protected function tokenFor(?string $role = null): string
    {
        $user = User::factory()->create(['brand' => BrandRegistry::currentId()]);
        $user->roles()->attach(Role::firstOrCreate(['name' => $role ?? UserRole::ADMIN->value]));

        $token = auth('api')->login($user);
        // Nicht über den Test-Prozess hinweg angemeldet lassen: sonst wirkt ein
        // späterer Request ohne Bearer-Header trotzdem authentifiziert.
        auth('api')->forgetUser();

        return $token;
    }

    /**
     * @return array<string, string>
     */
    protected function bearer(string $token): array
    {
        // Multipart-Uploads brauchen Accept: application/json, damit eine
        // Validierungsnicht 302 sondern 422 liefert (Anmerkung: post() setzt
        // diesen Header nicht selbst).
        return ['Authorization' => "Bearer {$token}", 'Accept' => 'application/json'];
    }
}
