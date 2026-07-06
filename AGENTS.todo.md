# Task Board — Portal Reisinger Pictures

## Code Review Report (2026-07-06) — Vollständiges Codebase-Audit

Schwerpunkt: echte Bugs (Logik, Concurrency, Geldfluss, Auth/Brand-Isolation). Style wird nicht bewertet.
Findings pro Thema; Severity: **C**ritical / **H**igh / **M**edium / **L**ow.

### Thema 1 — Backend Services & Geldfluss

| # | Sev | Ort | Befund |
|---|-----|-----|--------|
| B-01 | **H** | `app/Services/CheckoutService.php:34,47` + `app/Services/CouponService.php:139` | **Coupon-Lock läuft außerhalb der Transaktion.** `resolveCoupon()` ruft `lockAndRevalidateCoupon()` (mit `lockForUpdate()`) vor `DB::transaction()` auf. Ohne aktive Transaktion gibt MySQL die Sperre sofort nach dem SELECT wieder frei → zwei parallele Checkouts mit gleichem Coupon passieren beide die Usage-Limit-Prüfung, bevor `incrementUsage()` (innerhalb der TX) rennt. **Szenario:** begrenztes Coupon (z.B. "3× nutzbar") → 5+ parallele Käufe überziehen das Limit. **Fix:** `resolveCoupon()` (bzw. nur `lockAndRevalidateCoupon`) innerhalb der `DB::transaction` ausführen. |
| B-02 | **H** | `app/Pricing/ScopeLicensingStrategy.php:55-60` + `app/Services/CheckoutService.php:36-39,50,214-217` | **Rabatt wird für Scope-Strategie nicht angewendet, Nutzung aber verbraucht.** `ScopeLicensingStrategy` (Default, RP-Brand) gibt immer `discountCents:0`/`couponId:null` zurück — wendet also KEIN Coupon an. Dennoch validiert `CheckoutService::resolveCoupon()` den Coupon und `createOrder()` zählt `incrementUsage` + schreibt `coupon_id`/`coupon_discount_cents` auf die Order. **Szenario:** existiert ein RP-Brand-Coupon, zahlt der Kunde den vollen Preis, aber der Coupon-Verbrauch zählt. Diskrepanz zwischen Rechnungssumme und gespeichertem Rabatt. Vermutlich "by design" SRP-only — dann MUSS `resolveCoupon`/`incrementUsage` für die Scope-Strategie übersprungen werden, sonst Datenmüll. **Klärung nötig.** |
| B-03 | **M** | `app/Services/CheckoutService.php:259-261` | **Stripe PaymentIntent wird NACH commit der TX erzeugt.** Schlägt die Stripe-Anfrage fehl, bleibt die Order mit Status `pending_payment` und leerem `stripe_payment_intent_id` stehen — keine Compensation/Mail. **Szenario:** Stripe-Timeout → verwaiste Order, Kunde sieht "pending", kein PaymentIntent existiert. Wiederherstellung nur durch Admin-Eingriff. |
| B-04 | **M** | `app/Services/CheckoutService.php:149-168` (`buildLineItems`) | **Index-Kopplung requestItems↔pricingResult.** `$requestItems[$idx]` nimmt an, die Pricing-Strategie liefert Items in identischer Reihenfolge zurück. Aktuell erfüllt (beide Strategien pushen in Input-Reihenfolge), aber jede künftige Strategie, die filtert/sortiert, verschiebt stillschweigend `useCaseId`/`tier`/`notes` zu falschen Photos. Fragil — besser über `itemId` matchen statt Index. |
| B-05 | **M** | `app/Services/PayoutCalculationService.php:95-106` | **`firstOrNew` + save überschreibt/akkumuliert nicht idempotent.** `calculatePoolShares` holt `firstOrNew(user/month/year)` und addiert mit `bcadd` auf `total_shares_earned`/`pool_earnings_cents`. Wird der Job für denselben Monat zweimal geroutet (oder nach Teil-Fehler erneut), werden Shares **doppelt** addiert — es gibt keine Idempotenz-Markierung pro Run. **Szenario:** Cron-Fehler-Wiederanlauf → Photographen-Auszahlung zu hoch. Gleiches gilt für `calculatePowerUserDelta` (Zeile 158-162). |
| B-06 | **L** | `app/Services/SlugService.php:27-33` | `LIKE "{$slug}%"` zählt auch unzusammenhängende Slugs ("foo-bar" matcht bei Basis "foo") → nicht-fortlaufende Suffixe. Kein echter Bug, nur kosmetisch (Lücken in Numerierung). Zudem TOCTOU zwischen count und insert (unique-Constraint fängt ab). |

