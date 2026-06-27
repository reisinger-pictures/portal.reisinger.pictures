# 📝 Review-Backlog — Test- & Qualitäts-Initiative

> Ergebnis der **abgeschlossenen** Test- & Qualitäts-Initiative (alle Pakete **BK-00…10, FE-00…04, E2E-01**
> umgesetzt und grün). Dieses Dokument fasst die dabei gefundenen **REVIEW-Marker** als einzeln angehbare
> Aufgaben (`R-NN`) zusammen. Die jeweiligen Tests frieren das **aktuelle** Verhalten ein; diese Marker sind
> Abweichungen/Bugs, die bewusst **nicht** „passend gemacht" wurden — jede Aufgabe ist unabhängig bearbeitbar.
>
> **Status:** ☐ offen · 🔄 in Arbeit · ☑ erledigt. **Schwere:** 🔴 kritisch · 🟡 mittel · 🟢 niedrig.
> **Herkunft** verweist auf das Ursprungs-Paket. Alle `file:line` gegen den aktuellen Stand verifiziert (2026-06-23).
>
> Ursprüngliche Initiative-Spec (Paket-Spezifikationen §6/§7, Backend-/Frontend-Patterns, Workflow) ist in der
> Git-Historie von `AGENTS.todo.md` konserviert; der verbindliche Paket-Workflow + Lessons Learned auch in `CLAUDE.md`.

**Bearbeitungs-Regel pro Fix:** zugehörigen einfrierenden Test anpassen (bzw. `_review`-Suffix/`markTestSkipped`
entfernen), Bugfix-Test ergänzen und **gesamte Suite grün** halten (`backend`: phpunit, `frontend`: `vitest run` +
`build` + `lint:fix` + betroffene E2E).

---

## 🎯 Priorisierung (Stand: 2026-06-25)

Umsetzungs-Reihenfolge der verbleibenden `R-NN`-Aufgaben nach erfolgreichem Abschluss der Multi-Brand-Infrastruktur.

| Prio         | Aufgaben                                    | Fokus                                                                     |
|--------------|---------------------------------------------|---------------------------------------------------------------------------|
| **Erledigt** | A-01 ✓, A-02 ✓                              | **Multi-Domain-Infrastruktur & B2C-Pricing (ATR) vollständig integriert** |
| **P0**       | R-01 ✓, R-02 ✓                              | Sicherheit & Datenintegrität (Public-Endpoint, SQL-Bindings)              |
| **P1**       | R-03 ✓, R-04 ✓, R-05 ✓                      | Schleifen- & Berechnungs-Guards (Zyklus, Div-by-0, Typ-Parsing)           |
| **P2**       | R-06, R-11, R-13, R-14 ✓                    | Utility- & Logik-Konsistenz (Datumsformat, Nullish, Collections)          |
| *offen*      | R-07 ✓ (akzeptiert), R-08, R-09, R-10, R-12 | R-07 als gewollt eingefroren; Rest niedrige Code-Qualität                 |

---

## 🌐 Multi-Domain-Architektur & Pricing-Strategie (Zweigleisigkeit 2026)

### Brand-Infrastruktur & B2C-Erweiterung

#### **A-01 · 🔴 P0 · Multi-Domain-Infrastruktur & Theme-Weiche** ☑ erledigt (2026-06-25)

- **Zentrale Datenhaltung & Backend-Context:** Middleware zur dynamischen Erkennung des Brands (`all-the.rest` vs
  `reisinger.pictures`) via Host/Referer implementiert. Settings-Abfragen greifen im ATR-Kontext linter- und typsicher
  auf das `atr_`-Präfix zu.
- **Frontend-Theme-Switch (Tailwind v4):** Pre-Boot-Skript in `index.html` implementiert, das Favicons, Webmanifests und
  Metas flackerfrei zur Laufzeit tauscht. Tailwind v4 Themes (`b2b-light`, `b2b-dark`, `atr-light`, `atr-dark`)
  formvollendet mit harmonischen OKLCH-Werten und markenspezifischen Rahmenkonturen definiert.
- **Backend-PDF-Invoicing:** Dynamische Farbanpassungen und Logo-Vererbung aus dem photos-Storage direkt in den
  Blade-Templates verankert.

#### **A-02 · 🔴 P0 · `ShootingCalculatorModal.tsx` Rewrite & B2C-Pricing** ☑ erledigt (2026-06-25)

