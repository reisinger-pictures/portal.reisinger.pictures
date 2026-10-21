<?php

namespace App\Services;

use Carbon\Carbon;
use Illuminate\Support\Str;

/**
 * Signierte, ablaufende Links für den **externen** Contact Sheet (Phase 4).
 *
 * Der Link ist der Ersatz für den eingeloggten Download: der Empfänger
 * (Kunde/Agentur) öffnet eine URL und bekommt das PDF — ohne Portal-Login.
 * Damit ist der Token selbst das **Bearer-Credential**: er wird in keinem
 * **Log der Anwendung** geschrieben, nie in einen Audit-Kontext und nie in
 * eine Fehlermeldung aufgenommen. Für die Audit-Zeilen dient stattdessen die
 * `link_id` (eine UUID), die im Token mitläuft, aber selbst kein Credential
 * ist. Der Token ist Bestandteil der **URL** — er steht damit im Access-Log
 * des Webservers bzw. eines vorgelagerten Proxys, sobald dieser Zugriffe
 * protokolliert (dieselbe Abwägung wie bei den bestehenden Token-Routen
 * `/api/model-profil/{token}` und `/api/contracts/join/{token}`).
 *
 * Signiert wird über {@see OfferTokenService} — derselbe HS256-Secret und
 * derselbe Verifikationspfad wie der Login-Token. Es gibt bewusst **keine**
 * zweite HMAC-/Base64-Eigenimplementierung.
 *
 * Die Variante ist **immer** `external`: die interne Variante enthält PII
 * (Realname, Kontaktdaten, Altersnachweis-Status) und darf niemals teilbar
 * sein. `verify()` prüft das strikt — eine Abweichung liefert `null`, nie
 * eine teilweise akzeptierte Nutzlast.
 */
class ModelContactSheetLinkService
{
    /**
     * Gültigkeitsfenster des Links (Tage).
     *
     * Der Link ist bis zum Ablauf **nicht widerrufbar** (siehe
     * `features/crm/07-model-contact-sheet-export.md` §6) — das Fenster ist
     * deshalb bewusst kurz.
     */
    public const TTL_DAYS = 7;

    /**
     * Typ-Kennung im Payload. Sie trennt den Contact-Sheet-Link von anderen
     * signierten Nutzlasten (z. B. dem Quote-Token).
     */
    public const TOKEN_TYPE = 'model_contact_sheet';

    /**
     * Die einzige teilbare Variante.
     */
    public const VARIANT = ModelContactSheetService::VARIANT_EXTERNAL;

    public function __construct(
        private readonly OfferTokenService $offerTokens,
    ) {}

    /**
     * Signiert einen Link über die angegebenen Model-Profile.
     *
     * @param  array<int, string>  $profileIds  Model-Profile-IDs (eindeutig).
     * @param  string  $brand  Brand des Akteurs (Bindung im Payload).
     * @param  Carbon|null  $expiresAt  Ablauf; Standard: jetzt + {@see self::TTL_DAYS}.
     */
    public function issue(array $profileIds, string $brand, ?Carbon $expiresAt = null): string
    {
        $expiresAt ??= now()->addDays(self::TTL_DAYS);

        return $this->offerTokens->issue([
            'type' => self::TOKEN_TYPE,
            'model_profile_ids' => array_values($profileIds),
            'variant' => self::VARIANT,
            'brand' => trim($brand),
            'link_id' => (string) Str::uuid(),
        ], $expiresAt);
    }

    /**
     * Prüft einen Token und validiert die **vollständige** Nutzlast.
     *
     * Analog zu {@see OfferTokenService::verifyQuote()}: jede Abweichung
     * (Typ, Variante, ID-Liste, Brand, Ablauf) liefert `null` — es gibt
     * keine teilweise akzeptierte Nutzlast.
     *
     * @param  string|null  $expectedBrand  Brand, an den der Token gebunden sein muss.
     * @return array{model_profile_ids: array<int, string>, variant: string, brand: string, link_id: string}|null
     */
    public function verify(string $token, ?string $expectedBrand = null): ?array
    {
        $payload = $this->offerTokens->verify($token);
        if ($payload === null) {
            return null;
        }

        if (($payload['type'] ?? null) !== self::TOKEN_TYPE) {
            return null;
        }

        // Nur die externe Variante ist teilbar — die interne enthält PII.
        if (($payload['variant'] ?? null) !== self::VARIANT) {
            return null;
        }

        $profileIds = $payload['model_profile_ids'] ?? null;
        if (! is_array($profileIds) || $profileIds === [] || count($profileIds) > ModelContactSheetService::MAX_BULK_PROFILES) {
            return null;
        }

        $normalizedIds = [];
        foreach ($profileIds as $profileId) {
            if (! is_string($profileId) || trim($profileId) === '' || strlen($profileId) > 255) {
                return null;
            }

            $normalizedIds[] = $profileId;
        }

        if (count(array_unique($normalizedIds)) !== count($normalizedIds)) {
            return null;
        }

        $brand = $payload['brand'] ?? null;
        if (! is_string($brand) || trim($brand) === '') {
            return null;
        }
        if ($expectedBrand !== null && ! hash_equals(trim($expectedBrand), trim($brand))) {
            return null;
        }

        $linkId = $payload['link_id'] ?? null;
        if (! is_string($linkId) || ! Str::isUuid($linkId)) {
            return null;
        }

        return [
            'model_profile_ids' => $normalizedIds,
            'variant' => self::VARIANT,
            'brand' => trim($brand),
            'link_id' => $linkId,
        ];
    }

    /**
     * Die `link_id` eines ausgestellten Links — der PII-freie Audit-Griff.
     *
     * Der Token selbst ist ein Bearer-Credential und darf nirgends auftauchen;
     * die Audit-Zeile nennt deshalb nur diese UUID.
     */
    public function linkIdFor(string $token): ?string
    {
        $payload = $this->verify($token);

        return $payload['link_id'] ?? null;
    }
}
