# Review: ODS-Clean-Room-Basis vs. EAR-Implementierung im Portal

**Datum:** 2026-11-22 · **Art:** Unabhängige Vollständigkeits- und Abweichungsprüfung
**Auftrag:** Alle Regeln und Features der Clean-Room-Basis gegen die tatsächliche
Portal-Implementierung prüfen und dem Portalagenten eine improving-orientierte
Übergabe geben.

## Executive Summary

**Gesamturteil: 60/131 Regeln (45,8 %) sind vollständig oder besser umgesetzt;
71/131 (54,2 %) haben Lücken.**

| Bereich | VORHANDEN | BESSER | SCHLECHTER | FEHLT | Summe |
|---|---:|---:|---:|---:|---:|
| B01/B02 Grundlagen, Stammdaten | 15 | 2 | 8 | 5 | 30 |
| B03/B04 Einnahmen, Ausgaben, Vorsteuer | 5 | 0 | 8 | 5 | 18 |
| B05/B06 Anlagen, Reisen | 12 | 0 | 9 | 12 | 33 |
| B07–B09 USt, ESt, SV | 25 | 0 | 9 | 1 | 35 |
| B10/B11 Auswertungen, Validierung | 1 | 0 | 9 | 5 | 15 |
| **Gesamt** | **58** | **2** | **43** | **28** | **131** |

Das deckt sich mit der unabhängigen Feature-Einschätzung von **~55 %**
(Blatt-/Panel-/Persistenz-Ebene). Was vorhanden ist, ist meist **besser** als
die ODS: ganzzahlige Cent/Minuten, konsequente 5-%-Schritte, Brand-Isolation,
kontextgebundene Verschlüsselung, echte Paritäts-Fixture und dompdf-PDF.

Die fünf kritischsten Befunde:

1. **Drei reproduzierte Reise-Fehler** (`TravelCalculator`): 1 Mahlzeit
   Ausland = 0 statt 3.000 ct; `isTravelLeg()` invertiert; 2025-Halbjahres-
   km-Sätze werfen eine Exception. Bestehende Tests zementieren zwei davon.
2. **Keine Trust-/QES-Kette, kein OCR, keine Integrations-API** — drei
   Querschnittsfunktionen der Basis sind vollständig abwesend; die UI nennt
   einen „PDF signieren"-Button, der eine **unsignierte** PDF liefert.
3. **Drei Auswertungsblätter fehlen komplett**: BE-ART, BA-ART, ZA
   (EUE-119/120/122); Projekte nur als Jahres-Marge.
4. **Steuer-/Moduspfade unvollständig**: `/est/calc` ignoriert das gewählte
   Jahr und die Progressionsaufteilung (EUE-089…093, 095, 103–106); Jungunter-
  nehmer ist toter Code (EUE-108); 9225 wird nicht herausgerechnet (EUE-115).
5. **Evidenz ist teils Scheinevidenz**: 32/131 Matrixzeilen „offen",
   `rule-coverage.json` nennt nicht existierende Tests, und der Fixture-Test
   vergleicht Katalog gegen Katalog statt echte Engine gegen Katalog.

## 0. Clean-Room-Grenze und Methode

- Die **Implementierung** im Portal ist Clean-Room: sie durfte nur die
  versionierten Artefakte verwenden.
- Diese **Überprüfung** ist ausdrücklich *nicht* Clean-Room: die ODS wurde
  direkt gelesen (`local/input.ods`, 16 Blätter, 158 definierte Namen,
  47 Datenvalidierungen, 42 bedingte Formatierungen) und mit dem echten
  Portal-Code verglichen. Es wird **keine Kopie der Basis, keine Formel und
  kein Engine-Code** ins Portal übernommen — nur ein Bewertungsbericht.
- Geprüfte Stände:
  - ku-online `HEAD 75f230c07010db91755f78fdbd9ae591d52c7e85` (Arbeitsbaum mit
    unversionierten Doku-/Prompt-Dateien; Regelkatalog unverändert).
  - Portal `HEAD 043826ba2e708199597dcd2527330fb9064d3e4a`
    (`feat(ea-rechnung): complete the 2026 bookkeeping app and rename EÜR to EAR`),
    Arbeitsbaum mit unversionierten Screenshot-/Video-Konfigurationen.
- Bewertungsskala je Regel: **VORHANDEN** (voll umgesetzt und belegt) ·
  **BESSER** (Portal geht über die ODS-Anforderung hinaus) ·
  **SCHLECHTER** (teilweise/abweichend umgesetzt) · **FEHLT** (keine Umsetzung).
- Evidenz ist immer **Datei:Zeile + Testname** aus dem Portal-Code bzw.
  **Blatt/Zelle** aus der Basis. Die Portal-Selbstdokumentation
  (`features/eur/07-verification-matrix.md`) wurde kritisch gegen den Code
  geprüft, nicht übernommen.

## 1. Vollständigkeit des Regelkatalogs (unabhängig nachgerechnet)

| Prüfung | Ergebnis | Nachweis |
|---|---|---|
| Regeln im Katalog | **131** | `domain-rules.json` |
| IDs | **EUE-001…EUE-131**, lückenlos, 131 eindeutig, 0 Dubletten | Zählskript |
| Fachbereiche | 11 (B01:3, B02:27, B03:7, B04:11, B05:13, B06:20, B07:7, B08:18, B09:10, B10:11, B11:4) | `domain-rules.json` |
| Jahresabhängige Regeln | 26 | `validate_domain_rules.py` |
| Blätter der Basis | **16/16** dokumentiert, je ≥1 Regel | `sheet_coverage.py --check` |
| Definierte Namen | 158; 62 nicht wörtlich in Regeln → 45 Spaltenaliasse + 17 Festzell-/Bereichsnamen, alle wertmäßig durch Regeln gedeckt (z. B. `ue_est_1_grenze` → EUE-089, `e1b_einnahmen_kz` → EUE-008/024/034/100) | `analysis/rule_analysis.txt`, Zählskript |
| Datenvalidierungen / bedingte Formatierungen | 47 / 42; 10 Arbeitsmappen-Artefakte als `not-applicable` ausgewiesen | `content.xml`, `coverage-status.md` |

Gates (alle grün):

```text
SCHEMA VALID — 131 rules, 26 year-specific
SHEET COVERAGE OK — every workbook sheet is documented and has rules
PARITY MAPPING OK — 131 rules accounted for + 15 extension rules
```

**Zwischenfazit:** Der Regelkatalog ist vollständig — es gibt keine still
verloren gegangene Regel und kein übersehenes Blatt. Die offene Frage ist
ausschließlich, wie viel davon im Portal angekommen ist.

## 2. Gepinnter Handoff und Paritätsnachweis (Laufzeit)

- `MANIFEST.json` (v2, `artifact_version 2026.3`, 5 Szenarien / 19 Zellen):
  alle 10 gepinnten Dokumente sind **byte-identisch** mit dem aktuellen
  ku-online-Stand (0 Abweichungen).
- Befehl:
  `cd /projects/portal.reisinger.pictures/backend && php artisan test --filter='CleanRoomFixtureIntegrityTest|CleanRoomParityTest'`
- Ergebnis: **29 Tests bestanden, 382 Assertions**, u. a.
  `every pinned document matches manifest checksum and size`,
  `vector count is fixed at five scenarios and nineteen cells`,
  `vector matches fixture canonical` (19 Data Sets),
  `changed expected value is rejected` (Wirksamkeitsnachweis).
- Zusätzlich EUR-Engine-Unit-Tests: **153 bestanden, 1 übersprungen,
  749 Assertions**.

**Zwischenfazit (geschärft):** Die 19 Fixture-Vektoren sind echt implementiert
und grün — aber die Aussagekraft ist begrenzt:

- Für die 12 Konstanten-Vektoren liest `RuleValues::canonicalFor()`
  (`RuleValues.php:27-50,90-103`) die **portal-eigene Kopie von
  `domain-rules.json`** — also dieselbe Quelle, aus der die Fixture-Canonicals
  abgeleitet wurden. `YearTables`, `IncomeTaxTariff` und `EurOverviewService`
  werden davon **nicht** berührt.
- Die 5 Szenario-Vektoren laufen gegen `BookkeepingTotals`, ein framework-
  freies Minimal-Helferobjekt (`CleanRoomParityTest::totals()`), **nicht**
  gegen `E1Statement`/`MonthStatement`/`EurOverviewService`.
- Kein Test prüft den ODS-Basis-Hash `cf5060f…` gegen eine Engine.

