# Task & Bugfix Backlog

> **Regel:** `features/` enthält NUR den Soll-Zustand (Spezifikation).
> Umsetzungspläne, Tasks, Bugfixes gehören ausschließlich hierher.
> Getroffene Entscheidungen werden in `features/` persistiert.
>
> **Konventionen:** Code & Docs englisch, UI deutsch.
> Backend-Tests via `php artisan test`, Frontend via `vitest run` + `build` + `lint:fix`,
> E2E via `node ai_test_runner.mjs`.
>
> **Stand: 2026-06-30 (bereinigt).** 23 verifiziert-abgeschlossene Tasks entfernt
> (siehe "Resolviert"-Block unten). Verbleiben: 17 offene Tasks, alle mit umsetzbaren Checklisten.

---

## Übersicht — Offene Tasks

| Prio | ID | Beschreibung |
|------|-----|--------------|
| 🟢 P0 | INF-01 | MariaDB/DB-Verbindung auf Dev (Root Cause aller 40+ E2E-Fails) |
| ✅ | T-14 | ✅ Mandant ATR → SRP umbenennen — umgesetzt & verifiziert (2026-06-30) |
| ✅ | T-15 | ✅ V018 in-place: ENUM('rp','srp') — umgesetzt & verifiziert (2026-06-30) |
| ✅ | T-16 | ✅ V019: brand-Spalten auf Katalog/CRM-Tabellen — umgesetzt & verifiziert (2026-06-30, Roundtrip ok) |
| 🔴 P1 | T-17 | Backend brand-scoping: Product/LicenseCatalog/Settings + SRP-Volumen-Preismodell |
| 🔴 P1 | T-18 | ⛔ SRP-Seeder-Dataset (Blocker: User-Input) |
| 🔴 P1 | U-01 | Streng mandantengetrennte User — nur florian@reisinger.pictures cross-brand |
| 🔴 P1 | U-02 | Policy-A umkehren: Staff wird brand-bound (nicht mehr cross-brand) |
| 🔴 P1 | U-03 | CRM-Isolation: Customer & Text-Snippets brand-scoping |
| 🔴 P1 | AT-01 | ⛔ Klärung: Bild-Analyse (Backend-Vision) behalten ODER entfernen? |
| 🔴 P1 | AT-02 | Status AI-05/AI-06 korrigieren (Code noch aktiv, fälschlich ✅ markiert) |
| 🔴 P1 | AT-03 | E2E für KI-Bildbeschreibung fehlt (nur Button-Sichtbarkeit getestet) |
| 🔴 P1 | D-01 | Full Test-Suite vor Deployment |
| 🔴 P1 | M-01 | 🆕 Migrationen V18+V19+V020 → konsolidiertes V18 (V17 = letzte in Prod) |
| 🔴 P1 | K-01 | 🆕 Paket-Kalkulator: "Custom Shooting Paket |"-Prefix entfernen + leere Zeilen überschreiben + am Anfang einfügen |
| 🔴 P1 | J-01 | 🆕 Maschinell auslesbare Angebote: EOF-PDF-Marker + QuoteLink → JWT (exp = Angebots-Gültigkeit) |
| 🟡 P2 | T-19 | Frontend-Brand-UI + Lightroom-Plugin + E2E an SRP anpassen |
| 🔴 P1 | T-20 | 🆕 SRP-Volumen-Preismodell (30/25/20€-Staffel, retroaktiv) — Pricing/Checkout/Cart/UI |
| 🟡 P2 | AT-04 | Backend-Vision-Tests an Realität anpassen (je nach AT-01) |
| 🟡 P2 | AT-05 | Frontend useAI + LM-Studio-Lokal-Modus vitest |
| 🟡 P2 | A-08 | BrandRegistry State-Resetter bei Queue-Workern prüfen |
| 🟡 P2 | F-11 | Props-Validierung CartItemList + StripeCheckoutForm (TS-Typen) |
| 🟡 P2 | R-03 | Performance-Regression-Check Slider (Profiler DevTools) |
| 🟡 P2 | B-13 | Rollen-Abweisung Integrationstest (403 Forbidden) |
| 🟡 P2 | B-14 | Migration V018 Rollback testen |
| 🟡 P2 | B-15 | Datenkonsistenz Audit-Snapshots (PhotoMetadataVersion) |
| 🟡 P2 | T-09 | Lightroom Plugin Multi-Brand (Restpunkt: E2E) |
| 🟡 P2 | FT-01 | FTP-Upload: Brand-Isolation & Defense-in-Depth |
| 🟡 P2 | T-11-ext | Frontend-Komponententests ausbauen (~90–110 Tests) |
| 🟡 P2 | T-13 | Backend-Test-Lücken schließen |

> **Verbindliche Entscheidungen (2026-06-30, User)** — siehe Abschnitte E-01 bis E-03 unten:
> Login-Abweisung bei Brand-Mismatch · Photographen-Doppelrolle = getrennte Accounts ·
> Historische Rechnungen alle `'rp'` · ATR wird zu SRP · Volle Paket-/Lizenz-/Settings-Trennung.

---

# 🧪 E2E-Infrastruktur

## INF-01 · 🟢 P0 · MariaDB-Verbindungsfehler auf Dev (Root Cause aller E2E-Fails) 🆕

> **Analyse (2026-06-30):** `frontend/ai_test_report.txt` (705 KB) — **40+ identische Fehler**:
> `Admin login failed → SQLSTATE[HY000] [2002]` (MariaDB `127.0.0.1:3306` verweigert Verbindung).
> 80 "Admin login failed"-Vorkommen, **0 echte Assertions-Fehler**. Playwright bricht nach
> 10. Fehler-Cap ab. Reiner **Infrastrukturausfall**, kein Code-Defekt. Blockiert alle E2E-Verifikationen.

**Checkliste:**
- [ ] MariaDB/Herd-DB-Dienst starten (Herd-Tray oder `mariadbd`)
- [ ] Verbindung verifizieren: `mysql -u root -h 127.0.0.1 -P 3306 portal_db`
- [ ] Backend gesund: `curl https://portal.test/api/health` → 200
- [ ] E2E neu laufen lassen: `node ai_test_runner.mjs brand` (sollte jetzt echte Assertions erreichen)
- [ ] Neuen `ai_test_report.txt` analysieren — ggf. jetzt sichtbare Code-Defekte separat triagieren
- [ ] Optional: Pre-Flight-DB-Check in `ai_test_runner.mjs` ergänzen (frühzeitiger klarer Abbruch)

---

# 🔄 Brand-Migration: ATR → SRP + Per-Brand-Pakete

> **Entscheidung (2026-06-30, User):** Mandant `all-the.rest` (ATR) wird zu
> `story.reisinger.pictures` (SRP). Mandanten heißen intern `rp` und `srp`.
> V018 ist **nicht in Produktion** → wird in-place geändert. Volle Trennung der kaufbaren
> Pakete/Lizenzen/Settings. **Blocker:** SRP-Paketliste (T-18) vom User ausstehend.

## T-14 · 🔴 P1 · Mandant ATR → SRP umbenennen (Code-Konfiguration) 🆕