### Thema 2 — Backend Controller & Auth

| # | Sev | Ort | Befund |
|---|-----|-----|--------|
| B-07 | **H** | `app/Http/Controllers/SitemapController.php:20,45-47` | **Sitemap verletzt Brand-Isolation.** Weder `galleries()` noch `images()` filtern nach `BrandRegistry::current()`. `Gallery::where('is_public', true)` liefert öffentliche Galerien ALLER Brands, ausgeliefert unter der jeweiligen Brand-Domain. **Szenario:** SRP-Sitemap (`buy.…/sitemap-galleries.xml`) listet B2B-Galerien mit SRP-Domain → Broken Links + SEO-Cross-Contamination; analog Bilder-Sitemap. Endpunkte sind öffentlich (kein Auth). Zudem fehlt Expiry-Filter (`expires_at`). **Fix:** `->where('brand', BrandRegistry::current())` + Expiry-Bedingung wie in `SearchController`. |
| B-08 | **M** | `app/Http/Controllers/QuoteController.php:49` + `app/Services/QuoteLinkService.php:26,43` | **Query-Param-Mismatch Quote-Link.** `generateQuoteLink` baut URL mit `?quote_token=…`, `decode()`/`QuoteController` lesen aber `$request->query('token')` bzw. `extractTokenFromRequest` liest Key `token`. Token wird also serverseitig nicht gefunden, außer das Frontend mappt selbst. Falls Frontend das tut → tot. Verifizieren, welcher Kontrakt gilt, dann konsistent auf EINEN Parameternamen einigen. |
| B-09 | **M** | `app/Http/Controllers/AuthController.php:125-135` | **Brand-Check erst NACH Passwort-Change.** In `resetPassword` wird das Passwort geändert (125) und Token gelöscht (128), bevor der Brand-Mismatch-403 kommt (131). **Szenario:** Benutzer öffnet Reset-Link auf falschem Portal (manipulierte URL) → Passwort erfolgreich geändert, dann 403. Datenmäßig legal (eigenes Passwort), aber verwirrend + falsche Reihenfolge. Brand-Check vor Mutation. |
| B-10 | **M** | `app/Http/Controllers/WebhookController.php:84,93` | **`where('stripe_payment_intent_id', null)` matcht alle intent-losen Orders.** `charge.dispute.created`/`charge.refunded` mit `$piId = $dispute->payment_intent ?? null`. Ist `$piId` null (selten, aber möglich bei Legacy-Charges), wird `where(col, null)` in Laravel zu `IS NULL` → **alle** Orders ohne Payment-Intent werden auf `disputed`/`refunded` gesetzt. Null-Guard vor dem Query nötig. |
| B-11 | **M** | `app/Http/Controllers/WebhookController.php:59-79` | **Keine Replay-Idempotenz für `payment_intent.succeeded`.** Schutz ist nur `if ($order->status !== 'paid')`. Wird dasselbe Event mehrfach zugestellt (Stripe tut das), ist das无害 für Status — aber `Mail::queue(new InvoiceMail)` ist nicht durch Status geschützt und **Mail::queue erzeugt pro Event einen neuen Job** → doppelte Rechnungsmails bei Retry. Zusätzlich fehlt webhooks-Endpoint Idempotency-Key-Logging. |
| B-12 | **M** | `app/Http/Controllers/ContractCloseService.php:46-51,63` | **Order ohne `user_id` + Steuersatz-Inkonsistenz.** `Order::create([...])` setzt kein `user_id` → Contract-Order hat keinen User. `downloadOrderZip` (`DownloadController:289`) filtert aber `where('user_id', $user->id)` → Contract-Lieferung via Order-Zip nicht möglich. Zudem: `tax_rate => 20` + `total_net = gross/1.2` hier, während `CheckoutService` mit `tax_rate => 0` arbeitet. Bewusst? Konsistenz prüfen. |
| B-13 | **L** | `app/Http/Controllers/InviteController.php:122-126` | Bei Gästen mit `guestEmail`, das zu einem existierenden User **ohne Passwort und nicht admin** gehört, wird der Gast-Flow weiter ausgeführt (Token mit dieser Email). Realer Schaden gering (User kann sich ohnehin nicht einloggen), aber Login-Vereinheitlichung empfehlenswert. |
| B-14 | **L** | `app/Http/Middleware/ManagementMiddleware.php:26` | Photographen dürfen `api/management/tenants*` → `TenantController::index` gibt für Photographen **alle** Tenants zurück (Namen, User-Counts). Falls nur für Assignment-UI gedacht: ok; sonst Information Disclosure. |

