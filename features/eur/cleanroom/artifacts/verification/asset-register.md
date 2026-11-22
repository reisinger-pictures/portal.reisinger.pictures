# Anlageverzeichnis, jährliche Privatnutzung und AfA-Fortschreibung

**Status:** Spezifikation für den Build (nicht implementiert)
**Bezug:** B05 „Anlagenverzeichnis & AfA“ (`EUE-049` … `EUE-061`), B04 (`EUE-048`, `EUE-055`),
`verification/settings-and-references.md`, `verification/parity-report.md`
**Auslöser:** Anforderung „private Güter über das ganze Jahr führen, jährliche Privatnutzung
und Jahres-AfA buchen, Abschreibung darf länger als die Standarddauer laufen“.

---

## 1. Was heute schon existiert (und was nicht)

| Vorhanden | Nicht vorhanden |
|---|---|
| Eigenbeleg „Private Nutzung“: eine Jahresbuchung am 31.12. mit manuell eingegebenen „Jahreskosten (AfA, Miete oder Anschaffung)“ und jährlich änderbarem Betriebsanteil (`Router::deviceUsage`, `EigenbelegDialog`) | Kein Anlagegut, kein Anschaffungswert, keine Nutzungsdauer |
| Betriebsanteil je Buchung (5-%-Schritte), Privatanteil bleibt automatisch außer Ansatz | Keine AfA-Rechnung, kein Restbuchwert, keine Halbjahresregel, kein GWG |
| Beleg kann an eine Buchung gehängt werden | Keine Fortschreibung über Jahre, keine Vorjahreskonsistenz |
| Arbeitsmappen-Regeln B05 sind im Katalog dokumentiert | Keine Prüfungen `EUE-053` … `EUE-061` |

Die vorhandene Jahresbuchung ist eine **manuelle Näherung**, kein Anlageverzeichnis: Der Nutzer
tippt die AfA jedes Jahr selbst ein, das Tool kennt weder Kosten noch Nutzungsdauer und kann
deshalb weder den Restbuchwert noch die Plausibilität prüfen.

---

## 2. Datenmodell