**Soll:** `Brand::SRP = 'srp'`, Label/Domain `'story.reisinger.pictures'`, Prefix `'srp_'`.
Hosts: `portal-srp.test` (lokal) / `story.reisinger.pictures` (prod). `.env.srp` (Port 4322).

**Checkliste:**
- [ ] `backend/app/Enums/Brand.php` — `ATR='atr'` → `SRP='srp'`; `label()`/`domain()`/`prefix()` anpassen
- [ ] `backend/app/Support/BrandRegistry.php` — `ATR_DEV_HOST='portal-srp.test'`; `fromHost()` prüft `story.reisinger.pictures`; `isAtr()` → `isSrp()` (alle Konsumenten umbenennen)
- [ ] `backend/app/Http/Middleware/BrandContextMiddleware.php` — Referenzen aktualisieren
- [ ] Backend-Services/Controller mit `'atr'`/`'atr_'`/`Brand::ATR`: `AuthController`, `SettingResolver`, `CheckoutService`, `PricingService`, `FileDeliveryController`, `LicenseCatalogController`, `SettingsController`, `UserController`, `UpdateUserRequest`, `BackfillBrand`, `AppServiceProvider`, Models (`Photo`, `User`, `LicenseModifier`, `LicenseUseCase`, `UserResource`)
- [ ] `backend/database/seeders/DatabaseSeeder.php` — SRP-Tenant: `domain='story.reisinger.pictures'`, `name='story.reisinger.pictures'`, `brand=Brand::SRP`
- [ ] `frontend/src/logic/brandRegistry.ts` — `BRAND_SRP`, `SRP_DEV_HOST='portal-srp.test'`, `SRP_PROD_DOMAIN='story.reisinger.pictures'`, `getBrandFromHostname()`, `isSrpBrand()`, `brandPrefix('srp_'|'')`
- [ ] `frontend/src/logic/useBrand.ts` — `portalName='story.reisinger.pictures Portal'`, impressumUrl `https://story.reisinger.pictures/impressum/`
- [ ] `frontend/src/index.css` + `frontend/index.html` — `isAtr` → `isSrp`, Domain-Check (`all-the.rest` → `story.reisinger.pictures`)
- [ ] Frontend-Konsumenten: `useLicenseCatalog`, `useLicenseTerms`, `useUsers`, `shootingCalculator`, `api.ts`, `LicenseSelectorCard`, `LicenseSelectorModal`, `DeliveryView`, `CalculatorSettingsCard`, `LicenseSettingsCard`, `ShootingCalculatorModal`, `UserPermissionsModal` (`<option value="srp">`), `ManagementPayoutsView`, `PhotographerPayoutsView`, `ManagementUserView`
- [ ] `frontend/public/brands/atr/` → `frontend/public/brands/srp/` (Icons/Manifest `site.webmanifest`)
- [ ] `frontend/.env.atr` → `frontend/.env.srp`; `package.json` `dev:atr` → `dev:srp`; `frontend/vite.config.ts` Modes
- [ ] Lightroom-Plugin: `admin.lrplugin/AtrDeliveryManager.lua`/`AtrSelectionManager.lua` → `Srp*.lua` (Renames + Base-URLs `https://portal.story.reisinger.pictures` + `PluginInfoProvider.lua` Labels)
- [ ] `.idea`/`.run`-XMLs mit `all_the_rest`/`atr` → `srp`/`story` aktualisieren oder löschen
- [ ] `features/infrastructure/06,07,08,09,10,11,12-*.md` — ATR → SRP persistieren (Doc-as-Code)
- [ ] Lokale Infra: `herd secure portal-srp.test`, Hinweis ins `README.md`
- [ ] Build-Verifikation: `pnpm tsc -b && pnpm lint:fix && pnpm build` ✅, `php artisan test` ✅

## T-15 · 🔴 P1 · V018 in-place: ENUM('rp','atr') → ENUM('rp','srp') 🆕

> V018 nicht in Produktion → direktes Edit zulässig. Backfill ist ohnehin `'rp'`, kein `'atr'`-Content.

**Checkliste:**
- [ ] `backend/database/migrations/V018_add_brand_to_orders_and_invoice_snapshots.php`:
      `ENUM('rp','atr')` → `ENUM('rp','srp')` (im `ALTER TABLE`-Statement + PHPDoc-Kommentar)
- [ ] 6 Tabellen prüfen: `orders`, `invoice_snapshots`, `users`, `galleries`, `gallery_groups`, `tenants`
- [ ] Dev-DB aktualisieren: `php artisan migrate:fresh --seed` ODER `migrate:rollback && migrate`
- [ ] Verifikation: `SHOW COLUMNS FROM orders` → `brand ENUM('rp','srp')`
- [ ] `down()` dropColumn bleibt unberührt (sauber reversibel)

## T-16 · 🔴 P1 · V019: brand-Spalten auf Katalog- & CRM-Tabellen 🆕

**Checkliste:**
- [ ] Neue Datei `backend/database/migrations/V019__add_brand_to_catalog_and_settings.php` (**Doppel-Unterstrich** wie V001–V017)
- [ ] `products`: `brand ENUM('rp','srp') NULL` + Index + Backfill `'rp'`
- [ ] `license_use_cases`: `brand ENUM('rp','srp') NULL` + Index + Backfill `'rp'`
- [ ] `license_modifiers`: `brand ENUM('rp','srp') NULL` + Index + Backfill `'rp'`
- [ ] `settings`: `brand ENUM('rp','srp') NULL` + Index + Backfill `'rp'` (nutzt aktuell Prefix `'atr_'` → siehe T-17)
- [ ] `customers`: `brand ENUM('rp','srp') NULL` + Index + Backfill `'rp'` (CRM-Isolation)
- [ ] `text_snippets`: `brand ENUM('rp','srp') NULL` + Index + Backfill `'rp'` (CRM-Isolation)
- [ ] `down()`: alle 6 Spalten droppen, reversibel
- [ ] Dev-DB: `migrate` ✅ → `migrate:rollback` ✅ → `migrate` ✅

## T-17 · 🔴 P1 · Backend-Services & Controller: Brand-Scoping + Setting-Migration 🆕

**Checkliste:**
- [ ] **Vorab:** Ziel-Zustand in `features/infrastructure/14-per-brand-catalog.md` dokumentieren (Doc-as-Code-Pflicht)
- [ ] `ProductController` (index/store/update/destroy): Queries filtern nach `BrandRegistry::currentOrDefault()`; Store setzt `brand` automatisch
- [ ] `LicenseCatalogController::index`: liefert nur LicenseUseCases/Modifiers der aktuellen Brand; Store setzt `brand`
- [ ] `SettingsController` + `SettingResolver`: Umstellung von Prefix `'atr_'` auf echte `brand`-Spalte; Legacy `'atr_'`-Settings via Data-Migration auf `brand='srp'`; Prefix dann deprecated
- [ ] Models `Product`, `LicenseUseCase`, `LicenseModifier`: `$fillable['brand']`, `$casts['brand'=>Brand::class]`, ggf. Scope `byBrand()`
- [ ] `PricingService`/`CheckoutService`: nur Brand-eigene Entities in Preis/Checkout (Defense-in-Depth, Service-Validierung nicht nur UI)
- [ ] `DatabaseSeeder`: Refactoring in `seedCatalogForBrand(Brand $brand, array $data)`, zweimaliges Seed (rp + srp)
- [ ] Backend-Tests erweitern: `ProductControllerTest`, `LicenseCatalogTest`, `SettingsBrandPrefixTest` (srp-User sieht keine rp-Produkte & umgekehrt)

