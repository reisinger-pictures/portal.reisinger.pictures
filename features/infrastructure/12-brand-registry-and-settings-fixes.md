# Brand-Registry, Brand-ENUM & Settings-Reparaturen — Konzept (SOLL)

> **Status:** Beschreibt den Soll-Zustand (Ziel).
> Verknüpft: `features/infrastructure/07-lightroom-multi-tenant-gap.md`,
> `features/infrastructure/08-tenant-brand-concept.md`,
> `features/infrastructure/09-brand-context-queue-cli.md`,
> `features/infrastructure/11-brand-settings-separation.md`,
> `features/infrastructure/25-brand-separation-matrix.md`.

## 1. Kontext

Das Portal betreibt zwei White-Label-Brands:

- **B2B** (`rp`): `reisinger.pictures` — vollständiges Admin-/Mandanten-/CRM-/Invoicing-Portal.
- **SRP** (`srp`): `buy.reisinger.pictures` — reduziertes B2C-Kunden-Portal.

Bisher wird die Brand als **freier String** (`'reisinger.pictures'` / `'story.reisinger.pictures'`) behandelt,
und die Host→Brand-Map ist **doppelt** gepflegt (Backend `BrandContextMiddleware` und Frontend
`useBrand.ts`). Zudem ist `Brand` auf DB-Ebene **nicht** als echtes Unterscheidungsmerkmal
modelliert — nur `orders.brand`/`invoice_snapshots.brand` (V018, VARCHAR) existieren. Eine
User-/Fotografen-Trennung pro Brand fehlt, sodass Brand-Scoping in `getAllowedGalleryIds()` nicht
greift (T-09 P3).

Ziel: **Brand als ENUM-Primärkonzept** mit zentraler `BrandRegistry` und korrigierten
Settings-/PDF-Pfaden.

## 2. Soll-Zustand

### 2.1 Brand als ENUM `rp | srp` (`null` = cross-brand)

- **ENUM** `App\Enums\Brand` mit Werten `'rp'` (B2B) und `'srp'` (SRP); `null` bedeutet
  bewusst cross-brand (Super-Admin).
- `Brand`-Methoden: `id()` (enum value, self-documenting), `prefix()` (`'srp_'` | `''`).
- `BrandRegistry`-Methoden (Brand-Kontext-Auflösung): `frontendUrl()`, `resolveFromContract()`, `reset()`, u. a. (siehe §2.3).

### 2.2 `brand`-Spalte als ENUM-Fremdschlüssel auf 6 Tabellen

`users`, `galleries`, `gallery_groups`, `tenants` (neu) sowie `orders`, `invoice_snapshots`
(Konsolidierung von V018 VARCHAR → ENUM). Alle nullable, Default `null`; Backfill setzt
vorhandene Bestandsdaten auf `'rp'`. Index je Spalte für Brand-Scoping-Queries.

### 2.3 Zentrale `BrandRegistry`

- **Backend** `App\Support\BrandRegistry`: `fromHost()`, `current()`, `currentOrDefault()`,
  `isSrp()`, `prefix()`, `set()`, `resolveFromOrder()`, `resolveFromContract()`, `frontendUrl()`, `reset()`.
  Einzige Autorität für Host→Brand und Frontend-URL-Auflösung.
- **Frontend** `frontend/src/logic/brandRegistry.ts`: typsichere Konstanten + reine Funktionen
  `getBrandFromHostname()`, `isSrpBrand()`, `brandPrefix()`. `useBrand.ts` delegiert dorthin
  (Asset-/Portal-Logik bleibt UI-spezifisch in `useBrand`).

### 2.4 Brand-Scoping in `getAllowedGalleryIds()` (T-09 P3)

Brand-gebundene User (`users.brand != null`) sehen nur Galerien ihrer Brand. Super-Admin
(`brand = null`) bleibt cross-brand. Fotografen entsprechend ihrem `brand`.

### 2.5 `AuthController::me()` exponiert Brand

Antwortet `brand` und `is_cross_brand` (`brand === null`), damit das Frontend nicht nur auf den
Hostname vertrauen muss.

### 2.6 Settings-/PDF-Pfade repariert

- `downloadInvoice`: `SettingResolver` statt `$get` + `BrandRegistry::resolveFromOrder()`
  vor `loadView`.
- `BackfillBrand`: hardcodiert `Brand::B2B` (CLI-safe).
- `InvoiceMail::build()`, `InvoiceService`, `CheckoutService`, `ManualInvoiceService`: nutzen
  `BrandRegistry` statt `config('app.brand')`.

### 2.7 Bankdaten-Speichern (Frontend)

- Eigenes Interface `BillingDetails` (statt `LicenseTerms`-Recycle).
- `BillingDetailsCard` (extrahiert) mit **react-hook-form + zod** + **explizitem Save-Button**;
  Save-Button für Nicht-`super_admin` deaktiviert. Kein Per-Keystroke-PUT, kein Race.

### 2.8 Lokale Brand-Unterscheidung via Proxy (relative URLs)

- Prod: domain-basiert (`portal.reisinger.pictures` vs `buy.reisinger.pictures`).
- Lokal: **zwei Vite-Instanzen** — Port 4321 = B2B (`portal.test`), Port 4322 = SRP
  (`portal-srp.test`), jeweils eigenes Proxy-Target. Frontend-URLs bleiben **relativ**
  (`/api/...`); das Proxy-Target entscheidet, welchen Host das Backend sieht, sodass
  `BrandRegistry::fromHost()` korrekt greift. Keine IntelliJ-Run-Configs für Brand nötig.

### 2.9 UI-Umbenennung & Layout

"Kategorien (Grundhonorare)" → "Grundhonorare". Schönere Empty-States, responsive Add-Row,
sticky Tabellen-Header. Statische Tailwind-Klassen (AGENTS.md-konform).

## 3. Abgrenzung

- Diese Spec behandelt **Brand-Modellierung + zentrale Registry + die genannten
  Settings-/PDF-/Bank-Reparaturen + lokales Proxy-Setup**.
- **Kein** Backend-Policy-basiertes Brand-Gating zusätzlich zu `getAllowedGalleryIds()`
  (Folge-Aufgabe). `useBrandAccess`/`BrandGuard` (Frontend) bleiben, wie sie sind.
- **Keine** Änderung am `settings`-Datenmodell selbst (key/value + `SettingResolver`-Präfix
  bleiben, siehe `11-brand-settings-separation.md`).
