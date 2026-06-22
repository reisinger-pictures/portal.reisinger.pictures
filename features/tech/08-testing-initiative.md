---
domain: technical
topic: testing-initiative
status: active
---

# Technical Concept: Test- & Qualitäts-Initiative

> **Persistente Spezifikation (SOLL-ZUSTAND).** Dieses Dokument ist die alleinige Quelle der Wahrheit für die Test-Ausbaustaffel. Es ist so geschrieben, dass jedes Paket (BK-xx / FE-xx / E2E-01) **ohne Konversationkontext** von einem günstigen Modell implementiert und von einem teureren Modell reviewed werden kann. Actionable TODOs liegen gespiegelt in `AGENTS.todo.md`.
>
> **Beziehung zu [`05-testing-guidelines.md`](05-testing-guidelines.md):** Diese Initiative ist eine *Ergänzung*, keine *Ersetzung*. Alle Regeln aus `05-testing-guidelines.md` gelten unverändert (Mocking-Verbot in E2E, UI-First-Sync, E-Mail über Mailpit statt `Mail::fake()`, IDOR/Negative Tests, SRP, semantisches Locator-Scoping). Abweichungen sind in den Paketen explizit markiert.

## 1. Context & Entscheidungen

**Ziel.** Systematischer Ausbau der Testabdeckung (Backend Unit, Frontend Pure-Logic Unit, E2E-Edge-Cases) und Etablierung eines wiederverwendbaren **Doku → Implementierung (günstiges Modell) → Review (teures Modell) → Fix**-Workflows, der später auf den gesamten Code ausgeweitet wird.

**Ausgangslage (verifiziert 2026-06-22).**
- Backend: 47 Feature-Tests, ~153 Methoden, kein Unit-Test, nur 2 von 11 Services mit dedizierten Tests (`StatsCalculationService`, `ManualInvoiceService` — Vorbilder). Komplexe Logik (Permission-System 60+ Zweige, Watermark-Entscheidung, `effective_*`-Kaskade, Pricing, Payout) weitgehend ungetestet. Nur 4 Factories für ~22 Models.
- Frontend: 31 Playwright-E2E-Tests, 0 Unit-Tests, kein Vitest. Reine Geschäftslogik (`usePricing`, `utils.ts`, Cart, ShootingCalculator) ungetestet und teils inline in Hooks/Komponenten gekapselt.

**Entscheidungen.**
1. **Priorität:** P1 = Backend Unit-Tests (detailliert). P2 = Frontend Unit-Tests **nur für Berechnungen/Geschäftslogik**. P3 = E2E-Edge-Cases. Reihenfolge ist verbindlich (Budgetbewusst).
2. **Keine Frontend-Komponenten-UI-Tests** — dafür existieren E2E-Tests. Konsequenz: **Vitest allein** (keine `@testing-library/*`, kein jsdom für die prioritären Pakete). Begründet: Vitest ist für Vite + reine Funktionen der de-facto-Standard; Testing Library löst nur DOM-/Komponenten-Testing, das ausdrücklich nicht gewollt ist. Vitest ist eine **neue, zusätzliche Schicht** neben Playwright (E2E) und PHPUnit (Backend), keine Ersetzung.
3. **Inline-Geschäftslogik** (`usePricing`, Cart-Reducer, ShootingCalculator) wird zum Testen als **reine Funktionen extrahiert** (kleiner, verhaltenshaltender Refactor; vom Reviewer zu validieren). Das ist die einzige saubere Möglichkeit ohne Komponenten-Tests an die Logik zu kommen.
4. **Tests erfassen zuerst das AKTUELLE Verhalten** (auch Bugs). Gefundene Fehler werden vom Reviewer im Fix-Loop bewertet, nicht vom Implementierer „passend gemacht".
5. **Backend-Test-Ablage:** Default `tests/Feature/` (das bewährte Muster, DB-abhängig). `tests/Unit/` nur für Framework-/DB-freie Logik (z.B. `PayoutCalculationService::getShareMultiplier`).
6. **Geld = Cents (Integer)** durchgängig ([`03-backend-architecture.md` §4 Money Pattern](03-backend-architecture.md)) — alle Pricing-/Payout-Tests arbeiten in Cents.

**Gefundene potenzielle Bugs (für den Review-Fix-Loop dokumentieren, nicht selbst reparieren):**
- `formatDateToDE('2024-06-22T12:00:00Z')` → liefert fehlerhaft `'22T12:00:00Z.06.2024'` (Split nur auf `-`).
- ShootingCalculator: `calc_images_per_hour = '0'` → Division durch null → `Infinity`.
- `User::getSubGroupIds()` baut UUID-Listen per String-Concatenation (~Zeile 108) — Robustheits-/Sicherheits-Hinweis, kein nachgewiesener Exploit.

---

## 2. Workflow: Doku → günstiges Modell → teures Modell → Fix

Pro Paket:

| Phase | Wer | Aufgabe |
|---|---|---|
| **A. Spec lesen** | Implementierer (günstig) | Nur die Sektion des jeweiligen Pakets in diesem Dokument + die genannten Quelldateien. Kein Kontext nötig. |
| **B. Implementieren** | Implementierer | Tests nach Spec. **Verhaltenserhaltend** — Produktionscode nur ändern, wenn das Paket explizit „Extraktion" vorsieht (minimal & verhaltensgleich). |
| **C. Grün machen** | Implementierer | `php ./vendor/bin/phpunit …` bzw. `npx vitest run …` lokal grün. Test-DB via `docker-compose.test.yml`. |
| **D. Review** | Reviewer (teuer) | Review-Checkliste (§2.3). Befund: `blocker` / `major` / `minor` / `nit`. |
| **E. Fix-Loop** | Implementierer | `blocker`/`major` beheben. Max. 2 Loops, dann Eskalation. |
| **F. Abschluss** | Reviewer | „Approved" + Eintrag in §9-Fortschrittstabelle (gespiegelt in `AGENTS.todo.md`). |

