# Markenspezifische Settings-Trennung — Konzept (SOLL/Ist-Stand)

> **Status:** Beschreibt den **Ist-Stand** (Probleme) und den **Soll-Zustand** (Ziel), keine
> Implementierungsschritte. Verknüpft: `AGENTS.todo.md` T-04,
> `features/infrastructure/06-multi-domain-branding.md`, `features/infrastructure/08-tenant-brand-concept.md`.
> Erstellt 2026-06-29.

## 1. Kontext

Markenspezifische Settings (Bankdaten, Firmenadresse, Wasserzeichen-Opacity, Lizenz-/Preisfaktoren)
werden über ein **String-Präfix** im `settings.key` getrennt:
- **B2B** (`reisinger.pictures`) = kein Präfix, z. B. `bank_iban`.
- **ATR** (`all-the.rest`) = `atr_`-Präfix, z. B. `atr_bank_iban`.

Das `settings`-Model (`backend/app/Models/Setting.php`) ist ein plain key/value-Store **ohne**
Brand-Feld, Scope oder typisierten Accessor. Die Trennung passiert verstreut in Consumern über das
verteilte Muster `$pfx = config('app.brand') === 'all-the.rest' ? 'atr_' : ''`.

## 2. Ist-Stand — Die Probleme

Drei konkrete Defekte in `backend/app/Http/Controllers/SettingsController.php`, bei denen
Lesen und Schreiben der markenspezifischen Settings **asymmetrisch** sind:

### 2.1 (a) `updateBillingDetails()` — ATR-Bankdaten unbrauchbar

- **Lesen** (`getBillingDetails()`, `:146-153`): fragt für ATR `atr_bank_iban` etc. ab (mit Fallback
  auf ungeprefixten B2B-Key).
- **Schreiben** (`updateBillingDetails()`, `:210-213`): speichert **ungeprefixt** (`Setting::updateOrCreate(['key' => $key], …)`),
  unabhängig vom Brand.
- **Folge:** Im ATR-Kontext werden die Bankdaten **nie** unter `atr_*` gespeichert. `getBillingDetails()`
  findet den ATR-Key nicht → fällt auf B2B-Werte zurück. ATR-spezifische Bankdaten sind über die
  API **nicht persistierbar**; in T-02 (Queue-Leck) führt das zusätzlich zu falschen Bankdaten im PDF.

### 2.2 (b) `updateLicenseTerms()` — `atr_atr_*`-Dopplung

- `getLicenseTerms()` (`:131-134`) liefert bereits brandexplizite Keys wie `atr_base_price`.
- `updateLicenseTerms()` (`:185-188`): `$pfx . $key` mit `$pfx='atr_'` + bereits praefixiertem
  Key `'atr_base_price'` ergibt **`atr_atr_base_price`** — ein Schlüssel, der nie wieder gelesen wird.
- **Folge:** ATR-Preisfaktoren werden im ATR-Kontext in einen „Datenmüll"-Key geschrieben und gehen
  bei erneuter Abfrage verloren.

### 2.3 (c) `updateWatermark()` — Opacity asymmetrisch

- **Lesen** (`getWatermark()`, `:58`): fragt `$pfx . 'watermark_opacity'` ab (mit Fallback).
- **Schreiben** (`updateWatermark()`, `:75`): speichert hart `watermark_opacity` **ungeprefixt**.
- **Folge:** Es existiert nur ein globaler Opacity-Wert für beide Marken; die brandpräfixierte
  Abfrage beim Lesen findet nie einen ATR-Wert und fällt stets auf denselben B2B-Wert zurück.
  Symmetrie zwischen Lesen und Schreiben ist gebrochen.

### 2.4 (übergreifend) Dupliziertes `$get()`-Lambda

Das Hilfsmuster `$get = fn($k) => Setting::where('key', $pfx . $k)->value('value') ?? Setting::where('key', $k)->value('value');`
ist in mindestens 5 Dateien dupliziert (`OrderController`, `InvoiceMail`, `ManualInvoiceService`,
`header.blade.php`, `SettingsController`). Jede Änderung der Präfix-Logik muss an allen Stellen
konsistent nachgezogen werden — hohes Inkonsistenz-Risiko.

## 3. Soll-Zustand

### 3.1 Symmetrie Lesen ↔ Schreiben

Jede markenspezifische Setting-Operation muss **dieselbe Präfix-Regel** beim Lesen und Schreiben
anwenden:
- B2B-Kontext: ungeprefixter Key.
- ATR-Kontext: `atr_`-Präfix beim Lesen **und** Schreiben.

Konkret:
- `updateBillingDetails()`: Keys brandabhängig prefixen.
- `updateLicenseTerms()`: bereits brandexplizite Keys (`atr_*`) **nicht** erneut prefixen.
- `updateWatermark()`: `watermark_opacity` brandabhängig prefixen.

### 3.2 Zentraler Resolver

Statt des verteilten `$get()`/`$pfx`-Musters ein zentraler Resolver (z. B.
`App\Services\BrandSettings\SettingResolver`) mit:
- `get(string $key, ?string $brand = null): ?string` — preäfixierte Abfrage + Fallback auf B2B.
- `set(string $key, string $value, ?string $brand = null): void` — markenspezifisch speichern.
- `prefixFor(string $brand): string` — liefert `''` (B2B) bzw. `'atr_'` (ATR).
- `isAlreadyBrandPrefixed(string $key): bool` — verhindert `atr_atr_*`-Dopplung.

Dieser Resolver konsolidiert die Präfix-Logik an einer Stelle und wird von allen Consumern
(Controller, Mail, Blade, Services) verwendet.

## 4. Abgrenzung

- Diese Spec behandelt ausschließlich die **Konsistenz der Settings-Trennung** (Lesen/Schreiben
  symmetrisieren + zentralisieren).
- **Keine** Änderung am Setting-Datenmodell selbst (plain key/value bleibt); eine `brand`-Spalte
  auf `settings` ist nicht Teil dieses Konzepts.
- Die ATR-Bankdaten-Verfügbarkeit im **Queue-/CLI-Pfad** (PDF-Rendering) ist Thema von T-02
  (`09-brand-context-queue-cli.md`) und baut auf diesem Resolver auf.

## 5. Verifikation (später)

- Test: `updateBillingDetails` im ATR-Kontext speichert unter `atr_*`; Roundtrip über
  `getBillingDetails` liefert die ATR-Werte.
- Test: `atr_base_price` wird unter `atr_base_price` (nicht `atr_atr_base_price`) persistiert.
- Test: ATR-Wasserzeichen-Opacity ist unabhängig von B2B speicher-/lesbar.
- Regression: B2B-Settings bleiben ungeprefixt und unverändert.