## T-18 · 🔴 P1 · SRP-Seeder-Dataset 🆕 — ⛔ **Blocker: User-Input**

> T-14 bis T-17 sind mit Platzhalter `srp = Kopie von rp` vorab umsetzbar; finale Daten warten auf Liste.

**Checkliste:**
- [ ] **User-Input teilweise erhalten (2026-06-30):** Volumen-Preismodell geklärt — 30€/Bild, ab 10 → 25€, ab 20 → 20€ (retroaktiv). Siehe **T-20**.
- [ ] **Noch offen:** SRP-Produkte/Pakete (Namen/Beschreibungen/Preise) — ob zusätzlich zum Volumenmodell vorhanden; SRP-Lizenzkatalog (wahrscheinlich **nicht** nötig, da SRP nur Volumenpreis nutzt, siehe T-20); SRP-Settings-Defaults.
- [ ] Volumen-Staffelwerte (30/25/20€, Schwellen 10/20) als `settings` (Keys `srp_price_per_image_tier1/2/3`, `srp_tier_threshold1/2`) seeden — konsumiert von T-20.
- [ ] Daten in `seedCatalogForBrand()`-Aufruf mit srp-Array strukturieren
- [ ] `migrate:fresh --seed` validieren, manuelle Stichprobe im Admin-UI
- [ ] PHPUnit/Feature-Test: srp-Katalog/Settings enthalten die gelieferten Werte

## T-19 · 🟡 P2 · Frontend-Brand-UI + Lightroom-Plugin + E2E an SRP anpassen 🆕

**Checkliste:**
- [ ] Frontend: korrekte SRP-Branding (Portalname, Logo, Manifest, CSS-Branches)
- [ ] `UserPermissionsModal`: `<option value="srp">SRP (story.reisinger.pictures)</option>`
- [ ] Lightroom-Plugin: PluginInfo zeigt SRP statt ATR, Base-URL korrekt
- [ ] E2E-Tests: `brand-isolation.spec.ts`, `gallery-brand-scoping.spec.ts`, `brand-e2e-infra.fixme.spec.ts` — Mocks `all-the.rest` → `story.reisinger.pictures`; ggf. playwright.config zweiten Vite-Port (`4322`) aktivieren
- [ ] E2E-Verifikation: `node ai_test_runner.mjs brand` (AGENTS.md §3)
- [ ] vitest: `brandRegistry.test.ts`, `shootingCalculator.test.ts` an SRP anpassen
- [ ] PHPUnit: `BrandRegistryTest`, `SettingResolverTest`, `SettingsBrandPrefixTest`, `BrandLeakTest`, `BrandScopingTest` an SRP anpassen

## T-20 · 🔴 P1 · SRP-Volumen-Preismodell (30/25/20€-Staffel, retroaktiv) 🆕

> **Entscheidung (2026-06-30, User):** SRP nutzt ein **eigenes, einfacheres Preismodell** —
> **30 € pro Bild**, ab **10 Bildern 25 € pro Bild** (alle), ab **20 Bildern 20 € pro Bild** (alle),
> **retroaktiv** für alle Bilder im Warenkorb. Keine license_use_cases, keine Modifikatoren,
> kein commercial/editorial. Später evtl. Rabattcodes (B2C) — aber NICHT Teil dieses Tasks.
>
> **Warum das ein neuer Mechanismus ist:** Das rp-System kennt nur **Einzelpreise**
> (`PricingService::calculateItemPriceCents` = 1 Bild, `CheckoutService` summiert, Cart reduziert).
> Es gibt **kein Mengen-/Staffelkonzept** irgendwo. Die retroaktive Staffel braucht therefore
> eine **cart-weite Pre-Pass**: erst alle Bilder zählen → Staffel ermitteln → dann pro Bild präisen.
>
> **IST (recherchiert 2026-06-30):**
> - `backend/app/Services/PricingService.php` — einzige Methode `calculateItemPriceCents(useCaseId, modifierIds, flatrateLevel)`, **rein einzelitem**.
> - `backend/app/Services/CheckoutService.php:26-77` — Preisschleife preist jedes Item isoliert, `$totalNetCents += $itemCents`. **Kein Quer-Item-Kontext.**
> - `frontend/src/logic/cartLogic.ts:35-37` — `calculateTotalAmount` = reines `reduce(items.price)`.
> - `frontend/src/ui/client/components/LicenseSelectorCard.tsx:76-100` — rp-Pfad: preist Einzelitem, friert `price` in Cart-Item ein. **Wird nie re-computed.**
> - `frontend/src/ui/client/components/LicenseSelectorModal.tsx` — zweiter (legacy) Lizenz-Pfad.
> - `CartItem`-Interface (`frontend/src/logic/CartContext.tsx:4-16`) hat **kein `qty`** — 1 Eintrag = 1 Bild (Duplikate ersetzt per photoId).
> - `products`-Tabelle `type` erlaubt bereits `'discount_fixed'`/`'discount_percent'` (nur manuelle Rechnungen, nicht Checkout).

### Architekturentscheidung: Brand-gesteuerte Pricing-Strategie (Strategy-Pattern)

Statt if/else-Brand-Checks über den Code zu streuen, wird eine **PricingStrategy** eingeführt:
- `LicensePricingStrategy` (rp): bestehende `calculateItemPriceCents`-Logik, einzelitem.
- `VolumePricingStrategy` (srp): mengenbasiert, berechnet **alle** Items gemeinsam.
- `BrandRegistry::current()` wählt die Strategie (Service-Provider-Bindung).
- Das hält den Brand-Switch an **genau einer Stelle** (DI) und respektiert Doc-as-Code.

### Checkliste — Backend

- [ ] **Vorab:** Spec `features/infrastructure/16-srp-volume-pricing.md` + `17-pricing-strategy-pattern.md` (Doc-as-Code-Pflicht, AGENTS.md §1)
- [ ] Neues Interface `App\Contracts\PricingStrategy` mit Methode `calculateCart(array $items, User $user): array` (returns `[{itemId, priceCents}, ...]` + `totalCents`)
- [ ] `App\Pricing\LicensePricingStrategy` — kapselt bestehende `calculateItemPriceCents`-Logik (pro Item, kompatibel mit rp). Refactor ohne Verhaltensänderung.
- [ ] `App\Pricing\VolumePricingStrategy` — neu:
  - Input: count der nicht-quote Items.
  - Tier-Lookup (retroaktiv): `<10 → 30€`, `10–19 → 25€`, `≥20 → 20€` pro Bild.
  - Preise: konfigurierbar via `settings` (Keys `srp_price_per_image_tier1/2/3` + `srp_tier_threshold1/2`), NICHT hardcoded — Vorbereitung auf T-18-Daten.
  - Quote-Items → 0 (wie bisher).
