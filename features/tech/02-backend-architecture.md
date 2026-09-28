---
domain: technical
topic: backend-architecture
status: active
---

# Technical Concept: Backend Architecture

## 1. Stateless API & Processing
- The backend serves exclusively as a stateless JSON API.
- Most request-path processing (including ExifTool) remains synchronous to keep local infrastructure simple. Production mail, durable cleanup, and selected indexing work use the supervised database queue described in the [Production Operations Runbook](../infrastructure/29-production-operations-runbook.md); local and CI fixtures intentionally use `sync` jobs.
- **Fail Fast:** File uploads are strictly validated before touching the disk. Corrupt files yield a 422 error.

## 3. Database Access (Eloquent Only)
- **Strict Eloquent Rule:** The use of the `DB` facade (e.g., `DB::table('...')->insert()`) is strictly forbidden for standard business logic (unless handling highly complex legacy aggregations where Eloquent fails). All database insertions and updates MUST use Eloquent Models to ensure events, UUID traits, casts, and mutators are triggered correctly.

## 2. On-The-Fly Delivery
- **Zip-Streaming:** Full gallery ZIPs are not pre-calculated. They are streamed on-the-fly directly to the client via `maennchen/zipstream-php`.
- **Benefits:** This saves massive amounts of storage space, drastically improves the Time To First Byte (TTFB), and increases perceived interactivity for the user.

## 4. Money Pattern (Fowler) — Einheitenvertrag

