# EÜR 2026 vollständig: Umsetzungsplan

**Status:** In Arbeit

## 1. Auftrag und Ausgangslage

Ziel ist eine vollständige EÜR für das Wirtschaftsjahr 2026 mit
jahresabhängigen Regeln (2024–2026), als eigenständige Ansicht „Einnahmen Ausgaben Rechnung" unter `/steuern`
im Portal. Grundlage sind der gepinnte Regelkatalog, die abgeleiteten
Anforderungsdokumente und die Referenz-Screenshots der eigenen Firma.

### Gemessener Stand des Portals (Arbeitsbaum, HEAD `554da4f3`)

- `git diff --stat`: 18 geänderte Dateien, 1.688 Einfügungen; zusätzlich 20
  unversionierte Pfade (Domain, Referenz, Controller, Modelle, Migration,
  Tests, UI-Panels, Artefakte).
- Vorhanden: Migration `V061` (Kunden, Projekte, Buchungen, Anlagen,
  Anlagenjahre, Audit), Modelle, EÜR-API (Referenz, Einstellungen, Summary,
  Buchungen, Anlagen, USt, Reisen, ESt), Rechenkerne (AfA/DEG, USt, Reisen,
  ESt-Tarif, SV-Werte, Feiertage), Panels (Übersicht, Buchungen, Reisen, USt,
  AfA, ESt, Stammdaten) und ein eigenständiger Cockpit-Einstieg.
- Offen: Tagesdatum, Belege, Wareneingang, Auswertungen, Anträge, Signatur,
  Stundenbuch, Monatsabschluss, PDF, Jahreswahl 2024/2025 und die daraus
  folgenden Jahrestabellen.

### Referenz-UI (eigene Screenshots, nur Inspiration)

Routen: `uebersicht`, `monat`, `buchungen`, `belege`, `anlagen`, `reisen`,
`steuer`, `ust`, `auswertungen`, `wareneingang`, `antraege`, `signatur`,
`einstellungen` — jeweils `filled`/`empty`, Desktop/Mobile.
Struktur: eigener Cockpit-Kopf, Monatsleiste links, Monats-KPIs, Balken
„betrieblich vs. privat", Jahresübersicht mit SV-/AfA-Block, darunter die
Tab-Leiste mit den Fachbereichen.

## 2. Bestandsaufnahme der Quelle (PHP/React)

Die Quelle ist als Inspiration und zur Wiederverwendung freigegeben; **nicht**
verwendet werden `.xlsx`/`.ods` und Rohdumps. PHP/React-Code ist erlaubt.

### Backend (`prototype/backend/src`, PHP)

| Bereich | Klassen | Wiederverwendung |
|---|---|---|
| Steuer | `Tax/EstYearTable`, `Tax/TaxTariff`, `Tax/IncomeTaxService`, `Tax/TaxService`, `Tax/E1aStatement`, `Tax/E1bStatement` | Jahrestabellen 2024–2026, Progression, E1A/E1B-Aggregation |
| SV | `SocialInsurance/SvYearTable`, `SocialInsuranceCalculator`, `SvMode`, `SvResult` | Jahrestabellen, Mindestbeiträge, Moduslogik |
| Reisen | `Travel/TravelRateTable`, `TravelCalculator`, `TravelEvaluator`, `TravelRules`, `TravelInput` | km-Sätze je Jahr/Halbjahr, Diäten, 5-/15-Tage-Regel, Betriebsanteil |
| Anlagen | `Assets/AfaSchedule`, `AssetService`, `AssetMethodService`, `Depreciation`, `AssetYear` | Linear/degressiv, Halbjahr, Wechsel, Jahresplan |
| USt | `Vat/VatCodes`, `VatService`, `VatReturnService`, `VatQuarter` | Kennzahlen, Zahllast, Quartale |
| Journal | `Journal/JournalService`, `JournalRules`, `JournalFieldHelp` | Zeilenregeln, Fallbacks, Feldhilfe |
| Monat/Report | `Reports/MonthlyViewService`, `MonthlyBurdenService`, `OverviewService`, `ReportRepository` | Monatsjournal, SV/ESt-Block, Jahresübersicht |
| Belege/Signatur | `Documents/*`, `Trust/*`, `Pdf/*`, `Crypto/*` | Upload, Verschlüsselung, externe Signatur, PDF |
| Sonstiges | `Time/HoursLedgerService`, `Warehouse/WarehouseService`, `Applications/ApplicationService`, `Validation/RowCheck`, `Analytics/*` | Stundenbuch, Wareneingang, Anträge, Zeilencheck, Auswertungen |
| HTTP | `Http/*Routes.php` (16 Router) | API-Vertrag und Statuscodes |
| DB | `prototype/db/schema.sql` (24 Tabellen) | Tabellen und Pflichtfelder als Anforderung |

