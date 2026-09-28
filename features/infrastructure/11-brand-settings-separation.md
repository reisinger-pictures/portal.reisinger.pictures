# Markenspezifische Settings-Trennung — Konzept (SOLL)

> **⚠️ Status:** `superseded` (korrigiert 2026-09-28). Das Dokument beschreibt ein
> `srp_`-Präfix-Verfahren für eine zweite Marke; SRP wurde am 2026-07-14 entfernt
> (siehe `AGENTS.md` §3). Der aktuelle Vertrag steht in
> [`20-setting-resolver.md`](20-setting-resolver.md) (brand-scoped `SettingResolver`
> auf `(key, brand)`), [`21-brand-config-driven.md`](21-brand-config-driven.md) und
> [`22-brand-settings-overlay.md`](22-brand-settings-overlay.md).
> Verknüpft: `features/infrastructure/06-multi-domain-branding.md`, `features/infrastructure/08-org-brand-concept.md`.

## 1. Kontext

Markenspezifische Settings (Bankdaten, Firmenadresse, Wasserzeichen-Opacity, Lizenz-/Preisfaktoren)
werden über ein **String-Präfix** im `settings.key` getrennt:
- **B2B** (`reisinger.pictures`) = kein Präfix, z. B. `bank_iban`.
- **SRP** (`story.reisinger.pictures`) = `srp_`-Präfix, z. B. `srp_bank_iban`.

Das `settings`-Model (`backend/app/Models/Setting.php`) ist ein key/value-Store **mit**
`brand`-Spalte und zusammengesetztem Primärschlüssel `(key, brand)`
(`:24` → `protected $fillable = ['key', 'value', 'brand'];`,
`:26` → `protected $casts = ['brand' => AsBrand::class];`). Die frühere Annahme
„ohne Brand-Feld“ ist damit überholt. Der brand-scoped Zugriff läuft zentral über den
`SettingResolver` (`20-setting-resolver.md`), nicht mehr über das hier beschriebene
verteilte `$pfx = config('app.brand') === 'story.reisinger.pictures' ? 'srp_' : ''`-Muster —
`config('app.brand')` existiert nicht mehr und `story.reisinger.pictures` kommt im
Code nicht vor (`grep -rn "config('app.brand')" backend/app` → nur ein Kommentar in
`BackfillBrand.php`).

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
