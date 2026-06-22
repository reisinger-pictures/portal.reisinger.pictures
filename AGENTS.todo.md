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

## 🔒 Sicherheit & Datenlecke

### R-01 · 🔴 `GET /api/settings/license-terms` ist public und exponiert Bank-/Kontaktdaten ☐

- **Symptom:** Der Endpunkt ist ohne Authentifizierung aufrufbar und liefert u. a. `bank_iban`, `bank_bic`,
  `bank_holder`, `company_email` — potenzielles Datenleck (IBAN-/Kontaktdaten-Harvesting).
- **Ursache:** Route ohne Auth-Middleware — `backend/routes/api.php:42`
  `Route::get('/settings/license-terms', [SettingsController::class, 'getLicenseTerms']);` (außerhalb aller
  `auth`/`admin`-Gruppen). Response baut sensible Felder auf —
  `backend/app/Http/Controllers/SettingsController.php:119-126`.
- **Vorschlag:** Entweder Endpunkt hinter Auth/`admin` legen **oder** sensible Felder aus der Public-Antwort
  entfernen. Vorher prüfen, welche Caller den anonymen Zugang brauchen (z. B. Checkout-/Impressum-Anzeige im
  Frontend) und diese über einen separaten, reduzierten Public-Endpunkt versorgen.
- **Herkunft:** BK-10.

### R-02 · 🟡 `User::getSubGroupIds` baut CTE-ID-Liste per String-Concatenation ☐

- **Symptom:** Für die `WITH RECURSIVE`-CTE werden die Eltern-UUIDs per `implode` + manuelle Quotes zu einem
  SQL-String zusammengebaut statt gebundene Parameter/`whereIn` zu verwenden → Robustheits-/Sicherheitsrisiko
  (Injection-nah, falls Nicht-UUID-Werte eingeschleust werden). Kein nachgewiesener Exploit.
- **Ursache:** `backend/app/Models/User.php:158-160`
  ```php
  $inIds = implode(',', array_map(fn($id) => "'" . $id . "'", $parentIds));
  ```
- **Vorschlag:** Auf gebundene Parameter (`?`-Platzhalter + Bindings) bzw. `whereIn` umstellen; leere Liste früh
  `return []`.
- **Herkunft:** BK-01 (auch §1).

> **Verwandt (Cache):** Prozessglobaler Cache `unrestricted_photographer_gallery_ids`
> (`backend/app/Models/User.php:216`, `Cache::rememberForever(...)` ohne User/Role-Scope) → siehe **R-10 (c)**.

---

## 🔁 Rekursion / Endlosschleife

### R-03 · 🟡 Kein Schutz gegen `parent_id`-Zyklus → Endlosrekursion ☐

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

### R-04 · 🟡 Shooting Calculator: `calc_images_per_hour = 0` → `Infinity` ☐