- [ ] `App/Providers/AppServiceProvider` (o. dedizierter `PricingServiceProvider`): Bindung `PricingStrategy::class` via `BrandRegistry::current()` → License- vs. Volume-Strategy.
- [ ] `PricingService` wird Dünner Wrapper: delegiert an `PricingStrategy` (oder wird durch Strategy ersetzt — entscheiden). `calculateItemPriceCents` bleibt für rp-Rückkompatibilität erhalten.
- [ ] `CheckoutService::processCheckout` (Zeile 26-77) umbauen: **einmal** `$strategy->calculateCart($items, $user)` statt Item-Loop mit Einzelpreis. Ergebnis: korrekte retroaktive Staffel-Summe → `orders.total_amount`.
- [ ] Neue Config/Settings: SRP-Staffelpreise + Schwellen via `SettingResolver` (Brand-Prefix `srp_` nach T-17-Migration der echten `brand`-Spalte). Vorbereitet, Werte aus T-18.
- [ ] Backend-Tests: `VolumePricingStrategyTest` (Grenzfälle: 9/10/11/19/20/21 Bilder, quotes, leerer Cart, mixed — srp hat keine quotes im einfachen Modell, aber防御iv absichern); `LicensePricingStrategyTest` = Verhaltensgleichheit zur alten Logik; `CheckoutServiceTest` SRP-Pfad.
- [ ] `php artisan test` ✅

### Checkliste — Frontend

- [ ] Neuer Hook `frontend/src/logic/useSrpPricing.ts` (oder Erweiterung `pricingLogic.ts`): `calculateVolumeTier(count)` + `calculateVolumeTotal(items)`. Pure Funktion, testbar.
- [ ] `frontend/src/logic/cartLogic.ts::calculateTotalAmount` **brand-aware**: SRP → `calculateVolumeTotal` (retroaktiv, hängt von `items.length` ab); rp → bestehendes `reduce`.
- [ ] **WICHTIG — Re-Pricing bei Cart-Änderung:** Da SRP-Preis von der **Anzahl** abhängt, muss bei jedem `addToCart`/`removeFromCart` der Preis **aller** Items re-computed werden (nicht eingefroren). Anpassung in `CartProvider.tsx` (oder abgeleiteter State during render — AGENTS.md §2 "Derived State": berechnen statt cachen).
- [ ] Neue SRP-UI-Komponente `frontend/src/ui/client/components/SrpPriceCard.tsx` (o.ä.) — ersetzt `LicenseSelectorCard` für SRP:
  - Keine Use-Case-Radios, keine Modifier-Checkboxen, kein commercial/editorial.
  - Zeigt Einzelpreis (30/25/20€ je nach aktueller Cart-Größe) + Hinweis "ab 10/20 Bildern günstiger".
  - "In den Warenkorb"-Button.
- [ ] Brand-Weiche: `PhotoDetailView.tsx:142` rendert `LicenseSelectorCard` (rp) vs. `SrpPriceCard` (srp) basierend auf `useBrand()`. Analog `LicenseSelectorModal` für SRP ausblenden/ersetzen.
- [ ] `ClientCartView` / `CartItemList`: Zeilenpreis bei SRP dynamisch (re-computed), Badge "Mengenrabatt". Hinweis: "Noch X Bilder bis zum nächsten Rabatt".
- [ ] `CartItem`-Interface: ggf. Feld `isSrpVolumeItem?: boolean` oder Berechnung rein aus `brand` (bevorzugt — keine Daten-Mutation).
- [ ] vitest: `useSrpPricing.test.ts` (Tier-Grenzen), `cartLogic.test.ts` um SRP-Pfad erweitern, `ClientCartView`/`CartItemList` bei Mengenänderung.
- [ ] `pnpm tsc -b && pnpm lint:fix && pnpm build && pnpm vitest run` ✅

### Checkliste — E2E & Integration

- [ ] E2E SRP-Szenario (neuer Spec): lege 1/10/20/21 Bilder in SRP-Cart → Gesamtpreis = 30/250/400/420€. Via `node ai_test_runner.mjs` (AGENTS.md §3).
- [ ] E2E rp-Smoke: rp-Checkout unverändert (Regressionsschutz durch Strategy-Refactor).
- [ ] Stripe: SRP-Checkout erzeugt korrekten `total_amount`, `invoice_snapshots` spiegelt Volumen-Staffel (line-items-JSON zeigt einheitlichen Einzelpreis). Keine Stripe-API-Änderung nötig (Betrag als `amount`).

### Abgrenzung / NICHT Teil von T-20
- Rabattcodes (B2C) — separates späteres Feature; T-20 bereitet nicht vor (außer Config-Sauberkeit).
- T-18 (SRP-Seeder-Dataset): liefert die konkreten Euro-Werte/Schwellen; T-20 liest sie via Settings. T-20 kann mit Platzhalter-Werten (30/25/20, 10/20) vorab umgesetzt werden.

---

# 🔐 User/CRM-Isolation: Strikte Mandanten-Trennung

> **Entscheidung (2026-06-30, User):** `florian@reisinger.pictures` ist der **einzige** cross-brand
> Account. Alle anderen User, CRM-Kunden, Produkte und Dienstleistungen sind pro Brand isoliert.
> **Policy A wird umgekehrt** (Staff brand-bound, nicht mehr cross-brand).

## Entscheidungen (2026-06-30, User) — bindend für U-01 bis U-03

### E-01 · Login-Portal-Bindung: Abweisung bei Brand-Mismatch ✅
- Ein User kann sich **nur am Portal seiner Brand** einloggen. Brand-Mismatch → Abweisung
  (HTTP 403/422, deutsche Meldung "Dieser Account ist für ein anderes Portal registriert.").
- Ausnahme: Super-Admin (`florian@reisinger.pictures`, `brand=null`) darf an beiden Portalen einloggen.
- Implementierung: `AuthController::login()` — nach Passwort-Check Vergleich `$user->brand` vs.
  `BrandRegistry::currentOrDefault()`. E2E: rp-User am srp-Kontext → Abweisung; Super-Admin an beiden → Erfolg.

### E-02 · Photographen-Doppelrolle: Getrennte Accounts ✅ (Architekten-Empfehlung übernommen)
- Ein Photographer (oder jeder Staff-Account) für beide Brands erhält **zwei separate Accounts**.
  Keine Multi-Brand-Zuweisung, keine Sonderbehandlung. Cross-brand nur via Super-Admin.
- Begründung: Konsistenz mit U-02, Einfachheit (`users.brand` bleibt Einzelwert), Auditierbarkeit,
  Defense-in-Depth. Bestehende cross-brand-Staff werden bei Daten-Migration (U-01) auf `'rp'` gesetzt.

### E-03 · Historische Rechnungen/Bestellungen: Alle `'rp'` ✅
- Keine Prod-Rechnungen vorhanden. V018-V019-Backfill auf `'rp'` ist korrekt und ausreichend.
- Keine aufwendige Migration anhand zugehöriger User nötig. Neue SRP-Daten entstehen erst nach Go-Live.