### Thema 3 — Models, Migrations, Pricing

| # | Sev | Ort | Befund |
|---|-----|-----|--------|
| B-15 | **L** | `app/Models/Photo.php:125-130` (`getFilenameAttribute`) | Filename-Extension wird aus `mime_type` abgeleitet (Default jpg). Upload (`ImageController:55-56`) nutzt aber `$file->extension()` (client-seitig). Stimmen mime_type (DB) und tatsächliche Extension (Disk) nicht überein (z.B. PNG gespeichert, mime als image/jpeg), löst das abgeleitete Attribut `.jpg` aus → Datei auf Disk heißt `.png`, gesucht wird `.jpg` → 404 beim Download. Prüfen, ob mime_type verlässlich persistiert wird. |
| B-16 | **L** | `app/Models/User.php:131-154` (`hasPurchasedPhoto`) | Lädt je Download-Check alle Orders des Users + Snapshots und iteriert. Bei kaufstarken Kunden O(n·m). Eher Performance-Hinweis als Bug. |
| B-17 | info | `database/migrations/V018__…:165` | `coupon_user_usage.used_count` hat `default(0)` → `updateOrCreate()->increment()` ist korrekt (kein NULL-Bug). Nur zur Info, da im Raum stand. |

**Zusammenfassung Backend:** 2× High (B-01 Coupon-Race, B-02 Coupon-vs-Strategie), 1× High (B-07 Sitemap-Brand-Leak), mehrere Medium. B-01/B-02/B-07 priorisieren.

> **Korrektur zu B-08 (nach Frontend-Analyse):** KEIN Bug. `ClientCartView.tsx:74,78` liest `quote_token` aus der URL und sendet es als `token` an `/api/orders/quote-decode`. Das Frontend übersetzt also korrekt. Einzig `QuoteLinkService::extractTokenFromRequest` ist tote Methode (Low, aufräumen).

### Thema 4 — Frontend Logic & UI