### Frontend (`prototype/frontend/src`, React/JSX)

`App.jsx`, `app/MonthSidebar.jsx`, `app/MainContent.jsx`, `app/TabContent.jsx`,
`app/EntriesPanel.jsx`, `app/ReportView.jsx`, `app/SignaturePanel.jsx`,
`components/{Overview,Monthly,Tax,Vat,Travel,Assets,Documents,Warehouse,
Applications,Analytics,Settings,EntryDialog,EigenbelegDialog,RowWarnings}.jsx`,
`api.js`, `format.js`, `constants.js`.

Wiederverwendung: Struktur, Feldgruppen, Interaktionsmuster und Texte als
Vorlage; die Portalseite bleibt React/TSX mit Lingui und den Portal-Panels.

### Jahresabhängige Regeln (Kern)

- `EstYearTable`: Tarifgrenzen 2024/2025/2026 in Cent, Grundfreibetrag
  30.000 € vor 2024 bzw. 33.000 €, Satz 13 % vor 2022 / 15 % ab 2022.
- `SvYearTable`: `max_bgl`, KV/PV/SVS-Sätze, Mindestbeiträge, UV-Pauschale je
  Jahr (2024/2025/2026).
- `TravelRateTable`: km-Sätze je Jahr und Halbjahr (2025 Wechsel 30.06.),
  Kontingente, Mitfahrer, 3-Stunden-/50-km-/25-km-Grenzen.
- `YearWarnings`: EUE-002/003 für Jahre außerhalb 2024–2026.

## 3. Zielarchitektur

1. **Jahreskontext statt Konstante.** `EurYear` löst `business_year` (Default
   2026, zulässig 2024–2026) serverseitig auf und liefert Tarif-, SV-, Reise-
   und USt-Tabellen. Kein stiller Fallback; unbekanntes Jahr → 422.
2. **Jahrestabellen als abgeleitete PHP-Tabellen** (kein xlsx/ods): Tarif, SV,
   Reisen, USt-Sätze, Feiertage je Jahr. Werte aus Katalog/Anforderungen,
   Integer-Cent und exakte Brüche, BCMath im Rechenpfad.
3. **Ein Rechenkern je Fachbereich** (bereits vorhanden), erweitert um
   Jahresparameter und Modussichten (Gesamt/Betrieb/Vermietung/Prognose).
4. **Persistenz** nach `data-model-requirements.md`: bestehende EÜR-Tabellen
   plus Belege, Anträge, Signaturen, Stundenbuch, Wareneingang, Zahlarten,
   Kataloge und Monatsstatus; brand-scoped, keine Float-Spalten.
5. **API** nach dem Quell-Router: Journale, Monat, Auswertungen, Steuer, USt,
   Reisen, Anlagen, Belege, Signaturen, Warehouse, Anträge, Stunden.
   Geld = Cent, Zeit = Minuten, Anteile = 5-%-Schritte, fremde IDs → 404.
