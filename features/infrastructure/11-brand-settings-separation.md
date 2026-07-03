# Markenspezifische Settings-Trennung — Konzept (SOLL)

> **Status:** Beschreibt den Soll-Zustand (Ziel).
> Verknüpft: `features/infrastructure/06-multi-domain-branding.md`, `features/infrastructure/08-tenant-brand-concept.md`.

## 1. Kontext

Markenspezifische Settings (Bankdaten, Firmenadresse, Wasserzeichen-Opacity, Lizenz-/Preisfaktoren)
werden über ein **String-Präfix** im `settings.key` getrennt:
- **B2B** (`reisinger.pictures`) = kein Präfix, z. B. `bank_iban`.
- **SRP** (`story.reisinger.pictures`) = `srp_`-Präfix, z. B. `srp_bank_iban`.

Das `settings`-Model (`backend/app/Models/Setting.php`) ist ein plain key/value-Store **ohne**
Brand-Feld, Scope oder typisierten Accessor. Die Trennung passiert verstreut in Consumern über das
verteilte Muster `$pfx = config('app.brand') === 'story.reisinger.pictures' ? 'srp_' : ''`.

## 2. Soll-Zustand (Architektur)

### 3.1 Symmetrie Lesen ↔ Schreiben

Jede markenspezifische Setting-Operation muss **dieselbe Präfix-Regel** beim Lesen und Schreiben
anwenden:
- B2B-Kontext: ungeprefixter Key.
- SRP-Kontext: `srp_`-Präfix beim Lesen **und** Schreiben.

Konkret:
- `updateBillingDetails()`: Keys brandabhängig prefixen.
- `updateLicenseTerms()`: bereits brandexplizite Keys (`srp_*`) **nicht** erneut prefixen.
- `updateWatermark()`: `watermark_opacity` brandabhängig prefixen.

### 3.2 Zentraler Resolver

Statt des verteilten `$get()`/`$pfx`-Musters ein zentraler Resolver (z. B.
`App\Services\BrandSettings\SettingResolver`) mit:
- `get(string $key, ?string $brand = null): ?string` — preäfixierte Abfrage + Fallback auf B2B.
- `set(string $key, string $value, ?string $brand = null): void` — markenspezifisch speichern.
- `prefixFor(string $brand): string` — liefert `''` (B2B) bzw. `'srp_'` (SRP).
- `isAlreadyBrandPrefixed(string $key): bool` — verhindert `srp_srp_*`-Dopplung.

Dieser Resolver konsolidiert die Präfix-Logik an einer Stelle und wird von allen Consumern
(Controller, Mail, Blade, Services) verwendet.

## 3. Abgrenzung

- Diese Spec behandelt ausschließlich die **Konsistenz der Settings-Trennung** (Lesen/Schreiben
  symmetrisieren + zentralisieren).
- **Keine** Änderung am Setting-Datenmodell selbst (plain key/value bleibt); eine `brand`-Spalte
  auf `settings` ist nicht Teil dieses Konzepts.
- Die SRP-Bankdaten-Verfügbarkeit im **Queue-/CLI-Pfad** (PDF-Rendering) ist Thema von T-02
  (`09-brand-context-queue-cli.md`) und baut auf diesem Resolver auf.

## 4. Verifikation (später)

- Test: `updateBillingDetails` im SRP-Kontext speichert unter `srp_*`; Roundtrip über
  `getBillingDetails` liefert die SRP-Werte.
- Test: `srp_base_price` wird unter `srp_base_price` (nicht `srp_srp_base_price`) persistiert.
- Test: SRP-Wasserzeichen-Opacity ist unabhängig von B2B speicher-/lesbar.
- Regression: B2B-Settings bleiben ungeprefixt und unverändert.