Alle Beträge sind `*_cents` (Integer, nach #26). Mandantentrennung wie überall.

### 2.1 `assets` (Anlagegut)

| Feld | Typ | Regel |
|---|---|---|
| `id` | Serial | — |
| `tenant_id` | FK | Pflicht |
| `name` | Text | Pflicht, ≤ 190 (`EUE-059`) |
| `invoice_no` | Text | Pflicht, ≤ 64 (`EUE-059`) |
| `supplier` | Text | Lieferant, ≤ 190 (Spalte AV!I) |
| `purchase_date` | Date | Pflicht; Jahr nicht in der Zukunft (`EUE-058`); Tag/Monat wie AV!G/H |
| `cost_cents` | Bigint | Pflicht, > 0, Brutto-Anschaffungskosten |
| `useful_life_years` | Numeric(4,1) | `0` = nicht abnutzbar, sonst **2–99** (`EUE-052`); längere Dauer als der Standard ist ausdrücklich erlaubt |
| `business_share` | Integer | 0–100 in 5-%-Schritten (#30); im Verzeichnis **≥ 50** (`EUE-054`) |
| `entry_type_id` | FK | Ausgaben-Art; muss auf `9130`, `9134`, `9135` oder `9500` zeigen (`EUE-053`, `EUE-061`) |
| `project_id` | FK, nullable | Projekt (Spalte AV!L); Kundenbezug über #27 |
| `entry_id` | FK, nullable | Ursprüngliche Anschaffungsbuchung |
| `document_id` | FK, nullable | Rechnung/Beleg |
| `status` | Enum | `active`, `disposed` |
| `disposed_on` | Date, nullable | Verkauf/Entsorgung |
| `disposal_proceeds_cents` | Bigint, nullable | Erlös → `EUE-050`/Buchwert |
| `notes` | Text | ≤ 500 (Spalte AV!K „Kommentar“) |

Die Spalten `ABSCHREIBUNG <Vorjahr>` und `RESTBUCHWERT <Vorjahr>` der Arbeitsmappe werden nicht
dupliziert, sondern aus `asset_years` des Vorjahres gelesen. **Verkauf/Entsorgung ist eine
Erweiterung** gegenüber der Arbeitsmappe (AV hat dafür keine Spalten); sie ist im UI als solches
gekennzeichnet und ändert die AfA-Kette nicht rückwirkend.

### 2.2 `asset_years` (AfA- und Privatnutzungsplan je Jahr)

| Feld | Typ | Bedeutung |
|---|---|---|
| `asset_id`, `year` | FK + Int | eindeutig (`UNIQUE`) |
| `afa_cents` | Bigint | AfA des Jahres nach 3.1 |
| `book_value_cents` | Bigint | Restbuchwert am Jahresende nach 3.2 |
| `private_use_percent` | Integer | 0–100 in 5-%-Schritten (#30, konsistent zur Anteilsregel) |
| `private_use_cents` | Bigint | `afa_cents * private_use_percent / 100`, kaufmännisch gerundet |
| `business_use_cents` | Bigint | `afa_cents − private_use_cents` |
| `entry_id` | FK, nullable | Jahresbuchung in Dezember |
| `status` | Enum | `planned`, `booked`; `booked` ist unveränderlich, solange der Monat signiert ist |

Der Plan wird für **alle** Jahre von der Anschaffung bis zum Ende der Nutzungsdauer berechnet
und gespeichert. Künftige Jahre sind `planned`, gebuchte Jahre `booked`. Damit ist die
„Übertragung über das ganze Jahr“ eine echte Fortschreibung und keine Neueingabe.

---

## 3. Berechnung

### 3.1 Jahres-AfA (`EUE-049`)

```
factor = 0.5, wenn Anschaffungsmonat Juli–Dezember, sonst 1.0
afa    = round(cost / useful_life_years * factor, 2)
```

- Im Anschaffungsjahr gilt die Halbjahresregel der Arbeitsmappe.
- In Folgejahren `factor = 1.0`, solange Restbuchwert > 0.
- `useful_life_years = 0` → keine AfA (Grundstück, nicht abnutzbar).
- GWG: `cost < 1.000 €` → Hinweis „unter GWG-Grenze, evtl. sofort im A-Blatt erfassen“;
  das Anlagegut bleibt trotzdem erfassbar (`EUE-051`).
- Längere Nutzungsdauer als der Standard ist zulässig; die App schlägt nichts vor, sondern
  validiert nur 2–99 Jahre. Die fachliche Begründung der Dauer liegt beim Nutzer.

### 3.2 Restbuchwert (`EUE-050`)

```
book_value = max(cost − sum(afa bis inkl. Jahr), 0.01)
```

- Der Restbuchwert sinkt nie unter 0,01 €.
- Bei Verkauf: `book_value_after = max(book_value − proceeds, 0)`; der Restbuchwert wird
  als Ausgabe „Buchwert abgeg. Anlagegüter“ (`9210`) buchbar.

### 3.3 Privatnutzung und Jahresbuchung

- Der Nutzer erfasst je Anlagegut und Jahr den **Privatanteil** (0–100, 5-%-Schritte).
  Vorbelegt wird der Vorjahreswert, im ersten Jahr `100 − business_share`.
- Jahresbuchung (Dezember, idempotent je `asset_id` + `year`):
  - `amount_cents = afa_cents`
  - `business_share = 100 − private_use_percent`
  - `category` / E1A-Kennzahl aus der Ausgaben-Art des Anlageguts
  - Beschreibung `AfA <Jahr>: <Name> (<Privatanteil> % privat)`
  - Verknüpfung `entry_id` ↔ `asset_years.entry_id`
- Der Privatanteil mindert den Betriebsaufwand über den Betriebsanteil; eine zusätzliche
  Entnahme ist für die **laufende** Nutzung nicht nötig. Die Entnahme bei **Anschaffung**
  (`EUE-055`) bleibt eine eigene Buchung und wird im Anlagegut als erledigt/nicht erledigt
  angezeigt.
- **Abgrenzung zur Arbeitsmappe:** AV führt keinen jährlichen Privatanteil je Anlagegut; dort
  wird der Privatanteil über die Ausgaben-Art und die Entnahme bei Anschaffung abgebildet
  (`EUE-054`/`055`). Der jährliche Privatanteil ist eine ausdrückliche Erweiterung auf
  Nutzerwunsch; er ändert die AfA-Rechnung nicht, sondern nur den abziehbaren Anteil der
  Jahresbuchung.
- Ist der Dezember bereits signiert, ist die Jahresbuchung nur mit Begründung
  (`change_reason`) änderbar; die bestehende Regel aus #14/#20 gilt unverändert.

### 3.4 Prüfungen (B05)

| Regel | Prüfung | Verhalten |
|---|---|---|
| `EUE-053` | Ausgaben-Art hat AfA-Kennzahl | Fehler beim Speichern |
| `EUE-054` | Betriebsanteil ≥ 50 % | Fehler beim Speichern (sonst Privatvermögen) |
| `EUE-055` | Privatanteil bei Anschaffung → Entnahme vorhanden | Warnung mit Betrag und Belegnummer |
| `EUE-056` | AfA passt zu Kosten/Nutzungsdauer (±0,01 €) | Warnung bei manueller Korrektur |
| `EUE-057` | Vorjahres-Restbuchwert und AfA konsistent | Fehler, wenn die Kette bricht |
| `EUE-058` | Anschaffungsjahr nicht in der Zukunft | Fehler |
| `EUE-059` | Belegnummer, Jahr, Monat; Tag/Bezeichnung empfohlen | Pflicht/Warnung wie angegeben |
| `EUE-061` | AfA-Kennzahl aus der Ausgaben-Art | automatisch, Anzeige im Verzeichnis |

---

## 4. Oberfläche

Neuer Tab **„Anlagen“** (neben Buchungen/Belege):

1. **Liste** je Wirtschaftsjahr: Bezeichnung, Belegnummer, Anschaffung, Kosten, Nutzungsdauer,
   AfA des Jahres, Restbuchwert, Privatanteil, Status (aktiv/verkauft).
2. **Detail**: Stammdaten, Beleg, Anschaffungsbuchung, AfA-Verlauf über alle Jahre,
   Privatanteil je Jahr, Jahresbuchung mit Link.
3. **Jahresabschluss**: pro Jahr eine Tabelle „AfA / Privatanteil / Betriebsanteil /
   Buchung fehlt“ mit Ein-Klick-Buchung für alle offenen Anlagegüter; Vorschau der Summen.
4. **Ausfüllhilfe** an jedem Feld (Nutzungsdauer, Halbjahresregel, GWG, Privatanteil,
   Betriebsanteil), Quellen = B05-Regeln.

Keine Änderung an bestehenden Tabs außer einem Link „Aus Anschaffung anlegen“ aus einer
Buchung mit AfA-Kennzahl.

---

## 5. Auswertung

- Übersicht: AfA gesamt (betrieblich), Privatanteil gesamt, Restbuchwert gesamt.
- E1A: AfA landet über die Ausgaben-Art in `9130`/`9134`/`9135`/`9500`;
  Buchwertabgang in `9210`.
- Kunden/Projekte (#27): Anlagegüter sind keiner Kundschaft zugeordnet; die AfA bleibt
  Gemeinkosten. Keine künstliche Verteilung.

---

## 6. Rechtlicher Prüfvorbehalt

Die App bildet die Clean-Room-Arbeitsmappe ab, keine Steuerberatung. Vor Produktivbetrieb
sind extern zu bestätigen:

- Zulässigkeit und Dauer der gewählten Nutzungsdauer (die App erlaubt 2–99 Jahre).
- Halbjahresregel der Arbeitsmappe vs. tatsächliche Anspruchsdauer im Anschaffungsjahr.
- GWG-Grenze 1.000 € und Sofortabschreibung.
- Behandlung der Privatnutzung (Entnahme bei Anschaffung, laufender Privatanteil).

Bis dahin bleibt das Feature als „fachlich noch nicht freigegeben“ markiert; die Tests
sichern ausschließlich die dokumentierte Rechenlogik, nicht die steuerliche Würdigung.

---

## 7. Abgrenzung

- **#26** Cent-Umstellung ist Voraussetzung (`cost_cents`, `afa_cents`, …).
- **#27** liefert Einstellungen, Ausgaben-Arten mit E1A-Kennzahl und Ausfüllhilfe.
- **#28** trackt B05; dieses Dokument ist die Umsetzungsspezifikation für `EUE-049` … `EUE-061`
  (außer `EUE-060` = laufende Nummern, `not-applicable`).
- Die bestehende „Private Nutzung“-Jahresbuchung bleibt für Geräte ohne Anschaffungswert
  (Miete, Internet) erhalten; das Anlageverzeichnis ersetzt sie für echte Anlagegüter.

---

## 8. Testplan

**Unit (Smoke):**
- Halbjahresregel: Anschaffung März → volle AfA; Juli → halbe AfA; Dezember → halbe AfA.
- Nutzungsdauer 2, 3, 5, 10, 99; Dauer 1 und 100 → 422; Dauer 0 → keine AfA.
- GWG 999,99 € → Hinweis; 1.000,00 € → kein Hinweis.
- Restbuchwert: letztes Jahr → 0,01 €; Verkauf → Buchwertabgang.
- Kette über 5 Jahre: Summe der AfA + Restbuchwert = Kosten (±0,01).
- Privatanteil 0/30/100 %: Betriebsanteil 100/70/0, Beträge gerundet.
- `EUE-054` (49 %), `EUE-053` (falsche Kennzahl), `EUE-058` (Zukunft) → Fehler.
- Idempotenz: Jahresbuchung zweimal → eine Buchung; Änderung des Privatanteils aktualisiert.
- Signierter Dezember → Änderung nur mit Begründung.

**E2E (Playwright):**
- Anlagegut aus einer Buchung anlegen, AfA-Verlauf prüfen.
- Jahresabschluss bucht zwei Anlagegüter, Summen stimmen, Buchung erscheint in Dezember.
- Privatanteil ändern → Betriebsanteil und KPI ändern sich, Vorjahr bleibt unverändert.
- Verkauf → Status und Buchwertabgang sichtbar.
- Ausfüllhilfe an Nutzungsdauer und Privatanteil sichtbar und verknüpft.

---

## 9. Nachtrag: Jahreskorrektur (`EUE-056`/`057`)

Die Arbeitsmappe lässt AfA und Restbuchwert je AV-Zeile überschreiben. Der Prototyp
hat das zunächst bewusst nicht angeboten; damit waren die beiden Warnregeln nur
backend-seitig prüfbar. Seit dem Nachtrag gibt es eine begründungspflichtige
Korrektur:

- `PUT /api/assets/{id}/years/{year}` mit `afa_cents`, `book_value_cents` und
  `change_reason` (Pflicht, wird auditiert).
- Der Privatanteil wird auf die neue AfA angewendet; eine bereits gebuchte
  Dezember-Buchung folgt mit `amount_cents = afa_cents` und
  `business_share = 100 − Privatanteil`.
- **Keine Kaskade:** spätere Jahre bleiben unverändert. Genau dadurch meldet
  `EUE-057` die entstehende Lücke, und `EUE-056` meldet die Abweichung zu
  Kosten/Nutzungsdauer.
- Die wirtschaftlichen Stammdaten (Kosten, Nutzungsdauer, Datum, Anteil, Art)
  bleiben nach der ersten Buchung unveränderlich; ein solcher Versuch endet mit
  422 „Gebuchte Jahre bleiben unveränderlich (EUE-057)“.
- Ein Restbuchwert unter 0,01 € wird abgelehnt.

**Tests:** Korrektur ohne Begründung → 422; konsistente Korrektur im letzten Jahr
→ keine Kettenwarnung, aber `EUE-056`; gebrochener Restbuchwert → `EUE-057`;
Wiederherstellung → beide Warnungen weg; Korrektur eines gebuchten Jahres
aktualisiert dessen Dezember-Buchung, frühere Buchungen bleiben unberührt;
Stammdatenänderung nach Buchung → 422. E2E deckt Formular, Fehlermeldung und beide
Warnungen ab.

---

## 10. Nachtrag: Degressive AfA (#32, DEG-001 … DEG-015)

Die degressive AfA ist eine bewusste Erweiterung gegenüber der Clean-Room-
Arbeitsmappe, die nur linear rechnet. Die Regeln sind aus der Sekundärquelle
<https://kalkuel.at/unternehmensratgeber/abschreibung-oesterreich/> abgeleitet
und stehen mit Primärquellen-Vorbehalt in
`business-rules/degressive-afa-rules.json` sowie `business-rules/DEGRESSIVE-AFA.md`.

**Rechenkern:** `src/Assets/AfaSchedule.php` (getrennt von `Depreciation.php`,
damit beide unter der 400-Zeilen-Grenze bleiben). Alles ganzzahlige Cent,
eine Rundung je Multiplikation, kein Float.

- **DEG-001/002:** `assets.afa_method` (`linear` | `degressive`) und
  `degressive_rate_percent` (1–30, ganzzahlig). Die Methode wird im ersten
  Wirtschaftsjahr gewählt; nach einer Buchung sind wirtschaftliche Änderungen
  gesperrt.
- **DEG-003/004:** `afa = round(restbuchwert × satz / 100)`; im letzten Jahr
  wird der Rest bis auf 0,01 € abgeschrieben. Die Quelle endet bei 0,00 €; der
  Prototyp behält den Erinnerungswert konsistent zur linearen AfA.
- **DEG-005:** Anschaffung ab Juli halbiert den ersten Jahresbetrag. Offen:
  Die Quelle stellt auf die Inbetriebnahme ab, der Prototyp speichert nur das
  Anschaffungsdatum.
- **DEG-006/007/008:** `is_used`, `is_immaterial` (`immaterial_exception` ∈
  `digitalisation`, `eco`, `health`) und `has_special_afa` werden serverseitig
  geprüft; Elektroautos sind ausdrücklich zulässig.
- **DEG-009/010/014:** `POST /api/assets/{id}/switch-to-linear` mit
  `year` und `change_reason`. Genau ein Wechsel, nicht im Anschaffungsjahr,
  nicht in einem gebuchten Jahr, kein Rückwechsel. Ab dem Wechseljahr wird der
  Restbuchwert auf die Restjahre verteilt; frühere Jahre bleiben unverändert.
  Der Wechsel wird als `asset.switch_to_linear` auditiert.
- **DEG-011/012:** `GET /api/assets/compare` (ohne gespeichertes Anlagegut) und
  `GET /api/assets/{id}/comparison` liefern beide Pläne, das empfohlene
  Wechseljahr (linear ≥ degressiv), den Jahresvorteil und den maximalen
  kumulierten Vorteil bis Laufzeitende.
- **DEG-013:** `assets[].switch_hint` ist nicht blockierend; das UI zeigt eine
  Erinnerung in der Liste und im Detail, die nach dem Wechsel verschwindet.
- **DEG-015:** `EUE-056` prüft gegen `AfaSchedule::expectedPlan()` (Methode,
  Satz, Wechseljahr), nicht mehr pauschal gegen linear. `EUE-057` bleibt
  methodenunabhängig. Ein korrekter degressiver Plan erzeugt keine Falschwarnung.

**Beispiel (Quelle, nachgerechnet):** 20.000,00 € / 10 Jahre / 30 % →
6.000,00 / 4.200,00 / 2.940,00 € …; Wechsel in Jahr 8 bei Restbuchwert
1.647,09 € → 549,03 / 549,03 / 549,02 €, Rest 0,01 €. Empfehlung Jahr 8,
Jahresvorteil 54,90 €, maximaler kumulierter Vorteil 258,04 €.

**Tests:** `tests/smoke/assets-degressive.php` (Plan, Halbjahresregel,
Satzgrenzen, Ausschlüsse, Wechsel, Vergleich, Erinnerung, EUE-056) und zwei
Playwright-Tests in `e2e/assets.spec.js` (Vergleich/Erinnerung/Wechsel sowie
Optionsmengen und API-422). Nachweisliste je Regel: `business-rules/DEGRESSIVE-AFA.md`.