6. **UI** als eigenständiges Cockpit: Monatsleiste, Monats-KPIs, Jahres-
   übersicht, Tab-Leiste (Buchungen, Wareneingang, Belege, Anlagen, Monat,
   Auswertungen, Reisen, Steuer, USt, Anträge, Signatur, Einstellungen),
   Rücksprung ins Portal, deutsche Texte, Lösungshinweise statt Roherrors.

## 4. Umsetzungsphasen

### P0 — Arbeitsbaum sichern (1 Commit)

Bestehenden Diff prüfen, Gates fahren (`pint`, fokussierte PHPUnit,
`pnpm lint:fix && pnpm build`, Vitest, `@feature:eur`, `git diff --check`),
ein Commit, unabhängiges Review, Push, CI grün. Erst danach neue Phasen.

### P1 — Jahreskontext und Tabellen

- `EurYear`/`YearTables` mit 2024/2025/2026; `RuleValues` und
  `ReferenceTables` von `YEAR=2026` lösen.
- `EstYearTable`, `SvYearTable`, `TravelRateTable`, `VatRates` als PHP-Tabellen.
- `GET /api/management/eur/years` und `?year=` an allen Endpunkten.
- Tests: je Jahr ein benannter Test, Grenzwerte, unbekanntes Jahr → 422,
  EUE-002/003-Warntexte exakt.

### P2 — Journal und Monat vollständig

- Tagesdatum, Kategorie/Beschreibung, Belegnummer, Zahlart, Projekt/Kunde.
- `RowCheck`-Hinweise mit Lösungshinweisen; Fallbacks 9040/9230.
- Monatsjournal (E/A/WEB/FR), Monatssummen, offene Belege, Signaturstatus.
- API: `GET/POST/PUT/DELETE entries`, `GET month`, `POST month/close`.
- Tests: Pflichtfelder, 5-%-Schritte, Tagesdatum, Monatsstatus.

### P3 — Belege, Warehouse, Stunden

- Beleg-Upload (PDF/Bild), Prüfsumme, Verschlüsselung, nie öffentlich.
- Wareneingang und Abgleich mit Ausgaben (WEB-Zeile).
- Stundenbuch (Minuten), 3-Stunden-Regel, Summen je Monat/Projekt.
- Tests: Upload-Validierung, Verschlüsselung, WEB-Abgleich, Stunden.

### P4 — Steuer, USt, SV vollständig

- ESt: Rechenmodi, KZ 245/260, Vorauszahlung, Lohnsteuer, E1A/E1B,
  Arbeitsplatz-/Ergonomie-Pauschalen, Steuerbilanz.
- USt: acht Kennzahlen, Zahllast, Quartale, zwei Erklärungsblöcke.
- SV: Modi GSVG/FSVG/NS-GSVG, Mindestbeiträge, Beitragsgrundlage, Monatsliste.
- Tests: je EUE-ID ein Test, alle 26 jahresabhängigen Werte für 2026 plus
  2024/2025-Vergleich, Rundung gegen null bei USt.

### P5 — Reisen und Anlagen

- Reisen: neue Reise, Länder-/Fahrzeugpflicht, km-Stand oder Distanz,
  Mahlzeiten, 5-/15-Tage, Betriebsanteil, Kontingente, Reisekosten summiert.
- Anlagen: Jahresabschluss, „alle offenen AfA buchen" idempotent, GWG,
  Privatanteil, Begründung, Detailansicht, DEG-Vergleich.