- **Logik-Implementierung (`shootingCalculator.ts`):** Mathematisch exakte `calculateB2CFlexPrice()` Formel für den
  B2C-Tarif implementiert (149€ Basis, Setup-Fee, Privacy-Fee für Akt). Vollständige Linter-konforme Typisierung der
  Select-Literale etabliert.
- **UI-Weiche & Entfesselung:** Das Modal steuert die Rechner-Inhalte vollautomatisch zur Laufzeit. Die
  Einstellungskarte für den B2B-Kalkulator wird auf `all-the.rest` konsequent ausgeblendet, bleibt aber auf dem
  Hauptportal voll administrierbar.

## 🔁 Rekursion / Endlosschleife

### R-03 · 🟡 **P1** · Kein Schutz gegen `parent_id`-Zyklus → Endlosrekursion ☑ erledigt (2026-06-23)

- **Resolution:** Kombinierter Schutz (defensiv + DB-Schicht).
    - `GalleryGroup`: 4 `effective_*`-Accessoren rekursiv → **iterativ** (`walkParentChain`-Generator mit
      Visited-Set); `Gallery::getFullPathAttribute` While-Schleife mit Visited-Set. Terminieren bei Zyklus/
      Selbstreferenz (kein Stack-Overflow), verhaltensgleich für azyklische Bäume.
    - `GalleryGroup::booted()`: neuer `saving`-Hook lehnt selbstreferenzierende/zyklische `parent_id` ab
      (`InvalidArgumentException`).
    - Tests: `EffectiveAttributesTest` — 2 übersprungene `_review`/`markTestSkipped` durch 7 gehärtete Tests
      ersetzt (Terminierung + true-Propagation + saving-Rejektion + deep-acyclic OK).

- **Symptom:** Ein zirkulärer (`A → B → A`) oder selbstreferenzieller `parent_id` führt in der `effective_*`-Kaskade
  und in `getFullPath` zu Endlosrekursion (Timeout / Stack-Overflow möglich).
- **Ursache:** While-Schleife ohne Visited-Set — `backend/app/Models/Gallery.php:90-101` (`getFullPathAttribute`)
  ```php
  while ($group) { $path = $group->slug . '/' . $path; $group = $group->parent; }
  ```
  sowie rekursive `effective_*`-Attribute — `backend/app/Models/GalleryGroup.php:53-73`, z. B.
  `return $this->is_editorial_only || ($this->parent ? $this->parent->effective_is_editorial_only : false);`
- **Vorschlag:** Visited-Set / Besuchs-Limit in der Schleife; **oder** DB-Seitige Validierung beim Speichern, die
  Zyklen verbietet (sicherer). BK-03 hat die beiden Zyklus-Fälle derzeit `markTestSkipped`.
- **Herkunft:** BK-03.

---

## ➗ Numerik / Division durch null

### R-04 · 🟡 **P1** · Shooting Calculator: `calc_images_per_hour = 0` → `Infinity` ☑ erledigt (2026-06-23)

- **Resolution:** Guard in `calculateShootingPrice` (`shootingCalculator.ts`): `parsedImagesPerHour < 1` oder
  nicht-finite → Fallback auf dokumentierten Default `6`. Psycholog. Rundung unangetastet. Tests:
  `shootingCalculator.test.ts` — `_review` ersetzt + 2 Regressionstests (`'0'`/`'abc'`/`'-5'` → finite).

