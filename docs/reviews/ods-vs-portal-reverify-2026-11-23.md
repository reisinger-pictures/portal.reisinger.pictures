# Re-Verifikation: ODS-Clean-Room-Basis vs. EAR-Implementierung im Portal (2026-11-23)

**Art:** Vollständige Neuprüfung aller 131 Regeln und 16 Blätter nach weiterer
Portal-Entwicklung und Deployment. Der vorherige Bericht
`verification/ods-vs-portal-review.md` bleibt als **Snapshot** `043826ba` erhalten;
diese Datei ist der aktuelle Stand.

## 0. Rahmen

- Geprüfter Portal-HEAD: `b3c97d18d3fc895f9e22508fa2d3df313f2e8a55`
  (Arbeitsbaum sauber). Ausgangsstand der Erstprüfung: `918bb351` / `043826ba`.
- ku-online `75f230c07…`; Clean-Room-Basis `local/input.ods`, SHA-256
  `cf5060fb…e90790`, unverändert und identisch mit der Fixture-Basisangabe.
- Die Implementierung war Clean-Room; **diese Prüfung ist es nicht**: ODS und
  alle ku-online-Artefakte wurden direkt gelesen. Es wird keine Basis, keine
  Formel und kein Engine-Code ins Portal übernommen.
- Statuskürzel: **V** = VORHANDEN · **B** = BESSER als die ODS ·
  **S** = SCHLECHTER / teilweise · **F** = FEHLT. „N/A" markiert bewusst
  dokumentierte Workbook-Artefakte.
- Evidenz ist eher Code + ausgeführter Test / Reproduktion als die
  Selbstdokumentation des Portals. Die Nachverfolgungstabelle
  `docs/reviews/ods-vs-portal-review.md` §7 wurde als Behauptung behandelt
  und an vielen Stellen widerlegt.

## 1. Executive Summary

| Bereich | alt V/B/S/F | neu V/B/S/F | Bewegung |
|---|---|---|---|
| B01/B02 Grundlagen, Stammdaten | 15/2/8/5 | **15/2/8/5** | keine Statusänderung |
| B03/B04 Einnahmen, Ausgaben, Vorsteuer | 5/0/8/5 | **5/6/3/4** | +6 besser |
| B05/B06 Anlagen, Reise | 12/0/9/12 | **17/0/6/10** | +5 vorhanden |
| B07–B09 USt, ESt, SV | 25/0/9/1 | **26/0/8/1** | +1 vorhanden |
| B10/B11 Auswertungen, Validierung | 1/0/9/5 | **3/0/11/1** | +2 vorhanden |
| **Gesamt (131)** | **58/2/43/28** | **66/8/36/21** | **+14 vollständig/besser** |

**Vollständig oder besser: 74/131 (56,5 %)** — vorher 60/131 (45,8 %).
**Lücken: 57/131 (43,5 %)** — vorher 71/131. Feature-Ebene (Blatt/Panel/
Persistenz/API): **~68 %** (vorher ~55 %).

Wesentliche Fortschritte: alle drei reproduzierten P0-Reisefehler behoben;
Einnahmen-/Ausgaben-Validierung und ⚠-Anzeige; Anteil aus Art; Anlagen-UI mit
Jahresabschluss; Auswertungs-Endpunkte; USt-Kennzahlen im Monat/PDF;
9225-Berechnung; jahresabhängige ESt; Fixture 5/19 → 16/45; mobile Karten;
accounting role.

Wesentliche Restlücken: **keine QES-Kette, kein OCR, keine externe
Integrations-API**; BE-ART/BA-ART/ZA nur im Backend, für Nutzer unsichtbar;
Reisen ohne Persistenz; WEB-Datenmodell; Anlagen-Warnlogik; EUE-103/095/126;
EUE-131 unentschieden; Fixture prüft weiterhin überwiegend Katalog gegen
Katalog, nicht die echte Engine.

## 2. Ausgeführte Nachweise

```text
php artisan test --filter='CleanRoomFixtureIntegrityTest|CleanRoomParityTest'
  → 58 passed / 658 assertions
php artisan test tests/Unit/Eur tests/Feature/Eur
  → 221 passed / 1387 assertions
php artisan test --filter='Eur'
  → 248 passed / 1494 assertions
php artisan test --filter='EurAccountingRoleTest|EurDocumentSignedUploadApiTest|
  CleanRoomParityTest|CleanRoomFixtureIntegrityTest|EurInvoiceTakeoverTest|
  EurAssetPrivateInvestmentTest'
  → 85 passed / 853 assertions
npx vitest run EurAssetsPanel.test.tsx EurEntriesPanel.test.tsx → 8 passed
```

Reproduktionen gegen die echte Engine (HEAD `b3c97d18`):

