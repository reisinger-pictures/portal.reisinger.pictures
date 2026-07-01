# Brand-Registry, Brand-ENUM & Settings-Reparaturen — Konzept (SOLL/Ist-Stand)

> **Status:** Beschreibt den **Ist-Stand** (Probleme) und den **Soll-Zustand** (Ziel), keine
> Implementierungsschritte. Verknüpft: `AGENTS.todo.md` T-09 (P2/P3), B-01, B-02,
> `features/infrastructure/07-lightroom-multi-tenant-gap.md`,
> `features/infrastructure/08-tenant-brand-concept.md`,
> `features/infrastructure/09-brand-context-queue-cli.md`,
> `features/infrastructure/10-frontend-brand-tenant-isolation.md`,
> `features/infrastructure/11-brand-settings-separation.md`.
> Erstellt 2026-06-29.

## 1. Kontext

Das Portal betreibt zwei White-Label-Brands:

- **B2B** (`rp`): `reisinger.pictures` — vollständiges Admin-/Mandanten-/CRM-/Invoicing-Portal.
- **SRP** (`srp`): `story.reisinger.pictures` — reduziertes B2C-Kunden-Portal.

Bisher wird die Brand als **freier String** (`'reisinger.pictures'` / `'story.reisinger.pictures'`) behandelt,
und die Host→Brand-Map ist **doppelt** gepflegt (Backend `BrandContextMiddleware` und Frontend
`useBrand.ts`). Zudem ist `Brand` auf DB-Ebene **nicht** als echtes Unterscheidungsmerkmal
modelliert — nur `orders.brand`/`invoice_snapshots.brand` (V018, VARCHAR) existieren. Eine
User-/Fotografen-Trennung pro Brand fehlt, sodass Brand-Scoping in `getAllowedGalleryIds()` nicht
greift (T-09 P3).

Ziel: **Brand als ENUM-Primärkonzept** mit zentraler `BrandRegistry` und korrigierten
Settings-/PDF-Pfaden.

## 2. Ist-Stand — Die Probleme

- **Brand kein DB-Primärkonzept:** `users`, `galleries`, `gallery_groups`, `tenants` haben keine `brand`-Spalte → `getAllowedGalleryIds()` kann nicht brand-gebunden filtern. `orders.brand`/`invoice_snapshots.brand` sind VARCHAR ohne DB-Wertegarantie.
- **Duplizierte Brand-Map:** Host→Brand-Map in `BrandContextMiddleware.php` und `useBrand.ts` — zwei Quellen, die auseinanderlaufen können.
- **`$get`-Bug in `downloadInvoice`:** `SettingResolver`-Migration entfernte das `$get`-Lambda ohne Ersatz → 500er auf jedem Rechnungs-Download.
- **`downloadInvoice` ignoriert persistierte Brand:** Rendert PDF aus `config('app.brand')` statt `$order->brand`.
- **`BackfillBrand` CLI-sabotierend:** Liest `config('app.brand')` (im CLI-Kontext leer) → schreibt `null`.
- **Bankdaten-Speichern:** Kein eigenes Interface (`LicenseTerms` recycelt), Per-Keystroke-PUT mit Races, Endpunkt `super_admin`-only aber Form für alle sichtbar.
- **UI-Layout:** Header "Kategorien (Grundhonorare)" statt "Grundhonorare"; leere States, Mobile-Layout, Tabellen-Header.

## 3. Soll-Zustand

### 3.1 Brand als ENUM `rp | srp` (`null` = cross-brand)

- **ENUM** `App\Enums\Brand` mit Werten `'rp'` (B2B) und `'srp'` (SRP); `null` bedeutet
  bewusst cross-brand (Super-Admin).
- `Brand`-Methoden: `label()`, `domain()` (prod), `prefix()` (`'srp_'` | `''`).

### 3.2 `brand`-Spalte als ENUM-Fremdschlüssel auf 6 Tabellen

`users`, `galleries`, `gallery_groups`, `tenants` (neu) sowie `orders`, `invoice_snapshots`
(Konsolidierung von V018 VARCHAR → ENUM). Alle nullable, Default `null`; Backfill setzt
vorhandene Bestandsdaten auf `'rp'`. Index je Spalte für Brand-Scoping-Queries.

### 3.3 Zentrale `BrandRegistry`

