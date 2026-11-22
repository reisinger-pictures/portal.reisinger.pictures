# EÜR · Verifikationsmatrix (abgeleitet)

> **Keine zweite Regelquelle.** Diese Tabelle ist abgeleitete Evidenz aus den
> gepinnten Dokumenten unter `features/eur/cleanroom/` (MANIFEST v2,
> `artifact_version 2026.2`). Sie ersetzt weder `domain-rules.json` noch die
> Paritätsfixture. Regel-IDs und Fachwirkung stammen aus dem Regelkatalog,
> Werte aus den versionierten Artefakten, Testnachweise aus dem Portal-Repo.
> Bei Abweichung ist der Fixture-Wert maßgeblich; die Portalseite wird
> korrigiert, nicht die Fixture. Externe steuerliche Prüfung bleibt
> Abnahmegate; eine QES wird ohne validierte Kette nicht behauptet.

Legende: **Wert** = hergeleiteter Parameter bzw. Kennzahl; **Nachweis** =
Testdatei im Portal. `fixture` = im Paritätsvektor referenziert.

## B01 — Grundlagen & Wirtschaftsjahr

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-001 | Das Wirtschaftsjahr wird zentral festgelegt und steuert alle jahresabhängigen Konstanten, Formeln und Überschriften. | Wirtschaftsjahr=2026 | E1StatementTest.php, MonthStatementTest.php, VatReturnServiceTest.php, YearTablesTest.php |
| EUE-002 | Es wird eine Kompatibilitätswarnung angezeigt und der Nutzer wird gebeten, auf die neueste Version zu aktualisieren. | Obergrenze=2026 | EurYearsApiTest.php, YearTablesTest.php |
| EUE-003 | Es erscheint die Warnung, dass für ältere Jahre eine ältere Version (Lifetime-Lizenz) nötig ist. | Untergrenze=2024 | EurYearsApiTest.php, YearTablesTest.php |