```text
perDiemCents(3000,1,false) = 3000          (ODS 3000)        EUE-068 behoben
isTravelLeg(240,50,false) = true           (ODS true)        EUE-071 behoben
isTravelLeg(60,50,false)  = false          (ODS false)       EUE-071 behoben
Motorrad/Fahrrad 2025 M3 = 50, M9 = 25     (ODS H1/H2)       EUE-063/064 behoben
kilometreQuota(PKW,2026) = 30000, aber kilometreAllowanceCents(PKW,40000)
  = 2.000.000 ct → kein Cap                                  EUE-065 offen
```

Fixture: Portal `artifact_version 2026.4`, 16 Szenarien / 45 Zellen, alle 11
Bereiche und 26 jahresabhängigen Regeln. **Governance-Hinweis:** Diese Fixture
ist portal-eigen aus `domain-rules.json` abgeleitet (D-70, in `PROVENANCE.md`
dokumentiert) und **nicht** mehr byte-identisch mit der ku-online-Fixture
(5/19, SHA `fec99f67…`). Sie ist ein Konstanten-Pin-/Update-Gate, aber kein
unabhängiger ODS-Paritätsnachweis.

## 3. Regel-für-Regel (alle 131)

### B01 Grundlagen / B02 Stammdaten (EUE-001…030)

| ID | ODS-Feature | Alt→Neu | Evidenz / Restlücke / Empfehlung |
|---|---|---|---|
| EUE-001 | Wirtschaftsjahr 2026, 2024–26 | V→V | `EurYear.php:17-39`, `EurYearsController.php:22-41`, Parität `business-year-2026` |
| EUE-002 | Warnung >2026 | S→S | API/TS-Typ liefern `warnings`, `ManagementEurView.tsx` rendert sie nicht; Warnbanner + Test |
| EUE-003 | Warnung <2024 | S→S | Gleiche UI-Lücke; Text weiter ohne „Lifetime-Lizenz" |
| EUE-004 | Firma, ÖNACE 11–990 | S→S | Firmendaten jetzt sichtbar/editierbar, aber `branchenkennzahl` nur `nullable\|string\|max:16`; `min:11\|max:990`, E1A-Hinweis, PDF-Spiegelung |
| EUE-005 | Zahlarten + Startsaldo | S→S | ZA-Endpunkt neu (`EurAnalyticsController.php:81-131`), aber kein Zahlart-Feld im Buchungsdialog |
| EUE-006 | Projekte in Journalen | S→S | Reference/Analytics/FK vorhanden, `EurEntryDialog` sendet kein `eur_project_id`; kein CRUD |
| EUE-007 | Ausgaben-Art → 9230 | V→V | `JournalRules.php:96-106`, `ReferenceTablesTest.php:35-37` |
| EUE-008 | Einnahmen-Art → 9040 | V→V | `JournalRules.php:84-94`, `E1StatementTest.php` |
| EUE-009 | Anteile 5-%-Schritte | B→B | `ReferenceTablesTest.php:46-63`, Dialog `step={5}` |
| EUE-010 | 7 USt-Arten | V→V | `ReferenceTables.php:91-97`, `JournalRulesTest.php` |
| EUE-011 | Transportmittel → Typ | S→S | `TravelCalculator.php:318-325` mappt unbekanntes Mittel auf PKW; `null`/`Andere` + Rate 0 |
| EUE-012 | 13 Feiertage, Osterformel | B→B | `Holidays.php`, `ReferenceTablesTest.php:109-141` |
| EUE-013 | Länderpauschalen inkl. 2024 | S→S | 174 Länder + 30/17 korrekt, 2024 (26,4/15) fehlt, `for()` lehnt Jahr≠2026 ab |
| EUE-014 | max BGL 7070/7525/8085 | V→V | `YearTables.php:26-30`, 9 Fixture-Zellen grün |
| EUE-015 | KV 6,8 % | V→V | `YearTables.php:31-38` (680 bp) |
| EUE-016 | Mindest-KV 35,45/37,47 | V→V | `YearTables.php:27-29` (3545/3747 ct) |
| EUE-017 | GSVG-PV-Min 95,91/101,95 | V→V | `YearTables.php:27-29` (inkl. I61) |
| EUE-018 | FSVG-PV-Min 103,69/110,22 | V→V | `YearTables.php:27-29` |
| EUE-019 | GSVG-PV 18,5 % | V→V | `YearTables.php:31-38` (1850 bp) |
| EUE-020 | FSVG-PV 20 % | V→V | `YearTables.php:31-38` (2000 bp) |
| EUE-021 | SVS 1,53 % | V→V | `YearTables.php:31-38`, Modus-Ausnahmen `SocialInsurance.php:117-150` |
| EUE-022 | UV 11,35/12,07/12,95 | V→V | Werte korrekt; `accidentYearlyCents()` liefert weiter Monatswert → umbenennen |
| EUE-023 | 27 KZ inkl. 6 E1B-KZ | S→S | 9460/9530 in `tables.json`, aber 9500/9510/9520/9521 fehlen; 27-KZ-Artefakt + Test |
| EUE-024 | Einnahmen-KZ 9040…9460 | V→V | `JournalRules.php:108-114`, `E1StatementTest.php:41` |
| EUE-025 | Duplikatwarnung Zahlarten | F→F | Kein Code; read-only Katalog → als N/A dokumentieren |
| EUE-026 | Duplikatwarnung Projekte | F→F | `EurProject` nur Model, kein Controller/Unique |
| EUE-027 | Duplikatwarnung Ausgaben-Arten | F→F | Keine Warnlogik → N/A-Doku |
| EUE-028 | Duplikatwarnung Einnahmen-Arten | F→F | Wie EUE-027 |
| EUE-029 | Verwendungszähler ❯/99+/999+ | F→F | Kein `usage_count` im Reference-Endpoint |
| EUE-030 | Wertebereiche, 5-%-Schritte | V→V | `JournalRules.php:54-82`, `EurEntryDialog.tsx:189-193` |