## U-01 · 🔴 P1 · Cross-Brand ausschließlich Super-Admin 🆕

**Checkliste:**
- [ ] **Vorab:** Soll in `features/infrastructure/15-strict-user-brand-isolation.md` dokumentieren (Doc-as-Code)
- [ ] User-Observer/Validierung: `brand=null` nur erlaubt bei Super-Admin-Rolle oder E-Mail `florian@reisinger.pictures`; sonst MUSS Brand gesetzt sein
- [ ] `UpdateUserRequest`/`UserController::update`: `brand` obligatorisch für alle Nicht-Super-Admins (Policy-A-Logik entfernen — siehe U-02)
- [ ] `AuthController::register`: Neue Self-Service-User bekommen automatisch `BrandRegistry::currentOrDefault()` (nie NULL)
- [ ] Daten-Migration bestehender `brand=null`-User (außer Super-Admin) auf `'rp'` (historisch B2B, E-03)
- [ ] `AuthController::login()` (E-01): Brand-Mismatch → Abweisung; Super-Admin → beide Portale
- [ ] Frontend `UserPermissionsModal.tsx`: Brand-Select für Nicht-Super-Admins erzwungen (keine cross-brand-Option); nur Super-Admin darf cross-brand
- [ ] PHPUnit: brand-bound User sieht keine fremdbrandigen Galerien/Kunden; nur Super-Admin cross-brand
- [ ] PHPUnit/E2E: Login-Abweisung rp↔srp; Super-Admin an beiden Kontexten erfolgreich

## U-02 · 🔴 P1 · Policy A umkehren: Staff wird brand-bound 🆕

**Checkliste:**
- [ ] `backend/app/Http/Controllers/UserController.php` (~Zeile 128–139): Logik umkehren — Staff (admin/photographer) wird brand-bound (ausgewählte/Default-Brand), NICHT `null`. Ausnahme: Super-Admin bleibt cross-brand
- [ ] `backend/app/Http/Requests/UpdateUserRequest.php`: Brand für Staff verpflichtend (nicht mehr "nullable wenn Staff"); `in:rp,srp` beibehalten
- [ ] `frontend/src/ui/management/components/UserPermissionsModal.tsx`: Brand-Select für Staff aktiv (nicht disabled). Wechsel zu Super-Admin → `null` (cross-brand); Wechsel von Super-Admin zu Staff → Brand MUSS gewählt werden
- [ ] `AccessControlService::getAllowedGalleryIds()`: `brand=null` bleibt "cross-brand" (korrekt für Super-Admin); brand-bound Staff: bestehende Filterung greift
- [ ] Photographen (E-02): pro Brand separater Account, kein Sonderfall-Code, Hinweis im Admin-UI/Onboarding
- [ ] E2E/PHPUnit: Brand-Scoping-Tests (`BrandLeakTest`, `BrandScopingTest`) auf neue Policy umstellen (Staff brand-bound)

## U-03 · 🔴 P1 · CRM-Isolation: Customer & Text-Snippets brand-scoping 🆕

> **Sicherheitslücke:** `CustomerController::index` liefert aktuell alle Kunden an jeden auth-User.

**Checkliste:**
- [ ] **Abhängig von T-16** (`customers` + `text_snippets` `brand`-Spalte)
- [ ] `CustomerController::index/store/update/destroy`: Queries nach User-Brand filtern; Super-Admin sieht alle; Store setzt `brand` aus `BrandRegistry`
- [ ] `TextSnippetController`: analog Brand-Scoping
- [ ] Models `Customer`, `TextSnippet`: `$fillable['brand']`, ggf. `byBrand()`-Scope
- [ ] `DatabaseSeeder`: Kunden/Snippets pro Brand seeden (Refactoring ähnlich T-17)
- [ ] PHPUnit: srp-User sieht keine rp-Kunden & umgekehrt; Super-Admin sieht beide
- [ ] Frontend CRM-Autocomplete (`manual-documents.spec.ts`): nur Brand-eigene Kunden auftauchend

---

# 🔀 Neue Initiative (2026-06-30): Migrationen + Kalkulator + JWT-Angebote

> **Priorität über T-17-Fixes.** T-17-Test-Fixes sind pausiert.

## M-01 · 🔴 P1 · Migrationen V18+V19+V020 → konsolidiertes V18 🆕

> **Entscheidung (User):** V17 ist die letzte Migration in Produktion. V18, V19, V020 sind
> **nicht in Prod** → History-Rewrite ist sicher. Alle drei werden zu **einem** V18 zusammengefasst.

**IST:** 3 separate Migrationsdateien, die alle dasselbe `brand ENUM('rp','srp')`-Konzept umsetzen:
- `V018_add_brand_to_orders_and_invoice_snapshots.php` (Single-UnderScore!): brand auf 6 Tabellen (`orders`, `invoice_snapshots`, `users`, `galleries`, `gallery_groups`, `tenants`)
- `V019__add_brand_to_catalog_and_settings.php`: brand auf 6 weitere Tabellen (`products`, `license_use_cases`, `license_modifiers`, `settings`, `customers`, `text_snippets`)
- `V020__unique_settings_key_brand.php`: settings PK `key` droppen, composite unique `(key, brand)` + plain key-Index

**Soll:** EIN konsolidiertes `V018__add_brand_multitenancy.php` (**Doppel-Unterstrich**, Normalisierung des Namensschemas), das die Union (12 Tabellen) + settings-PK-Umbau in einer deterministischen Reihenfolge ausführt.

**Checkliste:**
- [ ] Neue Datei `backend/database/migrations/V018__add_brand_multitenancy.php` (Doppel-Unterstrich!)
- [ ] `up()` in dieser Reihenfolge (idempotent, mit `Schema::hasColumn`/`SHOW INDEX`-Guards wie bisher):
      1. **settings PRIMARY KEY auf `key` droppen** (V001 hat `key` als PK; muss VOR brand-Backfill auf settings passieren, damit `(key, brand)`-unique greift)
      2. **`brand ENUM('rp','srp') NULL DEFAULT NULL`** auf allen **12 Tabellen** adden (Union V018+V019)
      3. **Backfill** aller 12 Tabellen `whereNull('brand')->update(['brand'=>'rp'])`
      4. **`{table}_brand_index`** auf allen 12 Tabellen
      5. **`settings_key_index`** (plain Index auf `key`)
      6. **`settings_key_brand_unique`** (composite unique auf `(key, brand)`)
- [ ] `down()`: sauber invers — drop composite unique, drop plain key-Index, drop brand-Indexes, `dropColumn('brand')` auf allen 12 Tabellen. **NICHT** den alten `key`-PRIMARY wiederherstellen (Design-Entscheidung).
- [ ] **Löschen:** `V018_add_brand_to_orders_and_invoice_snapshots.php`, `V019__add_brand_to_catalog_and_settings.php`, `V020__unique_settings_key_brand.php`
- [ ] **Dev-DB Reset:** `php artisan migrate:fresh --seed` (da V18/V19/V020 nicht in Prod, ist ein frischer Rebuild sicher und der korrekte Verificationsweg)
- [ ] Verifikation: `SHOW COLUMNS FROM <table>` für alle 12 Tabellen → `brand enum('rp','srp')`; `SHOW INDEX FROM settings` → composite unique + key-Index, kein PRIMARY mehr
- [ ] `php artisan test` ✅ (T-17-Umbau muss mit konsolidiertem V18 weiterhin grün sein — sobald T-17-Fixes wieder aktiv sind)