| # | Sev | Ort | Befund |
|---|-----|-----|--------|
| F-01 | **H** | `frontend/src/ui/client/ClientCartView.tsx:86-96` + `backend/app/Services/CheckoutService.php` + `app/Pricing/*` | **Quote-Link-Preis wird beim Checkout nicht honoriert.** Das decodierte Angebot setzt items als `isQuote: false` mit `price = data.price / data.photos.length` (Frontend verhält für die payable-SOLL korrekt). Aber die Backend-Strategien nutzen das request-`price` **gar nicht** — sie berechnen den Preis neu (Volume: `count × tierPrice`; Scope: useCase/modifier). **Szenario SRP:** Fotograf sendet Angebot über 50 € für 5 Bilder → Kunde sieht 10 €/Bild → Checkout berechnet 5 × 30 € = 150 €. **Szenario RP:** items haben keinen `useCaseId` → `ScopeLicensingStrategy::calculateSingleItem` ruft `LicenseUseCase::findOrFail('')` → **500**. **SOLL (Architekt 2026-07-06, final):** Proactive Quote = zahlbarer Festpreis via Standard-Cart-Flow; die Backend-Strategien müssen den Token-Preis durchreichen (Custom-Price-Passthrough), zusätzlich müssen die ausgehandelten **Custom Conditions** in die Auslieferung (EXIF/XMP + Lizenz-PDF) einfließen. Fix-Richtung: Custom-Price-Pfad in `CheckoutService`/Strategien, NICHT `isQuote:true`. Siehe `features/ecommerce/03-custom-quotes-and-stripe.md` §1.B. |
| F-02 | **M** | `frontend/src/logic/useVolumeLicensing.ts:95` (`calculateVolumeTotal`) | **SRP-Gesamtsumme im Frontend zählt Quote-Items mit.** `count = items.length` multipliziert alle Items (inkl. Quote) mit dem Tier-Preis, obwohl der Kommentar das Gegenteil behauptet und das Backend nur Nicht-Quote-Items summiert. **Wirkung begrenzt**, weil `CartItemList` bei `hasQuotes` die Summe als `--- €` maskiert — wenn gemischter Warenkorb, stimmt die **abgeleitete `totalAmount`** im Context trotzdem nicht (verwendet z.B. für Validierung/Anzeige in anderen Konsumenten). Backend rechnet korrekt → kein Geldfehler, aber inkonsistente Display-Werte. Fix: `count = items.filter(i => !i.isQuote).length`. |
| F-03 | **L** | `frontend/src/logic/useInvoiceDraft.ts:214` vs `usePdfExtraction.ts:43,50` | Preis-Codierung zwischen Speichern (`×100`) und Laden (`÷100`) ist konsistent — kein Bug. Nur notiert, da der Pfad fehleranfällig ist (Zukunft: `discount_percent` in Basis-Points, `item` in Cent). |
| F-04 | **L** | `frontend/src/api.ts:137` (`apiMutate`) | Bei leerer Response mit `Content-Type: application/json` wird `text ? JSON.parse : {}` zurückgegeben — bei 204/leerem Body korrekt. Kein Bug, nur Hinweis: `fetcher` (Zeile 96-101) wirft bei nicht-JSON, `apiMutate` nicht — asymmetrisch, kann Konsumenten verwirren. |
| F-05 | **L** | `frontend/src/App.tsx:90` | `/galleries/*` ist nicht von `ProtectedRoute` umschlossen. Zugriff auf nicht-öffentliche Galerien wird zwar API-seitig per 401/403 abgelehnt, aber die Route rendert die Komponente (und ggf. Lade-Skeletons) auch für unauthentifizierte User. Bewusst (public galleries)? Wenn ja ok. |

**Zusammenfassung Frontend:** 1× High (F-01 Quote-Link-Preis ignored/500), 1× Medium (F-02 Volume-Count). F-01 ist der kritischste Frontend-Fund und eng mit B-02 verwandt (Pricing-Strategien nutzen Cart-Preise nicht).

### Thema 5 — Frontend UI (Contracts/Stripe/Autocomplete)

| # | Sev | Ort | Befund |
|---|-----|-----|--------|
| F-06 | **M** | `frontend/src/ui/ContractSignView.tsx:204` | **Falsche Altersberechnung.** `age = new Date().getFullYear() - birthDate.getFullYear()` ignoriert Monat/Tag → bei noch nicht erfolgtem Geburtstag im laufenden Jahr ist das angezeigte Alter um 1 zu hoch. Backend `AgeHelper::calculate` nutzt korrekt `Carbon::diffInYears`. In einem Vertrags-/Altersverifikationskontext relevant (Minderjährige). Fix: vollständige Datums-Differenz wie im Backend. |
| F-07 | **L** | `frontend/src/ui/client/components/StripeCheckoutForm.tsx:50-82` | **Webhook-Polling bei `processing`.** Pollt 15× `/api/orders` (ganze Liste, nicht die Einzel-Order) im Sekundentakt. Bei Queue-Rückstau → `onSuccess(false)` → "Webhook fehlt"-Toast, obwohl Zahlung bei Stripe durchgeht und Webhook nur verzögert kommt. Kein Geldverlust (Webhook setzt Status später auf `paid`), aber irreführende UX. Lieber Einzel-Order-Poll `/api/orders/{id}` + längeres Intervall. |
| F-08 | **L** | `frontend/src/ui/client/components/StripeCheckoutForm.tsx:83-85` | `else`-Zweig (PaymentIntent-Status weder succeeded/processing noch error) setzt nur `isProcessing=false` — ohne Feedback. Kunde sieht stummen Form-Reset. Seltener Edge-Case. |

