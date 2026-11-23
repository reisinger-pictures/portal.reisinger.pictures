# EAR · Verifikationsmatrix (abgeleitet)

> **Keine zweite Regelquelle.** Diese Tabelle ist abgeleitete Evidenz aus den
> gepinnten Dokumenten unter `features/eur/cleanroom/` (MANIFEST v2,
> `artifact_version 2026.3`). Sie ersetzt weder `domain-rules.json` noch die
> Paritätsfixture. Regel-IDs und Fachwirkung stammen aus dem Regelkatalog,
> Werte aus den versionierten Artefakten, Testnachweise aus dem Portal-Repo.
> Bei Abweichung ist der Fixture-Wert maßgeblich; die Portalseite wird
> korrigiert, nicht die Fixture. Externe steuerliche Prüfung bleibt
> Abnahmegate; eine QES wird ohne validierte Kette nicht behauptet.

>
> **Evidenzgrenze B08 (D-88, 2026-11-23):** Die Integrationsnotiz
> `ku-online/verification/tax-engine.md` war im Clean-Room-Handoff zunächst als
> Engine-Code ausgeschlossen. Der Eigentümer hat die Nutzung ausdrücklich
> freigegeben, weil sie nur öffentlich zugängliche Steuerformeln und
> Cent-Vektoren enthält (D-88 in `features/owner-decisions.md`). Verwendet
> wurden ausschließlich die Formelbeschreibungen und
> die Cent-Vektoren für EUE-094/095/103; **keine** Klassen, Methoden oder
> Implementierungslogik. Alle Zahlen wurden vor der Assertion gegen die
> gepinnten Tarifgrenzen und -sätze in `YearTables`/`IncomeTaxTariff`
> nachgerechnet. Die Parameter selbst bleiben allein aus `domain-rules.json`
> und den gepinnten Tabellen; es gibt keine zweite Regelquelle.