Die Werte sind separat in `YearTablesTest`/`tables.json` korrekt belegt —
aber **Fixture-Grün ist kein Beleg für End-to-End-Engine-Korrektheit**, und
die Fixture deckt nur einen kleinen Teil der 131 Regeln ab. Empfehlung:
`CleanRoomParityTest` auf die echte Engine umstellen.

## 3. Regel-für-Regel-Bewertung (B01–B11)

### B01 — Grundlagen & Wirtschaftsjahr · B02 — Stammdaten & Kontenplan (EUE-001…030)

Die **SV-Konstanten EUE-014…022 sind vollständig und exakt** (alle Werte
2024/2025/2026 stimmen mit ODS I57–I66 überein, konsequent Cent/Basispunkte).
Die Lücken liegen bei Stammdaten, Katalog-Verdrahtung und Sichtbarkeit.

| ID | ODS-Feature | Status | Evidenz / Lücke | Empfehlung |
|---|---|---|---|---|
| EUE-001 | D2 Jahr 2026, 2024–2026 | VORHANDEN | `EurYear.php:17-39`; `EurYearsController.php:34-36`; `EurYearsApiTest.php:25-26`; `YearTablesTest.php:66-78` | Pinning 2026 dokumentieren (`EurSettingsController.php:28`) |
| EUE-002 | D1 Warnung >2026 | SCHLECHTER | `EurYear.php:52-58`, Test `EurYearsApiTest.php:31-33`; **UI liest `warnings` nicht** (`ManagementEurView.tsx:234-241`) | Warnung im Jahres-Selector rendern |
| EUE-003 | D1 Warnung <2024 | SCHLECHTER | `EurYear.php:61-66`; Wortlaut ohne „Lifetime-Lizenz"; gleiche UI-Lücke | Text angleichen + anzeigen |
| EUE-004 | D3–D7 Firma, ÖNACE 11–990 | SCHLECHTER | `EurSettingsController.php:25-49` nur 3 Settings; keine ÖNACE-Validierung, kein E1A!C6-Hinweis; Matrix-Zitat `ReferenceTablesTest.php` ohne Firmenbezug | EAR-Stammdatenformular + `min:11\|max:990`, Spiegelung in PDF |
| EUE-005 | H3/H4 Zahlarten + Startsaldo | SCHLECHTER | `ReferenceTables.php:116-127` (Werte korrekt null), `EurEntryController.php:418` Freitext; kein ZA-Abgleich | Zahlarten-Dropdown binden, ZA nachrüsten |
| EUE-006 | L3–L5 Projekte in allen Journalen | SCHLECHTER | `ReferenceTables.php:132-138`, `EurProject`+DB da, **aber** `EurEntryDialog.tsx` ohne Projektfeld, `EurHoursPanel.tsx:151-155` Freitext | Projektfeld an `eur_project_id` binden |
| EUE-007 | Art→KZ Ausgaben, Fallback 9230 | VORHANDEN | `ReferenceTables.php:24,75-78`; `JournalRules.php:96-106`; `JournalRulesTest.php:14-23` | — |
| EUE-008 | Art→KZ Einnahmen, Fallback 9040 | VORHANDEN | `ReferenceTables.php:27,83-86`; `JournalRules.php:84-94`; `E1StatementTest.php:58` | — |
| EUE-009 | Betriebsanteile, 5-%-Schritte | **BESSER** | `ReferenceTablesTest.php:46-63` (0–100, %5); `JournalRules.php:78-82`; `EurSettingsController.php:40` | ODS stetig 0–1; Portal enforcet Nutzerentscheidung #30 |
| EUE-010 | 7 USt-Arten | VORHANDEN | `ReferenceTables.php:91-97`; `JournalRulesTest.php:112-116` (RC 4,9 % abgelehnt) | — |
| EUE-011 | Transportmittel→Typ | SCHLECHTER | `ReferenceTables.php:102-111` (13 korrekt), aber `TravelCalculator.php:293-302` mappt unbekanntes Mittel auf PKW statt „ohne Zuordnung" | `null`/`Andere` + km-Geld 0 |
| EUE-012 | 13 Feiertage, Osterformel | **BESSER** | `Holidays.php:17-101` (Meeus, ganzzahlig); `ReferenceTablesTest.php:124-142`; berechnet auch ≥1583 | — |
| EUE-013 | Länderpauschalen, AT 26,4/15→30/17 | SCHLECHTER | 174 Länder + 30/17 korrekt (`TravelAllowances.php:17-90`), **aber 2024-Variante fehlt**, `for()` lehnt Jahr ≠2026 ab (`:64-70`) | 2024-Werte nachziehen oder 2026-Gate dokumentieren |
| EUE-014 | max BGL 7070/7525/8085 | VORHANDEN | `YearTables.php:26-30`; `SocialInsurance.php:83-93`; `MonthStatementTest.php:67-74` | — |
| EUE-015 | KV 6,8 % | VORHANDEN | `YearTables.php:32-38` (`kv_satz=>680`); `SocialInsurance.php:119-121` | — |
| EUE-016 | Mindest-KV 35,45/37,47 | VORHANDEN | `YearTables.php:27-29` (3545/3747 ct); Test `:183-184` | — |
| EUE-017 | GSVG-PV-Min 95,91/101,95 | VORHANDEN | `YearTables.php:27-29`; inkl. I61 (`ns_gsvg`) | — |
| EUE-018 | FSVG-PV-Min 103,69/110,22 | VORHANDEN | `YearTables.php:27-29` (10369/11022 ct) | — |
| EUE-019 | GSVG-PV 18,5 % | VORHANDEN | `YearTables.php:33-38` (1850 bp) | — |
| EUE-020 | FSVG-PV 20 % | VORHANDEN | `YearTables.php:33-38` (2000 bp) | — |
| EUE-021 | SVS 1,53 % | VORHANDEN | `YearTables.php:33-38` (153 bp); Modus-Ausnahmen in `SocialInsurance.php:109-150` | — |
| EUE-022 | UV 11,35/12,07/12,95 | VORHANDEN | `YearTables.php:27-29`; `EurOverviewService.php:68` (×12) | `accidentYearlyCents()` → `accidentMonthlyCents()` umbenennen |
| EUE-023 | 27 KZ inkl. E1B 9500–9530 | SCHLECHTER | 22 E1A-KZ via `E1aHelpTexts.php:28-68` vorhanden; **6 E1B-KZ fehlen** im Artefakt, kein maschinenlesbarer Kontenplan | Kontenplan-Artefakt mit allen 27 KZ + Test |
| EUE-024 | Einnahmen-KZ 9040/9050/9060/9090/9460 | VORHANDEN | `e1a-ausfuellhilfe.md:58-61`; `JournalRules.php:108-114`; `E1StatementTest.php:41` | — |
| EUE-025 | Duplikatwarnung Zahlarten | FEHLT | Kein Code; read-only Artefakt macht Duplikate strukturell unmöglich | Als N/A dokumentieren oder Warnung nachrüsten |
| EUE-026 | Duplikatwarnung Projekte | FEHLT | `EurProject` editierbar, `V061:45-49` `name` nur index, nicht unique | Unique `(brand,name)` + Warnung |
| EUE-027 | Duplikatwarnung Ausgaben-Arten | FEHLT | `expenseKinds()` read-only; keine Warn-Logik | Als N/A dokumentieren |
| EUE-028 | Duplikatwarnung Einnahmen-Arten | FEHLT | Wie 027 | Wie 027 |
| EUE-029 | Verwendungszähler ❯/99+/999+ | FEHLT | Kein `usage_count` in `EurReferenceController.php:67-98` / `useEur.ts:13,32-37` | Aggregation im Reference-Endpoint (optional) |
| EUE-030 | Wertebereiche, 5-%-Schritte | VORHANDEN | `JournalRules.php:55-58,78-82`; `EurAssetController.php:89,92`; `EurTaxStatementService.php:119-137`; mehrere Tests | — |

**Area-Urteil B01/B02:** 15 VORHANDEN · 2 BESSER · 8 SCHLECHTER · 5 FEHLT.
Alle zitierten Testdateien existieren tatsächlich; die SV-Werte-Behauptungen
sind code- und testgedeckt. Die „offen"-Zeilen 025–029 sind dagegen wirklich
nicht umgesetzt.

### B03 — Einnahmen · B04 — Ausgaben & Vorsteuer (EUE-031…048)

Harte Rechenregeln sind solide: Betriebsanteil-Multiplikation, Fallbacks
9040/9230, „Anteil 0 ⇒ keine KZ", E1B-Umleitung 9460, Cent-Arithmetik,
IG/RC-Sätze inkl. 4,9 %. Schwach ist die **Validierungs-/Abgleichschicht**.