---

## Review-Synthese — Priorisierung

**Sofort (Critical/High, Geld oder Security):**
1. **F-01** — Quote-Link-Festpreis wird nicht honoriert (SRP: Standardpreis statt Angebot; RP: 500er). SOLL geklärt 2026-07-06: zahlbarer Festpreis via Standard-Cart-Flow + Custom Conditions in der Auslieferung. Fix = Custom-Price-Passthrough in `CheckoutService`/Strategien (Backend liest Preis aus verifiziertem Token, nicht aus Request-Body). Siehe `features/ecommerce/03-custom-quotes-and-stripe.md` §1.B.
2. **B-02** — Coupons SRP-exklusiv (by design, geklärt 2026-07-06); `CheckoutService` MUSS `resolveCoupon`/`incrementUsage` für `ScopeLicensingStrategy` (RP) überspringen. Siehe `features/ecommerce/08-srp-coupon-system.md` §3.
3. **B-01** — Coupon `lockForUpdate` außerhalb Transaktion → Race Condition, Coupons überziehbar. Fix: `resolveCoupon`/`lockAndRevalidateCoupon` in die `DB::transaction` ziehen.
4. **B-07** — Sitemap ohne Brand-Filter → Cross-Brand-SEO-Leak öffentlich. Fix: `->where('brand', current())` + Expiry-Filter.

**Bald (Medium):**
- B-03 (Stripe nach TX-Commit), B-05 (Payout-Idempotenz), B-08→korrigiert, B-09 (Brand-Check-Reihenfolge), B-10 (Webhook null-PI), B-11 (Webhook-Replay-Mails), B-12 (Contract-Order ohne user_id), F-02 (Volume-Count), F-06 (Alter).

**Kosmetisch (Low):** B-04, B-06, B-13, B-14, B-15, B-16, F-04, F-05, F-07, F-08.

**Statistik:** 24 Funde — 0 Critical, **4 High** (B-01, B-02, B-07, F-01), 9 Medium, 11 Low/Info. Keine RCE/Auth-Bypass gefunden; Auth/Guards sind solide (Default-Guard=api, Policies + Management-Middleware konsistent). Brand-Isolation ist überall umgesetzt **außer** in Sitemap (B-07). Geldfluss ist solide bis auf Coupon-Race (B-01) und Quote-Preis (F-01).

**DoD-Hinweis:** Für jeden Fix ist laut AGENTS.md §0 ein Regression-Test zu schreiben (B-01: parallel-Coupon-Test; F-01: E2E Quote-Checkout; B-07: Sitemap-Brand-Test).

---

## Fix-Backlog aus Code-Review 2026-07-06 (nur Tasks, keine Umsetzung)

Jeder Task: Dateien + Fix + Pflicht-Test (§0 DoD). Reihenfolge = Priorität. Keine Migrationen nötig (keine Schema-Änderungen).

### Tier 1 — High (Geld/Security)

#### FIX-1: F-01 — Custom-Price-Passthrough für Quote-Links
**SOLL:** `features/ecommerce/03-custom-quotes-and-stripe.md` §1.B (zahlbarer Festpreis via Standard-Cart + Custom Conditions in Auslieferung).
- [ ] **Backend — Preis aus verifiziertem Token, nicht aus Request-Body.**
      - `app/Http/Controllers/CheckoutController.php`: `coupon_code`-Logik bleibt; zusätzlich `quote_token` aus Request entgegennehmen.
      - `app/Services/CheckoutService.php`: wenn `quote_token` vorhanden → `OfferTokenService::verify()` → bei `null`: 422 "Angebot abgelaufen/ungültig". Bei Erfolg: **Custom-Price-Pfad** — Strategy überspringen, `totalNetCents = token.price`, `lineItems` pro Photo aus Token bauen.
      - `app/Pricing/PricingStrategy.php` (Contract): optional `supportsCustomPrice(): bool` — oder eigener Branch im CheckoutService.
      - `app/Services/CheckoutService.php::createInvoiceSnapshot`: `customer_details.custom_conditions` = token.rights (territory/duration/exclusivity/Freitext) persistieren.
      - **Security:** Preis darf NIEMALS aus `request.items[].price` kommen, immer aus verifiziertem Token.