> **Diese Sektion ist die einzige Quelle der Einheitenregel im Repo.** Jedes
> andere Dokument, das eine Geldeinheit nennt, **verweist hierher** und ergänzt
> nur den feldspezifischen Beleg. Eine zweite, abweichend formulierte Fassung
> dieser Regel ist ein Fehler — genau die Doppelung, die vor diesem Vertrag
> Inkonsistenz produziert hat (Befund und Begründung:
> `../infrastructure/28-settings-key-meaning.md`, Abschnitt „Warum das ein
> geschriebener Vertrag ist").

Beschlossen vom Owner am **2026-09-28**.

> **Zeilennummern, die einen Zustand „in Arbeit" beschreiben**, beziehen sich
> auf `82e8d17`, den letzten Commit **vor** der Cent-Umstellung. Betroffen sind
> alle Belege zu `frontend/src/logic/shootingCalculator.ts`,
> `frontend/src/ui/management/components/CalculatorSettingsCard.tsx` und
> `CouponService.php`. Im Arbeitsverzeichnis sind sie womöglich schon
> verschoben, weil die Umstellung parallel läuft. Belege aus
> `database/migrations/` und aus stabilen Backend-Dateien sind nicht betroffen.
>
> **Historische Zeilennummern** sind mit dem obigen Hinweis gemeint.
>
> **Belege hier sind zitiert, nicht nur nummeriert** (AGENTS.md § 3, Belegregel 1):
> jede Zeilennummer nennt das Zitierte daneben — den Feldnamen, den
> Konfigurationsschlüssel, den Variablennamen — damit ein Leser sie prüfen kann,
> ohne die Datei zu öffnen.

1. **Jeder Geldbetrag ist Cent — im Speicher und in der API, ausnahmslos,
   auch bei ganzzahligen Euro-Beträgen.** Es gibt keine Ausnahme der Form
   „dieser eine Betrag bleibt in Euro, weil er immer rund ist", und keine
   Begründung der Form „er ist praktisch immer ganzzahlig". Ein Client muss
   jeden Eurobetrag als Centbetrag behandeln können, **ohne das Feld zu
   kennen**. Die Währung ist fest auf EUR und wird nicht in der Datenbank
   gespeichert.

   *Bisheriger Wortlaut, den diese Nummer ersetzt:* „ausnahmslos mit ganzzahligen
   Cents" / „Alle Geld-Beträge (Preise, Warenkörbe, Rechnungen) werden als
   Cents (Integer) über die API gesendet und empfangen". Der Satz war richtig,
   aber er hatte keine Ausnahmenliste und keine Trennung von dem, was *nicht*
   Geld ist — deshalb war nicht entscheidbar, welche Felder er meint.

2. **Discriminator-abhängige Felder: ein Feld, und die Einheit definiert
   `type`.** Für ein Feld, dessen Einheit von einem Geschwister-Discriminator
   abhängt, gilt: **ein** Feld, und die Einheit wird durch den `type`-Wert
   bestimmt. Der **Geldzweig ist Cent** (Nummer 1). Der Nicht-Geld-Zweig
   behält seine eigene Einheit, und diese Einheit wird **bei sich selbst
   benannt** — „Prozentpunkte", „Basispunkte", „Hundertstel" — nicht als
   Geldbetrag. Ein Feldname, der eine Geldeinheit verspricht, während ein
   Zweig etwas anderes transportiert, ist keine Ausnahme von dieser Regel,
   sondern der Regelbruch, den Nummer 5 beschreibt. Ein Feld, dessen Einheit
   nur im Code steht, ist ebenfalls kein Sonderfall, sondern Regelbruch.

   *Belegte Fälle:* `coupons.value` (Euro bei `fixed`, Prozentpunkte bei
   `percentage`, `backend/app/Services/CouponService.php:224` und `:231`) und
   `contracts.discounts[].price` (Cent bei `discount_fixed`, Basispunkte bei
   `discount_percent`, `backend/app/Services/ContractPricingService.php:27-28`).
   Bei `coupons.value` ist der Feldname **selbst** das Problem: `10` bedeutet
   beim einen Coupon 10 €, beim anderen 10 %, und nichts am Feld sagt es. Die
   Einheitentabelle in `../infrastructure/28-settings-key-meaning.md` und die
   Schemazeile in `../ecommerce/08-srp-coupon-system.md` § 7 nennen sie deshalb
   am Feld.

3. **Die Ausnahmen sind abschließend, und keine davon ist Geld.** Die folgenden
   Größen werden **nicht** mit „alles Integer" umgestellt; sie tragen eine
   andere, jeweils eigene Einheit. Diese Liste ist Teil des Vertrags: was hier
   nicht steht, ist nach Nummer 1 ein Centbetrag.

   | Feld / Gruppe | Einheit | Beleg |
   |---|---|---|
   | `calc_flatrate_multiplier` | dimensionsloser Faktor | `backend/database/seeders/DatabaseSeeder.php:163` → `'1.2'` |
   | `mult_commercial`, `mult_unlimited`, `mult_international` | dimensionslose Faktoren | `DatabaseSeeder.php:151-153` → `'2.0'` / `'1.5'` / `'1.5'` |
   | `calc_images_per_hour`, `calc_outdoor_images_per_hour` | Anzahl Bilder pro Stunde | `DatabaseSeeder.php:161-162` → `'6'` / `'8'`; `frontend/src/logic/shootingCalculator.ts:68,75` `parseInt` |
   | `license_modifiers.percent_surcharge` | Prozentpunkte | `backend/database/migrations/V010__rsv_licensing.php:28` `decimal(8,2)`, `:42` → `100.00` |
   | `invoice_snapshots.tax_rate` | Prozentpunkte | `V004__ecommerce_and_governance.php:85` `decimal(5,2)`; `backend/app/Services/CheckoutService.php:1594` schreibt `null` |
   | `payout_pools.photographer_share_percent` | Prozentpunkte | `V011__payout_system.php:19` `integer`, Default `50` |
   | `payout_pools.total_shares`, `photographer_statements.total_shares_earned` | Anzahl (Share), `decimal(12,4)` | `V011__payout_system.php:21,32` |
   | `contracts.discounts[].price` bei `type=discount_percent` | Basispunkte (10 % = 1000) | `backend/app/Services/ContractPricingService.php:27-28` `PERCENT_SCALE = 10000` |
   | `coupons.value` bei `type=percentage` | Prozentpunkte | `backend/app/Services/CouponService.php:231` |
   | manueller Rechnungsmengen `qty` | Hundertstel einer Einheit | `frontend/src/logic/contractPricing.ts:3` `CONTRACT_SNAPSHOT_SCALE = 100` |

   `qty` ist der heikelste Fall: es hat **dieselbe Skala wie Cent** und eine
   andere Bedeutung. Gemeinsame Skala ist kein gemeinsames Feld.

4. **API-Typisierung: Geld als Integer, alles andere behält seinen Typ.** Die
   Grenze ist die **API**, nicht die Datenbank: draußen gehen Geldfelder als
   Integer heraus, innen darf ein Store mit heterogenen Werten (`settings` als
   Key-Value-Paar) Text halten — dort ist der Text kein Makel, sondern das
   Format. Weil `settings.value` eine `text`-Spalte ist
   (`V001__initial_portal_schema.php:223`), liefert MySQL **jeden**
   Settings-Wert als JSON-**String** zurück (`base_price` kommt als `"8000"`).
   Das ist der Ist-Stand dieser Spalte; die Regel ist bindend, und die
      Umstellung der Geldfelder auf Integer in der Antwort ist mit `754df6c`
    und `9d31e8e` abgeschlossen; Faktoren und Anzahlen behalten ihre Form,
    weil sie keine Geldbeträge sind.
      Ein Client, der `Number(value)`
   anwendet, ist auf der sicheren Seite; einer, der die Einheit aus dem Typ
   ableitet, ist es nicht — der Typ sagt sie nicht.

5. **Die Einheit steht am Feld, nicht am Geschwister und nicht im Formular.**
   Eine Einheit, die nur als Teiler in einem Eingabefeld existiert, ist keine
   dokumentierte Einheit — sie ist eine Tatsache, die nur an einer Stelle im
   Repo steht und von dort ausbreiten kann. Jede Feld-Dokumentation nennt die
   Einheit **an dem Feld**; ein Client darf sie nie aus einem benachbarten
   Feld ableiten.

6. **Umrechnung in Euro geschieht ausschließlich an der Darstellungsgrenze.**
   `/100` (bzw. `* 100` beim Schreiben) gehört ins Frontend (React) oder in die
   PDF-Erzeugung (Blade) — und in keine Datenbankschicht und in keinen
   Rechenpfad. **BCMath für Multiplikatoren (STRICT):** Sobald Cents mit
   Fließkomma-Faktoren (Shares, prozentuale Gebühren) multipliziert oder
   dividiert werden, ist zwingend die PHP-`bcmath`-Extension (`bcmul`, `bcdiv`,
   `bcadd`) zu verwenden. Type-Casts zu `float` für finanzielle Berechnungen
   sind untersagt. `decimal(12,4)`-Felder wie `total_shares` sind im Model als
   `string` zu casten (`V011__payout_system.php:21`).

**Was bereits der Zielzustand ist.** Diese Felder sind bereits Cent in und
Cent out und müssen unter dieser Regel **nicht** angefasst werden — wer nach
Geld-Einheiten sucht, hat hier nichts zu tun:
`volume_preset_tiers.price_cents` (`VolumePresetTier.php:15`, Integer-Cast,
durchgereicht in `SettingsController.php:338` und `VolumePresetController.php:38`),
`projects.price_cents` (`Project.php:57`, Integer-Cast),
`orgs.shared_flatrate_cents` (`V019__consolidated_fixes.php:199`),
`license_use_cases.base_price` (`LicenseUseCase.php:21`, Integer-Cast, Werte
`4500`–`45000` in `V010__rsv_licensing.php:35-38`),
`orders.total_amount` und `invoice_snapshots.total_net` / `.total_gross`
(dieselbe Variable `$totalNetCents` in `CheckoutService.php:1047` und `:1594`,
die als `amount` an Stripe geht, `:317`, und gegen die zurückgeprüft wird,
`:1404`), Offer-Token-`price` (`OfferTokenService.php:78,122` `int` /
`is_int`), Vertrags-Positionen und -Rabatte
(`../ecommerce/10-digital-contracts.md:65`), `products.price` in seinen
Geld-Varianten (`Product.php:19`, Integer-Cast) sowie
`payout_pools.*` / `photographer_statements.*` (alle Betragsspalten mit
`_cents`-Suffix, `V011__payout_system.php:16-22,33-37`).

**Offen auf der Code-Seite, entschieden hier.** `calc_base_price` und
`calc_hourly_rate` sind heute Euro (`DatabaseSeeder.php:159-160` → `'50'` /
`'80'`), obwohl sie Geldfelder sind, und der Shooting-Kalkulator rechnet
intern in Euro (`frontend/src/logic/shootingCalculator.ts:64-96`). Die
Umstellung auf Cent ist mit `754df6c` abgeschlossen; Zielzustand und Nachweis stehen in
`../infrastructure/28-settings-key-meaning.md` und
`../ecommerce/07-psychological-pricing.md`.

## 5. Dependency Injection & Security
- **Service Container:** Zentrale Dienste wie der `HtmlSanitizer` werden als Singleton im `AppServiceProvider` registriert. Dies sichert das DRY-Prinzip und ermöglicht sauberes Mocking in Unit-Tests.
- **Strikte Whitelists:** HTML-Inputs (z.B. aus dem WYSIWYG-Editor) werden über strenge, explizite Whitelists (`allowElement`) bereinigt, um XSS-Angriffe effektiv zu verhindern. Pauschale Freigaben wie `allowSafeElements()` werden vermieden.

## 6. Performance & Eager Loading
- **N+1 Problemvermeidung:** Für Performance-kritische Controller (wie `StatsController`) wird konsequent Eloquent Eager Loading (`with('gallery.latestPhoto')`) eingesetzt, um die Anzahl der Datenbank-Queries zu minimieren, ohne komplexe, manuelle `whereIn` oder Collections-Mapping-Logik im Controller zu verstreuen.