>
> **Reconciliation, 2. Pass (2026-11-22, nur Dokumentation):** Nach dem
> ersten Pass wurden die 46 verbleibenden `— offen —`-Zeilen erneut gegen
> den Repo-Stand geprüft. Die inzwischen gelandeten Implementierungen
> (Steuerwerte/Modi, SV-Modi und Übersichtsblöcke) liefern für 15 Zeilen
> konkrete Code-/Testevidenz (Datei:Zeile bzw. benannter Test/Symbol); diese
> Zeilen sind jetzt gefüllt. Die übrigen offenen Zeilen sind entweder durch
> fehlende Quell-Paritätsvektoren (#23) blockiert oder betreffen reine
> Tabellen-/Workbook-Eigenschaften ohne Portal-Entsprechung (z. B.
> Spalten-B-Hervorhebung, Farbskalen, laufende Zeilennummern, veraltete
> Formeln der letzten Zeile). Die Bezeichnung folgt dem fleet-weiten Rename
> (EAR); Regel-IDs und der gepinnte `cleanroom/`-Baum bleiben unverändert.
> **Keine Aussage über steuerliche Richtigkeit (not a claim of tax
> correctness):** dies ist ausschließlich Repo-Evidenz; die externe
> steuerliche Prüfung bleibt Abnahmegate.

Legende: **Wert** = hergeleiteter Parameter bzw. Kennzahl; **Nachweis** =
Testdatei im Portal. `fixture` = im Paritätsvektor referenziert.

## B01 — Grundlagen & Wirtschaftsjahr

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-001 | Das Wirtschaftsjahr wird zentral festgelegt und steuert alle jahresabhängigen Konstanten, Formeln und Überschriften. | Wirtschaftsjahr=2026 | E1StatementTest.php, MonthStatementTest.php, VatReturnServiceTest.php, YearTablesTest.php |
| EUE-002 | Es wird eine Kompatibilitätswarnung angezeigt und der Nutzer wird gebeten, auf die neueste Version zu aktualisieren. | Obergrenze=2026 | EurYearsApiTest.php, YearTablesTest.php; Warntext in `EurYear::warning()`, sichtbar als Banner in `ManagementEurView.tsx` (Vitest `ManagementEurView.test.tsx`) |
| EUE-003 | Es erscheint die Warnung, dass für ältere Jahre eine ältere Version (Lifetime-Lizenz) nötig ist. | Untergrenze=2024 | EurYearsApiTest.php, YearTablesTest.php; Warntext mit Lifetime-Lizenz-Hinweis, Banner wie EUE-002 |

## B02 — Stammdaten & Kontenplan

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-004 | Firmenname, Anschrift, PLZ/Ort, UID und ÖNACE-Branchenkennzahl werden zentral hinterlegt und in E1A und Monatsauswertung übernommen. | Branchenkennzahl Minimum=11; Branchenkennzahl Maximum=990 | ReferenceTablesTest.php; `SettingsController::updateBillingDetails` validiert `numeric\|between:11,990` (Test `SettingsControllerTest::test_billing_details_validate_onace_range`), `BillingDetailsCard.tsx` spiegelt die Grenzen im Formular |
| EUE-005 | Die Zahlarten (Bankkonto, Bar) bilden die Dropdowns in E/A/WEB und liefern den Startsaldo für den Zahlartenabgleich. | KZ: KZ 000 | ReferenceTables.php:116-127 (paymentMethods, opening_balance_cents), ReferenceTablesTest.php:41; Zahlart im Buchungsdialog wählbar und wird als `payment_method` gespeichert (EurEntryDialog, Vitest) |
| EUE-006 | Projekte (Hauptprojekt, Nebenprojekt, Vermietung) steuern die Dropdowns in E, A, AV, FR, WEB und Stunden sowie die Projekte-Auswertung. | Verhalten (kein Skalarwert) | ReferenceTables.php:132-138 (projects), ReferenceTablesTest.php:42; `/eur/reference` liefert zusätzlich brand-scope `eur_projects` mit IDs (EurApiTest), Buchungsdialog sendet `eur_project_id` (EurEntryDialog, Vitest) |
| EUE-007 | Jede Ausgaben-Art wird über der hinterlegten Kennzahlzuordnung für Ausgaben einer E1A-Kennzahl zugeordnet; fehlt die Zuordnung, gilt der Fallback 9230 | KZ: 9100, 9110, 9120, 9130 | ReferenceTablesTest.php |
| EUE-008 | Jede Einnahmen-Art wird über der hinterlegten Kennzahlzuordnung für Einnahmen einer E1A-Kennzahl zugeordnet; fehlt die Zuordnung, gilt 9040. | KZ: 9040, 9050, 9060, 9090 | ReferenceTablesTest.php |
| EUE-009 | Pro Ausgaben-Art kann ein Fixes Betriebsanteil hinterlegt werden (z. B. 60 % bei Laptop/Drucker 40 % privat, 50 % bei Geschäftsessen und Klimaticket,  | Anteil Minimum=0.0; Anteil Maximum=1.0; Laptop/Drucker betrieblich=0.6; Geschäftsessen/Klimaticket=0.5 | ReferenceTablesTest.php:46-63 (share_percent 0-100 in 5-%-Schritten) |
| EUE-010 | Sieben USt-Arten stehen zur Verfügung; sie steuern Vorsteuer, Reverse Charge und innergemeinschaftliche Erwerbe. | KZ: KZ 057, KZ 070, KZ 072, KZ 073 | JournalRules::validVatType, ReferenceTablesTest.php:39 (7 USt-Arten) |
| EUE-011 | Das Transportmittel wird einem Fahrzeugtyp zugeordnet, der die Kilometergeldberechnung bestimmt. Die Kategorie 'Andere' gilt nur, wenn die Zuordnung i | Verhalten (kein Skalarwert) | TravelCalculator::vehicleKind, ReferenceTablesTest.php:40 (13 vehicleTypes) |
| EUE-012 | 13 gesetzliche Feiertage werden aus dem Wirtschaftsjahr berechnet; die beweglichen Feiertage folgen der Osterformel (Gauß/Meeus). | Anzahl Feiertage=13 | Holidays.php:17-101 (Osterformel), ReferenceTablesTest.php:124-142 |
| EUE-013 | Für 174 Länder/Regionen sind Tages- und Nächtigungspauschalen hinterlegt; für Österreich gelten im unterstützten Zeitraum 26,4/15 Euro (2024) bzw. 30/ | Inland Tagespauschale=26.4; Inland Tagespauschale=30; Inland Tagespauschale=30; Inland Nächtigungspauschale (Ländertabelle)=15 | TravelAllowances.php:17-90, ReferenceTablesTest.php:43,86-107 |
| EUE-014 | Die monatliche Beitragsgrundlage wird auf das jahresabhängige Maximum begrenzt; bei Erreichen erscheint ein Hinweis. | maximale Beitragsgrundlage=7070; maximale Beitragsgrundlage=7525; maximale Beitragsgrundlage=8085 | MonthStatementTest.php, YearTablesTest.php · fixture |
| EUE-015 | Der KV-Satz beträgt in allen unterstützten Jahren 6,8 % und wird mit der Beitragsgrundlage multipliziert. | KV-Satz=6,8 %; KV-Satz=6,8 %; KV-Satz=6,8 % | ReferenceTablesTest.php:190, YearTables.php:34 (kv_satz 680/10000) · fixture |
| EUE-016 | Der monatliche Mindest-KV-Beitrag ist jahresabhängig (2024 35,45 €, 2025/2026 37,47 €). | Mindest-KV-Beitrag=35.45; Mindest-KV-Beitrag=37.47; Mindest-KV-Beitrag=37.47 | ReferenceTablesTest.php:183-184, YearTables.php:27-29 (kv_min) · fixture |
| EUE-017 | Der monatliche Mindestbeitrag der GSVG-PV ist jahresabhängig (2024 95,91 €, 2025/2026 101,95 €). | Mindest-GSVG-PV=95.91; Mindest-GSVG-PV=101.95; Mindest-GSVG-PV=101.95 | ReferenceTablesTest.php:185-186, YearTables.php:27-29 (gsvg_pv_min) · fixture |
| EUE-018 | Der monatliche Mindestbeitrag der FSVG-PV ist jahresabhängig (2024 103,69 €, 2025/2026 110,22 €). | Mindest-FSVG-PV=103.69; Mindest-FSVG-PV=110.22; Mindest-FSVG-PV=110.22 | ReferenceTablesTest.php:187, YearTables.php:27-29 (fsvg_pv_min) · fixture |
| EUE-019 | Der PV-Satz beträgt 18,5 % (konstant 2023–2026). | GSVG-PV-Satz=18,5 %; GSVG-PV-Satz=18,5 %; GSVG-PV-Satz=18,5 % | ReferenceTablesTest.php:191, YearTables.php:35 (gsvg_pv_satz) · fixture |
| EUE-020 | Der PV-Satz beträgt 20 % (konstant 2023–2026). | FSVG-PV-Satz=20 %; FSVG-PV-Satz=20 %; FSVG-PV-Satz=20 % | ReferenceTablesTest.php:192, YearTables.php:36 (fsvg_pv_satz) · fixture |
| EUE-021 | Der SVS-Satz beträgt 1,53 % (konstant 2023–2026) und wird bei FSVG/UV ausgesetzt. | SVS-Satz=1,53 %; SVS-Satz=1,53 %; SVS-Satz=1,53 % | ReferenceTablesTest.php:193, YearTables.php:37 (svs_satz) · fixture |
| EUE-022 | Die jährliche UV-Pauschale ist jahresabhängig (2024 11,35 €, 2025 12,07 €, 2026 12,95 €). | UV-Pauschale=11.35; UV-Pauschale=12.07; UV-Pauschale=12.95 | MonthStatementTest.php, ReferenceTablesTest.php, YearTablesTest.php · fixture; Monatswert eindeutig `SocialInsurance::accidentMonthlyCents()` (irreführendes `accidentYearlyCents()` entfernt) |
| EUE-023 | Die 27 unterschiedlichen Kennzahlen 9100–9230 sowie E1B 9500–9530 bilden den Kontenplan; 9135 ist sowohl E1A- als auch E1B-Kennzahl, 9225 (Pflichtvers | Anzahl unterschiedlicher Ausgaben-Kennzahlen=27 | — offen — |
| EUE-024 | Die Kennzahlen 9040, 9050, 9060 und 9090 sowie E1B 9460 bilden den Einnahmen-Kontenplan. | KZ: 9040, 9050, 9060, 9090 | — offen — |
| EUE-025 | Doppelte Zahlarten werden mit ⚠ und Zeilennummer angemahnt. | Verhalten (kein Skalarwert) | **N/A (Workbook-Artefakt):** Zahlarten sind im Portal ein gepinnter, schreibgeschützter Katalog (`ReferenceTables::paymentMethods()`); es gibt keine benutzerbearbeitbare Liste, in der Duplikate entstehen könnten. |
| EUE-026 | Doppelte Projekte werden mit ⚠ und Zeilennummer angemahnt. | Verhalten (kein Skalarwert) | Projekt-IDs brand-scope in `/eur/reference` (`eur_projects`, EurApiTest); **offen:** keine Duplikatwarnung, da `EurProject` weder Unique-Constraint noch CRUD-UI hat (Migration/Decision nötig). |
| EUE-027 | Doppelte Ausgaben-Arten werden mit ⚠ und expliziter Aufzählung angemahnt. | Verhalten (kein Skalarwert) | **N/A (Workbook-Artefakt):** Ausgaben-Arten stammen aus dem gepinnten Katalog (`ReferenceTables::expenseKinds()`), nicht aus einer bearbeitbaren Tabelle. |
| EUE-028 | Doppelte Einnahmen-Arten werden mit ⚠ und Zeilennummer angemahnt. | Verhalten (kein Skalarwert) | **N/A (Workbook-Artefakt):** wie EUE-027 (`ReferenceTables::incomeKinds()`). |
| EUE-029 | Neben jeder Art wird angezeigt, wie oft sie in den Journalen verwendet wird: '❯' entspricht keiner Verwendung, die Zahl ist die Anzahl der Verwendunge | Schwellwert Ausgaben=99; Schwellwert Einnahmen=999 | `/eur/reference` liefert `usage_counts` aus brand-scope Journalen (EurApiTest); `EurReferencePanel.tsx` zeigt ❯ / Zahl / 99+ / 999+ (Vitest) |
| EUE-030 | Datenvalidierungen begrenzen Anteile auf 0–1, Tage auf 1–31 (nur in E, AV und WEB — nicht im A-Blatt und nicht im Reiseblatt), Nutzungsjahre auf 2–99, | Tag Minimum/Maximum (E/AV/WEB)=1–31; Nutzungsjahre=2–99; Mitfahrer=0–10; Betriebsanteil=0–1 | JournalRulesTest.php, MonthStatementTest.php |

## B03 — Einnahmen

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-031 | Die betriebliche Einnahme in Q ergibt sich aus Brutto mal dem (optionalen) betrieblichen Anteil; ohne Anteil gilt 1. | Verhalten (kein Skalarwert) | E1StatementTest.php:57-70 (business_share 50), E1Statement::amountFor |
| EUE-032 | Die Kennzahl wird aus der hinterlegten Zuordnung der Einnahmenarten ermittelt; ohne Zuordnung gilt die Kennzahl 9040 für Betriebseinnahmen. | KZ: 9040 | E1StatementTest.php, JournalRulesTest.php |
| EUE-033 | Der Betriebsanteil ist 0, die Buchung erscheint nicht in den betrieblichen Einnahmen und nicht in E1A. | Betriebsanteil Privat=0 | JournalRulesTest.php:20 (share 0 -> keine KZ), E1StatementTest.php:57-70, EurEntryDialog.test.tsx (Art setzt Anteil aus Referenz, 0 % ⇒ KZ leer) |
| EUE-034 | Der Betrag wird in der Mieteinnahmen in der Vermietungsaufstellung und in der Vermietungs-Ergebnisermittlung des Vermietungsergebnisses in der Übersic | KZ: 9460 | E1StatementTest.php, JournalRulesTest.php, VatReturnServiceTest.php |
| EUE-035 | Eine fortlaufende Nummer wird nur für tatsächliche Buchungen vergeben; leere Zeilen werden übersprungen. | Verhalten (kein Skalarwert) | — offen — |
| EUE-036 | Fehlende Belegnummer/Tag/Monat oder eine undefinierte/mehrfach definierte Art erzeugt ⚠-Hinweise in Spalte B; der Hinweis 'Belegnummer fehlt' ist nur  | Verhalten (kein Skalarwert) | JournalRulesTest.php (DAY/CATEGORY/DESCRIPTION/MONTH/RECEIPT ok=false), EurEntriesPanel.tsx (⚠-Spalte) |
| EUE-037 | Monate müssen einem der zwölf Namen entsprechen; eine Tagesbereichsvalidierung (1–31) existiert in E, AV und WEB, nicht aber im A-Blatt oder im Reiseb | Verhalten (kein Skalarwert) | JournalRulesTest.php |

## B04 — Ausgaben & Vorsteuer

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-038 | Die betriebliche Ausgabe ergibt sich aus dem Betrag mal dem hinterlegten betrieblichen Anteil; ohne Anteil gilt der volle Betrag. Es gibt keine 'VST'- | Verhalten (kein Skalarwert) | E1StatementTest.php:57-70 (business_share 50 -> 500 ct) |
| EUE-039 | Die IG-Erwerb-Vorsteuer wird mit dem jeweiligen Steuersatz (20/10/13/4,9 %) auf den Bruttobetrag berechnet (Regelsatz sagt „Nettobetrag", widerspricht aber seiner eigenen Formel und der ODS-Live-Text A!L2/E1A!C8). | IG-Sätze=20/10/13/4,9 % | JournalRulesTest.php; VatReturnServiceTest.php (`test_ig_base_is_the_gross_amount_not_a_net_amount`: 119000 ct Brutto → KZ 072 = 119000 ct, Zahllast 23800 ct); `vat_kind` aus dem Katalog ist im Buchungsdialog wählbar und wird gespeichert (EurEntryDialog, Vitest/E2E) |
| EUE-040 | Die Steuerschuld aus Reverse Charge wird analog mit 20/10/13 % berechnet. | RC-Sätze=20/10/13 % | JournalRules::RC_RATES, JournalRulesTest.php:45-62 (20/10/13 %); `vat_kind` wie EUE-039 |
| EUE-041 | Die Kennzahl kommt aus der hinterlegten Zuordnung der Ausgabenarten; ohne Zuordnung gilt die Kennzahl 9230 für übrige Betriebsausgaben. | KZ: 9230 | JournalRules::expenseCode, JournalRulesTest.php:14-22 (9230) |
| EUE-042 | Fehlende Belegnummer erzeugt den Hinweis auf die Beweispflicht, fehlender Monat/Tag/Art oder eine undefinierte Art erzeugt ⚠; bei FSVG entfällt der WE | Verhalten (kein Skalarwert) | JournalRulesTest.php |
| EUE-043 | Für Wareneinkäufe wird geprüft, ob die Belegnummer im WEB vorkommt; sonst erscheint ein Hinweis. | KZ: 9100 | JournalRules.php (WEB-Check bei KZ 9100), JournalRulesTest.php |
| EUE-044 | Fortlaufende Nummerierung nur für tatsächliche Buchungen. | Verhalten (kein Skalarwert) | — offen — |
| EUE-045 | Der betriebliche Anteil wird aus der hinterlegten Zuordnung der Ausgabenarten entnommen; der Betrag wird nur bei Belegnummern mit dem Präfix 'WE' gebi | Verhalten (kein Skalarwert) | WarehouseReconciliationTest.php |
| EUE-046 | Die WEB-Prüfung verweist auf die passende Ausgabe im A-Blatt und meldet fehlende oder unbekannte Belegnummern. | Verhalten (kein Skalarwert) | WarehouseReconciliationTest.php:14-25,40-59 (receipt_no, WEB-Hinweis) |
| EUE-047 | Monat, Tag, Lieferant und Warenbezeichnung sind Pflicht; die Ausgaben-Art muss definiert und eindeutig sein. | Verhalten (kein Skalarwert) | WarehouseReconciliationTest.php |
| EUE-048 | Der Betriebsanteil ist 0; die Ausgabe wird nicht als Betriebsausgabe und nicht in E1A übernommen. | Betriebsanteil Privat=0 | JournalRulesTest.php:20-21, E1StatementTest.php:57-70 (share 0 entfaellt), EurEntryDialog.test.tsx |

## B05 — Anlagenverzeichnis & AfA

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-049 | Die AfA im laufenden Jahr ist Kosten/Nutzungsjahre, bei Anschaffung in der zweiten Jahreshälfte nur die Hälfte (Halbjahresregel); bei 0 Nutzungsjahren | Halbjahresfaktor=0,5; Ganzer-Jahres-Faktor=1 | DepreciationCalculatorTest.php |
| EUE-050 | Der Restbuchwert ist Anschaffungskosten minus AfA, mindestens 0,01 €; bei 0 Nutzungsjahren bleibt er bei den Kosten. | Mindest-Restbuchwert=0.01 | DepreciationCalculator::residualCents, DepreciationCalculatorTest.php:59-64 |
| EUE-051 | Ein Hinweis empfiehlt die sofortige Erfassung im A-Blatt statt im Anlagenverzeichnis. | GWG-Grenze=1000 | DepreciationCalculatorTest.php:26-41 (GWG-Grenze 100000 ct); `EurReferenceController` liefert `assets.gwg_limit_cents` aus dem Katalog (EurApiTest), `EurAssetsPanel.tsx` nutzt diesen Wert statt einer hart codierten Grenze (Vitest) |
| EUE-052 | Nutzungsjahre sind Pflicht und auf 2–99 begrenzt; der Wert 1 löst den Hinweis aus, dass Güter nicht abnutzbar (0) oder mindestens 2 Jahre zu erfassen  | Nutzungsjahre Minimum=2; Nutzungsjahre Maximum=99 | DepreciationCalculatorTest.php:26-32, EurAssetController::validateAsset:275 |
| EUE-053 | Die zugeordnete E1A-Kennzahl muss eine AfA-Kennzahl (9130, 9135, 9134 oder 9500) enthalten. | KZ: 9130, 9134, 9135, 9500 | `DepreciationCalculator::afaCodes()` liest die vier KZ aus dem gepinnten Regeltext (EUE-053); `EurAssetController::warnings()` liefert das ⚠ als Antwortfeld, `EurAssetsPanel.tsx` zeigt es (EurApiTest, Vitest) |
| EUE-054 | Das Gut gilt als Privatvermögen und darf nicht ins Anlagenverzeichnis; es erscheint eine Warnung. | Mindest-Betriebsanteil=0.5 | DepreciationCalculatorTest.php:29,43-50 |
| EUE-055 | Der Privatanteil muss als 'Entnahme' im E-Blatt unter derselben Belegnummer gebucht sein; anderenfalls erscheint ein Hinweis mit dem Betrag. | Privatanteil-Bereich=50 % < Anteil < 100 % | teilweise: `EurAssetsPanel.tsx` (`withdrawalHint`) zeigt den Hinweis bei jedem Betriebsanteil < 100 % (gepinnter Auslöser), `EurAssetsPanel.test.tsx` prüft 25 % Privatanteil bei 75 % Betriebsanteil. Der Abgleich gegen tatsächliche E-Buchungen bleibt offen (siehe #51). |
| EUE-056 | Die Vorjahres-AfA wird gegen die erwartete AfA (Kosten/Nutzungsjahre, ggf. Halbjahresregel) geprüft und Abweichungen gemeldet. | Toleranz AfA=0.01 | — offen — |
| EUE-057 | Fehlende oder veraltete Vorjahreswerte (AfA/Restbuchwert) werden als ⚠ gemeldet; der Restbuchwert wird auf Plausibilität gegen die Formel geprüft. | Verhalten (kein Skalarwert) | — offen — |
| EUE-058 | Ein Anschaffungsjahr größer als das Wirtschaftsjahr erzeugt eine Warnung. | Verhalten (kein Skalarwert) | `EurAssetController::warnings()` vergleicht das Anschaffungsjahr mit `EurYear::UPPER` (2026) und liefert das ⚠ als Antwortfeld; `EurAssetsPanel.tsx` zeigt es (EurApiTest, Vitest) |
| EUE-059 | Belegnummer, Jahr, Monat, Tag und Bezeichnung sind Pflicht; fehlende Werte erzeugen ⚠-Hinweise. | Verhalten (kein Skalarwert) | — offen — |
| EUE-060 | Die laufende Nummer wird nur für tatsächliche Einträge vergeben. | Verhalten (kein Skalarwert) | **N/A (Workbook-Artefakt):** die laufende Nummer ist ein ODS-Sichtartefakt ohne DataSource-Wert; auch ku-online dokumentiert die Regel als nicht prüfbar. Das Portal vergibt Belegnummern `AV-<id>-<jahr>` nur für tatsächlich gebuchte AfA-Jahre (`EurAssetController::bookDepreciation`) |
| EUE-061 | Die Kennzahl wird aus der hinterlegten Zuordnung der Ausgabenarten übernommen; ohne Zuordnung gilt die Kennzahl 9130 für Anlagegüter. | KZ: 9130, 9134, 9135, 9500 | DepreciationCalculatorTest.php |

## B06 — Reise- & Fahrtkosten

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-062 | Der Satz ist 0,42 €/km (2024) bzw. 0,50 €/km (2025+); pro Mitfahrer kommen 0,05 € (2024) bzw. 0,15 € (2025+) hinzu, höchstens 6 Mitfahrer. | KM-Geld PKW=0.42; KM-Geld PKW=0.5; KM-Geld PKW=0.5; Mitfahrerzuschlag PKW=0.05 | TravelCalculatorTest.php, YearTablesTest.php |
| EUE-063 | 2024: 0,24 €/km; 2025: 0,50 €/km im ersten Halbjahr, 0,25 €/km ab Juli; 2026: 0,25 €/km. Mitfahrerzuschlag wie beim PKW (max 1). | KM-Geld Motorrad=0.24; KM-Geld Motorrad=0,50 H1 / 0,25 H2; KM-Geld Motorrad=0.25; Mitfahrerzuschlag Motorrad=0.05 | YearTablesTest.php:40-51, TravelCalculatorTest.php (30.06./01.07.-Wechsel + Default), EurApiTest.php (422 statt 500) |
| EUE-064 | 2024: 0,38 €/km; 2025: 0,50 €/km im ersten Halbjahr, 0,25 €/km ab Juli; 2026: 0,25 €/km. | KM-Geld Fahrrad=0.38; KM-Geld Fahrrad=0,50 H1 / 0,25 H2; KM-Geld Fahrrad=0.25 | YearTablesTest.php:40-51 (Fahrrad 38/50/25), TravelCalculatorTest.php:27-33 |
| EUE-065 | PKW und Motorrad zusammen sind auf 30.000 km gedeckelt; Fahrrad/E-Bike auf 1.500 km (2024) bzw. 3.000 km (2025+). | Fahrrad-Kontingent=1500; Fahrrad-Kontingent=3000; Fahrrad-Kontingent=3000; KFZ-Kontingent=30000 | TravelCalculatorTest.php (inkl. Regression `test_km_allowance_is_capped_at_the_annual_quota`); Fixture-Vektor `derived.eue.065.fahrrad.kontingent` als `count`=3000 statt 300000 ct (MANIFEST aktualisiert) |
| EUE-066 | Bei PKW/Kombi sind höchstens 6, beim Motorrad höchstens 1 Mitfahrer zulässig; bei anderen Transportmitteln muss das Feld leer oder 0 sein. | Max Mitfahrer PKW=6; Max Mitfahrer Motorrad=1 | YearTables.php:117-123 (maximumPassengers: PKW=6, Motorrad=1), TravelCalculatorTest.php:35-44 |
| EUE-067 | Entweder Anfangs- und Endkilometerstand ODER die Distanz ist anzugeben; beides gleichzeitig ist ein Fehler. | Verhalten (kein Skalarwert) | TravelCalculatorTest.php, EurTravelPersistenceTest.php (`test_store_derives_distance_from_km_readings_and_rejects_mixing`: Distanz aus Differenz, `prohibits` bei gemischter Eingabe, Endstand > Startstand) |
| EUE-068 | Das Tag-Geld wird anteilig für zwölf Monate berechnet. Bei einer Mahlzeit im Inland wird die halbe Pauschale angesetzt, im Ausland die volle Pauschale | 1 Mahlzeit Inland=AM3/2; 1 Mahlzeit Ausland=AM3 (voll); 2 Mahlzeiten Inland=0; 2 Mahlzeiten Ausland=AM3/3 | TravelCalculatorTest.php (Ausland 1 Mahlzeit = 3000 ct, Faktor 1) |
| EUE-069 | Im Inland beträgt die Pauschale vor 2024 4,4 Euro und ab 2024 4,5 Euro, im Ausland 5,85 Euro; ansonsten gilt die Ländertabelle. | Inland Nächtigung ohne Beleg=4.5; Inland Nächtigung ohne Beleg=4.5; Inland Nächtigung ohne Beleg=4.5; Ausland Nächtigung Pauschale=5.85 | TravelCalculatorTest.php |
| EUE-070 | Ab dem 5. Tag entfällt das Tagesgeld, ab dem 15. Tag auch das Nächtigungsgeld; der Reisestatus weist dies aus. | Tagesgeld-Grenze=5; Nächtigungsgeld-Grenze=15 | TravelCalculatorTest.php |
| EUE-071 | Die Zeilen werden als 'Hin-Rückfahrt' markiert; dafür entfällt das Tagesgeld und die Veranlassung/Einsatzort bleiben leer. | Zeitgrenze Hin-/Rückfahrt=3; Hinfahrt-Minimum Einzelzeile=50; Hinfahrt-Minimum verkettet=25 | HoursLedgerTest.php, TravelCalculatorTest.php (ab 180 min, inkl. AE3/ledgerMinutes) |
| EUE-072 | Der Betriebsanteil wird aus dem Verhältnis der betrieblichen zur gesamten Reisezeit gebildet; liegt er unter zehn Prozent, wird er mit null Prozent be | Untere Rundungsgrenze=10 %; Obere Rundungsgrenze=90 % | TravelCalculatorTest.php |
| EUE-073 | Die betriebliche Ausgabe und alle anteiligen Kosten werden auf 0 gesetzt; die Reise wird als 'Nein (VERANLASSUNG: Privat)' markiert. | Verhalten (kein Skalarwert) | EurTravelPersistenceTest.php (`test_private_purpose_zeroes_the_deductible_amount`: `business_cents=0`, `deductible=false`, `purpose='Privat'`; die ODS-Textmarkierung wird im Portal über `purpose`/`deductible` ausgedrückt, nicht als Literal) |
| EUE-074 | Die Anzahl der Werktage wird mit NETWORKDAYS inklusive der jahresabhängigen Feiertagsliste berechnet. | Verhalten (kein Skalarwert) | TravelCalculatorTest.php (`test_working_days_use_the_pinned_holidays_and_the_half_day_rule`, `test_working_days_follow_the_year_dependent_easter_formula`: Halbtagsregel aus FR!AV3, Wochenenden, Feiertage 2025/2026); Feiertagsableitung selbst in ReferenceTablesTest.php gegen das Artefakt geprüft |
| EUE-075 | Weicht das Jahr vom eingestellten Wirtschaftsjahr ab, erscheint eine Warnung; ungültige Datumswerte werden ebenfalls gemeldet. | Verhalten (kein Skalarwert) | TravelCalculatorTest.php |
| EUE-076 | Ende darf nicht vor oder gleich Start liegen (bei gleichem Datum); bei mehrteiligen Reisen soll die nächste Zeile dort fortsetzen, wo die vorherige en | Verhalten (kein Skalarwert) | TravelCalculatorTest.php:124-138 (Reiseende nach Reisestart; EUE-075...079) |
| EUE-077 | Die Belegnummer muss als Referenz auf Fahrtenbuch/Reisekostenabrechnung gesetzt sein; doppelte Belegnummern für verschiedene Reisen werden gemeldet. | Verhalten (kein Skalarwert) | EurTravelPersistenceTest.php (`test_store_requires_a_receipt_number_and_rejects_duplicates`: Pflichtfeld 422, Doppelbeleg 422 pro Brand) |
| EUE-078 | Land/Region und Transportmittel müssen gewählt und im Stammdatensatz definiert sein. | Verhalten (kein Skalarwert) | EurTravelPersistenceTest.php (`test_store_rejects_invalid_travel_input_without_persisting`: unbekanntes Land 422), EurApiTest.php (unbekanntes Land 422) |
| EUE-079 | Veranlassung und Einsatzort dürfen nur in einer Zeile der Reise stehen und müssen bei Hin-/Rückfahrtzeilen leer bleiben. | Verhalten (kein Skalarwert) | TravelCalculatorTest.php |
| EUE-080 | Die Insgesamt geleisteten Stunden des Belegs werden aus dem Stundenblatt summiert und fließen in die 3-Stunden-Regel ein. | Verhalten (kein Skalarwert) | HoursLedgerTest.php |
| EUE-081 | Die betriebliche Reiseausgabe ist die Summe aus Fahrt (BA), Tag (BB) und Nacht (BC); alle Zeilen ergeben fr_betriebsausgaben, das in die Monats- und J | Verhalten (kein Skalarwert) | TravelCalculatorTest.php, EurTravelPersistenceTest.php (`e1a_code='9160'`; Index summiert `business_cents` aller Reisen des Brands) |

## B07 — Umsatzsteuer

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-082 | Die Zeilen des Formulars werden mit festen Kennzahlen belegt: 000 Gesamtbetrag, 016 Kleinunternehmer, 057 Reverse Charge, 070 IG-Erwerbe, 072/073/008/ | KZ: KZ 000, KZ 016, KZ 057, KZ 070 | VatCalculatorTest.php, VatReturnServiceTest.php |
| EUE-083 | Die Betriebseinnahmen des Monats werden als Kleinunternehmer-Umsatz (§ 6 Abs. 1 Z 27 UStG) ausgewiesen. | KZ: KZ 000, KZ 016 | VatCalculatorTest.php |
| EUE-084 | Die Reverse-Charge-Steuer aus dem A-Blatt wird auf zwei Nachkommastellen abgerundet in KZ 057 übernommen. | KZ: KZ 057 | VatCalculatorTest.php |
| EUE-085 | Die IG-Erwerbe werden je Steuersatz (20 %, 10 %, 13 %, 4,9 %) auf KZ 072, 073, 008 und 125 verteilt und auf zwei Stellen abgerundet. | KZ: KZ 070, KZ 072, KZ 073, KZ 008 | VatCalculatorTest.php |
| EUE-086 | Die Zahllast ist Reverse Charge plus die IG-Erwerb-Bemessungsgrundlagen multipliziert mit 20 %, 10 %, 13 % und 4,9 %. | USt-Sätze=20/10/13/4,9 % | VatCalculatorTest.php, VatReturnServiceTest.php |
| EUE-087 | Die Monatswerte werden zu Quartalssummen addiert (H/M/S/X), sofern nicht alle leer sind. | Verhalten (kein Skalarwert) | VatCalculatorTest.php, VatReturnServiceTest.php |
| EUE-088 | Das Blatt enthält zwei Bereiche: '1. Umsatzsteuervoranmeldung' und '2. Umsatzsteuererklärung' mit gleicher Kennzahlenstruktur. | Verhalten (kein Skalarwert) | VatCalculatorTest.php, EurVatApiTest.php (Block 1 = Monat, Block 2 = Jahressummen) |

## B08 — Einkommensteuer & Grundfreibetrag

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-089 | Der erste Steuersatz beträgt 20 % bis zur jahresabhängigen Grenze 12.816 € (2024) / 13.308 € (2025) / 13.539 € (2026). | Tarifgrenze Stufe 1=12816; Tarifgrenze Stufe 1=13308; Tarifgrenze Stufe 1=13539; Steuersatz Stufe 1=20 % | YearTablesTest.php:17-23, IncomeTaxTariff::forYear(), EurIncomeTaxApiTest.php (Jahr-Parameter) |
| EUE-090 | Der zweite Steuersatz beträgt 30 % von der Grenze Stufe 1 bis 20.818 € (2024) / 21.617 € (2025) / 21.992 € (2026). | Tarifgrenze Stufe 2=20818; Tarifgrenze Stufe 2=21617; Tarifgrenze Stufe 2=21992; Steuersatz Stufe 2=30 % | YearTablesTest.php:17-23, YearTables.php:16-23 (Stufe 2) |
| EUE-091 | Der dritte Steuersatz beträgt 40 % bis 34.513 € (2024) / 35.836 € (2025) / 36.458 € (2026). | Tarifgrenze Stufe 3=34513; Tarifgrenze Stufe 3=35836; Tarifgrenze Stufe 3=36458; Steuersatz Stufe 3=40 % | YearTablesTest.php:17-23, YearTables.php:16-23 (Stufe 3) |
| EUE-092 | Der vierte Steuersatz beträgt 48 % bis 66.612 € (2024) / 69.166 € (2025) / 70.365 € (2026). | Tarifgrenze Stufe 4=66612; Tarifgrenze Stufe 4=69166; Tarifgrenze Stufe 4=70365; Steuersatz Stufe 4=48 % | YearTablesTest.php:17-23, YearTables.php:16-23 (Stufe 4) |
| EUE-093 | Der fünfte Steuersatz beträgt 50 % oberhalb von 99.266 € (2024) / 103.072 € (2025) / 104.859 € (2026). | Tarifgrenze Stufe 5=99266; Tarifgrenze Stufe 5=103072; Tarifgrenze Stufe 5=104859; Steuersatz Stufe 5=50 % | YearTablesTest.php:17-23, YearTables.php:16-23 (Stufe 5) |
| EUE-094 | Die Steuer wird stufenweise aus den jeweiligen Einkommensanteilen berechnet; die Summe der fünf Stufen ergibt die Einkommensteuer. | Verhalten (kein Skalarwert) | MonthStatementTest.php |
| EUE-095 | Je nach Modus ('Gesamt', 'Nur Betrieb', 'Nur Vermietung') werden betriebliche Einkünfte, Vermietung, nichtselbständige Einkünfte und prognostizierte W | KZ: KZ 245; U14 (Vektor) = 2 905 000 ct | `IncomeTaxService::totalIncomeCents()` (U10+U11 / U11+U12+U13 / Summe), `EurTaxStatementService` liefert U12/U13; `IncomeTaxServiceTest.php` (3 Modi + Aliase), `EurIncomeTaxApiTest.php` (`est_allocation.total_income_cents`, `kz.U12`) |
| EUE-096 | Die Grenze beträgt 30.000 € vor 2024 und 33.000 € ab 2024; im unterstützten Zeitraum immer 33.000 €. | Grundfreibetrag Grenze=33000; Grundfreibetrag Grenze=33000; Grundfreibetrag Grenze=33000 | YearTablesTest.php:25-30, YearTables::basicAllowance:59-65 · fixture |
| EUE-097 | Der Satz beträgt 13 % vor 2022 und 15 % ab 2022; im unterstützten Zeitraum immer 15 %. | Grundfreibetrag Satz=15 %; Grundfreibetrag Satz=15 %; Grundfreibetrag Satz=15 % | YearTablesTest.php:25-30 (satz 1500/10000) · fixture |
| EUE-098 | Der Grundfreibetrag entspricht dem kleineren Wert aus 15 Prozent des Gewinns und 15 Prozent von 33.000 Euro, mindestens null; er wird vom Gewinn abgez | KZ: KZ 330 | YearTablesTest.php |
| EUE-099 | Die Beträge je Kennzahl werden per SUMIF aus den Journalen geholt; 9100 enthält zusätzlich das WEB, 9160 die Reisekosten, 9130/9134/9135 das Anlagenve | KZ: 9040, 9050, 9060, 9090 | E1StatementTest.php |
| EUE-100 | Mieteinnahmen und Werbungskosten je Kennzahl werden aus den Einnahmen-, Ausgaben- und Anlagenjournalen aggregiert; der Überschuss der Mieteinnahmen üb | KZ: 9414, 9460, 9500, 9135 | E1StatementTest.php |
| EUE-101 | Bis 300 € gilt die kleine Pauschale KZ 9215, von über 300 bis 1.200 € die große KZ 9217; bei Zusage von 9275 (Arbeitszimmer) ist der Wert zu löschen. | Kleine Pauschale bis=300; Große Pauschale bis=1200; Eingabe Minimum=25 | EurTaxStatementService.php:117-135 (9215/9217), EurTaxStatementServiceTest.php:76-96, EurIncomeTaxApiTest.php:54 |
| EUE-102 | Ergonomisches Mobiliar wird mit KZ 9216 ausgewiesen. Unterdrückt wird die Kennzahl nur, wenn die Arbeitszimmerpauschale 9275 erfasst ist oder kein Bet | Eingabe Maximum=300 | EurTaxStatementService.php:117-144 (9216, Sperre bei 9275), EurTaxStatementServiceTest.php:76-96 |
| EUE-103 | Die Einkommensteuer wird per Progressionsrechnung auf nichtselbständige Einkünfte, auf Vermietung und auf den Betrieb aufgeteilt; die Einkommensteuer- | KZ: KZ 245, KZ 260; Vektor U23 380 800 / U27 129 220 / U25 28 000 / U24 223 580 / U29 230 800 ct | `IncomeTaxService::allocate()` + `::progressiveTaxCents()` (gepinnter Tarif); `ue_est` als U23 dokumentiert (D-88). `EurIncomeTaxController` gibt `est_allocation` aus. `IncomeTaxServiceTest.php`, `EurIncomeTaxApiTest.php` (Vektor, Null-Vorauszahlung, Bruttolohn-Modus) |
| EUE-104 | Mit Prognose werden weitere betriebliche/ Vermietungsgewinne aufs Jahr hochgerechnet; die Modi bestimmen, welche Einkünfte in die Übersicht einfließen | Verhalten (kein Skalarwert) | EurTaxStatementService::rentalForecast, EurTaxStatementServiceTest.php (Einnahmen − Werbungskosten) |
| EUE-105 | Bestimmte Zeilen der Übersicht und die SV-Beiträge werden ausgeblendet bzw. auf 0 gesetzt. | Verhalten (kein Skalarwert) | EurTaxStatementService.php:52-60 (Modus setzt Werte auf 0), EurTaxStatementServiceTest.php:98-119, EurOverviewService.php:90-100 |
| EUE-106 | Die Auswahl des Rechenmodus bietet nur die Werte 'Gesamt', 'Nur Betrieb' und 'Nur Vermietung'. In der Berechnung werden jedoch zusätzlich die Variante | Verhalten (kein Skalarwert) | EurTaxStatementService.php:25,40-42 (4 Modi inkl. Prognose), EurIncomeTaxPanel.tsx:134-137, EurTaxStatementServiceTest.php:121-127 |

## B09 — Sozialversicherung

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-107 | Vier Modi bestimmen die SV-Beiträge: Gewerbetreibende (GSVG), Neue Selbständige (GSVG), Freiberufliche (FSVG) und Nur Unfallversicherung. | KZ: 9225 | SocialInsurance.php:20-24,106-128,154-157, SocialInsuranceModesTest.php:38-60, EurSettingsController.php:41 |
| EUE-108 | Bei Jungunternehmern wird nur der KV-Satz in der Anzeige auf '-' gesetzt; der Mindest-KV-Beitrag wird dennoch bezahlt. SVS und PV sind von der Jungunt | Mindest-KV Jungunternehmer=449.64 | SocialInsurance.php, SocialInsuranceModesTest.php, EurSettingsApiTest.php (Modus erreichbar, kv_rate_hidden) |
| EUE-109 | Die Beitragsgrundlage ist das Betriebseinkommen zuzüglich der bereits gezahlten SV-Beiträge (9225), gedeckelt mit dem Zwölffachen der jahresabhängigen | Deckelung Beitragsgrundlage=84840; Deckelung Beitragsgrundlage=90300; Deckelung Beitragsgrundlage=97020 | EurOverviewService.php:35-36 (Einkuenfte + 9225, gedeckelt), EurSummaryController.php:44-52, EurOverviewServiceTest.php:24-41 |
| EUE-110 | Der KV-Beitrag entspricht dem höheren Wert aus 6,8 Prozent der Beitragsgrundlage und dem zwölffachen Mindest-KV-Beitrag. Bei Jungunternehmern wird nur | KV-Satz=6,8 %; Mindest-KV ×12=425.4; Mindest-KV ×12=449.64; Mindest-KV ×12=449.64 | EurOverviewService.php:38-43, EurOverviewServiceTest.php:24-41 (KV 3747 ct), SocialInsuranceModesTest.php:73-78 |
| EUE-111 | Der PV-Beitrag ist dem höheren Wert aus Beitragsgrundlage × PV-Satz, modusabhängiger Mindestbeitrag × 12); der Satz ist 18,5 % (GSVG) bzw. 20 % (FSVG) | PV-Satz GSVG=18,5 %; PV-Satz GSVG=18,5 %; PV-Satz GSVG=18,5 %; PV-Satz FSVG=20 % | EurOverviewService.php:45-54, EurOverviewServiceTest.php:24-41,53-60 |
| EUE-112 | Die Unfallversicherung ist eine Jahrespauschale. Die Selbständigenvorsorge entspricht dem höheren Wert aus 1,53 Prozent der Beitragsgrundlage und dem  | SVS-Satz=1,53 %; UV ×12=136.2; UV ×12=144.84; UV ×12=155.4 | EurOverviewService.php:56-68, EurOverviewServiceTest.php:24-60, SocialInsuranceModesTest.php:62-71 |
| EUE-113 | Für die Monate ohne Einkommen werden Mindestbeiträge (UV, SVS, KV, PV) als Sockel angesetzt. | Verhalten (kein Skalarwert) | EurOverviewService.php:28-84 (12 Sockel-Monate), EurOverviewServiceTest.php:24-41 |
| EUE-114 | Die Summe der vier Beiträge ergibt die jährlichen SV-Beiträge; die Division durch 12 liefert den Monatsbetrag. | Verhalten (kein Skalarwert) | EurOverviewService.php:70-83 (Jahreswerte / 12), EurOverviewServiceTest.php:24-41 |
| EUE-115 | Bereits bezahlte SV-Beiträge (KZ 9225) werden bei der Ermittlung der Beitragsgrundlage zu den Einkünften addiert; in der Übersicht werden sie außerdem | KZ: 9225 | EurOverviewService.php (excludeBookedSvFromExpenses), EurSummaryController.php, EurSummaryApiTest.php (Herausrechnen außer UV-Modus) |
| EUE-131 | Die Auswahlliste für die Sozialversicherungsart und die Formeln für die Pensionsversicherung verwenden die Bezeichnung 'Freiberufliche (FSVG)'. Die Fo | Verhalten (kein Skalarwert) | **Entscheidung D-79:** Portal nutzt den korrigierten FSVG-Mindestbeitrag 110,22 € (2025/26) statt des ODS-Defekts 101,95 € aus `Ü!I12` (String 'Freiberufler' trifft die Validierung 'Freiberufliche' nicht). Beleg: rules.json (EUE-131), re-verification.md:57 |

## B10 — Auswertungen & Abgleich

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-116 | Der Schalter 'OFF' deaktiviert die rechenaufwendige Monatsansicht; bei OFF erscheint ein 💡-Hinweis, die Ansicht wieder abzuschalten. | Verhalten (kein Skalarwert) | MonthStatementTest.php (`JournalRules::HEADER_HINT`); Hint wird in `EurMonthPanel.tsx` gerendert (TS-Feld `EurMonthData.hint`, Vitest) |
| EUE-117 | Die Monatsansicht zieht Einnahmen, Ausgaben (A/AV/WEB/FR/USt) und Stunden des gewählten Monats per SUMIFS/INDEX zusammen. | Verhalten (kein Skalarwert) | MonthStatementTest.php |
| EUE-118 | Die Zeilen der Einnahmen-, Ausgaben-, Anlagen- und Reisejournale werden über eine aufwendige Auswahlformel in die Monatsansicht übernommen; Einträge d | Verhalten (kein Skalarwert) | MonthStatementTest.php |
| EUE-119 | Eine Matrix zeigt die Einnahmen je Art und Monat; Summen und ein ✓✓-Abgleich gegen das E-Blatt stellen die Vollständigkeit sicher. | Verhalten (kein Skalarwert) | EurAnalyticsController::byIncomeType, EurAnalyticsApiTest.php; sichtbar in `EurAnalyticsMatricesPanel.tsx` mit %-Spalte (1 Stelle) und ✓✓/Δ (Vitest, E2E `@feature:eur-analytics`) |
| EUE-120 | Eine Matrix summiert die Ausgaben aus den Journalen je Art und Monat; FR-Kosten kommen als eigene Zeile hinzu; ✓✓ vergleicht mit der Gesamtsumme. | Verhalten (kein Skalarwert) | EurAnalyticsController::byExpenseType, EurAnalyticsApiTest.php (A/AV/WEB/FR); UI wie EUE-119 inkl. Herkunftsspalte A/AV/WEB/FR |
| EUE-121 | Für jedes Projekt und jeden Monat werden Einnahmen, Ausgaben (inkl. Vorjahres-AfA und 9225-Bereinigung) und Arbeitsstunden ausgewiesen; Differenz- und | KZ: 9225 | — offen — |
| EUE-122 | Für jede Zahlart und jeden Monat werden Ein- und Ausflüsse aus E/A/WEB gegenübergestellt; ✓/✓✓ bestätigen die Übereinstimmung mit Saldo und Startsaldo | Verhalten (kein Skalarwert) | EurAnalyticsController::paymentReconciliation, EurAnalyticsApiTest.php; sichtbar in `EurAnalyticsMatricesPanel.tsx` (Vitest, E2E `@feature:eur-analytics`) |
| EUE-123 | Stunden werden je Monat und Projekt erfasst und speisen die Projekte-Auswertung, die Reise-3-Stunden-Regel und die Monatsübersicht. | Verhalten (kein Skalarwert) | HoursLedgerTest.php |
| EUE-124 | Die Werte der UST-Voranmeldung werden über ust_daten/ust_kennzahlen/ust_monate in die Monatsansicht übernommen. | Verhalten (kein Skalarwert) | MonthStatementTest.php (vat.kennzahlen), EurPdfApiTest.php (USt-Tabelle), EurMonthPanel.tsx |
| EUE-125 | Der monatliche Mindest-SV-Beitrag (UV, SVS-Minimum, KV-Minimum, PV-Minimum je nach Modus) und die aus den Monatsgewinnen abgeleitete Verlustbasis werd | Mindest-SV monatlich=160.80 €; I14 = ABS(MIN(ue_gewinn_pro_monat)) | `EurOverviewService::svMonthly()` bei leerer Bemessungsgrundlage → I12 16 080 ct (gepinnte SV-Parameter); `IncomeTaxService::monthlyResults()` setzt I14 aus den Monatsgewinnen. `IncomeTaxServiceTest.php`, `EurIncomeTaxApiTest.php` |
| EUE-126 | Je Monat wird die anteilige Einkommensteuer samt SV aus der Jahressteuer abgeleitet; der Reingewinn ist Bruttoergebnis minus dieser Belastung; daraus  | J12 = (G12+I14)/I16×I13+I12; K12 = N(G12)−N(J12); N5 = K12×60/M12; Unit-Vektor (SV = 12×I12, 12×100 000 ct) → J12 37 045 / K12 62 955 / N5 6 296 ct | `IncomeTaxService::monthlyResults()` (ganze Cent, BCMath); `EurIncomeTaxController` ergänzt `monthlyResults` mit `est_sv_cents`, `net_profit_cents`, `net_per_hour_cents` und den J12/K12/N5/I16-Äquivalenten (null ohne Monatsdaten). `IncomeTaxServiceTest.php`, `EurIncomeTaxApiTest.php` |

## B11 — Validierung & Plausibilität

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-127 | Alle Zeilenchecks der Journale und Stammdatenblätter stehen in Spalte B und werden farblich hervorgehoben, wenn sie einen ⚠-Hinweis enthalten; im Mona | Verhalten (kein Skalarwert) | — offen — |
| EUE-128 | Zahlenbereiche in der Übersicht und den Auswertungen werden als Farbskala dargestellt; ✓-Symbole in der Zuordnung werden hervorgehoben. | Verhalten (kein Skalarwert) | — offen — |
| EUE-129 | Summen über die Journal-Beträge werden mit den Summen der Auswertungen bzw. der Steuererklärung verglichen; Abweichungen werden als 'Fehler (Δ €)' ang | Abgleich-Rundung=1 | MonthStatementTest.php, WarehouseReconciliationTest.php |
| EUE-130 | Die Formeln in der letzten Zeile der Journalblätter sind veraltete Relikte mit verschobener Spaltenbedeutung und dürfen nicht als fachliche Regeln ext | Verhalten (kein Skalarwert) | — offen — |