### B03 Einnahmen / B04 Ausgaben & Vorsteuer (EUE-031…048)

| ID | ODS-Feature | Alt→Neu | Evidenz / Restlücke / Empfehlung |
|---|---|---|---|
| EUE-031 | Betriebliche Einnahme = Brutto×Anteil | V→V | `EurEntryController.php:439-445`, `E1StatementTest.php:58-70` |
| EUE-032 | Fallback 9040 | V→V | `JournalRules.php:85-94`; Entry-Controller setzt KZ nicht beim Schreiben |
| EUE-033 | Anteil 0 = private Einnahme | S→**B** | `EurEntryDialog.tsx:85-95,113-123`, Test `EurEntryDialog.test.tsx:33-47`; nur Dialogpfad, „Private Entnahme" noch 9040 |
| EUE-034 | Mieteinnahmen → E1B 9460 | V→V | `JournalRules.php:109-114`, `E1StatementTest.php:40-55` |
| EUE-035 | Laufende Nummer E | F→F | Kein `line_no` in Schema/Code/Ausgabe; dichte Nummer oder N/A |
| EUE-036 | E!B3 Zeilenchecks | S→**B** | `JournalRules.php:161-293`, `ok=false`, ⚠-Spalte `EurEntriesPanel.tsx:80-89`; Bullet vs ⚠, kein Katalogcheck, Dialog sendet `day/category/description` nicht |
| EUE-037 | Monatsnamen, Tag 1–31 | S→**B** | `JournalRules.php:20-24,196-209`, `parseCsvMonth`; API speichert Zahlen, Tag fehlt im Dialog |
| EUE-038 | Betriebliche Ausgabe, keine VST | V→V | `E1StatementTest.php:58-70` |
| EUE-039 | IG-Erwerb inkl. 4,9 % | S→S | Sätze korrekt, aber `VatReturnService.php:227-238` nutzt betrieblichen Betrag, `JournalRules` Brutto; `vat_kind` fehlt im Dialog → Basiskonflikt auflösen |
| EUE-040 | RC 20/10/13 % | S→S | Gleiche Basendivergenz wie EUE-039 |
| EUE-041 | Fallback 9230 | V→V | `JournalRules.php:96-106`, `EurEigenbelegApiTest.php:13-50` |
| EUE-042 | A!B3 Belegpflicht/WEB/FSVG | S→**B** | `JournalRules.php:161-293` (RECEIPT/MONTH/WEB/VAT_KIND), ⚠-Spalte; FSVG nicht verdrahtet |
| EUE-043 | KZ 9100 ⇒ WEB | F→**B*** | `JournalRules.php:250-258` warnt, **aber immer** (`check(...,false,...)`) — Scheinalarm ohne WEB-Abgleich, keine FSVG-Ausnahme; echter A→WEB-Check nötig (*Grenzfall: Rating folgt Existenz der Prüfung, fachlich aber SCHLECHTER) |
| EUE-044 | Laufende Nummer A | F→F | Wie EUE-035 |
| EUE-045 | WEB nur bei `WE*`, Anteil aus Art | F→F | `V062:72-86` ohne `business_share`/`expense_kind`/`supplier`; Schema + Präfixlogik nachrüsten |
| EUE-046 | WEB→A-Hinweise | S→S | `WarehouseReconciliation.php:32-114`; keine laufende Nr., keine Differenzierung, nicht im WEB-Panel |
| EUE-047 | WEB-Pflichtfelder | F→F | `EurWarehouseController.php:52-59`: Tag optional, kein Lieferant/Art/Eindeutigkeit |
| EUE-048 | Anteil 0 = private Ausgabe | S→**B** | Wie EUE-033; `EurEntryDialog.test.tsx:33-47`; nur Dialogpfad |

