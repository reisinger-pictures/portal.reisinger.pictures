<?php

declare(strict_types=1);

namespace Tests\Feature\Eur;

use App\Enums\UserRole;
use App\Models\Role;
use App\Models\User;
use App\Support\BrandRegistry;
use Illuminate\Foundation\Testing\RefreshDatabase;
use LSNepomuceno\Signet\Signet;
use Tests\Support\AuthTokenFactory;
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

        $token = AuthTokenFactory::forUser($user);
        // Nicht über den Test-Prozess hinweg angemeldet lassen: sonst wirkt ein
        // späterer Request ohne Bearer-Header trotzdem authentifiziert.
        auth('api')->forgetUser();

        return $token;
    }

    /** Produces a real self-signed PAdES signature over the given bytes (D-84). */
    protected function signedBytes(string $original): string
    {
        $key = openssl_pkey_new(['private_key_bits' => 2048, 'private_key_type' => OPENSSL_KEYTYPE_RSA]);
        $csr = openssl_csr_new(['commonName' => 'EAR Unit Testsigner'], $key, ['digest_alg' => 'sha256']);
        $x509 = openssl_csr_sign($csr, null, $key, 1, ['digest_alg' => 'sha256']);

        if ($key === false || $csr === false || $x509 === false) {
            $this->fail('Could not generate the test signing certificate.');
        }

        openssl_x509_export($x509, $certificate);
        openssl_pkey_export($key, $privateKey);

        return (new Signet)->newSignature()
            ->certificateFromPem((string) $certificate, (string) $privateKey)
            ->pdfContents($original, 'signiert.pdf')
            ->sign()
            ->contents();
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