## K-01 · 🔴 P1 · Paket-Kalkulator → Invoice-Einfügen (2 Fixes) 🆕

> **User-Anforderung:** Zwei Anpassungen beim Einfügen von Kalkulator-Daten in die manuelle Rechnung/Angebot.

**Fix 1 — "Custom Shooting Paket |"-Prefix entfernen:**
- `frontend/src/ui/management/components/ShootingCalculatorModal.tsx:79` (RP/B2B-Zweig in `handleCalculate`).
- Aktuell: `notes = \`Custom Shooting Paket | ${calcIsOutdoor ? 'Outdoor' : 'Indoor'} | Dauer: ${calcDuration} Minuten | Inkludierte Bilder: ${calcImages} Stück.\`;`
- Neu (Prefix weg): `notes = \`${calcIsOutdoor ? 'Outdoor' : 'Indoor'} | Dauer: ${calcDuration} Minuten | Inkludierte Bilder: ${calcImages} Stück.\`;`
- Die `description` (Zeile 78) ist separat und enthält den Prefix nicht → unangetastet.

**Fix 2 — Leere Zeilen überschreiben + am Anfang einfügen:**
- `frontend/src/logic/useInvoiceDraft.ts:118-123` (`handleAddPackageFromCalculator`).
- Aktuell: `setItems(prev => [...prev, newItem]);` → **appendet ans Ende**, leere Platzhalter-Zeilen bleiben erhalten (User muss sie manuell löschen).
- Neu: leere Leistung/Positionen-Zeilen **entfernen** und Kalkulator-Items **am Anfang** einfügen:
  ```ts
  const isEmptyRow = (i: InvoiceItem) =>
      i.type === 'item' && !i.description.trim() && !i.notes.trim() && i.qty === 1 && i.price === 0;
  setItems(prev => [newItem, ...prev.filter(i => !isEmptyRow(i))]);  // prepend + leere droppen
  ```
- "Leere Zeile"-Definition (`useInvoiceDraft.ts:39,81,154`): `{type:'item', description:'', notes:'', qty:1, price:0}` — Template-Literal, das initial/addItem/loadExtractedData verwendet wird.
- Discounts (`setDiscounts`) bleiben append (separater Block nach Items, Reihenfolge dort unkritisch).

**Checkliste:**
- [ ] `ShootingCalculatorModal.tsx:79` — Prefix entfernen
- [ ] `useInvoiceDraft.ts:118-123` — prepend + leere Zeilen filtern (`isEmptyRow`-Helper)
- [ ] vitest: falls `useInvoiceDraft` getestet, Fall ergänzen; sonst manuelle Verifikation
- [ ] `pnpm tsc -b && pnpm lint:fix && pnpm build` ✅

## J-01 · 🔴 P1 · Maschinell auslesbare Angebote: HMAC → JWT 🆕

> **Entscheidung (User):** Beide Wege (PDF-EOF-Marker `%SMART_DOC%` UND QuoteLink-URL-Token) werden
> auf **signierte JWT** umgestellt. Einheitlicher JWT-Issuer/Verifier ersetzt die **3 HMAC-Kopien**.
> JWT `exp` = **Angebots-Gültigkeit aus dem Formular** (`due_date`/`validity` in `customer_details`).

**IST (recherchiert 2026-06-30):** Drei parallele, hand-gerollte HMAC-SHA256/Base64-Stellen, alle mit `config('app.key')` als Secret:
1. **PDF-EOF-Marker** (`ManualInvoiceService::generateOfferPayload`/`extractOfferFromPdf`): `%SMART_DOC:{payload}.{signature}%` wird nach PDF-EOF appended; beim Upload wieder ausgelesen. `prepareOfferData` enthält **kein** Ablaufdatum.
2. **QuoteLink-Token** (`QuoteLinkService`): URL `?quote_token={payload}.{signature}`, `exp = now()+14 Tage` (hardcoded, nicht aus Formular).
3. **Duplikat in** `OrderController::sendQuote` (inline, statt Service zu nutzen).
- JWT-Infrastruktur vorhanden: `php-open-source-saver/jwt-auth` HS256, `config('jwt.secret')`. Aber: **kein** Helper für beliebige (Nicht-User-Auth-)JWTs existiert bisher.

**Soll:**
- **Ein** `OfferTokenService` (o.ä.) als zentraler JWT-Issuer/Verifier, nutzt `config('jwt.secret')` (nicht `app.key`).
- Claims: Angebots-Payload (`items`, `customer_*`, `terms_html`, etc.) + `exp` (= Angebots-Gültigkeit aus Formular) + `iat` + ggf. `jti`.
- Weg A (PDF): JWT-String wird statt `%SMART_DOC%`-Marker ins PDF eingebettet (gleiches EOF-Append-Prinzip, neuer Marker z.B. `%OFFER_JWT:{token}%`). Extraktion parst JWT, verifiziert Signatur + prüft `exp`.
- Weg B (QuoteLink): `?quote_token={jwt}` in URL; Decode via `OfferTokenService`; `exp` aus Angebot.
- **Ablaufdatum-Quelle:** `customer_details.due_date`/`validity` (bereits im Formular vorhanden) → JWT `exp`. Falls nicht gesetzt → Default-Frist (Fallback).

**Checkliste:**
- [ ] **Vorab:** Spec `features/infrastructure/18-jwt-offer-tokens.md` (Doc-as-Code-Pflicht, AGENTS.md §1)
- [ ] Neuer Service `backend/app/Services/OfferTokenService.php`:
      - `issue(array $offerData, ?Carbon $expiresAt): string` (JWT mit `exp`, `iat`, Payload)
      - `verify(string $jwt): ?array` (Signatur + `exp`-Check, null bei ungültig/expired)
      - Nutzt `config('jwt.secret')`; HS256 (konsistent mit jwt-auth)
      - Abstraktion über PHPOpenSourceSaver\JWTAuth\Factory ODER `Firebase\JWT` (klären: ggf. Composer-Abhängigkeit) — bevorzugt vorhandene `php-open-source-saver/jwt-auth`-Infra ohne neue Dep