### B05 Anlagen / B06 Reise (EUE-049…081)

| ID | ODS-Feature | Alt→Neu | Evidenz / Restlücke / Empfehlung |
|---|---|---|---|
| EUE-049 | Halbjahres-AfA | V→V | `DepreciationCalculator.php:71-84`; kein Verhaltensvektor |
| EUE-050 | Restbuchwert min. 0,01 € | V→V | `DepreciationCalculator.php:89-92`; 0-Jahre-Fall nicht darstellbar |
| EUE-051 | GWG-Hinweis 1000 € | F→**V** | Badge in `EurAssetsPanel.tsx:124,130`, aber hartcodiert `100000` statt kataloggesteuert |
| EUE-052 | Nutzungsjahre 2–99 | V→V | `EurAssetController.php:275`; ODS erlaubt 0 = nicht abnutzbar |
| EUE-053 | AfA-KZ-Whitelist | F→F | `validateAsset():267-283` ohne KZ-Regel; KZ 9230 wird akzeptiert |
| EUE-054 | Privatvermögen <50 % | V→V | `business_share min:50`; Hinweistext in 422 fehlt |
| EUE-055 | Entnahme im E-Blatt | F→**S*** | Badge „Entnahme prüfen" (`EurAssetsPanel.tsx:34,79`), aber kein Abgleich gegen `eur_entries`, kein Betrag (*von F auf S verbessert) |
| EUE-056 | Vorjahres-AfA ±0,01 € | F→F | Keine Korrektur-API, keine Toleranzprüfung; blockiert DEG-015 |
| EUE-057 | Vorjahreskette/Restbuchwert | F→F | Kein `chain_error`, `switchToLinear` schützt nur gebuchte Jahre |
| EUE-058 | Zukunftsjahr | F→F | `validateAsset():273` akzeptiert 2027 |
| EUE-059 | Pflichtfelder AV | V→V | `validateAsset():269-282` |
| EUE-060 | Laufende Nummer AV | F→F | Kein Zähler; ku-online selbst N/A → dokumentieren |
| EUE-061 | KZ-9130-Fallback | V→V | `suggestedCategory():403-434` |
| EUE-062 | PKW km 0,50 (2026) | V→V | `TravelCalculator.php:35-48`, Parität `travel-rates-2026` |
| EUE-063 | Motorrad 2025 H1/H2 | S→**V** | `kilometreRateCents():35-38` → `YearTables`; Repro M3=50/M9=25; API lehnt 2024/25 aber weiter ab |
| EUE-064 | Fahrrad 2025 H1/H2 | S→**V** | Wie EUE-063 |
| EUE-065 | km-Kontingente kappen | F→F | `kilometreQuota():60-66` nie aufgerufen (Repro 40.000 km → 2.000.000 ct); Fixture-Vektor hat falsche Einheit (3000 km als 300000 ct) |
| EUE-066 | Mitfahrer max 6/1 | V→V | Engine korrekt, Controller `max:6` global → Motorrad mit 2 → 500 |
| EUE-067 | KM-Stand XOR Distanz | F→F | `distance():102-124` nur Unit, API kennt nur `kilometres` |
| EUE-068 | 1 Mahlzeit Ausland = voll | S→**V** | `perDiemCents():132-147` Faktor `'1'`, Test `TravelCalculatorTest.php:64-72`, Repro 3000 ct |
| EUE-069 | Nächtigungspauschalen | V→V | `nightWithoutReceiptCents():184-190`; 5-Nächte-Zeilenlimit ungeprüft |
| EUE-070 | 5-/15-Tage-Regel | V→V | `dailyAllowancesCents():168-181`; Statuslabel `AH3` fehlt |
| EUE-071 | Hin-/Rückfahrt ≥3 h | S→**V** | `isTravelLeg():248-265` jetzt `>=` + km + `ledgerMinutes`, Repro korrekt; **nie außerhalb von Tests aufgerufen** → Produktpfad verdrahten |
| EUE-072 | BT/PT → Betriebsanteil | V→V | `businessSharePercent():221-238` nur Unit; Controller nimmt Input |
| EUE-073 | Privatreise → 0 + Status | F→F | `isPrivateTrip():307-311` nur Unit; kein `purpose` |
| EUE-074 | NETWORKDAYS/Feiertage | F→F | `Holidays` nur Anzeige; kein `workingDays()` |
| EUE-075 | Reisejahr/Datum-Warnung | S→S | `validateTrip():272-305` hart 2026, kein Controller-Aufruf |
| EUE-076 | Ende ≥ Start + Zeit | S→S | `validateTrip():283` Stringvergleich, keine Minuten/Toleranz |
| EUE-077 | Belegnummer + Duplikate | S→S | Nur „nicht leer"; API ohne `receipt_no` |
| EUE-078 | Land/Transportmittel im Katalog | V→V | `EurTravelController.php:30,33` |
| EUE-079 | Veranlassung nur 1 Zeile | S→S | `validateTrip():299-302` prüft Felder, die API/UI nicht haben |
| EUE-080 | Stunden des Belegs → 3 h | F→F | Kein `voucher` in `eur_hours`/API; `sumMinutesForVoucher()` fehlt |
| EUE-081 | Reisekosten → Monat/Projekt/KZ 9160 | S→S | `businessTravelCostsCents():195-216` korrekt, aber keine Persistenz/Aggregation |