**Implementierer-Brief (Standard-Prompt pro Paket, in neue Session kopieren):**
```
Du implementierst Test-Paket <PKG-ID> aus features/tech/08-testing-initiative.md.
Lies unbedingt: Abschnitt <PKG-ID> in features/tech/08-testing-initiative.md,
die Projekt-Testing-Regeln features/tech/05-testing-guidelines.md
sowie die unter „Target files" genannten Quelldateien.
Regeln:
- Schreibe NUR Tests (bzw. die explizit erlaubte Extraktion). Keine Feature-Entwicklung.
- Tests erfassen das AKTUELLE Verhalten. Erscheint Logik fehlerhaft, schreibe den Test
  auf aktuelles Verhalten UND markiere ihn mit "// REVIEW: vermuteter Bug …".
- Halte dich exakt an das Pattern (§3 Backend / §4 Frontend) und an 05-testing-guidelines.md.
- Mach alle Tests grün: <run-Befehl aus Paket-Spec>.
- Gib am Ende den Test-Runner-Befehl in einem eigenen Code-Block aus (AGENTS.md-Pflicht).
- Commit-Message: "test(<bereich>): <PKG-ID> <kurz>".
Liefere am Ende: geänderte Dateien, Testanzahl, gefundene REVIEW-Marker.
```