- [ ] `ManualInvoiceService::generateOfferPayload` / `extractOfferFromPdf` → delegieren an `OfferTokenService`; neuer Marker `%OFFER_JWT:{token}%`; `prepareOfferData` erweitert um `exp`/Angebots-Gültigkeit
- [ ] `OrderController::generateManualInvoice` (Zeile ~189): Marker-Einbettung auf neuen JWT-Marker umstellen
- [ ] `OrderController::extractOffer`: neue Marker-Extraktion + JWT-Verify; Fehlermeldung bei expired/invalid (deutsch)
- [ ] `QuoteLinkService` → nutzt `OfferTokenService`; `exp` aus Angebot statt hardcoded 14 Tage
- [ ] `OrderController::sendQuote`: Inline-HMAC entfernen, Service nutzen (dedup)
- [ ] **Angebots-Gültigkeit im Formular:** sicherstellen, dass `due_date`/`validity` aus `customer_details` ins JWT `exp` fließt; Frontend-Feld prüfen (`useInvoiceDraft`/`ManagementManualInvoiceView`)
- [ ] Frontend `usePdfExtraction.ts`: Response-Handling unverändert (Payload-Shape gleich), aber ggf. expired-Fehler-Toast
- [ ] Frontend `ClientCartView.tsx` (QuoteLink): expired/invalid → klarer Hinweis statt stiller Fehler
- [ ] PHPUnit: `QuoteLinkTest`, `ManualInvoiceServiceTest` (neuer Test: expired JWT → Abweisung; valides JWT → Decode); E2E `quote-restore.spec.ts`, `quote-cart.spec.ts`
- [ ] `php artisan test` ✅; E2E via `node ai_test_runner.mjs` (AGENTS.md §3)

### Offen / nach Klärung
- [ ] **Backward-Compat:** Alte `%SMART_DOC%`-PDFs (mit HMAC) nach Umstellung nicht mehr lesbar. Akzeptabel? (User-Input) — vermutlich ja, da noch keine Produktion.
- [ ] **QuoteLink expired-UX:** Kunde klickt abgelaufenen Link → klare Meldung "Angebot abgelaufen, kontaktieren Sie …"?

---

> **Aufgabe (2026-06-30, User):** E2E-Tests mit KI-Bildbeschreibung funktionieren nach Umbau nicht.
> **Befund:** E2E testen die Generierung gar nicht (nur Button-Sichtbarkeit); AI-05/AI-06 sind
> fälschlich als ✅ markiert (Backend-Vision-Code + Vision-Tests noch voll aktiv).

## AT-01 · 🔴 P1 · Klärung: Bild-Analyse behalten ODER entfernen? 🆕 — ⛔ **Blocker: User-Entscheidung**

> AI-03 (Bild-Analyse bleibt, ✅ korrekt) und AI-05 (Backend-Vision entfernt, ❌ falsch markiert)
> widersprechen sich. Code hat Vision aktiv. User muss entscheiden.

**Checkliste:**
- [ ] **User-Entscheidung:**
      - **Option A (empfohlen, entspricht AI-03):** Bild-Analyse **bleibt** → AI-05/AI-06 als "nicht relevant" korrigieren; Vision-Code + Tests bleiben
      - **Option B (AI-05 umsetzen):** Bild-Analyse **weg** → `AIController::generateMetadata`, Route `/ai/generate-metadata`, `AIService::generateMetadata` + `loadAndCompressImage` entfernen; Frontend `useAI` Server-Modus text-only
- [ ] Entscheidung in `features/` persistieren (Doc-as-Code)

## AT-02 · 🔴 P1 · Status AI-05/AI-06 korrigieren 🆕

> Unabhängig von AT-01: ✅-Markierungen widersprechen Code-Realität und müssen korrigiert werden.

**Checkliste:**
- [ ] AI-05: "✅ Done" → "❌ Nicht umgesetzt (Code aktiv)" ODER nach AT-01-Entscheidung finaler Status
- [ ] AI-06: "✅ Done" → "❌ Nicht umgesetzt (Vision-Tests vorhanden)" entsprechend AT-01

## AT-03 · 🔴 P1 · E2E für KI-Bildbeschreibung (Generierungs-Flow) erstellen 🆕

> KI-Bildbeschreibung ist E2E zu 0 % abgedeckt. Defekte nach Umbau bleiben unbemerkt.

**Checkliste:**
- [ ] **Vorab:** Spec in `features/ai/03-e2e-generation-flow.md` dokumentieren
- [ ] `AIBatchEditModal`: Klick "KI Generieren" → Route-Mock `/api/ai/generate-metadata` mit JSON `{title, description, keywords, location, detected_city}` → Assertion Felder vorausgefüllt
- [ ] `AIBatchEditModal`: "Alle generieren (leere)" → Progressbar + Reihen-Folge
- [ ] `PhotoDetailView`: Kontext-Eingabe + "KI generieren" → Mock → Vorschau in Feldern
- [ ] Text-Endpoint: `/api/ai/generate-metadata-text` (AIGalleryDefaultsModal) mocken
- [ ] Lokal-Modus (LM Studio): Route-Mock Bild-Base64 an `/v1/chat/completions`
- [ ] Abhängig von AT-01: Option B → nur Text-Flows testen
- [ ] Ausführung: `node ai_test_runner.mjs photographer/ai-batch-edit` (AGENTS.md §3)

## AT-04 · 🟡 P2 · Backend-Vision-Tests an Realität anpassen 🆕 (nach AT-01)

**Checkliste:**
- [ ] **Option A:** Vision-Tests `AIMetadataTest.php` (6) + `AIServiceTest.php` (2) verifizieren/auffrischen (`sample.jpg`, `Http::fake`); AI-06 als "nicht zutreffend" dokumentieren
- [ ] **Option B:** Vision-Tests entfernen; Text-Tests behalten/aufwerten
- [ ] `php artisan test` grün

## AT-05 · 🟡 P2 · Frontend useAI + LM-Studio-Lokal-Modus vitest 🆕

**Checkliste:**
- [ ] Neue Datei `frontend/src/logic/__tests__/useAI.test.ts` — Mocks für `fetch`/SWR
- [ ] `generateMetadata` Server-Modus → POST `/api/ai/generate-metadata`, Response-Mapping prüfen
- [ ] `generateMetadata` Lokal-Modus → POST an LM Studio mit `image_url`, Response-Mapping
- [ ] `generateMetadataFromText` → POST `/api/ai/generate-metadata-text`
- [ ] `isAvailable`/`mode`-Auflösung (Server → Lokal → unavailable), inkl. `localStorage.lmstudio_url`
- [ ] Mock der `getCompressedBase64`-Bildkompression (jsdom-Polyfill/Mock)
- [ ] `pnpm vitest run` grün

---

# 🏛️ Architecture / React / Frontend / Backend (Rest-P2)

## A-08 · 🟡 P2 · BrandRegistry State-Resetter bei Queue-Workern 🆕
> BrandRegistry hält Runtime-Cache. Langlebige Queue-Worker könnten State-Pollution verursachen.
- [ ] Prüfen, ob `BrandRegistry` beim Queue-Worker-Bootstrap sauber resettet wird
- [ ] Ggf. `app()->booted()`-Hook oder `ServiceProvider::register()`-Reset ergänzen
- [ ] Spec in `features/` dokumentieren falls Änderung nötig

## F-11 · 🟡 P2 · Props-Validierung CartItemList + StripeCheckoutForm 🆕
- [ ] `CartItemListProps` / `StripeCheckoutFormProps` Interfaces definieren (oder verifizieren)
- [ ] Optionale Props auf Null-Sicherheit prüfen (z.B. `clientSecret?: string | null`)
- [ ] `pnpm tsc -b && pnpm lint:fix` ✅