### B07 USt / B08 ESt / B09 SV (EUE-082…115, EUE-131)

| ID | ODS-Feature | Alt→Neu | Evidenz / Restlücke / Empfehlung |
|---|---|---|---|
| EUE-082 | 8 USt-Kennzahlen | V→V | `VatCalculator.php:19-41`, `EurVatApiTest.php:31-33` |
| EUE-083 | KZ 016/000 aus Einnahmen | V→V | `VatCalculator.php:65-69` |
| EUE-084 | KZ 057 ROUNDDOWN | V→V | `VatCalculator.php:71-74`; Cent-Kanonik dokumentieren |
| EUE-085 | IG 20/10/13/4,9 % → KZ | V→V | `VatCalculator.php:79-95,183-199` |
| EUE-086 | Zahllast | V→V | `VatCalculator.php:97-110`; einziger echt gegen Fixture geprüfter Engine-Wert |
| EUE-087 | Quartalsaggregate | V→V | `VatCalculator.php:121-177`, `VatMonthGroupsTest.php:14-36` |
| EUE-088 | 2 Erklärungsblöcke, Block 2 Jahressummen | S→S | `EurVatController.php:83-97` nutzt `year_totals`, aber aus **einem** Monat; `VatReturnService::forYear()` ungenutzt; AD5:AD14/AD20 fehlen |
| EUE-089 | Tarifstufe 1 12816/13308/13539 | V→V | `IncomeTaxTariff::forYear():70-92`, Repro Grenzen korrekt, `EurIncomeTaxApiTest.php:59-80` |
| EUE-090 | Stufe 2 20818/21617/21992 | V→V | `YearTables.php:17-19`, Repro |
| EUE-091 | Stufe 3 34513/35836/36458 | V→V | `YearTables.php:17-19`, Repro |
| EUE-092 | Stufe 4 66612/69166/70365 | V→V | `YearTables.php:17-19`, Repro |
| EUE-093 | Stufe 5 99266/103072/104859 | V→V | `YearTables.php:17-19`, Repro |
| EUE-094 | Progressionsrechnung | V→V | `IncomeTaxTariff.php:126-144`, Repro `tax(12.000.000,2026)=4372082` = Cleanroom-Vektor |
| EUE-095 | Gesamteinkommen U14, KZ 245 | S→S | `EurTaxStatementService.php:64-85` nur Echo; kein `totalIncome`/U14; Matrix-Evidenz falsch |
| EUE-096 | Grundfreibetrag 33.000 € | V→V | `YearTables.php:59-65`, `IncomeTaxTariff.php:149-158` |
| EUE-097 | Grundfreibetrag 15 % | V→V | `YearTables.php:63-64`, Repro GFB 135000 ct |
| EUE-098 | GFB-Berechnung | V→V | `IncomeTaxTariff.php:164-175` |
| EUE-099 | E1A-SUMIF inkl. WEB/FR/AV | S→S | `E1Statement.php:47-79` aggregiert nur Entry-Codes |
| EUE-100 | E1B 9460/9530 | V→V | `E1Statement.php:58-61,90-102`; `e1b_result_cents` fehlt als Feld |
| EUE-101 | Pauschalen 9215/9217 | V→V | `EurTaxStatementService.php:119-146`, Tests `:76-96` |
| EUE-102 | 9216, 9275-Sperre | V→V | `EurTaxStatementService.php:126-146` (alte „Nuance"-Kritik war Fehlablesung) |
| EUE-103 | ESt-Aufteilung U24/U25/U27/U29 | S→S | Kein Treffer im Code; KZ nur Echo; größter fachlicher Fehler im Scope |
| EUE-104 | Prognose, Monatsverteilung | S→S | `rentalForecast():96-111` jetzt Einnahmen−Werbungskosten (Teilfix); U8/J12/K12 weiter fehlend |
| EUE-105 | Modi Bruttolohn/andere Gewinne | S→S | `MODES=['total','business','rental','forecast']` unverändert |
| EUE-106 | Modus-Beschriftung Y17 | S→S | Wie EUE-105 |
| EUE-107 | 4 SV-Modi | V→V | `SocialInsurance.php:20-24`, `SocialInsuranceModesTest.php:38-71` |
| EUE-108 | Jungunternehmer, KV-Satz '-' | S→S | API/Flag behoben (`EurSettingsController.php:33-48`), aber kein UI-Toggle, kein `kv_rate_hidden` in TS/Panel |
| EUE-109 | BMG + 9225, Deckel 97020 € | V→V | `EurOverviewService.php:58-59` |
| EUE-110 | Mindest-KV ×12 = 449,64 € | V→V | `EurOverviewService.php:63-66` |
| EUE-111 | PV 18,5/20 % + Mindestbeitrag | V→V | `EurOverviewService.php:68-77` |
| EUE-112 | UV ×12, SVS 1,53 % | V→V | `EurOverviewService.php:79-91`; `accidentYearlyCents()` irreführend benannt |
| EUE-113 | Mindest-Sockel 12 Monate | V→V | `EurOverviewService.php:63-98`, rechnerisch 16080 ct; portalweit statt nur einkommensloser Monate |
| EUE-114 | Jahresbeiträge / 12 | V→V | `EurOverviewService.php:93-98` |
| EUE-115 | 9225 aus Ausgaben herausrechnen | S→**V** | `EurSummaryController.php:44-55`, UV-Ausnahme `:28-33`, `EurSummaryApiTest.php:103-137` (profit 180000 statt 170000) |
| EUE-131 | FSVG-Fallback 101,95 € | F→F | Keine dokumentierte Entscheidung; Portal nutzt still 11022; bewusst entscheiden |

### B10 Auswertungen / B11 Validierung (EUE-116…130)

| ID | ODS-Feature | Alt→Neu | Evidenz / Restlücke / Empfehlung |
|---|---|---|---|
| EUE-116 | Monat OFF + 💡 | S→S | `MonthStatement.php:117` liefert `hint`, aber kein OFF, `useEur.ts` ohne `hint`, Panel rendert es nicht |
| EUE-117 | Monat J13–J23, S/X USt | S→S | `MonthStatement.php:75-86` ohne `av_cents`/`fr_cents`/S/X-Spalten; FR nur Stundenzeilen |
| EUE-118 | Monatszeilen SMALL/INDEX | S→S | `MonthStatement.php:125-213`: kein WEB-Paar, keine AV-Zeilen, keine FR-Einzelzeilen |
| EUE-119 | BE-ART Art×Monat | F→**S** | `EurAnalyticsController.php:51-61,137-194` + Test grün, aber **kein Frontend-Consumer**; keine %-Spalte/✓✓/Rundung 1 |
| EUE-120 | BA-ART inkl. FR | F→**S** | `EurAnalyticsController.php:66-76,137-205`, `sources` A/AV/WEB/FR; kein UI, keine FR-Sonderzeile/%/✓✓ |
| EUE-121 | Projekte×Monat, 9225 | S→S | `EurAnalyticsController.php:218-245` nur Jahresmarge; keine Stunden/Vorjahres-AfA/9225 |
| EUE-122 | ZA Saldoabgleich | F→**S** | `EurAnalyticsController.php:81-131`; kein WEB-Zufluss/Brutto/SOLL/Δ; `startsaldo:null` macht ✓✓ faktisch unreachable; kein UI |
| EUE-123 | Stundenbuch | S→S | `HoursLedger.php:19-119` korrekt (Minuten, 180), keine Zwölfteleinheiten, kein Reisebezug |
| EUE-124 | 8 USt-KZ in Monat/PDF | S→**V** | `MonthStatement.php:100-102`, `EurPdfService.php:154-179`, `EurMonthPanel.tsx:336-362`, `EurPdfApiTest.php:54-55` |
| EUE-125 | Mindest-SV-Sockel, Verlustbasis | S→S | `EurOverviewService.php:51-107` Komponenten da, kein `min_sv_cents`/SV-Differenz/Verlustbasis, kein 16080-Test |
| EUE-126 | Reingewinn/Stunde je Monat | F→F | Keine `monthlyResults`-Struktur; ODS J12/K12/N5/I16 offen |
| EUE-127 | Zeilenchecks sichtbar | S→S | `EurEntriesPanel.tsx:80-89,119-126` ⚠-Spalte jetzt da; `data.hint` im Kopf weiterhin nicht |
| EUE-128 | Farbskalen/✓ | F→**V (N/A)** | Bewusst als `not-applicable` dokumentiert (`parity-plan.md:210-218`); Theme-Badges statt Farbskala |
| EUE-129 | Matrix-Totalabgleich, Rundung 1 | S→S | `MonthStatement.php:222-273` vergleicht E1A-Aggregate; Analytics-`match/delta` tautologisch; keine ✓✓/Δ-Texte |
| EUE-130 | Footer-Relikt 1001 | V (N/A) → V (N/A) | Dokumentiert, keine Code-Änderung nötig |

## 4. Blatt- und Querschnittsabdeckung

| Blatt | Alt | Neu | Stand |
|---|---|---|---|
| Einstellungen | S | S (verbessert) | Firmendaten editierbar, `jungunternehmer` im Formular; ÖNACE/ duplicates/usage offen |
| E | S | S (deutlich besser) | ⚠-Spalte, Anteil aus Art, mobile Karten; laufende Nr., Tag/Dialogfelder offen |
| A | S | S (deutlich besser) | Wie E; 9225-Herausrechnung; USt-Basis und `vat_kind`-Dialog offen |
| Ü | S | B/S | Jahresabhängige ESt, 9225/Jungunternehmer; monatliche Ergebnisrechnung offen |
| AV | S | **B** | Echte Anlagenliste, GWG/Privatanteil/Entnahme-Badge, idempotente Jahresabschlussbuchung; Vorjahresspalte und Warnlogik offen |
| FR | S | S | P0-Bugs behoben, aber reiner Rechner ohne Persistenz |
| WEB | S | S | Datenmodell unverändert unzureichend |
| E1A | S | B/S | Ausfüllhilfe + jahresabhängige ESt; 6 E1B-KZ offen |
| E1B | S | S | Berechnet, kein Panel, kein `e1b_result_cents` |
| UST | S | B/S | 8 KZ + QuartaIe + PDF-Zeilen; keine Persistenz |
| BE-ART | F | **S (nur Backend)** | Endpunkt + Test, kein Hook/Panel |
| BA-ART | F | **S (nur Backend)** | Wie BE-ART |
| Projekte | S | S | Nur Jahresmarge, keine Matrix/CRUD |
| ZA | F | **S (nur Backend)** | Endpunkt + Test, kein Hook/Panel |
| Monat | S | B/S | USt-Zeilen im PDF, Button „PDF herunterladen"; OFF/Hint offen |
| Stunden | S | S | Minuten + 3 h, keine Zwölfteleinheiten/Reisebezug |

**Querschnitt:**

| Feature | Alt | Neu | Evidenz / Restlücke |
|---|---|---|---|
| Trust-/QES | F | **S** | `EurPdfFingerprintService.php:50-139` SHA-256+JWT+`verify()`, `/signed`-Upload (`V064`), 14 Tests, UI sagt „keine QES"; **kein CMS/PAdES, keine Kette, kein öffentlicher `/verify`, `pdfsig` bewusst nicht installiert** |
| OCR/Tesseract | F | F | 0 Treffer in Backend/Frontend/Tests |
| Externe Integrations-API | F | F | #16 nur interne idempotente Rechnungsübernahme (`V065`, 9 Tests); keine Tokens/Idempotency-Key/pdfsig im EUR-Kontext |
| Mobile Buchungskarten | S | **V** | `EurEntriesPanel.tsx:60+`, 8/8 Frontend-Tests |
| Ausfüllhilfe jedes Feld | F | F | Nur E1A-Popover |
| Key-Rotation | S | S | Env vorhanden, kein EUR-Re-Encryptionslauf |
| Eigenbeleg-Jahresbuchung | S | S | Nur monatsbezogen; kein 31.12.-Jahresmodus |
| Paritäts-Fixture | B | **B (16/45)** | Alle 11 Bereiche/26 Jahresregeln, aber Konstanten-Pin, keine Engine-Parität |
| Brand-Isolation/Verschlüsselung | B | **B** | `forCurrentBrand()`, 404, `BoundFileEncryption` |
| Accounting role/Sanctum | – | V | `EurAccountingRoleTest.php` 4/4, keine Regression |
| Evidenz `rule-coverage.json` | S | **V** | 121 covered / 0 offen (vorher 32 offen) |
| DEG-Coverage | S | S | `degressive-afa-coverage.json` behauptet weiter 15/15 mit erfundenen Testnamen; real 11/15 |

**Feature-Einschätzung: ~68 %** (Spanne 64–72 %; alt ~55 %).

## 5. DEG (separat, nicht im Floor)

11/15 umgesetzt (001–005, 009–012, 014). Offen: **006/007/008** (Ausschluss-
regeln), **013** (Wechsel-Erinnerung), **015** (Abgleich = EUE-056/057).
`degressive-afa-coverage.json` markiert weiterhin alle 15 `covered` und zitiert
E2E-Titel, die nur im JSON selbst vorkommen (0 Treffer in `frontend/tests/e2e`).
Nach der ku-online-Regel „covered erfordert Smoke **und** Playwright" ist das
sachlich falsch. Zusätzliche Divergenz: `features/owner-decisions.md:343`
(Portal) hat DEG in Scope entschieden, `ku-online/verification/requirements/
deg-scope.md:3` sagt weiter „offen" — auf ku-online-Seite nachziehen.

## 6. Priorisierte Übergabe an den Portalagenten

| Prio | Thema | Konkreter Fix | Status alt |
|---|---|---|---|
| **P0** | Trust-/QES-Kette + `/verify` | Adapter (mock/http/CSC), CMS/PAdES, Zertifikats-/Trust-Prüfung, öffentlicher `/verify` mit `valid/stale`; Inhaltsbindung ist bereits da | Teilfortschritt, offen |
| **P0** | Externe Integrations-API | Scope-Tokens, Idempotency-Key, `pdfsig`, Rate-Limit, externer Import-Endpunkt nach `openapi.yaml`; #16 ist nur intern | offen |
| **P1** | UI für BE-ART/BA-ART/ZA | Hooks + Panels zu den vorhandenen Endpunkten (`useEur.ts:501-503` kennt sie nicht); %-Spalte, ✓✓/Δ, 1-Stellen-Rundung | Backend neu, UI offen |
| **P1** | Reise-Persistenz + Verdrahtung | `eur_travel_trips/rows` mit `receipt_no/purpose/location/leg/start_km/end_km`; `kilometreQuota()`, `isTravelLeg()`, `businessSharePercent()`, `isPrivateTrip()`, `workingDays()` in Controller; `voucher` in `eur_hours` + `sumMinutesForVoucher()`; KZ 9160 | offen |
| **P1** | EUE-103/095 + EUE-126 | `IncomeTaxService::totalIncome()/allocate()` aus `tax-engine.md`; `monthlyResults` mit `est_sv/net_profit/net_per_hour`; U14/U24/U25/U27/U29 | offen |
| **P1** | OCR | Tesseract-deu im Upload-Pfad als Vorschlag (Betrag/Datum/Händler/Kategorie) | offen |
| **P2** | USt-Basis + `vat_kind` (039/040) | `VatReturnService` auf Brutto `amount_cents` umstellen, Selector im Buchungsdialog | offen |
| **P2** | WEB-Datenmodell (045/047) | Migration: `business_share`, `expense_kind`, `supplier`, Warenbezeichnung, `WE*`-Präfix, Pflichtfelder | offen |
| **P2** | Anlagen-Warnlogik + DEG | EUE-053/056/057/058/060, kataloggesteuerter GWG-Grenzwert, EUE-055 echter Entnahme-Abgleich, DEG-006/007/008/013/015 + Coverage-Korrektur | offen |
| **P2** | EUE-065 Kontingent + Fixture | Cap in `kilometreAllowanceCents()`; Fixture-Vektor 3000 km als `count`, nicht `cents`; Verhaltensvektoren statt nur Konstanten | offen |
| **P2** | EUE-088/108/131 | 12-Monats-Input für Block 2; Jungunternehmer-UI + `kv_rate_hidden`; FSVG-Fallback bewusst entscheiden (Parität vs. genehmigter Fix) | Teile behoben |
| **P3** | Kleine Reste | EUE-002/003 Warnbanner; EUE-004 ÖNACE 11–990; EUE-025…029 N/A-Doku/usage_count; EUE-116 Hint im Kopf; EUE-051 Grenzwert aus Katalog; `accidentYearlyCents()` umbenennen; Ausfüllhilfe an allen Feldern | offen |

**Reihenfolge:** P0 zuerst (drei Soll-Säulen), dann P1 (Sichtbarkeit der
Auswertungen, Reise-Persistenz, Steueraufteilung), dann P2/P3.

## 7. Bewertung von Issue #51

**Issue #51 bleibt offen.** Die im altem Bericht genannten Gründe für „offen"
gelten unverändert: QES/Trust, OCR, externe Integrations-API, WEB-Datenmodell,
EUE-053…061, Unit-Inseln, Stammdaten, EUE-131, Evidenzkorrekturen (DEG,
Engine-Parität). Bereichsweise als erledigt zu markieren sind hingegen:
P0-Reisefehler (068/071/063/064), Signatur-Button-Label, Anlagen-UI (#34),
16/45-Paritätsvektoren, Accounting-Rolle (#11), mobile Karten (#15),
Analytics-Endpunkte (#23/#51), 9225-Korrektur (115), USt-KZ im PDF (124).

## 8. Grenzen

- Externe steuerliche Prüfung bleibt Abnahmegate, kein Blocker. Diese Review
  bewertet Implementierung und Evidenz, nicht steuerliche Richtigkeit.
- Playwright-/E2E-Suiten wurden lokal nicht gefahren (Host-Auflage: laufen in
  der CI). UI-Aussagen stützen sich auf Komponentencode, Vitest und Specs.
- Bewertungen beziehen sich auf HEAD `b3c97d18`; spätere Commits können
  Einzelbefunde verändern.