| ID | ODS-Feature | Status | Evidenz / Lücke | Empfehlung |
|---|---|---|---|---|
| EUE-031 | E!Q3 = M3×Anteil | VORHANDEN | `EurEntryController.php:67,439-445`; `E1StatementTest.php:58-70` (1000 ct @50 % → 500 ct) | — |
| EUE-032 | E!O3 KZ, Fallback 9040 | VORHANDEN | `JournalRules.php:85-94`; `E1Statement.php:54-56,160-176`; `JournalRulesTest.php:14-22` | Fallback zusätzlich beim Schreiben setzen (API/CSV ohne `e1a_code`) |
| EUE-033 | Anteil 0 = private Einnahme | SCHLECHTER | Katalog+Logik da (`tables.json:206-213`), **aber** `EurEntryDialog.tsx:23-30,66-77` defaultet 100 % → Private Einnahme landet mit KZ 9040 in E1A | `kind.share_percent` aus Referenztabelle ins Formular übernehmen; 0 % ⇒ KZ leer |
| EUE-034 | Mieteinnahmen → E1B 9460 | VORHANDEN | `JournalRules.php:108-114`; `E1StatementTest.php:40-55` | — |
| EUE-035 | E!D3 laufende Nummer | FEHLT | Kein Code/Test; `rule-coverage.json:337-340` nennt DB-ID als Ersatz — nicht gleichwertig (nicht dicht, nicht anteilabhängig) | Dichte `line_no` nur bei betrieblichem Betrag oder bewusst als akzeptiert dokumentieren |
| EUE-036 | E!B3 Zeilenchecks | SCHLECHTER | `JournalRules.php:161-258`; `JournalRulesTest.php:88-101` assertet `ok=true` bei fehlenden Pflichtfeldern; kein Belegnummer-Check; `EurEntriesPanel.tsx:71-84` zeigt `checks` **nicht** | Checks vervollständigen, ⚠-Marker, Referenzkatalog prüfen, UI-Spalte rendern |
| EUE-037 | Monatsnamen, Tag 1–31 | SCHLECHTER | `JournalRules.php:20-24,55-69`; API nutzt Zahlen 1–12; `validMonth()` ungenutzt, Tag optional | Monatsprüfung in `checks()` nutzen; Abweichung „Zahl statt Name" dokumentieren |
| EUE-038 | A!Q3 keine VST-Spalte | VORHANDEN | `E1StatementTest.php:58-70` (9230 → 500 ct) | — |
| EUE-039 | IG-Erwerb 20/10/13/4,9 % | SCHLECHTER | Sätze+KZ korrekt (`VatReturnService.php:27-32,216-224`), **aber** Basis = betrieblicher Betrag statt Brutto `L3`; `vat_kind` fehlt im Buchungsdialog | Basis klären/vereinheitlichen (`JournalRules` rechnet brutto, Service netto), `vat_kind` im UI |
| EUE-040 | RC 20/10/13 % (kein 4,9) | SCHLECHTER | Wie 039; `JournalRulesTest.php:45-62,115` | Wie 039 |
| EUE-041 | A!O3 Fallback 9230 | VORHANDEN | `JournalRules.php:97-106`; `EurEigenbelegController.php:50-62`; `EurEigenbelegApiTest.php:13-50` | — |
| EUE-042 | A!B3 Belegpflicht/WEB/FSVG | SCHLECHTER | Nur USt-Art-Check (`JournalRules.php:234-255`); kein Belegpflicht-, Monat/Tag-, WEB- oder FSVG-Hinweis | Hinweise nach ODS ergänzen, Modus aus Einstellungen lesen |
| EUE-043 | KZ 9100 ⇒ WEB-Pflicht | FEHLT | `WarehouseReconciliation` prüft nur WEB→A, nicht A→WEB | Gegencheck mit FSVG-Ausnahme und Bullet-Hinweis |
| EUE-044 | A!D3 laufende Nummer | FEHLT | Wie EUE-035 | Wie EUE-035 |
| EUE-045 | WEB nur bei `WE*`, Anteil aus Art | FEHLT | `EurWarehouseController.php:52-69`; Migration `V062:73-85`: kein Anteil, keine Art, kein Präfix | Schema + `Q=N×Anteil` nur bei `WE*`; Tests mit/ohne Präfix |
| EUE-046 | WEB→A-Hinweise | SCHLECHTER | `WarehouseReconciliation.php:32-110,140-144`; Tests da, aber keine laufende Nummer, keine „fehlt noch/nicht gefunden"-Differenzierung, Hinweise nur im Monatspanel | Texte angleichen, in WEB-UI anzeigen |
| EUE-047 | WEB-Pflichtfelder | FEHLT | `EurWarehouseController.php:52-59`: `day` optional, kein Lieferant/keine Art/keine Eindeutigkeit | Controller + Schema nachziehen |
| EUE-048 | Anteil 0 = private Ausgabe | SCHLECHTER | Logik+Katalog korrekt (`tables.json:158-167`, `EurEigenbelegApiTest.php:52-70`), aber Dialog defaultet 100 % → Fallback 9230 | Wie EUE-033 |