- Tests: Grenzwerte, Halbjahr, Wechsel einmalig, Idempotenz.
- Erweiterung laut [#34](https://github.com/reisinger-pictures/portal.reisinger.pictures/issues/34):
  degressive Abschreibung als Anzeige statt Rechner, Neuware-Vergleich beider
  Verfahren mit Kategorievorschlag, dauerhaft gesperrter Rückwechsel auf
  degressiv, Anlagenverzeichnis für Privateinlagen und Jahresabschlussbuchung
  der betrieblichen AfA (P5.1–P5.6).

### P6 — Auswertungen, Anträge, Signatur, PDF

- Auswertungen je Kunde/Projekt/Monat/Jahr, Deckungsbeitrag.
- Anträge (fachliche Vorgänge) und PDF.
- Signatur: extern signierten Beleg hochladen, Status, Testprovider sichtbar.
- PDF: Monatsbericht, Eigenbeleg, Anlagenverzeichnis.
- Tests: Statusübergänge, PDF-Inhalte, Signatur-Mock.

### P7 — Cockpit-UI vervollständigen

- Monatsleiste mit Anzahl/Summe, „Neuer Monat", Jahresautomatik.
- Monatskopf mit Aktionen (+ Buchung, Eigenbeleg, PDF signieren).
- Jahresübersicht mit Filtern, SV-/AfA-Block, Kunde/Projekt-Tabelle.
- Alle Tabs gemäß Referenz; Leerzustände und Mobile.
- Tests: Vitest je Tab, Playwright `@feature:eur` über alle Tabs.

### P8 — Härtung und Abnahme

- Paritätsvektoren je Bereich B01–B11 (blockiert durch Quelle, siehe #23).
- Externe steuerliche Prüfung als Abnahmegate für „vollständig".
- Last/Idempotenz, Audit ohne Inhalte, Brand-Trennung, 404/422.
- Doku in `features/eur/` aktualisieren, Issue #31 abarbeiten.

## 5. Datenmodell-Ergänzungen

Neu bzw. auszubauen: `eur_documents`, `eur_applications`, `eur_signatures`,
`eur_hours`, `eur_warehouse_entries`, `eur_payment_methods`,
`eur_entry_types`, `eur_vat_types`, `eur_company_profiles`,
`eur_social_insurance_settings`, `eur_tax_settings`, `eur_travel_trips`,
`eur_travel_rows`, `eur_monthly_reports`. Bestehende Tabellen um Tagesdatum,
Kategorie, Beschreibung, Belegbezug und Status erweitern. Migrationen additiv,
mit dokumentierter Schema-/Backfill-/Rollback-Entscheidung.

## 6. Test- und Gate-Strategie

- Backend: `php artisan test` (CI), lokal fokussiert je Fachbereich; je
  EUE-ID ein benannter Test; Jahresmatrix 2024/2025/2026.
- Frontend: Vitest je Panel/Tab; Playwright `@feature:eur`; Screenshot-Set
  `pnpm test:screenshots` für Desktop/Mobile.
- Gates: `pint`, `pnpm lint:fix && pnpm build`, `git diff --check`,
  `ratchet check` (CI), vollständige CI für den gepushten Head.
- Wirksamkeitsnachweis: geänderter Erwartungswert macht den Test rot.

## 7. Risiken und Entscheidungen

- **Externe Steuerprüfung** bleibt Gate, kein Blocker (Tarif, Pauschalen,
  AfA/Nutzungsdauer/GWG, Privatnutzung).
- **Paritätsvektoren** für B03–B11 fehlen in der Quelle (#23, ku#45).
- **Signatur** braucht einen echten Provider; Testprovider klar kennzeichnen.
- **OCR/PDF-Import** ist optional und nicht Voraussetzung für die EÜR 2026.
- **Jahreswahl:** 2026 ist Default; 2024/2025 nur als Vergleichszweig, keine
  stillen Defaults.

## 8. Evidenz

```sh
cd /projects/portal.reisinger.pictures && git diff --stat && git status --short
find /projects/ku-online/prototype/backend/src -type f -name '*.php' | wc -l
find /projects/ku-online/prototype/frontend/src -type f | wc -l
ls /projects/ku-online/prototype/frontend/test-results/ui-review/filled/desktop
php -r 'require "backend/vendor/autoload.php";' # optional
cd backend && php artisan test --filter 'Tests\\Unit\\Eur|EurApiTest'
cd frontend && npx vitest run src/ui/management/__tests__/ManagementEurView.test.tsx
```