- [ ] **Backend — Custom Conditions in Auslieferung.**
      - `app/Http/Controllers/DownloadController.php::injectMetadata`: wenn Photo zu Order mit `custom_conditions` gehört → EXIF `UsageTerms`/`Rights`/`SpecialInstructions` = custom conditions (statt Standard-AGB). Order-Lookup via `hasPurchasedPhoto`-Pfad erweitern oder eigenen Delivery-Context.
      - `app/Services/ManualInvoiceService.php` / Invoice-View: `custom_conditions`-Block in Lizenz-PDF rendern, wenn vorhanden.
- [ ] **Frontend — Token durchreichen.**
      - `frontend/src/ui/client/ClientCartView.tsx:74-103`: `quote_token` nach Decode **nicht** verwerfen, sondern in State/Ref halten und im `onCheckout`-Payload (`/api/orders/checkout`) als `quote_token` mitsenden. Cart-Items weiterhin als `isQuote:false` mit Display-Preis (kosmetisch).
- [ ] **Tests:**
      - **PHPUnit Feature** `tests/Feature/QuoteLinkCheckoutTest.php` (neu): (a) Admin generiert Quote-Link via `QuoteController::generateQuoteLink`; (b) Checkout mit `quote_token` → Order-Total == Token-Preis (NICHT `tier1Price×count`); (c) Invoice-Snapshot enthält `custom_conditions`; (d) abgelaufener/manipulierter Token → 422; (e) RP-Brand: kein 500 (Strategy übersprungen).
      - **E2E Playwright** `tests/e2e/client/quote-checkout.spec.ts` (neu) `{tag:['@feature:quote','@regression']}`: Fotograf erzeugt Angebot → Kunde öffnet Link → Cart zeigt Festpreis → Stripe-Checkout (Visa `4242…`) → Orderstatus `paid`, Rechnung enthält Custom Conditions.

#### FIX-2: B-02 — Coupons SRP-exklusiv (Redemption)
**SOLL:** `features/ecommerce/08-srp-coupon-system.md` §3.
- [ ] **`app/Services/CheckoutService.php`:** `resolveCoupon()` + `incrementUsage` nur ausführen, wenn Strategy Coupons unterstützt. Sauberste Variante: `PricingStrategy`-Contract um `supportsCoupons(): bool` erweitern (`VolumeLicensingStrategy: true`, `ScopeLicensingStrategy: false`). In `createOrder`/`processCheckout` entsprechend gaten.
- [ ] **`app/Http/Controllers/CouponController.php::validateCoupon`:** frühzeitig `Brand === SRP`-Guard (RP → `{valid:false}` ohne DB-Zugriff), analog Management-UI-Logik.
- [ ] **Tests:**
      - **PHPUnit** `tests/Feature/CouponScopeTest.php` (erweitern oder neu): (a) SRP + gültiger Coupon → Discount + `used_count`+1; (b) RP + Coupon-Code im Checkout → kein Discount, `used_count` unverändert, `orders.coupon_id` null; (c) `POST /api/coupons/validate` auf RP → `{valid:false}`.
      - **E2E** `{tag:['@feature:coupon','@smoke']}`: Coupon auf RP-Host im Checkout → Hinweis "nur auf buy.reisinger.pictures".