- **Symptom:** `calculateShootingPrice({calc_images_per_hour: '0', …})` liefert `packagePrice`/`finalPrice = Infinity`
  (UI würde „Infinity €" anzeigen). Settings-Eingabefeld hat nur clientseitiges `min="1"`, kein Server-/Logik-Guard.
- **Ursache:** `frontend/src/logic/shootingCalculator.ts` in `calculateShootingPrice`:
  `imagesPrice = (hourlyRate / imagesPerHourPackage) * input.images` — keine Guard gegen
  `imagesPerHourPackage === 0` (parseInt('0') = 0).
- **Vorschlag:** Guard: `imagesPerHourPackage < 1` (oder `NaN`) → Fallback `6` (Default) bzw. deterministischer
  Wert. Hintergrund siehe `features/ecommerce/07-psychological-pricing.md` (Kante).
- **Herkunft:** FE-04 (auch §1).

## 🧮 Parsing & Berechnungslogik

### R-05 · 🟡 **P1** · Pricing: `parseInt` trunciert dezimale Multiplikatoren ☑ erledigt (2026-06-23)

- **Resolution:** Parsing aufgeteilt — `getRequiredTerm` (`parseInt`) für Cent-Preise (`price_*`), neuer
  `getRequiredMultiplier` (`parseFloat`, Fallback `1`) für `mult_*`. 3 Caller umgestellt + Re-Export.
  Backend validiert `mult_*` als `numeric|min:1` (Dezimal erlaubt/Default). Tests: `pricingLogic.test.ts` —
  neuer `getRequiredMultiplier`-Block + End-to-End-Bugfix (dezimaler Multiplikator `1.5` bleibt erhalten).

- **Symptom:** `getRequiredTerm` parst **alle** Preisfaktoren via `parseInt` → dezimale Multiplikatoren wie
  `mult_commercial = '1.5'` werden zu `1` (stille Preisverfälschung). Preise selbst sind Integer-Cents (unkritisch),
  Multiplikatoren aber potenziell dezimal.
- **Ursache:** `frontend/src/logic/pricingLogic.ts` in `getRequiredTerm` (~Zeile 16):
  `const val = parseInt(terms[key] || '', 10);`
- **Vorschlag:** Für Multiplikatoren `parseFloat` verwenden (z. B. eigenes `getRequiredMultiplier`) **oder** die
  erlaubten Wertebereiche in `SettingsController` klären (Integer erzwingen ↔ Dezimal zulassen) und dort
  validieren. Test friert aktuelles Verhalten ein.
- **Konkret (Review):** Parsing aufteilen — `parseInt` für Cent-Preise (`price_*`), `parseFloat` für Multiplikatoren
  (`mult_*`), z. B. via separates `getRequiredMultiplier()`.
- **Herkunft:** FE-02.

### R-06 · 🟢 **P2** · `formatDateToDE` zerfällt ISO-Datum-mit-Uhrzeit ☑ erledigt (2026-06-26)

- **Symptom:** `formatDateToDE('2024-06-22T12:00:00Z')` → `'22T12:00:00Z.06.2024'` (falsch).
- **Ursache:** `frontend/src/logic/utils.ts` `formatDateToDE` (~Zeile 49): `iso.split('-')` nimmt genau 3 Teile an;
  bei ISO-Datetime landet die Uhrzeit im 3. Teil.
- **Vorschlag:** Nur den Datumsanteil verwenden (`iso.slice(0, 10)`) **oder** robust via `Date` parsen und
  `formatLocaleDate` nutzen. Test mit `_review`-Suffix existiert.
- **Konkret (Review):** `.slice(0, 10)` vor dem Split (Zeitanteil abschneiden); danach den `_review`-Test
  bereinigen/umschreiben.
- **Herkunft:** FE-01 (auch §1).

### R-07 · 🟡 PricingService: Modifier-Surcharge auf vollem Basispreis ☑ akzeptiert als GEWOLLT (2026-06-23)

- **Entscheidung (Stakeholder):** Das Verhalten ist **gewollt** und bleibt unverändert. Modell: die Flatrate
  deckt die Basis-Lizenz; Premium-Modifier (nicht in Flatrate enthalten) sind kostenpflichtige Add-ons, ihr
  `%`-Aufschlag wird auf dem **vollen** `basePriceCents` berechnet — unabhängig von der Deckung. Alternative
  (Surcharge nur auf ungedecktem Anteil = 0 bei Deckung → Modifier gratis) wurde bewusst verworfen.
- **Eingefroren durch:**
  `tests/Feature/PricingServiceTest.php::test_modifier_surcharge_added_even_when_base_covered_if_not_included_in_flatrate`
  (mit R-07-Kommentar). Keine Code-Änderung.
- **Herkunft:** BK-04.

---

## 🧹 Code-Qualität, Konsistenz & Kanten

### R-08 · 🟢 `CheckoutService`: inkonsistente 403-Behandlung ☑ erledigt (2026-06-26)

- **Symptom:** Zugriffsschutz teils via `abort(403)` (→ leere 403-Seite/Exception), teils via
  `response()->json([...], 403)` (→ strukturierte JSON-Antwort) — inkonsistent für Frontend-Caller.
- **Ursache:** `backend/app/Services/CheckoutService.php:27` `abort(403, 'Zugriff verweigert');` vs. `:36`
  `return response()->json(['error' => "…nur für redaktionelle Nutzung…"], 403);`
- **Vorschlag:** Einheitliche JSON-403-Antwort einführen; Frontend-Fehlerbehandlung prüfen.
- **Herkunft:** BK-06. *(Hinweis: die ursprünglich vermutete Redundanz `effective||is_editorial_only` existiert im
  Code nicht — verworfen.)*

### R-09 · 🟢 `InvoiceService`: `mailTo = null`-Zweig ist praktisch tot ☑ erledigt (2026-06-26)

- **Symptom:** Der `if ($mailTo)`-Guard schützt einen `null`-Fall, der über die Fallback-Kette (Initiator →
  Fallback-User → `mail.from.address`) nicht erreicht wird.
- **Ursache:** `backend/app/Services/InvoiceService.php:90-93`
  ```php
  $mailTo = $initiator ? $initiator->email : ($fallbackUser->email ?? null);
  if ($mailTo) { Mail::to($mailTo)->send(new InvoiceMail(...)); }
  ```
- **Vorschlag:** Entweder den `null`-Fall wirklich erreichbar machen + testen, **oder** den Guard/den toten Zweig
  entfernen. (Existenz eines Users ohne E-Mail verifizieren.)
- **Herkunft:** BK-07.

### R-10 · 🟡 `GalleryTreeService`: drei Detail-Probleme ☐

- **(a) Leere Gruppen-Hüllen:** Nach dem Filtern bleiben Gruppen ohne Galerien/Children als leere Hüllen im Baum
  (`backend/app/Services/GalleryTreeService.php:47-59`). → Hüllen herausfiltern.
- **(b) `getAllSubgroupIds` schließt eigene ID aus:** Sammelt nur `children`-IDs, nie die Gruppen-ID selbst (`:102`).
  → Semantik klären (aufrufende Stellen prüfen) und ggf. inkludieren.
- **(c) Admin-Cache ist user-unabhängig:** `Cache::rememberForever('gallery_tree_admin', …)` (`:17`) teilt sich
  einen Key über alle Admins/Rollen → inkorrekt, falls der (gefilterte) Teilbaum rollenabhängig ist.
  → Cache-Key um User/Role ergänzen oder absichern, dass der Admin-Baum wirklich für alle Admins identisch ist.
- **Herkunft:** BK-08.

### R-11 · 🟢 **P2** · `Photo::getArtistAttribute`: Copyright `'0'` fällt auf `name` zurück ☑ erledigt (2026-06-26)

- **Symptom:** Ein Copyright-Wert `'0'` (String) ist für `?:` falsy → fällt fälschlich auf `name` zurück statt `'0'`
  zu respektieren.
- **Ursache:** `backend/app/Models/Photo.php:54` `return $this->user->metadata_copyright ?: $this->user->name;`
- **Vorschlag:** Expliziten Null-Check statt `?:`, z. B. `$this->user->metadata_copyright ?? $this->user->name`
  (respektiert `'0'`).
- **Konkret (Review):** `?:` → `??` (Nullish Coalescing), damit der legitime String `'0'` erhalten bleibt.
- **Herkunft:** BK-02.

### R-12 · 🟢 `pricingLogic.isCovered`: ungenutzter `terms`-Parameter ☑ erledigt (2026-06-26)

- **Symptom:** Die extrahierte Funktion `isCovered` nimmt `terms` (als `_terms`) entgegen, nutzt es im Body aber
  nicht (nur Rang-Vergleich). Rein kosmetisch.
- **Ursache:** `frontend/src/logic/pricingLogic.ts` `isCovered`.
- **Vorschlag:** Parameter entfernen (Signatur-Vereinheitlichung mit `calculateUpgradePrice` aufgeben) **oder**
  bewusst als Platzhalter dokumentiert lassen.
- **Herkunft:** FE-02.

### R-13 · 🟢 **P2** · `utils.isEmpty` behandelt RegExp/Map/Set als „leer" ☑ erledigt (2026-06-26)

- **Symptom:** `isEmpty(/regex/)`, `isEmpty(new Map())`, `isEmpty(new Set())` → `true`, weil sie keine aufzählbaren
  Eigen-Keys besitzen.
- **Ursache:** `frontend/src/logic/utils.ts` `isEmpty` (~Zeile 87): `Object.keys(value).length === 0` für Objekte.
- **Vorschlag:** Klären, ob Collections berücksichtigt werden sollen (dann `size`/`Map.size`/`Set.size` prüfen);
  falls ja, erweitern. Aktuelles Verhalten eingefroren.
- **Konkret (Review):** `Map`/`Set` anhand ihrer `.size`-Eigenschaft validieren (statt `Object.keys`).
- **Herkunft:** FE-01.

---

## ✅ Akzeptiert / verifiziert sicher — KEINE Aktion

- **Psychologische Preis-Rundung** (Shooting Calculator): angezeigte `-50 %`/`-33 %` Rabatte sind bewusst
  mathematisch ungenau — **gewünschtes** Verhalten. Siehe `features/ecommerce/07-psychological-pricing.md`.
  Nicht „korrigieren". (FE-04.)
- **Frontend-IDOR-Defense** via `ProtectedDashboard`-Rollenweiche: ein Client bekommt bei Admin-Routen das
  `ClientDashboard`, keine eigene 403-Seite (Backend verteidigt zusätzlich). Bewusst akzeptiert. (E2E-01.)
- **Payout `totalShares = 0`** ist **tatsächlich guarded** — `PayoutCalculationService.php:92`
  `(float)$totalShares > 0 ? … : 0`. Keine Division-durch-Null; Verhalten durch BK-05 eingefroren. *(Ursprünglich
  als REVIEW markiert, bei Verifikation als sicher bestätigt.)*
- **PHPUnit 12 Data-Provider** als `#[DataProvider]`-Attribut statt Annotation — Konventions-Hinweis, keine Aufgabe. (
  BK-04.)

### Hinweise (nicht-aktionabel — Testbarkeit)

- Einige Null-Pfade (`gallery_id`, `flatrate_level`, `created_at` etc.) sind über die echte DB wegen
  NOT-NULL-Constraints
    + Timestamp-Trait nicht erreichbar und nur via `setRelation`/Speicher-Zuweisung ohne `save()` bzw. Query-Builder-
      `update()` testbar (BK-02, BK-05). Keine Code-Änderung nötig — dokumentierte Test-Technik.

---

## 🌐 Multi-Domain-Architektur & Pricing-Strategie (Zweigleisigkeit 2026)

Basierend auf den Entscheidungen zur Systemarchitektur wird das Portal-Backend zu einem **schlanken
Multi-Tenant-System (Single-Codebase)** ausgebaut. Es gibt keine getrennten Builds oder Deployments. Die Steuerung
erfolgt dynamisch zur Laufzeit über den Hostnamen (Domain).

### 🛠️ Kernkomponenten der Infrastruktur

1. **Zentrale Datenhaltung:** Eine gemeinsame Datenbank für beide Brands. Rechnungen, Bestellungen und Konfigurationen
   erhalten eine string-basierte Unterscheidung via `brand`-Flag (`all-the.rest` oder `reisinger.pictures`).
2. **Frontend-Theme-Switch (Tailwind v4):** Die Erkennung erfolgt im Client über `window.location.hostname`. Das Skript
   injiziert ein `data-theme`-Attribut in das `<html>`-Tag. Die Steuerung nutzt die neue Tailwind CSS v4 `@theme`
   -Direktive und native CSS-Variablen:
    * `all-the.rest` $\rightarrow$ B2C-Theme (dynamischer Dark-/Light-Mode-Wechsel).
    * `reisinger.pictures` $\rightarrow$ Premium-/B2B-Theme (Ausrichtung noch offen).
3. **Backend-PDF-Invoicing:** Um Rendering-Fehler moderner CSS-Compiler in PDF-Engines zu vermeiden, werden die
   Markenfarben (HEX) im Backend (z.B. PHP-Match-Query) für den Rechnungskopf pragmatisch hardcodiert.

---

### 📊 Der neue Pricing-Algorithmus (`all-the.rest` · B2C Flex-Tarif)

Das neue `ShootingCalculatorModal.tsx` für **all-the.rest** bricht die historische Komplexität in ein modulares
Paketsystem auf. **Wichtig:** Treue- und OG-Rabatte fallen für diesen Tarif komplett weg.

#### 1. Basiswerte & Konstanten

* **Grundpreis:** 149 € (Inklusive 20 Bilder, fixer Zeitrahmen von 1,5 bis 2 Stunden).
* **Zusätzliche Bilder:** 15 € pro Bild.

#### 2. Die mathematische Formel

Das finale Honorar berechnet sich nach folgender Struktur:

$$FinalPrice = 149 + SetupFee + (ExtraImages \times 15) + PrivacyFee$$

Wobei die einzelnen Faktoren folgenden festen Regeln unterliegen:

* **Setup-Aufschlag (`SetupFee`):**
    * `outdoor` (Natürliches Licht): **0 €**
    * `outdoor_flash` (Mobiles Blitz-Setup): **+50 €**
    * `indoor` (Fotostudio): **+50 €**

* **Schutzraum-Aufschlag (`PrivacyFee`):**
  Greift exklusiv, wenn der Bereich `akt` (Akt & Boudoir) gewählt wurde **und** das absolute Online-Verbot (
  `isFullyPrivate`) aktiv ist:
  $$\text{PrivacyFee} = 100 + (TotalImages \times 5)$$
  Da $TotalImages = 20 + ExtraImages$, reduziert sich die Formel für den Aufschlag auf:
  $$\text{PrivacyFee} = 200 + (ExtraImages \times 5)$$
  *In allen anderen Bereichen oder Konfigurationen ist die $\text{PrivacyFee} = 0$.*

#### 3. Super-Admin Cheat-Sheet für manuelle Kontrollen

| Bereich (`type`) | Setup (`setup`) | Zusätzliche Bilder | Online-Verbot  | Berechnungsschritte                                 | Endpreis  |
|:-----------------|:----------------|:-------------------|:---------------|:----------------------------------------------------|:----------|
| **Portrait**     | Outdoor (Natur) | 0                  | Nicht relevant | $149 + 0 + 0 + 0$                                   | **149 €** |
| **Pärchen**      | Indoor (Studio) | 5                  | Nicht relevant | $149 + 50 + (5 \times 15) + 0$                      | **274 €** |
| **Akt**          | Outdoor (Natur) | 0                  | Ja (Aktiv)     | $149 + 0 + 0 + [100 + (20 \times 5)]$               | **349 €** |
| **Akt**          | Indoor (Studio) | 10                 | Ja (Aktiv)     | $149 + 50 + (10 \times 15) + [100 + (30 \times 5)]$ | **599 €** |

---

### 🔄 Refactoring-Fahrplan `ShootingCalculatorModal.tsx` (`reisinger.pictures`)

Das bestehende Modal im Portal nutzt bisher ein System aus Stundensätzen, halbierten Bild-Stückpreisen bei Outdoor,
pauschalen Flatrate-Aufschlägen (+20 %) und prozentualen Rabatten (33 % / 50 %).

* **To-Do:** Die Berechnungslogiken im Code sauber in zwei getrennte Strategien kapseln: `calculateB2CFlexPrice()` (
  `all-the.rest`) und `calculateCustomStudioPrice()` (`reisinger.pictures`).
* **UI-Weiche:** Sobald im Super-Admin-Portal das Paket für `all-the.rest` kalkuliert wird, sperrt das UI die
  Rabatt-Auswahl, da dieser Tarif starr bleibt.

---

#

### 🛠️ Neue Qualitäts- & UI-Fixes (Juni 2026)

#### **R-14 · 🟡 P1 · Input-Typen & Step-Inkremente im Invoicing** ☑ erledigt

- [x] Alle numerischen Eingabefelder (Mengen, Preise, Stunden) in den Invoicing-Formularen (
  `ManagementManualInvoiceView`, `InvoiceItemsTable`, `InvoiceDiscountsSection`) strikt auf `type="number"` umstellen.
- [x] Sinnvolle `step`-Attribute hinterlegen (z. B. `step="0.25"` für Arbeitsstunden, um Viertelstunden-Schritte nativ
  zu erlauben).

#### **R-15 · 🟢 P2 · Inkonsistente Icons in der Mobile-Ansicht**

- [ ] Die Header- und Sidebar-Navigation im mobilen Viewport auf Darstellungsfehler und uneinheitliche Icon-Klassen (MDI
  vs. Custom SVGs) prüfen.
- [ ] Lücken bei der Mandantentrennung (B2B Multi-Tenant Isolation) im Frontend restlos schließen.

#### **R-16 · 🟡 P1 · Feature Gap: Multi-Tenant Support im Lightroom Plugin**

- [ ] **Status-Quo Dokumentation:** Das Lightroom Classic Plugin (`admin.lrplugin`) ist aktuell als
  *Single-Tenant-System* starr an die Hauptdomain gekoppelt. Es kann zur Laufzeit nicht dynamisch zwischen
  `all-the.rest` und `reisinger.pictures` umschalten, wenn Kollektionen hochgeladen werden.
- [ ] **Architektonische Lücke:** Es fehlt die Auswertung des tenant-spezifischen API-Contexts beim Metadaten-Abgleich
  und Upload-Routing aus Lightroom heraus. (Zukünftiges Refactoring erforderlich).

## 🔍 Status-Überblick der verbleibenden Code-Qualitäts-Aufgaben (Review-Backlog)

Die folgenden Aufgaben aus der Qualitäts-Initiative sind weiterhin offen und müssen parallel stabil gehalten werden:

* **`R-06` (P2 - Utility):** Datums-Parsing-Fehler in `formatDateToDE` bei ISO-Strings beheben (`.slice(0, 10)` vor
  Split nutzen).
* **`R-08` (Qualität):** Inkonsistente 403-Fehlerbehandlung im `CheckoutService` auf einheitliches JSON umstellen.
* **`R-09` (Qualität):** Toten `null`-Zweig im `InvoiceService` für Mail-Empfänger bereinigen.
* **`R-10` (Mittel):** `GalleryTreeService` logisch nachbessern (Leere Gruppen-Hüllen entfernen, eigene ID in Subgroups
  inkludieren, Admin-Cache benutzerabhängig scope-en).
* **`R-11` (P2 - Logik):** `Photo::getArtistAttribute` von `?:` auf Nullish Coalescing `??` umstellen, damit der
  Copyright-String `'0'` nicht überschrieben wird.
* **`R-12` (Qualität):** Ungenutzten `terms`-Parameter aus `pricingLogic.isCovered` entfernen.
* **`R-13` (P2 - Utility):** `utils.isEmpty` so erweitern, dass `Map`- und `Set`-Strukturen anhand ihrer `.size` korrekt
  validiert werden.

---

## 🌐 Multi-Domain-Architektur & Pricing-Strategie (Zweigleisigkeit 2026)

Basierend auf den Entscheidungen zur Systemarchitektur wird das Portal-Backend zu einem **schlanken
Multi-Tenant-System (Single-Codebase)** ausgebaut. Es gibt keine getrennten Builds oder Deployments. Die Steuerung
erfolgt dynamisch zur Laufzeit über den Hostnamen (Domain).

### 🛠️ Aufgaben-Spezifikation

#### **A-01 · 🔴 P0 · Multi-Domain-Infrastruktur & Theme-Weiche**

* **Zentrale Datenhaltung & Backend-Context:**
    * [ ] Backend-Middleware (`BrandContextMiddleware`) zur Erkennung des Brands (`all-the.rest` vs
      `reisinger.pictures`) anhand des Host-Headers implementieren.
    * [ ] Settings-Controller/-Logik erweitern, um markenspezifische Bank-/Firmendaten auszuliefern (z.B.
      `atr_bank_iban` vs `rp_bank_iban`).
    * [ ] PHPUnit Tests für die Brand-Erkennung und Settings-Trennung schreiben.
* **Frontend-Theme-Switch (Tailwind v4):**
    * [ ] Logik in `main.tsx` implementieren, die `window.location.hostname` auswertet und das `data-theme` Attribut
      dynamisch im `<html>`-Tag setzt.
    * [ ] Tailwind v4 Themes in `index.css` final definieren (ATR = B2C, RP = B2B).
    * [ ] Playwright E2E Test für den Theme-Switch basierend auf origin/hostname schreiben.
* **Backend-PDF-Invoicing:**
    * [ ] `header.blade.php`, `footer.blade.php` und `invoice.blade.php` anpassen, sodass Farben (HEX) und Logos
      dynamisch via PHP-Match-Query aus dem Brand-Context geladen werden.
    * [ ] PHPUnit Tests für die PDF-Generierung unter beiden Brand-Kontexten ergänzen.

#### **A-02 · 🔴 P0 · `ShootingCalculatorModal.tsx` Rewrite & B2C-Pricing (`all-the.rest`)**

Das neue `ShootingCalculatorModal.tsx` für **all-the.rest** bricht die historische Komplexität in ein modulares
Paketsystem auf. **Wichtig:** Treue- und OG-Rabatte fallen für diesen Tarif komplett weg.

* **Logik-Implementierung (`shootingCalculator.ts`):**
    * [ ] Funktion `calculateB2CFlexPrice()` für `all-the.rest` implementieren (Formel: 149 + SetupFee + (ExtraImages *
        15)
            + PrivacyFee).
    * [ ] Funktion `calculateCustomStudioPrice()` für `reisinger.pictures` kapseln (bestehende Logik).
    * [ ] Ausführliche Unit-Tests (Vitest) für beide Berechnungsstrategien schreiben.
* **UI-Weiche (`ShootingCalculatorModal.tsx`):**
    * [ ] Hostname/Brand-Context im Frontend auslesen (`useBrand` Hook).
    * [ ] UI dynamisch anpassen: Rabatt-Auswahl bei `all-the.rest` sperren/ausblenden, spezifische Toggles für Setup (
      Indoor/Outdoor/Flash) und Privacy einblenden.
    * [ ] Playwright E2E Tests für beide Ausprägungen des Kalkulator-Modals schreiben.

* **Basiswerte & Konstanten:**
    * **Grundpreis:** 149 € (Inklusive 20 Bilder, fixer Zeitrahmen von 1,5 bis 2 Stunden).
    * **Zusätzliche Bilder:** 15 € pro Bild.

* **Die mathematische Formel:**
  Das finale Honorar berechnet sich nach folgender Struktur:
  $$FinalPrice = 149 + SetupFee + (ExtraImages \times 15) + PrivacyFee$$

* **Setup-Aufschlag (`SetupFee`):**
    * `outdoor` (Natürliches Licht): **0 €**
    * `outdoor_flash` (Mobiles Blitz-Setup): **+50 €**
    * `indoor` (Fotostudio): **+50 €**

* **Schutzraum-Aufschlag (`PrivacyFee`):**
  Greift exklusiv, wenn der Bereich `akt` (Akt & Boudoir) gewählt wurde **und** das absolute Online-Verbot (
  `isFullyPrivate`) aktiv ist:
  $$\text{PrivacyFee} = 100 + (TotalImages \times 5)$$
  Da $TotalImages = 20 + ExtraImages$, reduziert sich die Formel für den Aufschlag auf:
  $$\text{PrivacyFee} = 200 + (ExtraImages \times 5)$$
  *In allen anderen Bereichen oder Konfigurationen ist die $\text{PrivacyFee} = 0$.*

* **UI-Weiche im Portal:** Sobald im Super-Admin-Portal das Paket für `all-the.rest` kalkuliert wird, sperrt das UI die
  Rabatt-Auswahl, da dieser Tarif starr bleibt. Für `reisinger.pictures` bleibt die alte Logik vorerst aktiv (
  `calculateCustomStudioPrice()`).

---

### 📊 Super-Admin Cheat-Sheet für manuelle Kontrollen (ATR)

| Bereich (`type`) | Setup (`setup`) | Zusätzliche Bilder | Online-Verbot  | Berechnungsschritte                                 | Endpreis  |
|:-----------------|:----------------|:-------------------|:---------------|:----------------------------------------------------|:----------|
| **Portrait**     | Outdoor (Natur) | 0                  | Nicht relevant | $149 + 0 + 0 + 0$                                   | **149 €** |
| **Pärchen**      | Indoor (Studio) | 5                  | Nicht relevant | $149 + 50 + (5 \times 15) + 0$                      | **274 €** |
| **Akt**          | Outdoor (Natur) | 0                  | Ja (Aktiv)     | $149 + 0 + 0 + [100 + (20 \times 5)]$               | **349 €** |
| **Akt**          | Indoor (Studio) | 10                 | Ja (Aktiv)     | $149 + 50 + (10 \times 15) + [100 + (30 \times 5)]$ | **599 €** |

---

### 🔍 Status-Überblick der verbleibenden Code-Qualitäts-Aufgaben (Review-Backlog)

Die offenen Punkte (`R-06` bis `R-13`) bleiben wie oben tabelarisch erfasst gültig und müssen parallel stabil gehalten
werden.

### 🌐 Multi-Brand-Infrastruktur & Dynamic Assets (2026)

- [x] **Brand-Umschaltung & Impressum:** useBrand-Hook liefert dynamisch markenspezifische Assets und korrekte
  Impressums-URLs (https://all-the.rest/impressum/ vs https://reisinger.pictures/impressum/).
- [x] **Preise entkoppeln & B2C-Pricing:** B2C Kalkulator-Werte (149€, 50€, 200€, 15€) dynamisch in die Settings-Tabelle
  integriert und über das UI administrierbar gemacht.
- [x] **Abrechnungs-Konsistenz:** Fallback im SettingsController für den Stundensatz von 100€ auf 80€ korrigiert (
  Parität mit Seeder und Migration).
- [x] **Watermark Auto-Detection:** Manueller Logo-Upload entfernt. System liest Quelldateien zur Laufzeit und
  regeneriert die PNG-Buckets transparent bei Änderungen oder Server-Neustart.

### 🔄 Offenes Review & Container-Isolierung (Zukunft)

- [ ] **Watermark-Infrastruktur / Container-Isolierung:** Passiert die Analyse, ob sich die Wasserzeichen geändert haben
  beim Container start und werden alle alten Bilder bei einem Update invalidiert?
- [ ] **InvoiceItemsTable / Posten-Inkremente:** Evaluieren, ob `step="0.25"` global für alle Rechnungsposten gilt oder
  ob differenziert zwischen "Stunden" (0.25 Inkrement) und "Artikeln/Produkten" (1.0 Inkrement) unterschieden werden
  muss. Tendenz zu 0,25 passt