- **Backend** `App\Support\BrandRegistry`: `fromHost()`, `current()`, `currentOrDefault()`,
  `isSrp()`, `prefix()`, `set()`, `resolveFromOrder()`. Einzige Autorität für Host→Brand und
  `config('app.brand')`-Zugriff. Alle inline-Checks (`=== 'story.reisinger.pictures'`, `$pfx = …`) werden
  darauf umgestellt.
- **Frontend** `frontend/src/logic/brandRegistry.ts`: typsichere Konstanten + reine Funktionen
  `getBrandFromHostname()`, `isSrpBrand()`, `brandPrefix()`. `useBrand.ts` delegiert dorthin
  (Asset-/Portal-Logik bleibt UI-spezifisch in `useBrand`).

### 3.4 Brand-Scoping in `getAllowedGalleryIds()` (T-09 P3)

Brand-gebundene User (`users.brand != null`) sehen nur Galerien ihrer Brand. Super-Admin
(`brand = null`) bleibt cross-brand. Fotografen entsprechend ihrem `brand`.

### 3.5 `AuthController::me()` exponiert Brand

Antwortet `brand` und `is_cross_brand` (`brand === null`), damit das Frontend nicht nur auf den
Hostname vertrauen muss.

### 3.6 Settings-/PDF-Pfade repariert

- `downloadInvoice`: `SettingResolver` statt `$get` + `BrandRegistry::resolveFromOrder()`
  vor `loadView`.
- `BackfillBrand`: hardcodiert `Brand::B2B` (CLI-safe).
- `InvoiceMail::build()`, `InvoiceService`, `CheckoutService`, `ManualInvoiceService`: nutzen
  `BrandRegistry` statt `config('app.brand')`.

### 3.7 Bankdaten-Speichern (Frontend)

- Eigenes Interface `BillingDetails` (statt `LicenseTerms`-Recycle).
- `BillingDetailsCard` (extrahiert) mit **react-hook-form + zod** + **explizitem Save-Button**;
  Save-Button für Nicht-`super_admin` deaktiviert. Kein Per-Keystroke-PUT, kein Race.

### 3.8 Lokale Brand-Unterscheidung via Proxy (relative URLs)

- Prod: domain-basiert (`portal.reisinger.pictures` vs `portal.story.reisinger.pictures`).
- Lokal: **zwei Vite-Instanzen** — Port 4321 = B2B (`portal.test`), Port 4322 = SRP
  (`portal-srp.test`), jeweils eigenes Proxy-Target. Frontend-URLs bleiben **relativ**
  (`/api/...`); das Proxy-Target entscheidet, welchen Host das Backend sieht, sodass
  `BrandRegistry::fromHost()` korrekt greift. Keine IntelliJ-Run-Configs für Brand nötig.

### 3.9 UI-Umbenennung & Layout

"Kategorien (Grundhonorare)" → "Grundhonorare". Schönere Empty-States, responsive Add-Row,
sticky Tabellen-Header. Statische Tailwind-Klassen (AGENTS.md-konform).

## 4. Abgrenzung

- Diese Spec behandelt **Brand-Modellierung + zentrale Registry + die genannten
  Settings-/PDF-/Bank-Reparaturen + lokales Proxy-Setup**.
- **Kein** Backend-Policy-basiertes Brand-Gating zusätzlich zu `getAllowedGalleryIds()`
  (Folge-Aufgabe). `useBrandAccess`/`BrandGuard` (Frontend) bleiben, wie sie sind.
- **Keine** Änderung am `settings`-Datenmodell selbst (key/value + `SettingResolver`-Präfix
  bleiben, siehe `11-brand-settings-separation.md`).

## 5. Verifikation

- `BrandRegistryTest`: `fromHost` für prod/dev/leer, `current(OrDefault)`, `isSrp`, `prefix`,
  `resolveFromOrder` mit/ohne persistierte Brand.
- `BrandLeakTest` erweitert: SRP-Order über B2B-Host geladen → SRP-Branding im PDF (F2);
  Inversfall B2B; `downloadInvoice` wirft keinen 500er mehr (`$get`-Regression).
- `BackfillBrandTest`: CLI-Kontext, `config('app.brand')=null`, Backfill setzt `'rp'`.
- Brand-Scoping-Test: SRP-User sieht nur SRP-Galerien; Super-Admin (`brand=null`) sieht alle.
- Vitest `brandRegistry.test.ts`: `getBrandFromHostname` für alle Hosts, `isSrpBrand`,
  `brandPrefix`.
- Playwright (`ai_test_runner.mjs`, input `billing-details-save`): Bankdaten eingeben →
  Speichern → Reload → persistiert (kein Race).