**Area-Urteil B03/B04:** 5 VORHANDEN · 0 BESSER · 8 SCHLECHTER · 5 FEHLT.
Warnung zur Evidenz: `rule-coverage.json` nennt für EUE-036/037 **Testnamen,
die im Repo nicht existieren** (z. B. „EUE-036 bullet • Belegnummer fehlt.",
„EUE-037 invalid month name rejected with 422").

### B05 — Anlagen & AfA · B06 — Reise & Fahrt (EUE-049…081)

**Für B05/B06 gibt es keinen einzigen Paritätsvektor** in der Fixture. Drei
Abweichungen wurden per `php -r` gegen die echte Engine reproduziert:

```text
1 meal foreign (day 3000ct): 0 ct (ODS: 3000)
isTravelLeg(240min,50km,false): false (ODS: true)
isTravelLeg(60min,50km,false): true  (ODS: false)
km motorrad 2025 ERROR: Regel EUE-063: unlesbarer Parameterwert.
km fahrrad  2025 ERROR: Regel EUE-064: unlesbarer Parameterwert.
```

| ID | ODS-Feature | Status | Evidenz / Lücke | Empfehlung |
|---|---|---|---|---|
| EUE-049 | Halbjahres-AfA | VORHANDEN | `DepreciationCalculator.php:71-84,120-144`; Test `:52-57` (0,5/1 korrekt) | Vektor 3 Jahre/7 Monat ergänzen |
| EUE-050 | Restbuchwert mind. 0,01 € | VORHANDEN | `DepreciationCalculator.php:89-92`; Test `:59-64`; Fall „0 Jahre → Kosten" durch `min:2` nicht darstellbar | 0-Jahre-Kategorie klären |
| EUE-051 | GWG-Hinweis 1000 € | FEHLT | `isLowValueAsset():53-56` nur **im Test**; kein Aufruf in Controller/UI | Hint in `serialize()`/Panel ausgeben |
| EUE-052 | Nutzungsjahre 2–99 | VORHANDEN | `EurAssetController.php:275`; `DepreciationCalculator.php:39-47`; ODS erlaubt 0 = nicht abnutzbar | 0-Sonderfall klären |
| EUE-053 | AfA-KZ-Whitelist | FEHLT | `categoryCode():381-391` extrahiert nur Ziffernprefix; `validateAsset:269-282` ohne KZ-Regel → KZ 9230 wird akzeptiert | Whitelist 9130/9134/9135/9500 mit 422 |
| EUE-054 | Privatvermögen <50 % | VORHANDEN | `EurAssetController.php:276` (`business_share min:50`); Test `:43-50` | Hinweistext „Privatvermögen" in 422 |
| EUE-055 | Entnahme im E-Blatt | FEHLT | Kein Code/Feld/Test; `is_private_investment` ist anderes Konzept | `entnahme_booked` + Abgleich receipts |
| EUE-056 | Vorjahres-AfA Toleranz 0,01 € | FEHLT | Kein Treffer in `backend/`; keine Korrektur-API | Jahr-Korrektur + `change_reason` + Audit |
| EUE-057 | Vorjahreskette/Restbuchwert | FEHLT | Kein `chain_error` in `serialize():457-488`; `switchToLinear` schützt nur gebuchte Jahre | Kettenprüfung über `eur_asset_years` |
| EUE-058 | Zukunftsjahr-Warnung | FEHLT | `validateAsset:273` nur `date`; 2027 wird akzeptiert | Validator `year(purchase_date) ≤ 2026` |
| EUE-059 | Pflichtfelder AV | VORHANDEN | `validateAsset:269-275` | — |
| EUE-060 | Laufende Nummer AV | FEHLT | `serialize():475-486` ohne Zähler; ku-online selbst N/A | Als `not-applicable` ausweisen oder Zähler |
| EUE-061 | KZ 9130-Fallback | VORHANDEN | `EurAssetController.php:403-434`; `EurAssetPrivateInvestmentTest.php:14-30` | FK auf Ausgaben-Art statt String-Kategorie |
| EUE-062 | PKW km 0,50 (2026) | VORHANDEN | `TravelCalculator.php:27-40,63-84`; `YearTables.php:90-105`; Test `:27-44` | — |
| EUE-063 | Motorrad inkl. 2025 H1/H2 | SCHLECHTER | **`parseValue("0,50 H1 / 0,25 H2")` wirft Exception** (`RuleValues.php:170-185`); `YearTables` hat Logik, wird nicht genutzt; API erlaubt 2025 → 500 | `YearTables::travelPerKmCents` verwenden; 30.06./01.07.-Test |
| EUE-064 | Fahrrad inkl. 2025 H1/H2 | SCHLECHTER | Identisch zu 063 | Wie 063 |
| EUE-065 | km-Kontingente kappen | FEHLT | `kilometreQuota():53-58` + Test da, **nie aufgerufen**; 40.000 km werden voll vergütet | Cap in `kilometreAllowanceCents`, bereits gebuchte km abziehen |
| EUE-066 | Mitfahrer max 6/1 | VORHANDEN | `TravelCalculator.php:43-50,69-78`; Test `:35-44`; Controller erlaubt global 6 → Motorrad-500 | Controller fahrzeugspezifisch validieren |
| EUE-067 | KM-Stand XOR Distanz | FEHLT (API) | `distance():89-111` + Test korrekt, aber API akzeptiert nur `kilometres` | Start/End-KM in API+UI, `distance()` aufrufen |
| EUE-068 | 1 Mahlzeit Ausland = voll | SCHLECHTER | **`TravelCalculator.php:129` Faktor `'0'`**; Test `TravelCalculatorTest.php:70` asserts 0 → zementiert Fehler. Referenz `travel-engine.md:59`: 3530 ct | Faktor `'1'`, Test auf 3000/3530 korrigieren, Fixture-Vektor |
| EUE-069 | Nächtigungspauschalen | VORHANDEN | `TravelCalculator.php:141-177`; Test `:86-94` (450/585 ct) | 5-Nächte-Zeilenlimit prüfen |
| EUE-070 | 5-/15-Tage-Regel | VORHANDEN | `TravelCalculator.php:155-157`; Test `:74-84` | Statuslabel `AH3` in API ergänzen |
| EUE-071 | Hin-/Rückfahrt ≥3 h | SCHLECHTER | **`isTravelLeg():229-240` invertiert** (`< 3*60` statt `>=`); Tests `:104-111` falsch herum | Bedingung drehen, AI3/AJ3/AE3 + 50/25 km nachbilden |
| EUE-072 | BT/PT → Betriebsanteil | VORHANDEN | `TravelCalculator.php:207-224`; Test `:96-102`; Controller nutzt Input stattdessen | `businessSharePercent()` verdrahten |
| EUE-073 | Privatreise → 0 + Status | FEHLT | `isPrivateTrip():283-286` nie aufgerufen; kein `purpose`-Feld | `purpose` ergänzen, Nullstellung + Status |
| EUE-074 | NETWORKDAYS/Feiertage | FEHLT | `Holidays.php:43-101` nur in `EurReferenceController.php:88` (Anzeige); keine `workingDays()` im Rechenpfad | `workingDays(start,end,year)` implementieren |
| EUE-075 | Reisejahr/Datum-Warnung | SCHLECHTER | `validateTrip():247-256` hart 2026, kein Formatcheck, kein Controller-Aufruf | `EurYear` nutzen, Datumsparser + Aufruf |
| EUE-076 | Ende ≥ Start + Zeit | SCHLECHTER | `validateTrip():258-260` Stringvergleich, keine Zeit/Toleranz | `start_time/end_time` (Minuten) nachbilden |
| EUE-077 | Belegnummer + Duplikate | SCHLECHTER | `validateTrip():262-264` nur nicht-leer; API ohne `receipt_no` | Row-Modell mit `receipt_no` + Duplicate-Check |
| EUE-078 | Land/Transportmittel im Katalog | VORHANDEN | `EurTravelController.php:29,32`; Test `:124-138` | — |
| EUE-079 | Veranlassung nur 1 Zeile | SCHLECHTER | `validateTrip():274-277` prüft Felder, die API/UI gar nicht haben | Felder in `eur_travel_rows` ergänzen |
| EUE-080 | Stunden des Belegs → 3 h | FEHLT | `HoursLedger::sumMinutes` nur project/month, **kein voucher**; `EurHourController.php:53-69` speichert keine Belegnummer | `voucher` + `sumMinutesForVoucher()`; mit 071 verknüpfen |
| EUE-081 | Reisekosten → Monat/Projekt | SCHLECHTER | `TravelCalculator.php:182-202`; Controller summiert, aber keine Persistenz, kein KZ 9160, keine Aggregation | `eur_travel_trips/rows` + 9160-Buchung |

**Area-Urteil B05/B06:** 12 VORHANDEN · 0 BESSER · 9 SCHLECHTER · 12 FEHLT.
AfA-Halbjahr, Restbuchwert und DEG-Rechner stimmen; Reise-Engine hat drei
**reproduzierte** Kongruenzfehler und viele Unit-Inseln ohne Verdrahtung.

**DEG-Scope (separat, nicht im Floor):** 11/15 umgesetzt (001–005, 009–012,
014); fehlend: 006–008 (Ausschlussregeln), 013 (Erinnerung), 015 (Abgleich =
EUE-056/057). `degressive-afa-coverage.json` markiert **alle 15 `covered`**
und zitiert E2E-Namen, die im Portal-Repo **nicht existieren** (0 Treffer für
„degressive" in `frontend/tests/e2e`). Nach der ku-online-Regel „covered
erfordert Smoke **und** Playwright" ist diese Deklaration sachlich falsch.
Divergenz: `features/owner-decisions.md:343` hat DEG in Scope entschieden,
`ku-online/verification/requirements/deg-scope.md:3` sagt noch „offen" —
dort nachziehen.

### B07 — USt · B08 — ESt · B09 — SV (EUE-082…115, EUE-131)

Konstanten und Tabellen sind **vollständig korrekt** (Tarifgrenzen 2024/25/26,
20/30/40/48/50 %, Grundfreibetrag 33.000 €/15 %, alle SV-Werte). Die Lücken
liegen in den **Berechnungs- und Moduspfaden**.

| ID | ODS-Feature | Status | Evidenz / Lücke | Empfehlung |
|---|---|---|---|---|
| EUE-082 | 8 USt-KZ | VORHANDEN | `VatCalculator.php:19-41`; `VatReturnServiceTest` | — |
| EUE-083 | KZ 016/000 aus Einnahmen | VORHANDEN | `VatCalculator.php:65-69`; Test `:23-31` | — |
| EUE-084 | KZ 057 ROUNDDOWN | VORHANDEN | `VatCalculator.php:71-74,204-207`; Test `:33-44` | Cent-Kanonik dokumentieren |
| EUE-085 | IG 20/10/13/4,9 % → KZ | VORHANDEN | `VatCalculator.php:76-95,183-199`; Test `:46-58` | — |
| EUE-086 | Zahllast | VORHANDEN | `VatCalculator.php:97-110`; Test `:60-70` | Rundung gegen 0 dokumentieren |
| EUE-087 | Quartalsaggregation | VORHANDEN | `VatCalculator.php:121-177`; Test `:83-99` | — |
| EUE-088 | 2 Erklärungsblöcke, Block 2 Jahressummen | SCHLECHTER | `EurVatDeclarationService.php:27-51,70-81`; **beide Blöcke enthalten denselben Monatswert**, AD5:AD14/AD20 fehlen; Matrix nennt falsche Tests | Block 2 mit `year_totals` füllen |
| EUE-089 / EUE-090 / EUE-091 / EUE-092 / EUE-093 | Tarifstufen 1–5 (2024/25/26) | VORHANDEN* | `YearTables.php:16-23`; `YearTablesTest:14-20`; Werte exakt. **Aber** `/est/calc` nutzt 2026-pinnende `ReferenceTables` (`EurIncomeTaxController.php:50-51`) | `YearTables::tariffStages($year)` verwenden oder 2024/25 aus Validierung nehmen |
| EUE-094 | Progressionsrechnung 5 Stufen | VORHANDEN | `IncomeTaxTariff.php:87-113`; `MonthStatement.php:270-291` (BCMath) | — |
| EUE-095 | Gesamteinkommen je Modus, KZ 245 | SCHLECHTER | `EurTaxStatementService.php:36-84`: KZ 245/260 nur Echo, Basis nur `profit_cents`; U14 nicht implementiert | `IncomeTaxService::totalIncome()` aus `tax-engine.md` portieren |
| EUE-096 / EUE-097 | Grundfreibetrag 33.000/15 % | VORHANDEN | `YearTables.php:59-65`; Fixture-Vektor, aber via `RuleValues` nicht `YearTables` | Direkt gegen `YearTables::basicAllowance()` prüfen |
| EUE-098 | Grundfreibetrag-Berechnung | VORHANDEN | `IncomeTaxTariff.php:118-144`; `MonthStatement.php:91,106` | — |
| EUE-099 | SUMIF E1A inkl. WEB/FR/AV | SCHLECHTER | `E1Statement.php:37-103`; WEB→9100, FR→9160, AV→9130/34/35 **nicht automatisch eingerechnet** | SUMIF-Zusammenführung nachziehen |
| EUE-100 | E1B 9460/9530 | VORHANDEN | `E1Statement.php:37-103`; `E1StatementTest:26-49` | `e1b_result_cents` als Feld ergänzen |
| EUE-101 / EUE-102 | Pauschalen 9215/9217/9216, Sperre 9275 | VORHANDEN | `EurTaxStatementService.php:117-143`; Tests `:76-96`; Nuance 9217 unterdrückt 9216 **nicht** korrekt | — |
| EUE-103 | Est-Aufteilung U24/U25/U27/U29 | SCHLECHTER | `EurTaxStatementService.php:62-70`; keine Progression, kein Abzug Vorauszahlung/Lohnsteuer; KZ nur Echo | `IncomeTaxService::allocate()` portieren |
| EUE-104 | Prognose/Monate | SCHLECHTER | `EurTaxStatementService.php:94-109`: `array_sum($e1b)` **addiert Einnahmen + Werbungskosten** statt zu verrechnen; kein J12/K12 | `income_cents−expense_cents`; Hochrechnung + Monatsverteilung |
| EUE-105 / EUE-106 | Modi Bruttolohn/andere Gewinne | SCHLECHTER | `EurTaxStatementService.php:25,52-60`: nur `total,business,rental,forecast`; die zwei ODS-Varianten und Y17 fehlen | Enum um `gross_wage`/`other_profits` erweitern |
| EUE-107 | 4 SV-Modi | VORHANDEN | `SocialInsurance.php:20-24,117-128`; `SocialInsuranceModesTest:38-71` | — |
| EUE-108 | Jungunternehmer | SCHLECHTER | Konstante `MODE_JUNGUNTERNEHMER` + Test (44964 ct) vorhanden, **aber nicht über Setting/API/UI erreichbar** — toter Code | Toggle + KV-Satz-'-'-Anzeige ergänzen |
| EUE-109 | BMG + 9225, Deckel 97020 | VORHANDEN | `EurOverviewService.php:35-36`; `EurSummaryController.php:44-52`; Fixture | — |
| EUE-110 / EUE-111 / EUE-112 | KV/PV/UV/SVS | VORHANDEN | `EurOverviewService.php:40-68`; `YearTables.php:27-37`; alle Fixture-Werte getroffen | `accidentYearlyCents()` liefert Monatswert → umbenennen |
| EUE-113 | Mindest-Sockel 12 Monate | VORHANDEN | `EurOverviewService.php:70-83`; numerisch ≈16080 ct; Vereinfachung: portalweit ein Wert statt nur einkommensloser Monate | Als Vereinfachung dokumentieren |
| EUE-114 | Jahresbeiträge / 12 | VORHANDEN | `EurOverviewService.php:40-75` | — |
| EUE-115 | 9225 herausrechnen (außer UV) | SCHLECHTER | `EurSummaryController.php:35-60`: `expense` enthält 9225 → `profit_cents` um den Betrag zu niedrig; UV-Ausnahme fehlt | 9225 aus Ausgaben herausrechnen + ODS-F12-Test |
| EUE-131 | FSVG-Bezeichnung/GSVG-Fallback | FEHLT | Kein Code/Test; Portal „repariert" still 110,22 € statt ODS-Ist 101,95 € (Δ 8,27 €/Monat) | Bewusste Entscheidung: Defekt parity-getreu oder als genehmigter Fix mit Rule-ID |

**Area-Urteil B07–B09:** 25 VORHANDEN · 0 BESSER · 9 SCHLECHTER · 1 FEHLT
(35 Regeln; die im Prompt angenommene Zahl 36 war ein Zählfehler — B09 hat
9 + EUE-131 = 10 Regeln). Zusätzlich ausgeführt: Bereichs-Suite **66 Tests /
332 Assertions** grün.

> **Hinweis zu EUE-089…093:** „VORHANDEN*" bedeutet: Die Werte und die
> `MonthStatement`-Progressionsrechnung sind korrekt; nur der
> `/est/calc`-Pfad ignoriert den Jahr-Parameter. Deshalb in der Gesamtzählung
> als VORHANDEN geführt, aber in der Empfehlungsliste priorisiert.

### B10 — Auswertungen & Abgleich · B11 — Validierung (EUE-116…130)

| Regel-ID | ODS-Feature (Blatt/Zelle) | Status | Evidenz im Portal | Begründung / Lücke | Empfehlung |
|---|---|---|---|---|---|
| EUE-116 | Monat!P5 `OFF,Jänner..`, P7 `💡` | SCHLECHTER | `JournalRules.php:27,50`; `MonthStatement.php:114`; `MonthStatementTest.php:41–44`; `EurMonthController.php:35–38`; `EurMonthPanel.tsx:141–146` | 💡-Text da und getestet, aber kein OFF-Zustand; `hint` wird im Panel nicht gerendert (`useEur.ts:256–268`) | OFF-Option + Hinweisbanner ergänzen oder als Workbook-Schalter dokumentieren; mindestens `data.hint` anzeigen |
| EUE-117 | Monat!J13–J23, S/X USt | SCHLECHTER | `MonthStatement.php:44–48,54–86,306–312`; `MonthStatementTest.php:25–45` | E/A/WEB+Stunden da; AV (Vorjahres-AfA im Jänner), FR-Betrag (nur Stundenzeilen mit 0 ct), getrennte USt-Spalten fehlen | `totals` um `av_cents`, `fr_cents`, `vat_acquisition_cents`, `vat_rc_cents` erweitern |
| EUE-118 | Monat!A254/E104/A54, INDEX D404 | SCHLECHTER | `MonthStatement.php:68–73,122–183`; `MonthStatementTest.php:25–45` | Einfacher Monatsfilter statt SMALL/IF-INDEX; WEB nicht paarweise, keine AV-Zeilen, FR nur Sammelzeile | AV-Zeilen, WEB-Paarung, FR-Einzelzeilen ergänzen |
| EUE-119 | BE-ART!E3, E154/Q154 ✓✓ | **FEHLT** | `EurAnalyticsController.php:25–43`; `MonthStatement.php:224–229` nur Check-Code | Keine Einnahmen-Art×Monat-Matrix, keine Summen/%, kein ✓✓-Abgleich | Endpunkt `by_income_type` mit ✓✓/Delta gegen `SUM(e_betriebseinnahmen)` |
| EUE-120 | BA-ART!E3, E153, Q155 ✓✓ | **FEHLT** | `EurAnalyticsController.php:50–77`; `MonthStatement.php:230–235` nur Check-Code | Keine Ausgaben-Art×Monat-Matrix aus A/AV/WEB, FR-Sonderzeile fehlt, kein Gesamt-✓✓ | Wie 119, plus A/AV/WEB/FR-Summen je Art |
| EUE-121 | Projekte!E1/E3/E10, D5/D7/D78, 9225 | SCHLECHTER | `EurAnalyticsController.php:25–43,50–77,83–101`; `HoursLedger.php:53–56`; `EurAnalyticsApiTest.php:29–67` | Nur Jahres-Sicht, keine Projekt×Monat-Kreuztabelle; keine Stunden, Vorjahres-AfA, 9225-Bereinigung, Differenzzeilen | Projekt×Monat-Matrix mit Gewinn/Stunde bauen |
| EUE-122 | ZA!E7/E10/E13/E14 ✓/✓✓ | **FEHLT** | `EurEntry.php:24`, `EurEntryController.php:97,296,418,485`; `ReferenceTables.php:116–127`; `MonthStatement.php:236–241` nur Check-Code | `payment_method` + Startsaldo persistiert, aber keine Zu-/Abflüsse, Saldo, ✓/✓✓ oder Δ | Endpunkt `/eur/payment-reconciliation` je Zahlart×Monat |
| EUE-123 | Stunden!C3/E3/F3, Ü!M12, FR!AE3 | SCHLECHTER | `HoursLedger.php:19–119`; `MonthStatement.php:49–52,185–208`; `HoursLedgerTest.php:23–62` | Kern solide (Minuten, 3-Stunden-Regel 180), aber Zwölfteleinheiten fehlen und keine Verknüpfung mit Projekte-Matrix | Stunden in Analytics/121 aufnehmen, Zwölfteleinheiten klären |
| EUE-124 | Monat!O29:O37 KZ-Zeilen | SCHLECHTER | `VatReturnService.php:24,35–49` (8 KZ berechnet); `MonthStatement.php:66,84` verwirft sie; `EurPdfService.php:65` nur eine Zeile | KZ im Rechenkern vorhanden, aber nicht in Monatsansicht/PDF | **Kleinster Aufwand, größter Gewinn:** `vat.kennzahlen` durchreichen, KZ-Tabelle im Panel + PDF rendern |
| EUE-125 | Ü!I12=160,80, I13/I14/I16 | SCHLECHTER | `EurOverviewService.php:28–84,90–100`; `EurOverviewServiceTest.php:24–41` | Monats-SV da, aber kein expliziter Mindest-Sockel 160,80 €, keine SV-Differenz/Verlustbasis/Monatsbasis | Werte im Overview exponieren und mit 16080 ct testen |
| EUE-126 | Ü!J12/K12/N5/I16/M12 | **FEHLT** | `MonthStatement.php:88–108`; `EurOverviewService.php:28–84`; `EurMonthPanel.tsx:244–266` | Keine anteilige EST+SV je Monat, kein Reingewinn, kein Reingewinn/Stunde | `monthlyResults`-Struktur mit `est_sv_cents`, `net_profit_cents`, `net_per_hour_cents` |
| EUE-127 | CF `⚠` in E/A/AV/FR/WEB!B3; Monat!P7 | SCHLECHTER | `JournalRules.php:27,50,138–149`; `EurMonthPanel.tsx:68–69,200–205,229–236`; `EurEntriesPanel.tsx:60–88` | Zeilenchecks im Monats-Panel sichtbar, **im Buchungsjournal nicht**; kein Spalte-B/CF | `checks` in `EurEntriesPanel` als Hinweisspalte rendern, `data.hint` im Kopf |
| EUE-128 | colorScale Ü/BE-ART/BA-ART/Projekte, ✓ in ZA | **FEHLT** | Nur statische Badges in `EurMonthPanel`/`EurOverviewPanel`/`EurAnalyticsPanel.tsx:43–50` | Keine Farbskalen, keine ✓-Hervorhebung; Eingriffsblätter fehlen ohnehin | Nach 119–122 umsetzen oder explizit `not-applicable` dokumentieren |
| EUE-129 | BE-ART!Q154, BA-ART!Q155, ZA!E14; Rundung 1 | SCHLECHTER | `MonthStatement.php:217–268`; `MonthStatementTest.php:47–64`; `WarehouseReconciliation` | Vier Checks vorhanden, aber sie vergleichen E1A-Aggregate statt Matrix-Summen; kein ✓✓/`Fehler (Δ €)`, kein Rundungsparameter | Echte Matrix-Totale speisen, ✓✓/Δ mit 1-Stellen-Rundung oder bewusst „exakte Cent" als BESSER dokumentieren |
| EUE-130 | A!D1001/P1001 Footer-Relikt | **VORHANDEN (N/A)** | Keine Entsprechung nötig; `JournalRules.php::validVatType`, `ReferenceTables.php` | Meta-Regel: Footer-Zeile darf *nicht* als Regel gelten. Portal verhält sich korrekt; Matrix-Feld „offen" ist falsch | Keine Code-Änderung; als `not-applicable (workbook footer artifact)` führen |

**Area-Urteil B10/B11:** 1 × VORHANDEN (N/A) · 0 × BESSER · 9 × SCHLECHTER · 5 × FEHLT.
Die klassischen Auswertungsblätter **BE-ART, BA-ART, Projekte, ZA** sind nicht
implementiert; die monatliche Ergebnisrechnung (125/126) fehlt. Positiv:
**PDF-Erzeugung ist vorhanden** (`EurPdfController.php:33–112`,
`EurPdfService.php:23–150`, `EurPdfApiTest.php:25–111`: Magic Bytes, deutsche
Beschriftung, Cent→Euro, Brand-Trennung) — der Plan `docs/plans/...:30` ist
hier überholt; lediglich ein PDF-Button im UI fehlt. Ebenso sind die
**Jahresübersichtsfilter** vorhanden (`EurOverviewPanel.tsx:35–37,88–95,161–173,218–243`,
`EurOverviewPanel.completion.test.tsx:52,76,97–99`). Die USt-Kennzahlen fehlen
im Monats-PDF: `VatReturnService` berechnet alle 8 KZ, `MonthStatement.php:66,84`
behält nur `zahllast_cents`, `EurPdfService.php:65` zeigt eine Zeile.

## 4. Blatt- und Querschnittsabdeckung

> **HEAD-Update während der Review:** Der Portal-HEAD wechselte im Verlauf von
> `043826ba` auf `918bb351` (`reset: moving to origin/main`, Merge `feat(ea-rechnung)…
> (#50)`). `git diff --stat 043826ba 918bb351` zeigt **keine Änderung an EUR-Code**
> (nur Plan-Doku, ESLint-/Video-/Screenshot-Konfiguration).Alle Befunde bleiben
> gültig. Die Selbsteinschätzung
> `features/eur/07-verification-matrix.md` markiert **32/131** Regelzeilen als
> „— offen —".

| ODS-Blatt | Portal-Umsetzung | Status | Evidenz / Empfehlung |
|---|---|---|---|
| Einstellungen | CompanyCard + 3-Feld-Formular, Reference-API | SCHLECHTER | `ManagementEurView.tsx:85-113,116-198`; `EurSettingsController.php:24-52`; Kataloge editierbar machen |
| E | Tabelle + Dialog (6 Felder) + API | SCHLECHTER | `V061:48-70`; `EurEntryDialog.tsx:103-145`; Tag/UID/Zahlart/Projekt/E1A/laufende Nr. fehlen |
| A | wie E + `vat_kind` (nur API/CSV) | SCHLECHTER | `EurEntryController.php:404-425`; USt-Werte nicht je Buchung persistiert |
| Ü | Overview, ESt/SV-Rechner, MonthStatement | SCHLECHTER | `EurOverviewService.php`; `MonthStatement.php:95-115`; Modi/Reingewinn nicht sichtbar |
| AV | Domain+API+AfA/DEG, UI nur Vergleichsrechner | SCHLECHTER | `EurDepreciationPanel.tsx:22-60`; `useEur.ts:415-416` (`useEurAssets`) **ungenutzt** |
| FR | nur `POST /travel/calc`, keine Persistenz | SCHLECHTER | `EurTravelPanel.tsx:38-75` (Jahr hart 2026); `EurTravelController.php:25-78` |
| WEB | Panel+Tabelle+API+Monatscheck | SCHLECHTER | `EurWarehouseController.php:44-90`; Lieferant/Anteil/Zahlart/Projekt fehlen |
| E1A | KZ-Auswertung + Ausfüllhilfe-Popover | SCHLECHTER | `EurIncomeTaxPanel.tsx:152,166,179-185`; **ESt-Jahresbug**: `EurIncomeTaxController.php:51` nutzt 2026-pinnende `ReferenceTables` |
| E1B | in E1Statement berechnet, kein Panel | SCHLECHTER | `EurIncomeTaxController.php:80-88`; 9460/9530-Block sichtbar machen |
| UST | 8 KZ + Zahllast + 2 Blöcke im Calc | SCHLECHTER | `VatCalculator.php:19-40`; keine Quartals-/Erklärungspersistenz |
| BE-ART | nur Umsatz je Kunde/Projekt/Monat | **FEHLT** | `EurAnalyticsController.php:33-42`; keine Arten-Matrix/✓✓ |
| BA-ART | wie oben | **FEHLT** | Wie oben; keine A/AV/WEB/FR-Artenmatrix |
| Projekte | `by_project`-Margen, DB da, keine CRUD | SCHLECHTER | `EurAnalyticsPanel.tsx:103-104`; keine Monatsmatrix/AfA/9225 |
| ZA | Zahlarten+Startsaldo nur Referenzdaten | **FEHLT** | `ReferenceTables.php:114-127`; kein Kontoabgleich |
| Monat | Panel+dompdf+Abschluss, 1 USt-Zeile | SCHLECHTER | `EurPdfService.php:23-82`; Button „PDF signieren" liefert **unsignierte** PDF |
| Stunden | Minuten+HoursLedger+Panel | SCHLECHTER | `HoursLedger.php`; keine Zwölfteleinheiten, kein Reisebezug |

**Querschnittsfunktionen:**

| Feature | Status | Evidenz |
|---|---|---|
| Cent/Minuten/5-%-Schritte | **BESSER** | `Money.php`, `Share.php`, `multiple_of:5`, `step=5`; Test `entry validation rejects … non five percent share` |
| Fixture-Integrität + 19 Vektoren | **BESSER** | `CleanRoomFixtureIntegrityTest.php`, `CleanRoomParityTest.php`; 29/382 grün |
| Brand-Scope / Tenant-Isolation | **BESSER** | `EurEntry.php:36-38`, `BoundFileEncryption`, 404 für fremde IDs |
| Verschlüsselung at rest | **BESSER** | AES-256-GCM + Brand/Dokument-ID-Bindung (abweichend von libsodium-Spezifikation, sicherheitlich stärker gebunden) |
| Monats-PDF (dompdf) | VORHANDEN | `EurPdfService.php:23,95,198-201`, `api.php:351-353` — aber ohne USt-Zeilen/Zeilenfilter/OFF |
| Jahresanlage 1 Klick, Lösungsaktionen, 12 Tabs, D-69-PWAs | VORHANDEN | `ManagementEurView.tsx:326-337,465-467,581-649`, `pwa.ts:9,43`, `eur-tabs.spec.ts` |
| Key-Rotation | SCHLECHTER | Env/Compose vorhanden (`backend/.env.example:217-219`), kein EUR-Rotationslauf/Test |
| Eigenbelege, mobile Buchungskarten | SCHLECHTER | Kein 31.12.-Jahresbeleg, keine Swipe-Karten |
| **Trust-/QES-Kette** | **FEHLT** | `EurSignatureController.php:42-100` speichert nur Freitext+Status; kein Hash, kein CMS/PAdES, kein `/verify` |
| **OCR (Tesseract)** | **FEHLT** | 0 Treffer in Backend/Frontend/Tests |
| **Integrations-API (Tokens, Idempotenz, pdfsig)** | **FEHLT** | Nur CSV-Import (`EurEntryController.php:101-155`), nicht idempotent — UI-Text `EurDocumentsPanel.tsx:218` behauptet fälschlich „übersprungen" |
| **Ausfüllhilfe an jedem Feld** | **FEHLT** | Nur E1A-Popover (`EurHelpPopover.tsx:11-36`) |

**Gesamteinschätzung Feature-Ebene: ca. 55 % (Spanne 50–60 %).**
Rechnet man nur die Testnachweise der Selbsteinschätzung, wirkt die Abdeckung
mit ~100/131 (~76 %) höher — das ist jedoch Unit-Abdeckung der Rechenkerne,
inklusive nicht im Produkt verdrahteter Funktionen. Auf Blatt-/Panel-/
Persistenz-Ebene ist ~55 % die ehrlichere Zahl. Stark ist, was da ist
(Cent/Minuten, Brand-Isolation, Verschlüsselung, 19 grüne Vektoren, dompdf,
12 Tabs); kritisch sind die **fehlenden Integrationsfunktionen** (Signatur,
OCR, Import-API) und die **fehlenden Auswertungsblätter** (BE-ART/BA-ART/ZA).

Backend 237 / Frontend 218 Tests grün (Agentenlauf), zusätzlich E2E-Spezifikationen
für alle Tabs und den Buchungsflow vorhanden.

## 5. Priorisierte Übergabe an den Portalagenten

### 5.1 Was das Portal besser macht (unbedingt erhalten)

- **Einheiten und Anteile:** konsequent ganzzahlige Cent/Minuten, BCMath,
  5-%-Schritte über API, Domain und UI (`Money.php`, `Share.php`,
  `multiple_of:5`); die ODS nutzt stetige 0–1-Floats (EUE-009).
- **Feiertage:** `Holidays.php:17-101` (Meeus, rein ganzzahlig) berechnet 13
  Feiertage exakt gegen das Artefakt und beliebige Jahre ≥1583 (EUE-012).
- **Brand-Isolation + Verschlüsselung:** `forCurrentBrand()` überall, 404 für
  fremde IDs, AES-256-GCM mit Brand-/Dokument-ID-Bindung
  (`BoundFileEncryption.php`), Audit-Log (stärker als die ODS-Vorgabe).
- **Paritäts-Infrastruktur:** MANIFEST-gestützte BYTE-Prüfung, 19 grüne
  Vektoren mit Negativtest (Wirksamkeitsnachweis) — aber siehe Abschnitt 2
  zur eingeschränkten Aussagekraft.
- **Bedienung:** 12 echte Tabs, Jahresanlage mit einem Klick, Lösungsaktionen
  statt Rohfehler, zwei PWAs nach D-69, responsive Panels.

### 5.2 Priorisierte Übergabe an den Portalagenten

| Prio | ID / Thema | Konkreter Fix (Datei:Zeile) | Warum |
|---|---|---|---|
| **P0** | EUE-068 Mahlzeit Ausland | `TravelCalculator.php:129` `'0'`→`'1'`; `TravelCalculatorTest.php:70` auf `3000` (Reference 3530 ct); Fixture-Vektor | **Reproduziert**, systematisch zu niedrige Auslandsvergütung; Test schützt den Fehler |
| **P0** | EUE-071 Hin-/Rückfahrt | `isTravelLeg():229-240` Bedingung auf `minutes >= 180` drehen, AJ3/AE3 + 50/25 km; Tests `:104-111` umschreiben | **Reproduziert**: 240 min → false, 60 min → true |
| **P0** | EUE-063/064 2025 km-Sätze | `kilometreRateCents()` auf `YearTables::travelPerKmCents()` umstellen; 30.06./01.07.-Regressionstest | **Reproduziert**: Exception bei zulässigem `year=2025` → HTTP 500 |
| **P0** | Trust-/QES-Kette | Signatur-Adapter (mock/http), kanonischer SHA-256 über Buchungen+Belege, `/verify` mit `valid/stale`; Button „PDF signieren" → „PDF herunterladen" bis echte Kette da ist | `EurSignatureController.php:42-100` speichert nur Statusstrings |
| **P1** | EUE-119/120/122 Auswertungen | Drei Endpunkte: `by_income_type`, `by_expense_type` (A/AV/WEB/FR), `payment-reconciliation` (Startsaldo ± Zu-/Abflüsse, ✓/✓✓, 1-Dezimal-Rundung) | Drei ODS-Blätter komplett fehlend; `payment_method`+`opening_balance_cents` sind schon persistiert |
| **P1** | EUE-103/095/104/115 Steuerpfade | `IncomeTaxService::totalIncome()/allocate()` aus `tax-engine.md` portieren; `rentalForecast()` `array_sum($e1b)`→Einnahmen−Werbungskosten; 9225 in `EurSummaryController` herausrechnen | KZ 245/260 sind heute nur Echo; Rechenfehler mit fachlicher Wirkung |
| **P1** | EUE-089…093 Jahr-Parameter | `EurIncomeTaxController.php:50-51` und `EurReferenceController.php:68,83,88` auf `YearTables::tariffStages($year)`; `EurTravelPanel.tsx:65` hartes 2026 entfernen | 2024/2025 werden mit 2026-Grenzen gerechnet |
| **P1** | EUE-108 Jungunternehmer | Toggle in Settings/API/UI; KV-Satz-'-'-Anzeige bei vollem Mindest-KV (449,64 €) | Konstante ist toter Code |
| **P1** | EUE-088 USt-Block 2 | `EurVatDeclarationService::declarations()` Block 2 mit Jahressummen statt Monatswert füllen | Beide Blöcke identisch; AD5:AD14/AD20 fehlen |
| **P1** | OCR + Integrations-API | Tesseract-deu im Upload-Pfad als **Vorschlag**; scope-Tokens, Idempotency-Key, `pdfsig`-Prüfung | Beide Querschnittsfunktionen fehlen; UI-Text behauptet fälschlich „übersprungen" |
| **P2** | EUE-033/048 Anteil aus Art | `kind.share_percent` aus `ReferenceTables` in `EurEntryDialog.tsx:23-30,66-77` übernehmen; 0 % ⇒ KZ leer | Private Buchungen landen heute mit 100 % und Fallback-KZ in E1A |
| **P2** | EUE-035/036/042 Zeilenchecks | Belegnummer-, Art-, Monat-, Belegpflicht- und WEB-Checks in `JournalRules::checks()`; ⚠-Marker; `entry.checks` in `EurEntriesPanel.tsx` rendern | Heute `ok=true` bei fehlenden Pflichtfeldern; Checks unsichtbar |
| **P2** | EUE-045/047 WEB-Datenmodell | `business_share`, `expense_kind`, `supplier`, `receipt_no`-Präfix; Betrag nur bei `WE*`; Pflichtfelder | WEB-Regeln nur im Rechenpfad, nicht im Modell |
| **P2** | EUE-053…061 Anlagen-Warnungen | AfA-KZ-Whitelist, Zukunftsjahr-Prüfung, GWG-Hinweis sichtbar, Entnahme-Abgleich, EUE-056/057 Vorjahreskette | Rechner korrekt, Warn-/Kettenlogik fehlt |
| **P2** | Unit-Inseln verdrahten | `kilometreQuota`, `distance()`, `businessSharePercent()`, `isPrivateTrip()`, `Holidays::datesForYear()`, `HoursLedger::sumMinutesForVoucher()` in Controller/Persistenz führen; `eur_travel_trips/rows` anlegen | Funktionen existieren und sind getestet, werden aber nie aufgerufen |
| **P3** | EUE-124 USt-Zeilen im Monat/PDF | `vat.kennzahlen` in `MonthStatement.php:66,84` durchreichen, 8 KZ in `EurPdfService.php:65` rendern | Kleinster Aufwand, direkter Paritätsgewinn |
| **P3** | EUE-004/023/025…029 Stammdaten/Kontenplan | ÖNACE 11–990; 27-KZ-Artefakt inkl. 6 E1B-KZ; Duplikatwarnungen bzw. bewusst N/A | Matrix-Evidenz für EUE-004 ist irreführend |
| **P3** | EUE-131 + Evidenz-Korrektur | Defekt parity-getreu oder genehmigter Fix; `rule-coverage.json` erfundene Testnamen entfernen; Matrixzeilen 116–118/123/124/127/129 → „teilweise", 130 → N/A; `degressive-afa-coverage.json` auf echte Portal-Tests umstellen | 32/131 „offen"; DEG-E2E-Namen existieren nicht |
| **P3** | Fixture-Schärfung | `CleanRoomParityTest` gegen echte Engine (`YearTables`, `IncomeTaxTariff`, `EurOverviewService`) statt `RuleValues`/`BookkeepingTotals` | Aktuell Katalog-vs-Katalog |
| **P3** | Ku-online-Doku | `verification/requirements/deg-scope.md:3` auf die getroffene Owner-Entscheidung (`features/owner-decisions.md:343`, DEG im Scope) aktualisieren | Dokumentationsdivergenz zwischen den Repos |

### 5.3 Reihenfolge-Empfehlung

1. **P0 zuerst:** Die drei Reise-Fehler sind reproduzierbar, haben falsche
   Tests und fachliche Geldwirkung. Danach Signatur-Button/Beschriftung
   korrigieren (QES selbst ist ein größeres Vorhaben).
2. **Dann P1:** Auswertungs-Endpunkte und Steuerpfade — hier liegt der größte
   Teil der fehlenden Fachlichkeit.
3. **P2/P3:** Validierung, Anlagen-Warnungen, Verdrahtung der Unit-Inseln,
   Dokumentations- und Evidenzkorrektur.

**Wichtig für die Abnahme:** Die externe steuerliche Prüfung bleibt Vorbehalt,
kein Blocker. Diese Review bewertet Implementierung und Evidenz, nicht die
steuerliche Richtigkeit der ODS-Regeln selbst.

## 6. Grenzen dieser Prüfung

- External steuerliche Prüfung (Tarif, Pauschalen, AfA/Nutzungsdauer, GWG,
  Privatnutzung) bleibt Abnahmegate, kein Blocker — diese Review bewertet
  **Implementierung**, nicht steuerliche Richtigkeit.
- Es wurden Backend-Unit-Tests, nicht die vollständige Playwright-/Browser-
  Suite ausgeführt (Host-Auflage: schwere E2E-Suiten laufen in der CI).
- Bewertungen stützen sich auf den oben genannten Arbeitsbaum; nachfolgende
  Commits können einzelne Zeilen verändern.

## 7. Nachverfolgung (2026-11-22, kein Bestandteil der Ursprungsprüfung)

> Die Abschnitte 1–6 sind ein unveränderter Snapshot des geprüften Stands
> `043826ba`. Die folgende Tabelle ist die spätere Statuszuordnung und nennt
> für jeden inzwischen behobenen Befund den Nachweis. Bewertungen im Präsens
> in den Abschnitten 1–6 sind daher nicht mehr als aktueller Zustand zu lesen.

| Befund | Status heute | Nachweis (Commit / Datei / Test) |
|---|---|---|
| EUE-068 1 Mahlzeit Ausland | behoben | `7789b6a7` · `TravelCalculator.php` Faktor `'1'` · `TravelCalculatorTest.php` |
| EUE-071 Hin-/Rückfahrt | behoben | `7789b6a7` · `isTravelLeg()` `minutes >= 180`, AE3 via `ledgerMinutes` |
| EUE-063/064 2025 km-Sätze | behoben | `7789b6a7` · `TravelCalculator::kilometreRateCents()` → `YearTables`, 422 statt 500 |
| EUE-089…093 Jahr-Parameter | behoben | `7789b6a7` · `IncomeTaxTariff::forYear()`, `EurIncomeTaxApiTest.php` |
| EUE-095/104/115 Steuerpfade | behoben | `7789b6a7` · `EurTaxStatementService::rentalForecast()`, `EurOverviewService::excludeBookedSvFromExpenses()` |
| EUE-108 Jungunternehmer | behoben | `7789b6a7` · `jungunternehmer` in Settings-Validierung, `kv_rate_hidden` exponiert |
| EUE-088 USt-Block 2 | behoben | `7789b6a7` · `EurVatController::declarations()` nutzt `year_totals` |
| EUE-119/120/122 Auswertungen | behoben | `7789b6a7` · `EurAnalyticsController::{byIncomeType,byExpenseType,paymentReconciliation}`, `EurAnalyticsApiTest.php` |
| EUE-033/048 Anteil aus Art | behoben | `7789b6a7` · `EurEntryDialog::selectKind()`, `EurEntryDialog.test.tsx` |
| EUE-035/036/042/043 Zeilenchecks | behoben | `7789b6a7` · `JournalRules::checks()` (MONTH/RECEIPT/WEB, `ok=false`), `EurEntriesPanel.tsx` ⚠-Spalte |
| EUE-127 Zeilenchecks | teilweise | `7789b6a7` · `JournalRules::checks()` und `EurEntriesPanel.tsx` ⚠-Spalte; die §6-Empfehlung `data.hint` im Panel-Kopf ist nicht umgesetzt, weil `EurEntriesResponse` kein `hint` enthält (offen) |
| EUE-124 USt-Zeilen Monat/PDF | behoben | `7789b6a7` · `MonthStatement::vat`, `EurPdfService`, `EurMonthPanel.tsx` |
| Signatur-Button „PDF signieren" | behoben (Label) | `7789b6a7` · „PDF herunterladen"; **QES-Kette weiterhin offen** |

Weiterhin offen und bewusst nicht in `7789b6a7`: Trust-/QES-Kette inkl. `/verify`
und echtem CMS, OCR/Tesseract im Upload-Pfad, scope-Tokens/Idempotency-Key/pdfsig
der Integrations-API, EUE-045/047 WEB-Datenmodell, EUE-053…061 Anlagen-Warnungen,
die Verdrahtung der Unit-Inseln (`kilometreQuota`, `distance()`, `isPrivateTrip()`,
`workingDays()`, `sumMinutesForVoucher()`), EUE-004/023/025…029 Stammdaten,
EUE-131 sowie die Evidenz-Korrekturen in `rule-coverage.json` und
`degressive-afa-coverage.json`. Issue #51 bleibt aus diesen Gründen offen.