#### FIX-3: B-01 — Coupon-Sperre in Transaktion
- [ ] **`app/Services/CheckoutService.php`:** `lockAndRevalidateCoupon()` (der `lockForUpdate`-Teil aus `resolveCoupon`) IN die `DB::transaction` (Zeile 47-55) verlagern, nach `findValidCoupon` (Read darf draußen bleiben). `incrementUsage` bleibt drin.
- [ ] **Tests:**
      - **PHPUnit** `tests/Feature/CouponConcurrencyTest.php` (neu): Coupon `max_uses_global=1`, zwei via `DB::beginTransaction` simulierte konkurrierende Checkouts → genau einer bekommt Discount+Increment, der andere 422. Alternativ: Assertions zur Call-Reihenfolge (Lock vor Increment in gleicher TX).

#### FIX-4: B-07 — Sitemap Brand-Isolation + Expiry
- [ ] **`app/Http/Controllers/SitemapController.php`:**
      - `galleries()`: `Gallery::where('is_public', true)->where('brand', BrandRegistry::current())` + `->where(fn($q)=>$q->whereNull('expires_at')->orWhere('expires_at','>',now()))`.
      - `images()`: gleiche Brand-/Expiry-Filter via `whereHas('gallery', …)`.
- [ ] **Tests:**
      - **PHPUnit** `tests/Feature/SitemapControllerTest.php` (erweitern): (a) Request mit SRP-Host (`request->headers->set('Host','buy.test')`) → nur SRP-Galerien im XML; (b) RP-Host → nur RP; (c) abgelaufene Galerie nicht enthalten; (d) B2B-Galerie taucht nicht in SRP-Sitemap auf.

### Tier 2 — Medium

#### FIX-5: B-03 — Stripe-PI-Fehler-Compensation
- [ ] **`app/Services/CheckoutService.php::respondBasedOnPayment`:** PaymentIntent-Erzeugung wrappe try/catch; bei Stripe-Fehler Order auf Status `cancelled` (oder neu `payment_init_failed`) + `Mail` an Accounting + 502 ans Frontend. Order nicht als `pending_payment` verwaisen lassen.
- [ ] **Test (PHPUnit):** Mock `StripePaymentService::createPaymentIntent` wirft → Order `cancelled`, Mail queued, Response 502.

#### FIX-6: B-05 — Payout-Idempotenz
- [ ] **`app/Services/PayoutCalculationService.php`:** vor `firstOrNew`+Addition prüfen, ob für `(pool_id, user_id)` bereits eine Abrechnung existiert (Marker z.B. `payout_calculations`-Tabelle mit `pool_id`+`user_id`-Unique, oder `PhotographerStatement.locked_at`). Bereits gesperrte Statements nicht erneut akkumulieren. Gleiches für `calculatePowerUserDelta`.
- [ ] **Test:** `calculatePoolShares` zweimal für gleichen Monat → identische Summen (keine Doppelung).

#### FIX-7: B-09 — Brand-Check vor Passwort-Mutation
- [ ] **`app/Http/Controllers/AuthController.php::resetPassword`:** Brand-Mismatch-Check (Zeile 131) VOR `Hash::make`/Token-Löschung (Zeile 125/128) ausführen.
- [ ] **Test (PHPUnit):** Reset auf falschem Portal → 403 UND Passwort in DB unverändert.

#### FIX-8: B-10 — Webhook null-PI-Guard
- [ ] **`app/Http/Controllers/WebhookController.php:84,93`:** `if ($piId === null) { Log::warning(...); return response()->json(['status'=>'success']); }` vor dem Order-Lookup.
- [ ] **Test:** Dispute-Event mit `payment_intent=null` → keine Order wird `disputed`.

#### FIX-9: B-11 — Webhook-Replay-Mails
- [ ] **`orders`-Spalte `invoice_sent_at` (nullable)** (Migration V028 — Hinweis: konsolidieren, da < V025-Grenze nicht zutrifft; V025+ wird ohnehin zu einer konsolidierten Migration zusammengefasst, §3). Webhook setzt `invoice_sent_at=now()` nur wenn noch null; `Mail::queue` nur wenn vorher null. Alternativ ohne Spalte: Reihenfolge-Check an `Order.updated_at` (weniger robust).
- [ ] **Test:** `payment_intent.succeeded` zweimal senden → exakt eine InvoiceMail gequeued.