## B02 — Stammdaten & Kontenplan

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-004 | Firmenname, Anschrift, PLZ/Ort, UID und ÖNACE-Branchenkennzahl werden zentral hinterlegt und in E1A und Monatsauswertung übernommen. | Branchenkennzahl Minimum=11; Branchenkennzahl Maximum=990 | ReferenceTablesTest.php |
| EUE-005 | Die Zahlarten (Bankkonto, Bar) bilden die Dropdowns in E/A/WEB und liefern den Startsaldo für den Zahlartenabgleich. | KZ: KZ 000 | — offen — |
| EUE-006 | Projekte (Hauptprojekt, Nebenprojekt, Vermietung) steuern die Dropdowns in E, A, AV, FR, WEB und Stunden sowie die Projekte-Auswertung. | Verhalten (kein Skalarwert) | — offen — |
| EUE-007 | Jede Ausgaben-Art wird über der hinterlegten Kennzahlzuordnung für Ausgaben einer E1A-Kennzahl zugeordnet; fehlt die Zuordnung, gilt der Fallback 9230 | KZ: 9100, 9110, 9120, 9130 | ReferenceTablesTest.php |
| EUE-008 | Jede Einnahmen-Art wird über der hinterlegten Kennzahlzuordnung für Einnahmen einer E1A-Kennzahl zugeordnet; fehlt die Zuordnung, gilt 9040. | KZ: 9040, 9050, 9060, 9090 | ReferenceTablesTest.php |
| EUE-009 | Pro Ausgaben-Art kann ein Fixes Betriebsanteil hinterlegt werden (z. B. 60 % bei Laptop/Drucker 40 % privat, 50 % bei Geschäftsessen und Klimaticket,  | Anteil Minimum=0.0; Anteil Maximum=1.0; Laptop/Drucker betrieblich=0.6; Geschäftsessen/Klimaticket=0.5 | — offen — |
| EUE-010 | Sieben USt-Arten stehen zur Verfügung; sie steuern Vorsteuer, Reverse Charge und innergemeinschaftliche Erwerbe. | KZ: KZ 057, KZ 070, KZ 072, KZ 073 | — offen — |
| EUE-011 | Das Transportmittel wird einem Fahrzeugtyp zugeordnet, der die Kilometergeldberechnung bestimmt. Die Kategorie 'Andere' gilt nur, wenn die Zuordnung i | Verhalten (kein Skalarwert) | — offen — |
| EUE-012 | 13 gesetzliche Feiertage werden aus dem Wirtschaftsjahr berechnet; die beweglichen Feiertage folgen der Osterformel (Gauß/Meeus). | Anzahl Feiertage=13 | — offen — |
| EUE-013 | Für 174 Länder/Regionen sind Tages- und Nächtigungspauschalen hinterlegt; für Österreich gelten im unterstützten Zeitraum 26,4/15 Euro (2024) bzw. 30/ | Inland Tagespauschale=26.4; Inland Tagespauschale=30; Inland Tagespauschale=30; Inland Nächtigungspauschale (Ländertabelle)=15 | — offen — |
| EUE-014 | Die monatliche Beitragsgrundlage wird auf das jahresabhängige Maximum begrenzt; bei Erreichen erscheint ein Hinweis. | maximale Beitragsgrundlage=7070; maximale Beitragsgrundlage=7525; maximale Beitragsgrundlage=8085 | MonthStatementTest.php, YearTablesTest.php · fixture |
| EUE-015 | Der KV-Satz beträgt in allen unterstützten Jahren 6,8 % und wird mit der Beitragsgrundlage multipliziert. | KV-Satz=6,8 %; KV-Satz=6,8 %; KV-Satz=6,8 % | — offen — · fixture |
| EUE-016 | Der monatliche Mindest-KV-Beitrag ist jahresabhängig (2024 35,45 €, 2025/2026 37,47 €). | Mindest-KV-Beitrag=35.45; Mindest-KV-Beitrag=37.47; Mindest-KV-Beitrag=37.47 | — offen — · fixture |
| EUE-017 | Der monatliche Mindestbeitrag der GSVG-PV ist jahresabhängig (2024 95,91 €, 2025/2026 101,95 €). | Mindest-GSVG-PV=95.91; Mindest-GSVG-PV=101.95; Mindest-GSVG-PV=101.95 | — offen — · fixture |
| EUE-018 | Der monatliche Mindestbeitrag der FSVG-PV ist jahresabhängig (2024 103,69 €, 2025/2026 110,22 €). | Mindest-FSVG-PV=103.69; Mindest-FSVG-PV=110.22; Mindest-FSVG-PV=110.22 | — offen — · fixture |
| EUE-019 | Der PV-Satz beträgt 18,5 % (konstant 2023–2026). | GSVG-PV-Satz=18,5 %; GSVG-PV-Satz=18,5 %; GSVG-PV-Satz=18,5 % | — offen — · fixture |
| EUE-020 | Der PV-Satz beträgt 20 % (konstant 2023–2026). | FSVG-PV-Satz=20 %; FSVG-PV-Satz=20 %; FSVG-PV-Satz=20 % | — offen — · fixture |
| EUE-021 | Der SVS-Satz beträgt 1,53 % (konstant 2023–2026) und wird bei FSVG/UV ausgesetzt. | SVS-Satz=1,53 %; SVS-Satz=1,53 %; SVS-Satz=1,53 % | — offen — · fixture |
| EUE-022 | Die jährliche UV-Pauschale ist jahresabhängig (2024 11,35 €, 2025 12,07 €, 2026 12,95 €). | UV-Pauschale=11.35; UV-Pauschale=12.07; UV-Pauschale=12.95 | MonthStatementTest.php, ReferenceTablesTest.php, YearTablesTest.php · fixture |
| EUE-023 | Die 27 unterschiedlichen Kennzahlen 9100–9230 sowie E1B 9500–9530 bilden den Kontenplan; 9135 ist sowohl E1A- als auch E1B-Kennzahl, 9225 (Pflichtvers | Anzahl unterschiedlicher Ausgaben-Kennzahlen=27 | — offen — |
| EUE-024 | Die Kennzahlen 9040, 9050, 9060 und 9090 sowie E1B 9460 bilden den Einnahmen-Kontenplan. | KZ: 9040, 9050, 9060, 9090 | — offen — |
| EUE-025 | Doppelte Zahlarten werden mit ⚠ und Zeilennummer angemahnt. | Verhalten (kein Skalarwert) | — offen — |
| EUE-026 | Doppelte Projekte werden mit ⚠ und Zeilennummer angemahnt. | Verhalten (kein Skalarwert) | — offen — |
| EUE-027 | Doppelte Ausgaben-Arten werden mit ⚠ und expliziter Aufzählung angemahnt. | Verhalten (kein Skalarwert) | — offen — |
| EUE-028 | Doppelte Einnahmen-Arten werden mit ⚠ und Zeilennummer angemahnt. | Verhalten (kein Skalarwert) | — offen — |
| EUE-029 | Neben jeder Art wird angezeigt, wie oft sie in den Journalen verwendet wird: '❯' entspricht keiner Verwendung, die Zahl ist die Anzahl der Verwendunge | Schwellwert Ausgaben=99; Schwellwert Einnahmen=999 | — offen — |
| EUE-030 | Datenvalidierungen begrenzen Anteile auf 0–1, Tage auf 1–31 (nur in E, AV und WEB — nicht im A-Blatt und nicht im Reiseblatt), Nutzungsjahre auf 2–99, | Tag Minimum/Maximum (E/AV/WEB)=1–31; Nutzungsjahre=2–99; Mitfahrer=0–10; Betriebsanteil=0–1 | JournalRulesTest.php, MonthStatementTest.php |

## B03 — Einnahmen

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-031 | Die betriebliche Einnahme in Q ergibt sich aus Brutto mal dem (optionalen) betrieblichen Anteil; ohne Anteil gilt 1. | Verhalten (kein Skalarwert) | — offen — |
| EUE-032 | Die Kennzahl wird aus der hinterlegten Zuordnung der Einnahmenarten ermittelt; ohne Zuordnung gilt die Kennzahl 9040 für Betriebseinnahmen. | KZ: 9040 | E1StatementTest.php, JournalRulesTest.php |
| EUE-033 | Der Betriebsanteil ist 0, die Buchung erscheint nicht in den betrieblichen Einnahmen und nicht in E1A. | Betriebsanteil Privat=0 | — offen — |
| EUE-034 | Der Betrag wird in der Mieteinnahmen in der Vermietungsaufstellung und in der Vermietungs-Ergebnisermittlung des Vermietungsergebnisses in der Übersic | KZ: 9460 | E1StatementTest.php, JournalRulesTest.php, VatReturnServiceTest.php |
| EUE-035 | Eine fortlaufende Nummer wird nur für tatsächliche Buchungen vergeben; leere Zeilen werden übersprungen. | Verhalten (kein Skalarwert) | — offen — |
| EUE-036 | Fehlende Belegnummer/Tag/Monat oder eine undefinierte/mehrfach definierte Art erzeugt ⚠-Hinweise in Spalte B; der Hinweis 'Belegnummer fehlt' ist nur  | Verhalten (kein Skalarwert) | JournalRulesTest.php |
| EUE-037 | Monate müssen einem der zwölf Namen entsprechen; eine Tagesbereichsvalidierung (1–31) existiert in E, AV und WEB, nicht aber im A-Blatt oder im Reiseb | Verhalten (kein Skalarwert) | JournalRulesTest.php |

## B04 — Ausgaben & Vorsteuer

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-038 | Die betriebliche Ausgabe ergibt sich aus dem Betrag mal dem hinterlegten betrieblichen Anteil; ohne Anteil gilt der volle Betrag. Es gibt keine 'VST'- | Verhalten (kein Skalarwert) | — offen — |
| EUE-039 | Die IG-Erwerb-Vorsteuer wird mit dem jeweiligen Steuersatz (20/10/13/4,9 %) auf den Nettobetrag berechnet. | IG-Sätze=20/10/13/4,9 % | JournalRulesTest.php |
| EUE-040 | Die Steuerschuld aus Reverse Charge wird analog mit 20/10/13 % berechnet. | RC-Sätze=20/10/13 % | — offen — |
| EUE-041 | Die Kennzahl kommt aus der hinterlegten Zuordnung der Ausgabenarten; ohne Zuordnung gilt die Kennzahl 9230 für übrige Betriebsausgaben. | KZ: 9230 | — offen — |
| EUE-042 | Fehlende Belegnummer erzeugt den Hinweis auf die Beweispflicht, fehlender Monat/Tag/Art oder eine undefinierte Art erzeugt ⚠; bei FSVG entfällt der WE | Verhalten (kein Skalarwert) | JournalRulesTest.php |
| EUE-043 | Für Wareneinkäufe wird geprüft, ob die Belegnummer im WEB vorkommt; sonst erscheint ein Hinweis. | KZ: 9100 | — offen — |
| EUE-044 | Fortlaufende Nummerierung nur für tatsächliche Buchungen. | Verhalten (kein Skalarwert) | — offen — |
| EUE-045 | Der betriebliche Anteil wird aus der hinterlegten Zuordnung der Ausgabenarten entnommen; der Betrag wird nur bei Belegnummern mit dem Präfix 'WE' gebi | Verhalten (kein Skalarwert) | WarehouseReconciliationTest.php |
| EUE-046 | Die WEB-Prüfung verweist auf die passende Ausgabe im A-Blatt und meldet fehlende oder unbekannte Belegnummern. | Verhalten (kein Skalarwert) | — offen — |
| EUE-047 | Monat, Tag, Lieferant und Warenbezeichnung sind Pflicht; die Ausgaben-Art muss definiert und eindeutig sein. | Verhalten (kein Skalarwert) | WarehouseReconciliationTest.php |
| EUE-048 | Der Betriebsanteil ist 0; die Ausgabe wird nicht als Betriebsausgabe und nicht in E1A übernommen. | Betriebsanteil Privat=0 | — offen — |

## B05 — Anlagenverzeichnis & AfA

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-049 | Die AfA im laufenden Jahr ist Kosten/Nutzungsjahre, bei Anschaffung in der zweiten Jahreshälfte nur die Hälfte (Halbjahresregel); bei 0 Nutzungsjahren | Halbjahresfaktor=0,5; Ganzer-Jahres-Faktor=1 | DepreciationCalculatorTest.php |
| EUE-050 | Der Restbuchwert ist Anschaffungskosten minus AfA, mindestens 0,01 €; bei 0 Nutzungsjahren bleibt er bei den Kosten. | Mindest-Restbuchwert=0.01 | — offen — |
| EUE-051 | Ein Hinweis empfiehlt die sofortige Erfassung im A-Blatt statt im Anlagenverzeichnis. | GWG-Grenze=1000 | — offen — |
| EUE-052 | Nutzungsjahre sind Pflicht und auf 2–99 begrenzt; der Wert 1 löst den Hinweis aus, dass Güter nicht abnutzbar (0) oder mindestens 2 Jahre zu erfassen  | Nutzungsjahre Minimum=2; Nutzungsjahre Maximum=99 | — offen — |
| EUE-053 | Die zugeordnete E1A-Kennzahl muss eine AfA-Kennzahl (9130, 9135, 9134 oder 9500) enthalten. | KZ: 9130, 9134, 9135, 9500 | — offen — |
| EUE-054 | Das Gut gilt als Privatvermögen und darf nicht ins Anlagenverzeichnis; es erscheint eine Warnung. | Mindest-Betriebsanteil=0.5 | — offen — |
| EUE-055 | Der Privatanteil muss als 'Entnahme' im E-Blatt unter derselben Belegnummer gebucht sein; anderenfalls erscheint ein Hinweis mit dem Betrag. | Privatanteil-Bereich=50 % < Anteil < 100 % | — offen — |
| EUE-056 | Die Vorjahres-AfA wird gegen die erwartete AfA (Kosten/Nutzungsjahre, ggf. Halbjahresregel) geprüft und Abweichungen gemeldet. | Toleranz AfA=0.01 | — offen — |
| EUE-057 | Fehlende oder veraltete Vorjahreswerte (AfA/Restbuchwert) werden als ⚠ gemeldet; der Restbuchwert wird auf Plausibilität gegen die Formel geprüft. | Verhalten (kein Skalarwert) | — offen — |
| EUE-058 | Ein Anschaffungsjahr größer als das Wirtschaftsjahr erzeugt eine Warnung. | Verhalten (kein Skalarwert) | — offen — |
| EUE-059 | Belegnummer, Jahr, Monat, Tag und Bezeichnung sind Pflicht; fehlende Werte erzeugen ⚠-Hinweise. | Verhalten (kein Skalarwert) | — offen — |
| EUE-060 | Die laufende Nummer wird nur für tatsächliche Einträge vergeben. | Verhalten (kein Skalarwert) | — offen — |
| EUE-061 | Die Kennzahl wird aus der hinterlegten Zuordnung der Ausgabenarten übernommen; ohne Zuordnung gilt die Kennzahl 9130 für Anlagegüter. | KZ: 9130, 9134, 9135, 9500 | DepreciationCalculatorTest.php |

## B06 — Reise- & Fahrtkosten

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-062 | Der Satz ist 0,42 €/km (2024) bzw. 0,50 €/km (2025+); pro Mitfahrer kommen 0,05 € (2024) bzw. 0,15 € (2025+) hinzu, höchstens 6 Mitfahrer. | KM-Geld PKW=0.42; KM-Geld PKW=0.5; KM-Geld PKW=0.5; Mitfahrerzuschlag PKW=0.05 | TravelCalculatorTest.php, YearTablesTest.php |
| EUE-063 | 2024: 0,24 €/km; 2025: 0,50 €/km im ersten Halbjahr, 0,25 €/km ab Juli; 2026: 0,25 €/km. Mitfahrerzuschlag wie beim PKW (max 1). | KM-Geld Motorrad=0.24; KM-Geld Motorrad=0,50 H1 / 0,25 H2; KM-Geld Motorrad=0.25; Mitfahrerzuschlag Motorrad=0.05 | — offen — |
| EUE-064 | 2024: 0,38 €/km; 2025: 0,50 €/km im ersten Halbjahr, 0,25 €/km ab Juli; 2026: 0,25 €/km. | KM-Geld Fahrrad=0.38; KM-Geld Fahrrad=0,50 H1 / 0,25 H2; KM-Geld Fahrrad=0.25 | — offen — |
| EUE-065 | PKW und Motorrad zusammen sind auf 30.000 km gedeckelt; Fahrrad/E-Bike auf 1.500 km (2024) bzw. 3.000 km (2025+). | Fahrrad-Kontingent=1500; Fahrrad-Kontingent=3000; Fahrrad-Kontingent=3000; KFZ-Kontingent=30000 | TravelCalculatorTest.php |
| EUE-066 | Bei PKW/Kombi sind höchstens 6, beim Motorrad höchstens 1 Mitfahrer zulässig; bei anderen Transportmitteln muss das Feld leer oder 0 sein. | Max Mitfahrer PKW=6; Max Mitfahrer Motorrad=1 | — offen — |
| EUE-067 | Entweder Anfangs- und Endkilometerstand ODER die Distanz ist anzugeben; beides gleichzeitig ist ein Fehler. | Verhalten (kein Skalarwert) | TravelCalculatorTest.php |
| EUE-068 | Das Tag-Geld wird anteilig für zwölf Monate berechnet. Bei einer Mahlzeit im Inland wird die halbe Pauschale angesetzt, im Ausland die volle Pauschale | 1 Mahlzeit Inland=AM3/2; 1 Mahlzeit Ausland=AM3 (voll); 2 Mahlzeiten Inland=0; 2 Mahlzeiten Ausland=AM3/3 | TravelCalculatorTest.php |
| EUE-069 | Im Inland beträgt die Pauschale vor 2024 4,4 Euro und ab 2024 4,5 Euro, im Ausland 5,85 Euro; ansonsten gilt die Ländertabelle. | Inland Nächtigung ohne Beleg=4.5; Inland Nächtigung ohne Beleg=4.5; Inland Nächtigung ohne Beleg=4.5; Ausland Nächtigung Pauschale=5.85 | TravelCalculatorTest.php |
| EUE-070 | Ab dem 5. Tag entfällt das Tagesgeld, ab dem 15. Tag auch das Nächtigungsgeld; der Reisestatus weist dies aus. | Tagesgeld-Grenze=5; Nächtigungsgeld-Grenze=15 | TravelCalculatorTest.php |
| EUE-071 | Die Zeilen werden als 'Hin-Rückfahrt' markiert; dafür entfällt das Tagesgeld und die Veranlassung/Einsatzort bleiben leer. | Zeitgrenze Hin-/Rückfahrt=3; Hinfahrt-Minimum Einzelzeile=50; Hinfahrt-Minimum verkettet=25 | HoursLedgerTest.php, TravelCalculatorTest.php |
| EUE-072 | Der Betriebsanteil wird aus dem Verhältnis der betrieblichen zur gesamten Reisezeit gebildet; liegt er unter zehn Prozent, wird er mit null Prozent be | Untere Rundungsgrenze=10 %; Obere Rundungsgrenze=90 % | TravelCalculatorTest.php |
| EUE-073 | Die betriebliche Ausgabe und alle anteiligen Kosten werden auf 0 gesetzt; die Reise wird als 'Nein (VERANLASSUNG: Privat)' markiert. | Verhalten (kein Skalarwert) | — offen — |
| EUE-074 | Die Anzahl der Werktage wird mit NETWORKDAYS inklusive der jahresabhängigen Feiertagsliste berechnet. | Verhalten (kein Skalarwert) | — offen — |
| EUE-075 | Weicht das Jahr vom eingestellten Wirtschaftsjahr ab, erscheint eine Warnung; ungültige Datumswerte werden ebenfalls gemeldet. | Verhalten (kein Skalarwert) | TravelCalculatorTest.php |
| EUE-076 | Ende darf nicht vor oder gleich Start liegen (bei gleichem Datum); bei mehrteiligen Reisen soll die nächste Zeile dort fortsetzen, wo die vorherige en | Verhalten (kein Skalarwert) | — offen — |
| EUE-077 | Die Belegnummer muss als Referenz auf Fahrtenbuch/Reisekostenabrechnung gesetzt sein; doppelte Belegnummern für verschiedene Reisen werden gemeldet. | Verhalten (kein Skalarwert) | — offen — |
| EUE-078 | Land/Region und Transportmittel müssen gewählt und im Stammdatensatz definiert sein. | Verhalten (kein Skalarwert) | — offen — |
| EUE-079 | Veranlassung und Einsatzort dürfen nur in einer Zeile der Reise stehen und müssen bei Hin-/Rückfahrtzeilen leer bleiben. | Verhalten (kein Skalarwert) | TravelCalculatorTest.php |
| EUE-080 | Die Insgesamt geleisteten Stunden des Belegs werden aus dem Stundenblatt summiert und fließen in die 3-Stunden-Regel ein. | Verhalten (kein Skalarwert) | HoursLedgerTest.php |
| EUE-081 | Die betriebliche Reiseausgabe ist die Summe aus Fahrt (BA), Tag (BB) und Nacht (BC); alle Zeilen ergeben fr_betriebsausgaben, das in die Monats- und J | Verhalten (kein Skalarwert) | TravelCalculatorTest.php |

## B07 — Umsatzsteuer

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-082 | Die Zeilen des Formulars werden mit festen Kennzahlen belegt: 000 Gesamtbetrag, 016 Kleinunternehmer, 057 Reverse Charge, 070 IG-Erwerbe, 072/073/008/ | KZ: KZ 000, KZ 016, KZ 057, KZ 070 | VatCalculatorTest.php, VatReturnServiceTest.php |
| EUE-083 | Die Betriebseinnahmen des Monats werden als Kleinunternehmer-Umsatz (§ 6 Abs. 1 Z 27 UStG) ausgewiesen. | KZ: KZ 000, KZ 016 | VatCalculatorTest.php |
| EUE-084 | Die Reverse-Charge-Steuer aus dem A-Blatt wird auf zwei Nachkommastellen abgerundet in KZ 057 übernommen. | KZ: KZ 057 | VatCalculatorTest.php |
| EUE-085 | Die IG-Erwerbe werden je Steuersatz (20 %, 10 %, 13 %, 4,9 %) auf KZ 072, 073, 008 und 125 verteilt und auf zwei Stellen abgerundet. | KZ: KZ 070, KZ 072, KZ 073, KZ 008 | VatCalculatorTest.php |
| EUE-086 | Die Zahllast ist Reverse Charge plus die IG-Erwerb-Bemessungsgrundlagen multipliziert mit 20 %, 10 %, 13 % und 4,9 %. | USt-Sätze=20/10/13/4,9 % | VatCalculatorTest.php, VatReturnServiceTest.php |
| EUE-087 | Die Monatswerte werden zu Quartalssummen addiert (H/M/S/X), sofern nicht alle leer sind. | Verhalten (kein Skalarwert) | VatCalculatorTest.php, VatReturnServiceTest.php |
| EUE-088 | Das Blatt enthält zwei Bereiche: '1. Umsatzsteuervoranmeldung' und '2. Umsatzsteuererklärung' mit gleicher Kennzahlenstruktur. | Verhalten (kein Skalarwert) | VatCalculatorTest.php, VatReturnServiceTest.php |

## B08 — Einkommensteuer & Grundfreibetrag

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-089 | Der erste Steuersatz beträgt 20 % bis zur jahresabhängigen Grenze 12.816 € (2024) / 13.308 € (2025) / 13.539 € (2026). | Tarifgrenze Stufe 1=12816; Tarifgrenze Stufe 1=13308; Tarifgrenze Stufe 1=13539; Steuersatz Stufe 1=20 % | — offen — |
| EUE-090 | Der zweite Steuersatz beträgt 30 % von der Grenze Stufe 1 bis 20.818 € (2024) / 21.617 € (2025) / 21.992 € (2026). | Tarifgrenze Stufe 2=20818; Tarifgrenze Stufe 2=21617; Tarifgrenze Stufe 2=21992; Steuersatz Stufe 2=30 % | — offen — |
| EUE-091 | Der dritte Steuersatz beträgt 40 % bis 34.513 € (2024) / 35.836 € (2025) / 36.458 € (2026). | Tarifgrenze Stufe 3=34513; Tarifgrenze Stufe 3=35836; Tarifgrenze Stufe 3=36458; Steuersatz Stufe 3=40 % | — offen — |
| EUE-092 | Der vierte Steuersatz beträgt 48 % bis 66.612 € (2024) / 69.166 € (2025) / 70.365 € (2026). | Tarifgrenze Stufe 4=66612; Tarifgrenze Stufe 4=69166; Tarifgrenze Stufe 4=70365; Steuersatz Stufe 4=48 % | — offen — |
| EUE-093 | Der fünfte Steuersatz beträgt 50 % oberhalb von 99.266 € (2024) / 103.072 € (2025) / 104.859 € (2026). | Tarifgrenze Stufe 5=99266; Tarifgrenze Stufe 5=103072; Tarifgrenze Stufe 5=104859; Steuersatz Stufe 5=50 % | — offen — |
| EUE-094 | Die Steuer wird stufenweise aus den jeweiligen Einkommensanteilen berechnet; die Summe der fünf Stufen ergibt die Einkommensteuer. | Verhalten (kein Skalarwert) | MonthStatementTest.php |
| EUE-095 | Je nach Modus ('Gesamt', 'Nur Betrieb', 'Nur Vermietung') werden betriebliche Einkünfte, Vermietung, nichtselbständige Einkünfte und prognostizierte W | KZ: KZ 245 | E1StatementTest.php |
| EUE-096 | Die Grenze beträgt 30.000 € vor 2024 und 33.000 € ab 2024; im unterstützten Zeitraum immer 33.000 €. | Grundfreibetrag Grenze=33000; Grundfreibetrag Grenze=33000; Grundfreibetrag Grenze=33000 | — offen — · fixture |
| EUE-097 | Der Satz beträgt 13 % vor 2022 und 15 % ab 2022; im unterstützten Zeitraum immer 15 %. | Grundfreibetrag Satz=15 %; Grundfreibetrag Satz=15 %; Grundfreibetrag Satz=15 % | — offen — · fixture |
| EUE-098 | Der Grundfreibetrag entspricht dem kleineren Wert aus 15 Prozent des Gewinns und 15 Prozent von 33.000 Euro, mindestens null; er wird vom Gewinn abgez | KZ: KZ 330 | YearTablesTest.php |
| EUE-099 | Die Beträge je Kennzahl werden per SUMIF aus den Journalen geholt; 9100 enthält zusätzlich das WEB, 9160 die Reisekosten, 9130/9134/9135 das Anlagenve | KZ: 9040, 9050, 9060, 9090 | E1StatementTest.php |
| EUE-100 | Mieteinnahmen und Werbungskosten je Kennzahl werden aus den Einnahmen-, Ausgaben- und Anlagenjournalen aggregiert; der Überschuss der Mieteinnahmen üb | KZ: 9414, 9460, 9500, 9135 | E1StatementTest.php |
| EUE-101 | Bis 300 € gilt die kleine Pauschale KZ 9215, von über 300 bis 1.200 € die große KZ 9217; bei Zusage von 9275 (Arbeitszimmer) ist der Wert zu löschen. | Kleine Pauschale bis=300; Große Pauschale bis=1200; Eingabe Minimum=25 | — offen — |
| EUE-102 | Ergonomisches Mobiliar wird mit KZ 9216 ausgewiesen. Unterdrückt wird die Kennzahl nur, wenn die Arbeitszimmerpauschale 9275 erfasst ist oder kein Bet | Eingabe Maximum=300 | — offen — |
| EUE-103 | Die Einkommensteuer wird per Progressionsrechnung auf nichtselbständige Einkünfte, auf Vermietung und auf den Betrieb aufgeteilt; die Einkommensteuer- | KZ: KZ 245, KZ 260 | — offen — |
| EUE-104 | Mit Prognose werden weitere betriebliche/ Vermietungsgewinne aufs Jahr hochgerechnet; die Modi bestimmen, welche Einkünfte in die Übersicht einfließen | Verhalten (kein Skalarwert) | — offen — |
| EUE-105 | Bestimmte Zeilen der Übersicht und die SV-Beiträge werden ausgeblendet bzw. auf 0 gesetzt. | Verhalten (kein Skalarwert) | — offen — |
| EUE-106 | Die Auswahl des Rechenmodus bietet nur die Werte 'Gesamt', 'Nur Betrieb' und 'Nur Vermietung'. In der Berechnung werden jedoch zusätzlich die Variante | Verhalten (kein Skalarwert) | — offen — |

## B09 — Sozialversicherung

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-107 | Vier Modi bestimmen die SV-Beiträge: Gewerbetreibende (GSVG), Neue Selbständige (GSVG), Freiberufliche (FSVG) und Nur Unfallversicherung. | KZ: 9225 | — offen — |
| EUE-108 | Bei Jungunternehmern wird nur der KV-Satz in der Anzeige auf '-' gesetzt; der Mindest-KV-Beitrag wird dennoch bezahlt. SVS und PV sind von der Jungunt | Mindest-KV Jungunternehmer=449.64 | — offen — |
| EUE-109 | Die Beitragsgrundlage ist das Betriebseinkommen zuzüglich der bereits gezahlten SV-Beiträge (9225), gedeckelt mit dem Zwölffachen der jahresabhängigen | Deckelung Beitragsgrundlage=84840; Deckelung Beitragsgrundlage=90300; Deckelung Beitragsgrundlage=97020 | — offen — |
| EUE-110 | Der KV-Beitrag entspricht dem höheren Wert aus 6,8 Prozent der Beitragsgrundlage und dem zwölffachen Mindest-KV-Beitrag. Bei Jungunternehmern wird nur | KV-Satz=6,8 %; Mindest-KV ×12=425.4; Mindest-KV ×12=449.64; Mindest-KV ×12=449.64 | — offen — |
| EUE-111 | Der PV-Beitrag ist dem höheren Wert aus Beitragsgrundlage × PV-Satz, modusabhängiger Mindestbeitrag × 12); der Satz ist 18,5 % (GSVG) bzw. 20 % (FSVG) | PV-Satz GSVG=18,5 %; PV-Satz GSVG=18,5 %; PV-Satz GSVG=18,5 %; PV-Satz FSVG=20 % | — offen — |
| EUE-112 | Die Unfallversicherung ist eine Jahrespauschale. Die Selbständigenvorsorge entspricht dem höheren Wert aus 1,53 Prozent der Beitragsgrundlage und dem  | SVS-Satz=1,53 %; UV ×12=136.2; UV ×12=144.84; UV ×12=155.4 | — offen — |
| EUE-113 | Für die Monate ohne Einkommen werden Mindestbeiträge (UV, SVS, KV, PV) als Sockel angesetzt. | Verhalten (kein Skalarwert) | — offen — |
| EUE-114 | Die Summe der vier Beiträge ergibt die jährlichen SV-Beiträge; die Division durch 12 liefert den Monatsbetrag. | Verhalten (kein Skalarwert) | — offen — |
| EUE-115 | Bereits bezahlte SV-Beiträge (KZ 9225) werden bei der Ermittlung der Beitragsgrundlage zu den Einkünften addiert; in der Übersicht werden sie außerdem | KZ: 9225 | — offen — |
| EUE-131 | Die Auswahlliste für die Sozialversicherungsart und die Formeln für die Pensionsversicherung verwenden die Bezeichnung 'Freiberufliche (FSVG)'. Die Fo | Verhalten (kein Skalarwert) | — offen — |

## B10 — Auswertungen & Abgleich

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-116 | Der Schalter 'OFF' deaktiviert die rechenaufwendige Monatsansicht; bei OFF erscheint ein 💡-Hinweis, die Ansicht wieder abzuschalten. | Verhalten (kein Skalarwert) | MonthStatementTest.php |
| EUE-117 | Die Monatsansicht zieht Einnahmen, Ausgaben (A/AV/WEB/FR/USt) und Stunden des gewählten Monats per SUMIFS/INDEX zusammen. | Verhalten (kein Skalarwert) | MonthStatementTest.php |
| EUE-118 | Die Zeilen der Einnahmen-, Ausgaben-, Anlagen- und Reisejournale werden über eine aufwendige Auswahlformel in die Monatsansicht übernommen; Einträge d | Verhalten (kein Skalarwert) | MonthStatementTest.php |
| EUE-119 | Eine Matrix zeigt die Einnahmen je Art und Monat; Summen und ein ✓✓-Abgleich gegen das E-Blatt stellen die Vollständigkeit sicher. | Verhalten (kein Skalarwert) | — offen — |
| EUE-120 | Eine Matrix summiert die Ausgaben aus den Journalen je Art und Monat; FR-Kosten kommen als eigene Zeile hinzu; ✓✓ vergleicht mit der Gesamtsumme. | Verhalten (kein Skalarwert) | — offen — |
| EUE-121 | Für jedes Projekt und jeden Monat werden Einnahmen, Ausgaben (inkl. Vorjahres-AfA und 9225-Bereinigung) und Arbeitsstunden ausgewiesen; Differenz- und | KZ: 9225 | — offen — |
| EUE-122 | Für jede Zahlart und jeden Monat werden Ein- und Ausflüsse aus E/A/WEB gegenübergestellt; ✓/✓✓ bestätigen die Übereinstimmung mit Saldo und Startsaldo | Verhalten (kein Skalarwert) | — offen — |
| EUE-123 | Stunden werden je Monat und Projekt erfasst und speisen die Projekte-Auswertung, die Reise-3-Stunden-Regel und die Monatsübersicht. | Verhalten (kein Skalarwert) | HoursLedgerTest.php |
| EUE-124 | Die Werte der UST-Voranmeldung werden über ust_daten/ust_kennzahlen/ust_monate in die Monatsansicht übernommen. | Verhalten (kein Skalarwert) | MonthStatementTest.php |
| EUE-125 | Der monatliche Mindest-SV-Beitrag (UV, SVS-Minimum, KV-Minimum, PV-Minimum je nach Modus) und die aus den Monatsgewinnen abgeleitete Verlustbasis werd | Mindest-SV monatlich=160.8 | — offen — |
| EUE-126 | Je Monat wird die anteilige Einkommensteuer samt SV aus der Jahressteuer abgeleitet; der Reingewinn ist Bruttoergebnis minus dieser Belastung; daraus  | Verhalten (kein Skalarwert) | — offen — |

## B11 — Validierung & Plausibilität

| Regel-ID | Fachliche Wirkung (gekürzt) | Hergeleiteter Wert | Testnachweis |
|---|---|---|---|
| EUE-127 | Alle Zeilenchecks der Journale und Stammdatenblätter stehen in Spalte B und werden farblich hervorgehoben, wenn sie einen ⚠-Hinweis enthalten; im Mona | Verhalten (kein Skalarwert) | — offen — |
| EUE-128 | Zahlenbereiche in der Übersicht und den Auswertungen werden als Farbskala dargestellt; ✓-Symbole in der Zuordnung werden hervorgehoben. | Verhalten (kein Skalarwert) | — offen — |
| EUE-129 | Summen über die Journal-Beträge werden mit den Summen der Auswertungen bzw. der Steuererklärung verglichen; Abweichungen werden als 'Fehler (Δ €)' ang | Abgleich-Rundung=1 | MonthStatementTest.php, WarehouseReconciliationTest.php |
| EUE-130 | Die Formeln in der letzten Zeile der Journalblätter sind veraltete Relikte mit verschobener Spaltenbedeutung und dürfen nicht als fachliche Regeln ext | Verhalten (kein Skalarwert) | — offen — |