- **Symptom:** `calculateShootingPrice({calc_images_per_hour: '0', …})` liefert `packagePrice`/`finalPrice = Infinity`
  (UI würde „Infinity €" anzeigen). Settings-Eingabefeld hat nur clientseitiges `min="1"`, kein Server-/Logik-Guard.
- **Ursache:** `frontend/src/logic/shootingCalculator.ts` in `calculateShootingPrice`:
  `imagesPrice = (hourlyRate / imagesPerHourPackage) * input.images` — keine Guard gegen
  `imagesPerHourPackage === 0` (parseInt('0') = 0).
- **Vorschlag:** Guard: `imagesPerHourPackage < 1` (oder `NaN`) → Fallback `6` (Default) bzw. deterministischer
  Wert. Hintergrund siehe `features/ecommerce/07-psychological-pricing.md` (Kante).
- **Herkunft:** FE-04 (auch §1).

---

## 🧮 Parsing & Berechnungslogik

### R-05 · 🟡 Pricing: `parseInt` trunciert dezimale Multiplikatoren ☐

- **Symptom:** `getRequiredTerm` parst **alle** Preisfaktoren via `parseInt` → dezimale Multiplikatoren wie
  `mult_commercial = '1.5'` werden zu `1` (stille Preisverfälschung). Preise selbst sind Integer-Cents (unkritisch),
  Multiplikatoren aber potenziell dezimal.
- **Ursache:** `frontend/src/logic/pricingLogic.ts` in `getRequiredTerm` (~Zeile 16):
  `const val = parseInt(terms[key] || '', 10);`
- **Vorschlag:** Für Multiplikatoren `parseFloat` verwenden (z. B. eigenes `getRequiredMultiplier`) **oder** die
  erlaubten Wertebereiche in `SettingsController` klären (Integer erzwingen ↔ Dezimal zulassen) und dort
  validieren. Test friert aktuelles Verhalten ein.
- **Herkunft:** FE-02.

### R-06 · 🟢 `formatDateToDE` zerfällt ISO-Datum-mit-Uhrzeit ☐

- **Symptom:** `formatDateToDE('2024-06-22T12:00:00Z')` → `'22T12:00:00Z.06.2024'` (falsch).
- **Ursache:** `frontend/src/logic/utils.ts` `formatDateToDE` (~Zeile 49): `iso.split('-')` nimmt genau 3 Teile an;
  bei ISO-Datetime landet die Uhrzeit im 3. Teil.
- **Vorschlag:** Nur den Datumsanteil verwenden (`iso.slice(0, 10)`) **oder** robust via `Date` parsen und
  `formatLocaleDate` nutzen. Test mit `_review`-Suffix existiert.
- **Herkunft:** FE-01 (auch §1).

### R-07 · 🟡 PricingService: Modifier-Surcharge auf vollem Basispreis (Intention klären) ☐

- **Symptom:** Der Modifier-Surcharge wird auf den **vollen** `basePriceCents` berechnet, auch wenn der Basispreis
  durch die Flatrate bereits gedeckt ist (`isBaseCovered === true`).
- **Ursache:** `backend/app/Services/PricingService.php:31`
  `$surchargeAmountCents += (int) round($basePriceCents * ((float)$mod->percent_surcharge / 100));`
- **Vorschlag:** Mit Stakeholder klären, ob gewollt (Surcharge unabhängig von Deckung) oder Bug (Surcharge nur auf
  nicht-gedecktem Anteil). Aktuelles Verhalten ist durch BK-04 eingefroren.
- **Herkunft:** BK-04.

---

## 🧹 Code-Qualität, Konsistenz & Kanten

### R-08 · 🟢 `CheckoutService`: inkonsistente 403-Behandlung ☐

- **Symptom:** Zugriffsschutz teils via `abort(403)` (→ leere 403-Seite/Exception), teils via
  `response()->json([...], 403)` (→ strukturierte JSON-Antwort) — inkonsistent für Frontend-Caller.
- **Ursache:** `backend/app/Services/CheckoutService.php:27` `abort(403, 'Zugriff verweigert');` vs. `:36`
  `return response()->json(['error' => "…nur für redaktionelle Nutzung…"], 403);`
- **Vorschlag:** Einheitliche JSON-403-Antwort einführen; Frontend-Fehlerbehandlung prüfen.
- **Herkunft:** BK-06. *(Hinweis: die ursprünglich vermutete Redundanz `effective||is_editorial_only` existiert im
  Code nicht — verworfen.)*

### R-09 · 🟢 `InvoiceService`: `mailTo = null`-Zweig ist praktisch tot ☐

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

### R-11 · 🟢 `Photo::getArtistAttribute`: Copyright `'0'` fällt auf `name` zurück ☐

- **Symptom:** Ein Copyright-Wert `'0'` (String) ist für `?:` falsy → fällt fälschlich auf `name` zurück statt `'0'`
  zu respektieren.
- **Ursache:** `backend/app/Models/Photo.php:54` `return $this->user->metadata_copyright ?: $this->user->name;`
- **Vorschlag:** Expliziten Null-Check statt `?:`, z. B. `$this->user->metadata_copyright ?? $this->user->name`
  (respektiert `'0'`).
- **Herkunft:** BK-02.

### R-12 · 🟢 `pricingLogic.isCovered`: ungenutzter `terms`-Parameter ☐

- **Symptom:** Die extrahierte Funktion `isCovered` nimmt `terms` (als `_terms`) entgegen, nutzt es im Body aber
  nicht (nur Rang-Vergleich). Rein kosmetisch.
- **Ursache:** `frontend/src/logic/pricingLogic.ts` `isCovered`.
- **Vorschlag:** Parameter entfernen (Signatur-Vereinheitlichung mit `calculateUpgradePrice` aufgeben) **oder**
  bewusst als Platzhalter dokumentiert lassen.
- **Herkunft:** FE-02.

### R-13 · 🟢 `utils.isEmpty` behandelt RegExp/Map/Set als „leer" ☐

- **Symptom:** `isEmpty(/regex/)`, `isEmpty(new Map())`, `isEmpty(new Set())` → `true`, weil sie keine aufzählbaren
  Eigen-Keys besitzen.
- **Ursache:** `frontend/src/logic/utils.ts` `isEmpty` (~Zeile 87): `Object.keys(value).length === 0` für Objekte.
- **Vorschlag:** Klären, ob Collections berücksichtigt werden sollen (dann `size`/`Map.size`/`Set.size` prüfen);
  falls ja, erweitern. Aktuelles Verhalten eingefroren.
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