#### FIX-10: B-12 — Contract-Order: user_id + Tax-Konsistenz (Entscheidung nötig)
- [ ] **Klärung:** Soll eine Contract-Schließung einen `Order`-Eintrag haben, den der Kunde via `downloadOrderZip` abholen kann? Wenn ja: `Order.user_id` setzen (aus `billing_details.email` → User-Lookup, oder Signer). Tax: einheitlich — entweder `tax_rate=0` (Kleinunternehmer, konsistent mit Shop) oder bewusst 20 % für B2B-Contracts (dann Doku + Frontend-Konsistenz).
- [ ] Nach Klärung: `app/Services/ContractCloseService.php` anpassen + Test.

#### FIX-11: F-02 — SRP-Volume-Count exkl. Quote
- [ ] **`frontend/src/logic/useVolumeLicensing.ts:95`:** `const count = items.filter(i => !i.isQuote).length;` (und `calculateVolumeTotal` entsprechend).
- [ ] **Test (Vitest):** `useVolumeLicensing.test.ts`: 5 Non-Quote + 3 Quote → Tier basiert auf 5, Total = 5 × tierPrice (nicht 8 ×).

#### FIX-12: F-06 — Alterberechnung ContractSignView
- [ ] **`frontend/src/ui/ContractSignView.tsx:204`:** vollständige Datums-Differenz (Backend `AgeHelper`-Logik: `floor((now - birth) under year/month/day)`). Am besten `logic/utils.ts`-Helper `calcAge(birth, ref)` + Vitest, im ContractSignView + ggf. ContractPdfService nutzen (Single Source of Truth).
- [ ] **Test (Vitest):** geb. 2010-12-31, ref 2026-01-01 → 15 (nicht 16).

### Tier 3 — Low / Cleanup (ein Batch, ggf. gemeinsam)
- [ ] **B-04:** `CheckoutService::buildLineItems` — Mapping über `itemId` statt Array-Index (robust gegen künftige Strategien, die sortieren/filtern).
- [ ] **B-06:** `SlugService::makeUnique` — Kollision-Check auf exakten Slug + numerierte Suffixe (`slug`, `slug-1`, …) statt `LIKE slug%`.
- [ ] **B-13:** `InviteController::redeem` — Gast-Email, die zu existierendem User ohne Passwort gehört: konsistent Login erzwingen statt Gast-Token.
- [ ] **B-14:** `ManagementMiddleware` — Photographen `tenants*` entfernen, falls nicht für Assignment-UI nötig; sonst in `TenantController::index` für Photographen auf Namens-/ID-Liste reduzieren.
- [ ] **B-15:** `Photo::getFilenameAttribute` — Extension verlässlich aus persistierter Quelle ableiten (Dateiname bei Upload mitspeichern oder mime konsistent setzen), damit Disk-Dateiname ≠ berechneter Name ausgeschlossen wird.
- [ ] **B-16:** `User::hasPurchasedPhoto` — pro Foto gecachte Lookup-Tabelle oder `whereJsonContains` auf Snapshot, statt alle Orders zu laden.
- [ ] **F-04:** `api.ts` — `fetcher`/`apiMutate` Non-JSON-Handling symmetrisieren (beide werfen oder beide `{}`).
- [ ] **F-05:** `App.tsx:90` `/galleries/*` — bewusst? Wenn ja Kommentar; sonst `ProtectedRoute` für nicht-öffentliche Zweige.
- [ ] **F-07:** `StripeCheckoutForm` — Polling auf Einzel-Order `/api/orders/{id}` + Intervall 2–3 s, Timeout 30 s.
- [ ] **F-08:** `StripeCheckoutForm` — `else`-Zweig mit Toast "Zahlung unvollständig — bitte erneut versuchen".
- [ ] **Dead Code:** `QuoteLinkService::extractTokenFromRequest` entfernen (ungnutzt nach B-08-Klärung).

### Abnahme
- Nach Tier 1: `php artisan test` + `pnpm vitest run` + `npx playwright test --grep "@smoke|@feature:quote|@feature:coupon"` grün.
- Vor Deployment: volle E2E-Suite (`npx playwright test`), Laufzeit aktuell ~7 min → Timeout 15 min (§5).