**Reviewer-Checkliste:**
1. Deckt der Test alle Zweige/Edge-Cases der Spec? (Abhaken gegen Edge-Case-Liste.)
2. Erfasst der Test echtes Verhalten, kein Wunschverhalten? (Keine „passend gemachten" Tests.)
3. Pattern- & Guideline-Konformität (Naming, `setUp()`, Assertion-Stil, IDOR wo relevant, SRP, semantisches Scoping).
4. Keine false confidence (Assertions spezifisch genug; kein `assertTrue(true)`).
5. Extraktionen verhaltensgleich? (Diff prüfen.)
6. REVIEW-Marker bewerten: Bug bestätigt? → separater Code-Fix-Task.
7. Suite grün & flügge (kein Flakiness, kein Regelverstoß gegen `05-testing-guidelines.md`).

**Generalisierung auf den gesamten Code:** derselbe Dreiklang gilt für bestehenden Produktionscode — pro Modul/Service eine Spec (Zweige, Edge-Cases, Invarianten) anlegen, entsteht oft aus dem Test-Schreiben (TDD-rückwärts). Review-Template funktioniert 1:1 für Code-Änderungen. Empfehlung: nach Abschluss der Test-Pakete pro Service eine `features/<bereich>/<Thema>.md` mit verifizierten Invarianten pflegen — das wird die „Doku", die künftige Reviews beschleunigt.

---

## 3. Backend-Pattern (für alle BK-Pakete verbindlich)

Basiert auf `tests/Feature/StatsCalculationServiceTest.php` / `ManualInvoiceServiceTest.php`:

```php
namespace Tests\Feature;                 // Default (DB-abhängig)
// namespace Tests\Unit;                // NUR für Framework-/DB-freie Logik

use Tests\TestCase;
use Illuminate\Foundation\Testing\RefreshDatabase;
use App\Services\XyzService;

class XyzServiceTest extends TestCase
{
    use RefreshDatabase;

    private XyzService $service;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = new XyzService();   // direkte Instanziierung, kein Container
    }

    public function test_<method>_<scenario>(): void   // SRP: ein Verhalten pro Test
    {
        // Arrange (Factory / Model::create)
        // Act
        // Assert — 1–3 fokussierte Assertions
    }
}
```

Konventionen & Guideline-Alignment:
- **Service:** `new Service()` im `setUp()`; nur echte externe Abhängigkeiten mocken (Stripe SDK, ExifTool-Binary). **E-Mail niemals via `Mail::fake()`** — Mailpit-API abfragen (MAIL_HOST :1026, siehe `05-testing-guidelines.md` §3).
- **Model:** Factory, Beziehungen per `attach()`/Factory-State; `effective_*`/Permission-Methoden direkt am Model.
- **IDOR/Negative Tests** (§3 der Guidelines): Jede zugriffsgeschützte Logik/Route auch mit unberechtigtem Nutzer prüfen → 403/404.
- **JSON-Leak-Prevention:** Bei HTTP-Feature-Tests `assertJsonStructure`/`assertJsonMissing`, nicht nur Status-Codes.
- **SRP:** viele kleine Tests (`test_<method>_<konkreterSzenario>`) > wenige riesige. Data-Provider nur bei großen Matrizen.
- **Kein `tearDown`** (`RefreshDatabase` resettet). **Geld = Cents.**

**Laufbefehle:**
```bash
cd backend
docker compose -f docker-compose.test.yml up -d
php ./vendor/bin/phpunit tests/Feature/XyzServiceTest.php
php ./vendor/bin/phpunit --filter test_<name>
```

---

## 4. Frontend-Pattern (für alle FE-Pakete verbindlich)

Nach **FE-00** (Vitest-Setup):

```ts
// src/logic/__tests__/utils.test.ts
import { describe, it, expect, vi } from 'vitest';
import { formatMoney } from '../utils';

describe('formatMoney', () => {
  it('formats cents to euro with 2 decimals', () => {
    expect(formatMoney(1234)).toBe('12.34 €');
  });
});
```

- **Ablage:** `src/logic/__tests__/<modul>.test.ts`.
- **Nur reine Logik** — kein React-Rendering, kein DOM (keine `@testing-library`).
- **Hooks (`use*`):** nicht direkt testen; stattdessen die **extrahierte reine Logik** (FE-02/03/04).
- **Zeit/Zufall:** `vi.useFakeTimers()` für `debounce`/`setTimeout`; `generateId` via Regex + Eindeutigkeit.

**Laufbefehle:**
```bash
cd frontend
npm run test:run
npx vitest run src/logic/__tests__/utils.test.ts
```

---

## 5. Paketübersicht (verbindliche Prioritäten-Reihenfolge)

| ID | Titel | Prio | Typ | Hängt ab von |
|---|---|---|---|---|
| **BK-00** | Shared Model Factories | **P0** | Backend | — |
| **BK-01** | User: Permission- & Zugriffslogik | **P1** | Backend | BK-00 |
| **BK-02** | Photo: Watermark, effective_*, URLs | **P1** | Backend | BK-00 |
| **BK-03** | Gallery + GalleryGroup: effective_*-Kaskade & fullPath | **P1** | Backend | BK-00 |
| **BK-04** | PricingService | **P1** | Backend | BK-00 |
| **BK-05** | PayoutCalculationService | **P1** | Backend | BK-00 |
| **BK-06** | CheckoutService | P2 | Backend | BK-00, BK-04 |
| **BK-07** | InvoiceService | P2 | Backend | BK-00 |
| **BK-08** | GalleryTreeService | P2 | Backend | BK-00 |
| **BK-09** | WatermarkService + PhotoProcessingService (Mocking) | P2 | Backend | BK-00 |
| **BK-10** | Shooting Calculator: SettingsController-Validierung | P2 | Backend | BK-00 |
| **FE-00** | Vitest-Setup (nur Vitest) | **P2** | Frontend | — |
| **FE-01** | utils.ts | **P2** | Frontend | FE-00 |
| **FE-02** | usePricing (Extraktion + Tests) | **P2** | Frontend | FE-00 |
| **FE-03** | Cart-Logik (Extraktion + Zod + Persistenz) | **P2** | Frontend | FE-00 |
| **FE-04** | ShootingCalculator (Extraktion + Tests) | **P2** | Frontend | FE-00 |
| **E2E-01** | E2E: zusätzliche Edge-Case-Specs | P3 | Frontend | — |

**Reihenfolge:** BK-00 → (BK-01..05 parallelisierbar) → BK-06..10 → FE-00 → FE-01..04 → E2E-01.

---

## 6. Backend-Paket-Spezifikationen

### BK-00 — Shared Model Factories · P0 · Voraussetzung für alle BK-Pakete
**Target (neu):** `backend/database/factories/` — `OrderFactory`, `TenantFactory`, `InvoiceSnapshotFactory`, `PayoutPoolFactory`, `PhotographerStatementFactory`, `LicenseUseCaseFactory`, `LicenseModifierFactory`, `DownloadLogFactory`, `SettingFactory`, `ProductFactory`.
**Vorlage:** vorhandene `UserFactory.php`/`GalleryFactory.php`. **Aufgabe:** pro Model eine `definition()` mit realistischen Defaults aller non-nullable Spalten (Spalten aus Migration ableiten). Wichtige Felder:
- `Order`: `user_id`, `status` (enum), `is_quote_request`, `stripe_fee_cents` (nullable), `payment_intent_id`, billing-Felder.
- `Tenant`: `invoice_frequency` (`immediate` vs. andere), billing.
- `InvoiceSnapshot`: `order_id`, `customer_details` (JSON: `items[]` à `{photoId, price, tier}`).
- `PayoutPool`: `month`, `year`, `net_cents`.
- `PhotographerStatement`: `user_id`, `month`, `year`, `pool_earnings_cents`, `delta_surcharge_earnings_cents`, `total_payable_cents`, `status` (`pending`/`rollover`).
- `LicenseUseCase`: `base_price_cents`, `flatrate_tier` (`none|web|print|original`), `is_commercial`, `name`.
- `LicenseModifier`: `percent_surcharge`, `is_included_in_flatrate`, `name`.
- `DownloadLog`: `user_id` (nullable), `gallery_id`, `photo_id`, `item_type` (`single_image|full_zip`), `resolution_tier` (`web|print|original`), `photo_count`, `created_at`.
- `Setting`: `key`, `value` (string).
- `Product`: tier (`web|print|original`), `base_price_cents`.
**Acceptance:** jedes `Model::factory()->create()` in frischer Test-DB erfolgreich; bestehende Suite bleibt grün. **Reviewer:** Spalten komplett? Beziehungen korrekt?

### BK-01 — User: Permission- & Zugriffslogik · P1 · CRITICAL (Sicherheit)
**Target:** `backend/app/Models/User.php`. **Test (neu):** `tests/Feature/UserPermissionLogicTest.php`.
Methoden & Zweige:
1. `getAllowedGalleryIds()` — 7 Quellen: (a) Gast-Frühreturn (`guest_id` → `transient_galleries ?? []`); (b) direkte Galleries; (c) GalleryGroups rekursiv via `getSubGroupIds()`; (d) **Tenant-Zuweisungen — nur `type='delivery'`**; (e) `transient_galleries`-Merge; (f) Fotograf: Cache `unrestricted_photographer_gallery_ids` + `photographerGalleries` + `photographerGalleryGroups` (rekursiv); (g) finale Dedup.
2. `getSubGroupIds($parentIds)` — Empty → `[]`; sonst `WITH RECURSIVE`-CTE.
3. `canAccessGallery($id)` — `is_super_admin`→true; Fotograf via `canPhotographerAccessGallery`; sonst `in_array`. **Normale Admins brauchen explizite Rechte (kein GOD MODE).**
4. `canPhotographerAccessGallery($id)` — super_admin; nicht-Fotograf→false; Gallery nicht existent→false; `effective_restricted_photographers===false`→true; direkte Zuweisung; Gruppen (rekursiv); sonst false.
5. `hasPurchasedPhoto($photoId,$requestedTier)` — exkludiert `disputed|refunded|cancelled`; Filter `is_quote_request=false OR status!='pending'`; durchsucht `invoiceSnapshot->customer_details['items']`; Rank `$ranks['none'=>0,'web'=>1,'print'=>2,'original'=>3]`, `requestedTier` defaultet zu `original`(3); Match wenn `itemRank>=reqRank`.
6. Rollen-Accessors `IsSuperAdmin/IsAdmin/IsPhotographer/IsCustomerManager/IsPowerUser` + `getIsPendingAttribute` (guest→false; sonst roles UND galleryGroups UND galleries alle leer).
**Edge-Cases (je eigene Tests):** Gast leer/transient; ohne Rechte; nur direkte; nur Gruppen (1/mehrstufig); Tenant nur delivery; Tenant nicht-delivery **ausgeschlossen**; Kombination+Dedup; Fotograf Cache-Hit/Miss (`Cache::flush()`); restricted ohne Recht→false; `getSubGroupIds` `[]`/einzeln/nicht-existent/tief; `hasPurchasedPhoto` stornierte/refunded/disputed; ausstehende Quote exkludiert; genehmigte Quote inkludiert; niedrigerer/höherer/gleicher Tier; fehlende Keys; fehlendes Snapshot; `canAccessGallery` super_admin/nicht-existente/Admin-ohne-Recht→false. **REVIEW-Marker:** String-Concatenation in `getSubGroupIds` — Test mit sehr langer UUID-Liste ergänzen.
**Acceptance:** ≥25 Tests. **Reviewer:** delivery-only-Filter wirklich getestet? Cache-Branch beide Pfade? super_admin vs. admin sauber getrennt?

### BK-02 — Photo: Watermark, effective_*, URLs · P1
**Target:** `backend/app/Models/Photo.php`. **Test (neu):** `tests/Feature/PhotoLogicTest.php`.
Methoden:
1. `requiresWatermark()` — 6 Zweige: Gallery null→true; `effective_is_free_download`→false; unauth→true; `is_admin||is_photographer`→false; `canAccessGallery`+`flatrate_level` rank≥1 (`web|print|original`)→false; sonst true. Auth via `$this->actingAs($user,'api')`.
2. `getEffectiveIsEditorialOnlyAttribute`/`IsHidden` — `photo.X || (gallery? gallery.effective_X : false)`.
3. `getUrlAttribute`/`getThumbUrlAttribute`/`getSrcsetAttribute` — Pfad & `v=`; Watermark-Präfix `watermarked/`; Cache `watermark_version` (default `'1'`); `created_at` null→`v=1`.
4. `getArtistAttribute` — user null→null; `metadata_copyright ?: name`.
5. `getFilenameAttribute` — mime→ext (`image/png`→png, `image/webp`→webp, sonst jpg).
6. `shouldBeSearchable()` — gallery null→false; `type='selection'`→false; sonst true.
**Edge-Cases:** jede Watermark-Bedingung; `flatrate_level` null→0→Watermark; ungültiger Level→0; URL ±Watermark ±`created_at`; `watermark_version` variieren; mime jpeg/png/webp/gif/null; artist ±copyright (`metadata_copyright='0'` fällt wegen `?:` auf `name` zurück — **REVIEW**).
**Acceptance:** ≥18 Tests. **Reviewer:** Watermark-Entscheidung und URL-Generierung entkoppelt? `actingAs` mit Guard `api`?

### BK-03 — Gallery + GalleryGroup: effective_*-Kaskade & fullPath · P1
**Target:** `app/Models/Gallery.php`, `app/Models/GalleryGroup.php`. **Test (neu):** `tests/Feature/EffectiveAttributesTest.php`.
Logik: `effective_is_editorial_only`/`is_free_download`/`is_hidden` = `$this->X || (parent? parent.effective_X : false)` (Gallery via `galleryGroup`, Group via `parent`). **`effective_restricted_photographers` anders:** `!== null`-Check — expliziter Wert (auch `false`) bricht Kaskade, nur `null` erbt. `getFullPathAttribute` (Gallery): While-Schleife über `galleryGroup`/`parent`, slugs voranstellen, Prefix `galleries/`.
**Edge-Cases:** Eigener Wert true/false; Parent null; mehrstufig (3+ Level, gemischt); `restricted_photographers` explizit `false` bricht (nicht erben!), `null` erbt, `0`/`1`; fullPath keine Group / 1 Level / mehrstufig. **REVIEW (potenzielle Endlosschleife):** zirkulärer `parent_id` (A→B→A) und Selbstreferenz — Test **dokumentiert aktuelles Verhalten** (Timeout/Overflow möglich) via Guard/`markTestSkipped` + Ticket. **Nicht** so anpassen, dass Endloslauf „grün" wird.
**Acceptance:** ≥16 Tests; Zyklus-Fall als REVIEW markiert. **Reviewer:** `!== null` vs `||`-Unterschied sauber getestet? Zyklus dokumentiert (nicht vertuscht)?

### BK-04 — PricingService · P1
**Target:** `app/Services/PricingService.php`. **Test (neu):** `tests/Feature/PricingServiceTest.php`.
Methode `calculateItemPriceCents(string $useCaseId, ?array $modifierIds, string $userFlatrateLevel): array`. Zweige: `LicenseUseCase` fehlt→`ModelNotFoundException`; Rank `['none'=>0,'web'=>1,'print'=>2,'original'=>3]`; Basis gedeckt wenn `userRank>=reqRank`→0, sonst voll; pro Modifier: gedeckt **und** `is_included_in_flatrate`→skip, sonst `surcharge=round(base_price*percent_surcharge/100)`; `modifierIds` null vs `[]`; unbekannter Level→0. Return: `total_cents`, `tier`, `use_case_name`, `modifier_names`.
**Edge-Cases:** unbekannte useCaseId (Exception); null vs leer; jeder Level×Tier; Modifier 0%/100%+; `is_included_in_flatrate` true/false × gedeckt/nicht; Basispreis 0; Rundung; unbekannter Level.
**Acceptance:** ≥14 Tests (Level×Tier ideal als Data-Provider). **Reviewer:** Rundung korrekt? Return-Shape vollständig?

### BK-05 — PayoutCalculationService · P1
**Target:** `app/Services/PayoutCalculationService.php`. **Test (neu):** `tests/Feature/PayoutCalculationServiceTest.php`.
Methoden:
1. `getShareMultiplier($tier)` — pure: `original=>4, print=>2, web=>1, default=>1`. (→ `tests/Unit/`.)
2. `calculatePoolShares(PayoutPool)` — Logs gruppieren nach `user_id|gallery_id`; Skip bei `effective_is_free_download`/`photographer_id` null; ZIP (`full_zip`, max `photo_count`) vs Single (sum); Shares=`photo_count*multiplier` via **bcmath 4 Dez.**; `value_per_share_cents=floor(net_cents/total_shares)`; PhotographerStatement aktualisieren.
3. `calculatePowerUserDelta(month,year)` — Orders `status='paid'` & `!is_quote_request`; Fee=`stripe_fee_cents` oder `price*4%`; net=price−fee; 50/50 bcmath; `delta_surcharge_earnings_cents`.
4. `finalizeStatements(month,year)` — earned+delta; Rollover vom Vormonat (`status='rollover'`); `total_payable>=5000`→`pending`, sonst `rollover`.
**Edge-Cases:** Multiplier original/print/web/ungültig/null; keine Logs; `user_id` null exkludiert; Duplikate (selbes Foto/User→1×); ZIP-vs-Single-Mix; free_download/photographer-null skip; `net_cents=0`; **Division-durch-Null** (`totalShares=0`)→Verhalten+REVIEW; bcmath-Rundung; Statement exists vs create; keine paid Orders; Order ohne Snapshot; Item ohne `photoId`/`price<=0`; Photo ohne `user_id`; `stripe_fee_cents` null; keine Statements; Schwellwert 5000; darunter; Vormonat fehlt/anderer Status.
**Acceptance:** ≥20 Tests; bcmath-Präzision explizit. **Reviewer:** Division-durch-Null dokumentiert? Floor bei `value_per_share`? ZIP-vs-Single separat?

### BK-06 — CheckoutService · P2
**Target:** `app/Services/CheckoutService.php` (Ctor `PricingService`). **Test (neu):** `tests/Feature/CheckoutServiceTest.php`.
`processCheckout($request,$user,$paymentMethod)` in `DB::transaction`. Zweige: Foto 404; Gallery-Zugriff 403 (nicht-öffentlich & kein Zugriff — **IDOR-relevant**); kommerziell vs. `effective_is_editorial_only`→403; Quote→Preis 0; sonst `PricingService`; `total<=0 && !quote`→400; `$isLieferschein=$tenant && invoice_frequency!=='immediate'`; Status `quote?pending:(lieferschein?delivery_note:(invoice?invoice_created:pending_payment))`; Quote/Lieferschein/Invoice→InvoiceMail **(über Mailpit verifizieren, nicht `Mail::fake()`)**→`{order_id,invoice_number}`; sonst Stripe PaymentIntent (Stripe SDK mocken — extern, erlaubt)→`{client_secret,requires_action}`.
**Edge-Cases:** leere Items; Zugriff verweigert (IDOR); kommerziell×editorial; Quote total 0 (erlaubt); Nicht-Quote total 0 (400); ohne Tenant; `invoice_frequency`-Varianten; `paymentMethod` invoice/stripe; Transaktions-Rollback.
**Acceptance:** ≥12 Tests. **Reviewer:** Stripe gemockt & Mail via Mailpit? Transaktions-Rollback getestet? IDOR-Pfad abgedeckt?

### BK-07 — InvoiceService · P2
**Target:** `app/Services/InvoiceService.php`. **Test (neu):** `tests/Feature/InvoiceServiceTest.php`.
`generateForTenant(Tenant,$initiator=null)` in `DB::transaction`+`lockForUpdate`; liefert `delivery_note`-Orders der Tenant-User; leer→Fehler; Summen/items; Orders→`archived_in_collective`; Billing-Fallback initiator→erster Tenant-User→`'Firmenadresse'`; neue collective Order+`InvoiceSequence`-Nummer+InvoiceSnapshot; InvoiceMail **(Mailpit)** nur wenn Mail vorhanden.
**Edge-Cases:** Tenant ohne User; User ohne delivery_note-Orders; leerer Snapshot/Items; Billing überall fehlend→Fallback; `initiator=null`; Einzel- vs Mehrfach-User; `mailTo` null.
**Acceptance:** ≥8 Tests. **Reviewer:** Fallback-Kaskade vollständig? Mail via Mailpit (nicht `Mail::fake()`)?

### BK-08 — GalleryTreeService · P2
**Target:** `app/Services/GalleryTreeService.php`. **Test (neu):** `tests/Feature/GalleryTreeServiceTest.php`.
`getAdminTree(User,?filterType)` — Cache `gallery_tree_admin` (Hit/Miss; `Cache::flush()` zwischen Tests); nicht-Admin→Filter via `getAllowedGalleryIds()`; `filterType` (`selection|delivery`). `getAllSubgroupIds(GalleryGroup)` — Rekursion über `children`. `clearCache()` — `Cache::forget`.
**Edge-Cases:** Cache-Miss/Hit; Admin (ungefiltert); Nicht-Admin ohne/mit Rechten; `filterType` null/selection/delivery; leerer Baum; verschachtelt (3+ Level).
**Acceptance:** ≥8 Tests. **Reviewer:** Cache zwischen Tests resettet? Permission-Filter korrekt?

### BK-09 — WatermarkService + PhotoProcessingService (Mocking) · P2
**Target:** `app/Services/WatermarkService.php`, `app/Services/PhotoProcessingService.php`. **Test (neu):** `tests/Feature/ImageServicesTest.php`.
**WatermarkService:** `applyWatermark($source,$dest,$maxWidth=null,$galleryType='delivery')` — Passthrough an `ImageProcessor` via `app(...)`. → `ImageProcessor` mocken (`$this->mock(ImageProcessor::class,...)`), Aufruf-Args verifizieren. Edge: null-maxWidth, galleryType-Varianten.
**PhotoProcessingService:** `processImage($targetPath,$thumbPath,Gallery)` — `getimagesize`; `applyDefaults=gallery.type!=='selection' && apply_metadata_to_photos`; Frühreturn bei selection/`!apply_metadata`; sonst ExifTool (Symfony `Process`)→JSON→Titel/Desc/Keywords/Location/Date mit Fallbacks. → `Process`/`getimagesize` mocken; `Carbon`-Parsing. Edge: selection (Frühreturn), `apply_metadata=false`, ExifTool JSON ungültig/leer, fehlende Felder (Fallbacks), Keywords Array vs Skalar, Datum-parse-Fehler.
**Hinweis:** Sind `Process`/`getimagesize` nicht sauber mockbar → **REVIEW notieren**; Test auf Frühreturn-Verzweigung + Metadaten-Mapping mit gefakter Process-Ausgabe (ExifTool-Binary nicht aufrufen).
**Acceptance:** ≥10 Tests. **Reviewer:** Externe Binaries wirklich nicht aufgerufen? Fallback-Logik abgedeckt?

### BK-10 — Shooting Calculator: SettingsController-Validierung · P2
**Target:** `app/Http/Controllers/SettingsController.php` (`getLicenseTerms`,`updateLicenseTerms`); Migration `V016__calculator_settings_defaults.php`. **Test (neu):** `tests/Feature/ShootingCalculatorSettingsTest.php`.
Validierung: `calc_base_price: nullable|numeric|min:0`; `calc_hourly_rate: nullable|numeric|min:0`; `calc_images_per_hour: nullable|integer|min:1`. Lesen-Defaults: `base_price→'35.00'`, `calc_base_price→'50'`, `calc_hourly_rate→'100'`, `calc_images_per_hour→'6'`.
**Tests (HTTP-Feature, als Admin):** GET liefert Defaults wenn Settings fehlen vs. gespeicherte Werte; Update akzeptiert valide; Update **lehnt ab** (422): negatives base_price/hourly_rate, `calc_images_per_hour=0` (min:1), nicht-numerisch, Typ-Verletzungen; Update persistiert; **nicht-Admin→403 (IDOR/Auth)**.
**Acceptance:** ≥8 Tests. **Reviewer:** Defaults vs. gespeicherte Werte unterschieden? Authorisierung getestet?

---

## 7. Frontend-Paket-Spezifikationen

### FE-00 — Vitest-Setup (nur Vitest) · P2 · Voraussetzung für FE-01..04
**Install (verifiziert minimal — keine Testing Library):**
```bash
cd frontend && npm install --save-dev vitest
```
*Rationale:* Vitest = Standard für Vite + reine Funktionen; `@testing-library/*` löst nur DOM-/Komponenten-Testing (ausgeschlossen).
**Neu `frontend/vitest.config.ts`:**
```ts
import { defineConfig } from 'vitest/config';
import react from '@vitejs/plugin-react';

export default defineConfig({
  plugins: [react()],
  test: {
    environment: 'node',   // reine Logik, kein DOM
    globals: false,         // explizite imports
    include: ['src/**/*.test.ts', 'src/**/*.test.tsx'],
  },
});
```
**`package.json` scripts:** `"test": "vitest"`, `"test:run": "vitest run"`, `"test:watch": "vitest"`.
**Smoke-Test** `src/logic/__tests__/smoke.test.ts`.
**Acceptance:** `npm run test:run` grün; `build`/`lint`/E2E unbeeinträchtigt. **Reviewer:** keine unnötigen Deps? `environment:'node'` ausreichend? `tsc -b` ok?

### FE-01 — utils.ts · P2
**Target:** `src/logic/utils.ts`. **Test (neu):** `src/logic/__tests__/utils.test.ts`.
Funktionen & Edge-Cases:
- `formatMoney(cents)` → `(cents/100).toFixed(2)+' €'`. Tests: 0→`0.00 €`, 1→`0.01 €`, 100, 1234→`12.34 €`, −500, Rundung `0.5`, 999999.
- `formatDateToDE(iso)` → Split `-`. Tests: `''`→`''`, `2024-06-22`→`22.06.2024`, 2-Teile→original, ungültig→original. **REVIEW-Marker:** `2024-06-22T12:00:00Z` → fehlerhaft `'22T12:00:00Z.06.2024'`.
- `formatLocaleDate(date)` → `de-AT` `DD.MM.YYYY`. Tests: konkrete Daten, Regex, Invalid-Date.
- `flattenGroups(groups,depth=0)`. Tests: `[]`, ohne `children`, 3-Level-Baum, `is_public` true/false/null/undefined, Geschwister.
- `generateId()` → Regex `^\d+-[a-z0-9]{9}$` + Eindeutigkeit über 1000.
- `debounce(func,wait)` → `vi.useFakeTimers()`. Tests: 0 Aufrufe vor Ablauf; 1 nach `advanceTimersByTime`; nur letztes Argument.
- `isEmpty(value)` → null/undef/leer-Array/leer-String(trimmed)/leer-Objekt. Tests: inkl. `0`, `false`, whitespace, RegExp/Map/Set.
- `safeJsonParse(json,fallback)` → try/catch. Tests: valid/invalid/`''`/primitive/Array/`null`/fallback-Typen.
**Acceptance:** ≥30 Assertions; `debounce` mit Fake-Timern. **Reviewer:** Bug-Tests erfassen aktuelles Verhalten? Fake-Timer ge-cleant?

### FE-02 — usePricing (Extraktion + Tests) · P2
**Target:** `src/logic/usePricing.ts` (Hook; reine Logik intern). **Refactor (verhaltensgleich):** reine Funktionen nach **neu** `src/logic/pricingLogic.ts` auslagern & exportieren: `RES_RANKS`, `getRequiredTerm(terms,key)`, `isCovered(...)`, `calculateUpgradePrice(terms,...)`. `usePricing.ts` importiert diese unverändert und reicht `terms` durch.
**Test (neu):** `src/logic/__tests__/pricingLogic.test.ts`.
Logik: `RES_RANKS={none:0,web:1,print:2,original:3}`; `getRequiredTerm` `!terms`→0, `parseInt`, `isNaN`→throw; `isCovered` early false wenn `usage!=='editorial'||duration!=='1_year'||territory!=='national'`, sonst `RES_RANKS[userLevel||'none']>=RES_RANKS[reqRes]`; `calculateUpgradePrice` gedeckt→0, sonst `prices[reqRes]*useMult*durMult*terrMult − (userLevel&&!=='none'?prices[userLevel]:0)`, `max(delta,0)`; Multipliers commercial/unlimited/international sonst 1.0.
**Edge-Cases:** `terms=null`→0; fehlender Key→throw; alle `userLevel`×`reqRes`; coverage-Matrix; negative→`max(.,0)`; Multiplikator-Kombinationen; Beispielrechnungen (user=web/req=print/editorial→1500; user=web/req=original/commercial/unlimited/international→17000 bei Beispiel-Terms).
**Acceptance:** ≥20 Tests; Extraktion diff-minimal. **Reviewer (kritisch):** Extraktion verhaltensgleich? Alle Multiplikator-Pfade? Beispielrechnungen gegen Hand-Rechnung?

### FE-03 — Cart-Logik (Extraktion + Zod + Persistenz) · P2
**Target:** `src/logic/CartContext.tsx`, `src/logic/CartProvider.tsx`. **Refactor (verhaltensgleich):** reine Logik nach **neu** `src/logic/cartLogic.ts`: `cartItemSchema`, `cartSchema` (Zod), `addToCartPure`, `removeFromCartPure`, `calculateTotalAmount` (`sum price where !isQuote`). `CartProvider` nutzt diese unverändert.
**Test (neu):** `src/logic/__tests__/cartLogic.test.ts`.
Logik: `addToCart` ersetzt bei gleichem `photoId`, sonst append; `removeFromCart` filter; `totalAmount=items.reduce((s,i)=>s+(i.isQuote?0:i.price),0)`; Zod: `tier enum web|print|original`, `price number`, `photoId string` Pflicht, Rest optional.
**Edge-Cases:** leerer Warenkorb; gleiche photoId (Update); verschiedene (Append); `isQuote` true/false/undefined bei totalAmount; negative/Null-Preise; Zod: fehlende Pflichtfelder, invalides tier, falsche Typen, Extra-Felder (stripping), alle Optionalen; Persistenz: invalides JSON→leer, Zod-Fail→leer+warn, valide→geladen (localStorage via `vi.stubGlobal`).
**Acceptance:** ≥18 Tests. **Reviewer:** Zod-Schema exakt? `isQuote` (undefined falsy)? Persistenz-Mock sauber?

### FE-04 — ShootingCalculator (Extraktion + Tests) · P2
**Target:** `src/ui/management/components/ShootingCalculatorModal.tsx` (Logik inline). **Refactor (verhaltensgleich):** `roundToPsychologicalValue` + `calculateShootingPrice` nach **neu** `src/logic/shootingCalculator.ts` auslagern & exportieren; Komponente importiert unverändert.
**Test (neu):** `src/logic/__tests__/shootingCalculator.test.ts`.
Logik: `roundToPsychologicalValue(v)`: `v<12`→`max(1,round(v))`; `v>=1000`→`round(v/50)*50` sonst `round(v/5)*5`; `−1` wenn `rounded!==0 && (rounded%10===0 || (v>=1000 && rounded%50===0))`. `calculateShootingPrice`: `base=parseFloat(calc_base_price||'50')`, `rate=parseFloat(calc_hourly_rate||'100')`, `perHour=parseInt(calc_images_per_hour||'6',10)`; `timePrice=(duration/60)*rate`; `imagesPrice=(rate/perHour)*images`; `multiplier=flatrate?1.2:1`; `rawTotal=(base+timePrice+imagesPrice)*multiplier`; `package=round(rawTotal)`; discount `33`→`100/3`, `50`→`50`, sonst 0; `final=discount!=='0'?round(package*(1−d/100)):package`; `discountAbsolute=package−final`.
**Edge-Cases (Beispielwerte verifiziert):** `roundToPsychologicalValue` 0→1, 5.5→6, 12→9, 13→15, 20→19, 100→99, 1000→999, 1026→1049, 1075→1099; `calculateShootingPrice` Defaults (90min/15imgs): no-flatrate/no-discount→{449,449,0}, flatrate→{539,539,0}, 33%→{449,299,150}, 50%→{449,224,225}; `calc_images_per_hour='0'`→`Infinity` (**REVIEW**); `calcDuration=0`/`calcImages=0`; defaults 0→package 1.
**Acceptance:** ≥15 Tests. **Reviewer:** Rundung an allen 3 Bereichsgrenzen? Infinity-Bug dokumentiert? Beispielrechnungen korrekt?

---

## 8. E2E-Edge-Cases (P3) · E2E-01
> **Guideline-Alignment (STRIKT):** Kein API-Mocking via `page.route`; kein `waitForResponse`/Netzwerk-Codes; UI-First via `toBeVisible`/`toPass`; semantisches Locator-Scoping über Landmarks (`page.locator('main')...`); role/text-basierte Locators; 100% Isolation (`E2ESessionHelper`, kein `serial`); mobile Viewports; SRP.

**Bestand:** `frontend/tests/e2e/**` (31 Specs), Helper in `tests/e2e/helpers/` (`AuthHelper`, `E2ESessionHelper`, `ModalHelper`, `SidebarHelper`, `UploadHelper`, `GalleryHelper`, `MailpitHelper`, `CreditCardHelper`, `FormHelper`, `NetworkHelper`). Helper wiederverwenden (POM/DRY).
**Aufgabe (P3):** gezielt echte Edge-Case-Specs ergänzen (keine Fläche verdoppeln). Vorschläge (je eigener Task im Review-Loop):
1. **Leer-/Grenz-Zustände (echt):** leerer Warenkorb beim Checkout, Galerie ohne Fotos, Suche ohne Treffer → korrekte Empty-State-UI.
2. **Form-Validierung:** native HTML5-Pflichtfelder vollständig füllen; ungültige Eingaben → clientseitige Fehler sichtbar; `imagesPerHour step="1"`.
3. **Berechtigungs-Grenzen (IDOR, echt via unauth. User):** nicht-Admin öffnet Admin-Route (direkte URL)→Umleitung/403; Fotograf ohne Galerie-Recht→keine Bilder.
4. **Assertion-Dichte erhöhen** in 3–4 bestehenden Specs (`gallery-modals`, `ecommerce`, `downloads`): zusätzliche role-basierte/ARRIA-Erwartungen via `getByRole`, sichtbare Status nach Action.
5. **Accessibility-Stichprobe:** `await expect(locator).toHaveAccessibleName()` auf primäre Buttons/Icons in 2–3 Specs.
6. **Mobile Viewport:** Lazy-Load-Bilder via `scrollIntoViewIfNeeded()` vor `naturalWidth`-Check (§1 Guidelines).
**Acceptance pro Spec:** `npm run test:e2e` grün, keine Flakiness, alle Guidelines eingehalten. **Reviewer:** echte Edge-Fälle (kein Mocking)? Helper genutzt? UI-First-Assertions?

---

## 9. Fortschritts-Tabelle
Gespiegelt in `AGENTS.todo.md`. Pro abgeschlossenem Paket: ID, Status (☐/☑), Implementierer, Reviewer, Loops, Bemerkung/gefundene Fixes.

| ID | Status | Impl. | Rev. | Loops | Bemerkung |
|---|---|---|---|---|---|
| BK-00 | 🟡 | haiku | | | impl läuft (Model-Factories) |
| BK-01 | ☐ | | | | |
| BK-02 | ☐ | | | | |
| BK-03 | ☐ | | | | |
| BK-04 | ☐ | | | | |
| BK-05 | ☐ | | | | |
| BK-06 | ☐ | | | | |
| BK-07 | ☐ | | | | |
| BK-08 | ☐ | | | | |
| BK-09 | ☐ | | | | |
| BK-10 | ☐ | | | | |
| FE-00 | 🟡 | haiku | | | impl läuft (Vitest-Setup) |
| FE-01 | ☐ | | | | |
| FE-02 | ☐ | | | | |
| FE-03 | ☐ | | | | |
| FE-04 | ☐ | | | | |
| E2E-01 | ☐ | | | | |

---

## 10. Verification (Ende-zu-Ende)
**Backend:** `docker compose -f docker-compose.test.yml up -d`; `php ./vendor/bin/phpunit` (gesamt grün) + je Paket-Datei. Abbruchkriterien: neue Tests grün **und** bestehende Suite weiterhin grün.
**Frontend:** `npm run test:run` (Vitest); `npm run build && npm run lint` unbeeinträchtigt; E2E `npm run test:e2e -- tests/e2e/<spec>`.
**Abnahme Gesamt:** §9 vollständig ☑; alle `REVIEW`-Marker als Bug-Fix-Ticket gelöst oder bewusst akzeptiert (mit Begründung); pro Service Invarianten in `features/<bereich>/` gepflegt.