## R-03 · 🟡 P2 · Performance-Regression-Check Slider (Profiler DevTools) 🆕
> R-02 verlegt Preview-Rendering in Slider-onChange. Re-Renders pro Slider-Event sollten 1 sein.
- [ ] Slider-Interaktion im React Profiler aufzeichnen
- [ ] Anzahl Re-Renders pro Slider-Event dokumentieren (sollte 1 sein)
- [ ] Frame-Drops ausschließen

## B-13 · 🟡 P2 · Rollen-Abweisung Integrationstest (403 Forbidden) 🆕
- [ ] `backend/tests/Feature/Authorization/RoleAbortTest.php` — 1 Test pro geschützter Route (B-06)
- [ ] `php artisan test` ✅

## B-14 · 🟡 P2 · Migration V018 Rollback testen 🆕
- [ ] `php artisan migrate:rollback` (V018) auf Dev-DB ausführen
- [ ] Forward/Backward-Migration auf Konsistenz prüfen
- [ ] Migration ggf. korrigieren falls verwaiste Constraints

## B-15 · 🟡 P2 · Datenkonsistenz Audit-Snapshots (PhotoMetadataVersion) 🆕
- [ ] Datenbank nach alten Einträgen ohne `PhotoMetadataVersion` durchsuchen
- [ ] Ggf. Migration für historische Snapshots erstellen
- [ ] Fallback-Logik im Code validieren (`??` / `optional()`)

---

# ⚙️ Infrastructure

## T-09 · 🟡 P2 · Lightroom Plugin Multi-Brand (Restpunkt: E2E) 🏗️
> **Ref:** `features/infrastructure/07-lightroom-multi-tenant-gap.md`, `features/infrastructure/10-frontend-brand-tenant-isolation.md`
> P1 (Plugin + Backend) ✅, P2 (Schema) ✅, P3 (Scoping) teils ✅.
- [x] Seed ATR-Tenant (2026-06-29) — *wird via T-14 zu SRP*
- [x] Admin-UI Brand-Zuweisung (`UserPermissionsModal`) — *wird via U-02 angepasst*
- [ ] **Offen:** E2E-Tests Plugin-Menüs, Brand-Header (`X-Brand`)
- [x] E2E Gallery-Scoping (`getAllowedGalleryIds`) — Spec erstellt

## FT-01 · 🟡 P2 · FTP-Upload: Brand-Isolation & Defense-in-Depth 🏗️
> `FtpController.php` ist komplett brand-blind. Unter Policy A kein akuter Leak, aber Defense-in-Depth-Lücken.
**Soll:**
- [ ] `FtpController::setTarget()`: Intersect `$request->gallery_id` mit `$user->getAllowedGalleryIds()` → 403 falls nicht enthalten
- [ ] `FtpController::process()`: gleiche Prüfung auf `$user->current_ftp_gallery_id` vor Import
- [ ] `ManagementFtpInbox.tsx`: `gallery.brand` als Badge bei Zielgalerie-Auswahl
- [ ] E2E: Photographer brand A kann nicht in brand-B-Gallery importieren (API-Direktaufruf)
- [ ] Spec: `features/infrastructure/13-ftp-brand-isolation.md`

---

# 🧪 Tests (Ausbau)

## T-11-ext · 🟡 P2 · Frontend-Komponententests ausbauen
> IST: 127 Logic-Tests ✅, 15 UI-Tests (ClientCartView 5, Sidebar 10). Infra (jsdom) unblocked.
- [ ] **P1:** ProtectedDashboard, SidebarLoginForm, ResetPassword, DeliveryView, GalleryView (~20–30 Tests)
- [ ] **P2:** ManagementOrdersView, ManagementManualInvoiceView, ErrorBoundary, ManagementGalleryView (~18–22)
- [ ] **P3:** LicenseSelectorCard, LicenseCatalogSettings, WysiwygEditor, IptcMetadataEditor (~14–18)
- [ ] **P4 Hooks:** useAuth, useGallery, CartContext, usePhoto, useSearch (~19–23)
- [ ] Gesamtaufwand: ~90–110 neue Tests in ~20–25 Testdateien

## T-13 · 🟡 P2 · Backend-Test-Lücken schließen
> IST: 537 Tests ✅ (1265 assertions), 67 Dateien (4 Unit + 63 Feature). 16/26 Controller (62%), 9/13 Services (69%).
- [ ] **P1:** FileDeliveryController (0 Tests — kritischster Pfad, Media-Serving)
- [ ] **P2:** Webhook/Stripe Payment-Flow (nur 2 Tests); OrderController Admin-Routen; ImageController::upload (nur Upload-Pfad)
- [ ] **P3:** QuoteLinkService, TenantController CRUD, SettingsController Schreib-Operationen, TenantInviteController

---

# 🚀 Pre-Deployment

## D-01 · 🔴 P1 · Full Test-Suite vor Deployment 🆕
- [ ] Backend: `php artisan test` ✅
- [ ] Frontend: `pnpm tsc -b` ✅, `pnpm lint:fix` ✅, `pnpm build` ✅, `pnpm vitest run` ✅ (142+ Tests)
- [ ] E2E: `node ai_test_runner.mjs brand` ✅
- [ ] E2E: Alle relevanten Suites (Download, Checkout, etc.) ✅

---

# Test-Befehle

```bash
# Backend (PHP via Herd: PATH muss php85 enthalten)
export PATH="/c/Users/flori/.config/herd/bin/php85:$PATH"
cd backend && php artisan test

# Frontend Unit (pnpm, NICHT npm)
cd frontend && pnpm vitest run

# Frontend Lint + Build (pnpm, NICHT npm)
cd frontend && pnpm lint:fix && pnpm build

# E2E (via ai_test_runner, NIE direkt npx playwright)
cd frontend
node ai_test_runner.mjs brand/download-invoice-brand-leak
node ai_test_runner.mjs brand/gallery-brand-scoping
node ai_test_runner.mjs brand/brand-e2e-infra
node ai_test_runner.mjs brand
```

---

# Resolviert & entfernt (Archiv)

> **Bereinigt 2026-06-30 (verifiziert durch Subagent gegen Code):** A-02, A-03, A-04, A-05, A-06, A-07,
> R-01, R-02, F-05, F-10, B-03, B-05, B-06, B-09, B-11, B-12, L-01, AI-01, AI-02, AI-03, AI-04, AI-07, T-11 (Infra).
>
> **Früher resolviert (2026-06-30):** C-01, C-03, R-03, R-04, R-05, R-06, R-07, R-08, F-01, F-02, F-03,
> F-09, B-01, B-02, B-04, B-08, B-10, T-10.
>
> **2026-06-29:** A-01, C-02, C-04, F-04, F-06, F-07, F-08, T-12, B-07, AI-DISABLED, BFIX-01, INTELLIJ.
>
> **⚠️ NICHT resolviert (fälschlich als ✅ markiert, siehe AT-01/AT-02):** AI-05 (Backend-Vision
> NICHT entfernt), AI-06 (Vision-Tests NICHT entfernt). Beide im Backlog unter AI-Test-Audit.
>
> Deren Specs in `features/` bleiben Source-of-Truth.
