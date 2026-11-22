# Paritäts-Implementierungsplan: parallele Modul-Wellen

**Zweck:** Umsetzungsplan für die 99 `missing` + 15 `partial` Regeln aus
`verification/rule-coverage.json`. Reine Planung — **keine Statusänderung**, keine
Coverage-Behauptung. Fachliche Werte stammen ausschließlich aus
`business-rules/domain-rules.json` und den Spezifikationen `workbook-soll.md`,
`settings-and-references.md`, `asset-register.md`.

**Stand:** Abgeschlossen und auf `main`: #26 (Cent), #27 (Einstellungen,
Kunden/Projekte, SV), #28 (Parität), #29 (B05 Anlagen), #30 (5-%-Schritte),
#31 (400-Zeilen-Ratchet) und #32 (degressive AfA). Der aktuelle Nachweis steht
in `verification/parity-report.md`: 121 Clean-Room-Regeln `covered`, 0
`unit-only`/`partial`/`missing`, 10 `not-applicable`, plus 15 `DEG-*`-Regeln.
Die Matrix unten bleibt der historische Umsetzungsplan.

**Namenskonvention:** `smoke/<modul>.php` = neuer, eigener Smoke-Test-Include unter
`prototype/backend/tests/`; `<modul>.spec.js` = Playwright-Spec unter
`prototype/frontend/e2e/`. Der Integrator verdrahtet beide mit je einer Zeile.
Neue Dateien bleiben unter 400 Zeilen (Ratchet `file-length`).

## 1. Regelmatrix (alle offenen Regeln)

| Welle | Regel | Bereich | Modul (Backend / UI) | Voraussetzung | Unit-Oberfläche | E2E-Oberfläche |
|---|---|---|---|---|---|---|
| W0 | EUE-001 | B01 | Settings/ReferenceData · Einstellungen | — | `smoke/settings.php` | `settings.spec` |
| W0 | EUE-002 | B01 | Settings/ReferenceData · Einstellungen | EUE-001 | `smoke/settings.php` | `settings.spec` |
| W0 | EUE-003 | B01 | Settings/ReferenceData · Einstellungen | EUE-001 | `smoke/settings.php` | `settings.spec` |
| W0 | EUE-004 | B02 | Settings/ReferenceData · Einstellungen | EUE-001 | `smoke/settings.php` | `settings.spec` |
| W0 | EUE-005 | B02 | Settings/ReferenceData · Einstellungen | — | `smoke/settings.php` | `settings.spec` |
| W0 | EUE-006 | B02 | Settings/ReferenceData · Einstellungen | — | `smoke/settings.php` | `settings.spec` |
| W0 | EUE-007 | B02 | Settings/ReferenceData · Einstellungen | EUE-023/024 | `smoke/settings.php` | `settings.spec` |
| W0 | EUE-008 | B02 | Settings/ReferenceData · Einstellungen | EUE-023/024 | `smoke/settings.php` | `settings.spec` |
| W0 | EUE-009 | B02 | Settings/ReferenceData · Einstellungen | — | `smoke/settings.php` | `settings.spec` |
| W0 | EUE-010 | B02 | Settings/ReferenceData · Einstellungen | — | `smoke/settings.php` | `settings.spec` |
| W0 | EUE-011 | B02 | Settings/ReferenceData · Einstellungen | — | `smoke/settings.php` | `settings.spec` |
| W0 | EUE-012 | B02 | Settings/ReferenceData · Einstellungen | EUE-001 | `smoke/settings.php` | `settings.spec` |
| W0 | EUE-014 | B02 | SocialInsurance · OverviewPanel | EUE-001 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-015 | B02 | SocialInsurance · OverviewPanel | EUE-001 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-016 | B02 | SocialInsurance · OverviewPanel | EUE-001 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-017 | B02 | SocialInsurance · OverviewPanel | EUE-001 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-018 | B02 | SocialInsurance · OverviewPanel | EUE-001 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-019 | B02 | SocialInsurance · OverviewPanel | EUE-001 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-020 | B02 | SocialInsurance · OverviewPanel | EUE-001 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-021 | B02 | SocialInsurance · OverviewPanel | EUE-001 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-022 | B02 | SocialInsurance · OverviewPanel | EUE-001 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-023 | B02 | Settings/ReferenceData · Einstellungen | EUE-001 | `smoke/settings.php` | `settings.spec` |
| W0 | EUE-024 | B02 | Settings/ReferenceData · Einstellungen | EUE-001 | `smoke/settings.php` | `settings.spec` |
| W0 | EUE-030 | B02 | Settings/ReferenceData · Einstellungen | — | `smoke/settings.php` | `settings.spec` |
| W0 | EUE-107 | B09 | SocialInsurance · OverviewPanel | EUE-014…022 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-108 | B09 | SocialInsurance · OverviewPanel | EUE-014…022 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-109 | B09 | SocialInsurance · OverviewPanel | EUE-014…022 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-110 | B09 | SocialInsurance · OverviewPanel | EUE-014…022 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-111 | B09 | SocialInsurance · OverviewPanel | EUE-014…022 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-112 | B09 | SocialInsurance · OverviewPanel | EUE-014…022 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-113 | B09 | SocialInsurance · OverviewPanel | EUE-014…022 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-114 | B09 | SocialInsurance · OverviewPanel | EUE-014…022 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-115 | B09 | SocialInsurance · OverviewPanel | EUE-109 | `smoke/sv.php` | `overview-sv.spec` |
| W0 | EUE-131 | B09 | SocialInsurance · OverviewPanel | EUE-014…022 | `smoke/sv.php` | `overview-sv.spec` |
| W1 | EUE-032 | B03 | Journal/E-A · EntryDialog | EUE-008/024 | `smoke/journals.php` | `entries.spec` |
| W1 | EUE-034 | B03 | Journal/E-A · EntryDialog | EUE-008/024; EUE-100 (nur KZ) | `smoke/journals.php` | `entries.spec` |
| W1 | EUE-036 | B03 | Journal/E-A · EntryDialog | EUE-008/024 | `smoke/journals.php` | `entries.spec` |
| W1 | EUE-037 | B03 | Journal/E-A · EntryDialog | EUE-008/024 | `smoke/journals.php` | `entries.spec` |
| W1 | EUE-039 | B04 | Journal/E-A · EntryDialog | EUE-010 | `smoke/journals.php` | `entries.spec` |
| W1 | EUE-040 | B04 | Journal/E-A · EntryDialog | EUE-010 | `smoke/journals.php` | `entries.spec` |
| W1 | EUE-041 | B04 | Journal/E-A · EntryDialog | EUE-007/023 | `smoke/journals.php` | `entries.spec` |
| W1 | EUE-042 | B04 | Journal/E-A · EntryDialog | EUE-007/023 | `smoke/journals.php` | `entries.spec` |
| W1 | EUE-043 | B04 | Journal/E-A · EntryDialog | EUE-041; EUE-045 (WEB) | `smoke/journals.php` | `entries.spec` |
| W1 | EUE-045 | B04 | Warehouse · Wareneingang | EUE-007/023 | `smoke/warehouse.php` | `warehouse.spec` |
| W1 | EUE-046 | B04 | Warehouse · Wareneingang | EUE-043 | `smoke/warehouse.php` | `warehouse.spec` |
| W1 | EUE-047 | B04 | Warehouse · Wareneingang | EUE-007/023 | `smoke/warehouse.php` | `warehouse.spec` |
| W2 | EUE-049 | B05 | Assets · Anlagen-Tab | EUE-001, EUE-007/023 | `smoke/assets.php` | `assets.spec` |
| W2 | EUE-050 | B05 | Assets · Anlagen-Tab | EUE-049 | `smoke/assets.php` | `assets.spec` |
| W2 | EUE-051 | B05 | Assets · Anlagen-Tab | EUE-001 | `smoke/assets.php` | `assets.spec` |
| W2 | EUE-052 | B05 | Assets · Anlagen-Tab | — | `smoke/assets.php` | `assets.spec` |
| W2 | EUE-053 | B05 | Assets · Anlagen-Tab | EUE-023/049 | `smoke/assets.php` | `assets.spec` |
| W2 | EUE-054 | B05 | Assets · Anlagen-Tab | EUE-007/009 | `smoke/assets.php` | `assets.spec` |
| W2 | EUE-055 | B05 | Assets · Anlagen-Tab | EUE-032 | `smoke/assets.php` | `assets.spec` |
| W2 | EUE-056 | B05 | Assets · Anlagen-Tab | EUE-049/050 | `smoke/assets.php` | `assets.spec` |
| W2 | EUE-057 | B05 | Assets · Anlagen-Tab | EUE-049/050 | `smoke/assets.php` | `assets.spec` |
| W2 | EUE-058 | B05 | Assets · Anlagen-Tab | EUE-001 | `smoke/assets.php` | `assets.spec` |
| W2 | EUE-059 | B05 | Assets · Anlagen-Tab | — | `smoke/assets.php` | `assets.spec` |
| W2 | EUE-061 | B05 | Assets · Anlagen-Tab | EUE-001, EUE-007/023 | `smoke/assets.php` | `assets.spec` |
| W2 | EUE-062 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-001, EUE-011 | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-063 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-001, EUE-011 | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-064 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-001, EUE-011 | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-065 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-011 | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-066 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-011 | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-067 | B06 | Travel/Time · Reise/Eigenbeleg | — | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-068 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-013 | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-070 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-012/013 | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-071 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-012 | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-072 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-068 | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-073 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-062…064 | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-074 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-012 | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-075 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-001 | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-077 | B06 | Travel/Time · Reise/Eigenbeleg | — | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-078 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-011 | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-079 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-071 | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-080 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-074, EUE-123 (eigenes Modul) | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-081 | B06 | Travel/Time · Reise/Eigenbeleg | EUE-062…064, EUE-068 | `smoke/travel.php` | `travel-rules.spec` |
| W2 | EUE-082 | B07 | Vat · USt-Tab | EUE-007/008, EUE-010 | `smoke/vat.php` | `vat.spec` |
| W2 | EUE-083 | B07 | Vat · USt-Tab | EUE-032 | `smoke/vat.php` | `vat.spec` |
| W2 | EUE-084 | B07 | Vat · USt-Tab | EUE-040 | `smoke/vat.php` | `vat.spec` |
| W2 | EUE-085 | B07 | Vat · USt-Tab | EUE-039 | `smoke/vat.php` | `vat.spec` |
| W2 | EUE-086 | B07 | Vat · USt-Tab | EUE-084/085 | `smoke/vat.php` | `vat.spec` |
| W2 | EUE-087 | B07 | Vat · USt-Tab | EUE-082…086 | `smoke/vat.php` | `vat.spec` |
| W2 | EUE-088 | B07 | Vat · USt-Tab | EUE-001 | `smoke/vat.php` | `vat.spec` |
| W2 | EUE-089 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-001 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-090 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-001 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-091 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-001 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-092 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-001 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-093 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-001 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-094 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-089…093 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-095 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-094, EUE-104/105 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-096 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-001 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-097 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-001 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-098 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-096/097, EUE-099 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-099 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-007/008, EUE-023/024 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-100 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-008/024, EUE-099 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-101 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-023 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-102 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-023 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-103 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-094/095 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-104 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-001 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-105 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-095 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-106 | B08 | Tax/E1A/E1B · Steuern-Tab | EUE-104/105 | `smoke/est.php` | `tax.spec` |
| W2 | EUE-123 | B10 | Travel/Time · Reise/Eigenbeleg | EUE-006 | `smoke/travel.php` | `travel-rules.spec` |
| W3 | EUE-116 | B10 | Reports/Monthly · Monat-Tab | EUE-001, EUE-119/120 | `smoke/monthly.php` | `monthly.spec` |
| W3 | EUE-117 | B10 | Reports/Monthly · Monat-Tab | EUE-001, EUE-119/120 | `smoke/monthly.php` | `monthly.spec` |
| W3 | EUE-118 | B10 | Reports/Monthly · Monat-Tab | EUE-001, EUE-119/120 | `smoke/monthly.php` | `monthly.spec` |
| W3 | EUE-119 | B10 | Analytics · Auswertungen-Tab | EUE-032/036 | `smoke/analytics.php` | `analytics.spec` |
| W3 | EUE-120 | B10 | Analytics · Auswertungen-Tab | EUE-041/045/049 | `smoke/analytics.php` | `analytics.spec` |
| W3 | EUE-121 | B10 | Analytics · Auswertungen-Tab | EUE-005/006, EUE-120 | `smoke/analytics.php` | `analytics.spec` |
| W3 | EUE-122 | B10 | Analytics · Auswertungen-Tab | EUE-005, EUE-032/041 | `smoke/analytics.php` | `analytics.spec` |
| W3 | EUE-124 | B10 | Reports/Monthly · Monat-Tab | EUE-082/087 | `smoke/monthly.php` | `monthly.spec` |
| W3 | EUE-125 | B10 | Reports/Monthly · Monat-Tab | EUE-113/114 | `smoke/monthly.php` | `monthly.spec` |
| W3 | EUE-126 | B10 | Reports/Monthly · Monat-Tab | EUE-094/098, EUE-125 | `smoke/monthly.php` | `monthly.spec` |
| W3 | EUE-127 | B11 | Validation · RowWarnings | EUE-036/042/047/059/077 | `smoke/row-checks.php` | `row-checks.spec` |
| W3 | EUE-129 | B11 | Analytics · Auswertungen-Tab | EUE-119/120/122 | `smoke/analytics.php` | `analytics.spec` |

## 2. Wellen

| Welle | Regeln (missing / partial) | Inhalt | Geteilte Dateien — ein Owner je Welle | Disjunkte Modulpfade |
|---|---|---|---|---|
| W0 | 31 / 3 = **34** | Abschluss #27: Kataloge, Firmen-/Zahlarten-/Projektdaten, SV-Konstanten und -Engine, Warnungen EUE-002/003, Nachweise | `Router.php`, `db/schema.sql`, `tests/smoke.php`, `App.jsx`, `openapi.yaml` → `integrator-w0` | `src/Settings/*`, `src/SocialInsurance/*`, `components/SettingsPanel.jsx`, `components/OverviewPanel.jsx` |
| W1 | 10 / 2 = **12** | Journalregeln E/A, Wareneingangsbuch WEB | dieselben fünf → `integrator-w1` | `src/Journal/*`, `src/Warehouse/*`, `components/EntryDialog.jsx`, `components/WarehousePanel.jsx` |
| W2 | 50 / 6 = **56** | AfA/Anlagen, Reise/KM/Stunden, USt, ESt/E1A/E1B (vier unabhängige Engines) | dieselben fünf → `integrator-w2` | `src/Assets/*`, `src/Travel/*`, `src/Time/*`, `src/Vat/*`, `src/Tax/*`, je eigene Panels/Specs |
| W3 | 8 / 4 = **12** | Auswertungen, Monatsansicht, Zeilenwarnungen | dieselben fünf → `integrator-w3` | `src/Analytics/*`, `src/Reports/MonthlyViewService.php`, `src/Validation/*`, je eigene Panels/Specs |

Jede Welle läuft intern parallel: die Modul-Agents schreiben ausschließlich ihre
 eigenen Dateien; der Integrator ist der einzige Schreiber der fünf geteilten
 Dateien. `smoke/*.php`-Includes und `<modul>.spec.js` sind Moduldateien und liegen
 nicht im Konflikt mit `tests/smoke.php` bzw. fremden Specs.

## 3. Agentenaufteilung (exakte Dateien)

| Welle | Agent | Dateien im Alleinbesitz |
|---|---|---|
| W0 | `settings-api` | `backend/src/Settings/ReferenceData.php`, `SettingsService.php`, `Holidays.php`, neu `YearWarnings.php`; `frontend/src/components/SettingsPanel.jsx`; neu `backend/tests/smoke/settings.php`; `frontend/e2e/settings.spec.js` |
| W0 | `sv-engine` | `backend/src/SocialInsurance/SvMode.php`, `SvResult.php`, `SvYearTable.php`, `SocialInsuranceCalculator.php`; `frontend/src/components/OverviewPanel.jsx`; neu `backend/tests/smoke/sv.php`, `frontend/e2e/overview-sv.spec.js` |
| W0 | `integrator-w0` | `backend/src/Http/Router.php`, `db/schema.sql`, `backend/tests/smoke.php`, `frontend/src/App.jsx`, `docs/openapi.yaml` |
| W1 | `journal-ea` | neu `backend/src/Journal/IncomeRules.php`, `ExpenseRules.php`; `frontend/src/components/EntryDialog.jsx`; neu `backend/tests/smoke/journals.php`; `frontend/e2e/entries.spec.js` |
| W1 | `warehouse-web` | neu `backend/src/Warehouse/WarehouseService.php`, `frontend/src/components/WarehousePanel.jsx`, `backend/tests/smoke/warehouse.php`, `frontend/e2e/warehouse.spec.js` |
| W1 | `integrator-w1` | fünf geteilte Dateien wie oben |
| W2 | `assets` | neu `backend/src/Assets/AssetService.php`, `Depreciation.php`, `backend/tests/smoke/assets.php`, `frontend/src/components/AssetsPanel.jsx`, `frontend/e2e/assets.spec.js` |
| W2 | `travel-km` | `backend/src/Travel/TravelCalculator.php`, `TravelRates.php`, `rates.json`; neu `TravelRules.php`, `backend/src/Time/HoursService.php`, `backend/tests/smoke/travel.php`, `frontend/src/components/TravelPanel.jsx`, `frontend/e2e/travel-rules.spec.js`; `frontend/src/components/EigenbelegDialog.jsx` |
| W2 | `vat` | neu `backend/src/Vat/VatReturnService.php`, `backend/tests/smoke/vat.php`, `frontend/src/components/VatPanel.jsx`, `frontend/e2e/vat.spec.js` |
| W2 | `tax-e1a-e1b` | neu `backend/src/Tax/EstYearTable.php`, `IncomeTaxService.php`, `E1aStatement.php`, `E1bStatement.php`, `backend/tests/smoke/est.php`, `frontend/src/components/TaxPanel.jsx`, `frontend/e2e/tax.spec.js` |
| W2 | `integrator-w2` | fünf geteilte Dateien wie oben |
| W3 | `analytics` | neu `backend/src/Analytics/BreakdownService.php`, `ProjectService.php`, `PaymentMethodService.php`, `backend/tests/smoke/analytics.php`, `frontend/src/components/AnalyticsPanel.jsx`, `frontend/e2e/analytics.spec.js` |
| W3 | `monthly-overview` | neu `backend/src/Reports/MonthlyViewService.php`, `backend/tests/smoke/monthly.php`, `frontend/src/components/MonthlyPanel.jsx`, `frontend/e2e/monthly.spec.js`; `backend/src/Pdf/MonthlyPdf.php` (nur dieser Agent) |
| W3 | `row-checks` | neu `backend/src/Validation/RowCheck.php`, `backend/tests/smoke/row-checks.php`, `frontend/src/components/RowWarnings.jsx`, `frontend/e2e/row-checks.spec.js` |
| W3 | `integrator-w3` | fünf geteilte Dateien wie oben |

`OverviewService.php` wird in W3 nicht von einem Modul-Agenten besetzt: die laufende
Änderung zuerst integrieren; danach nur der W3-Integrator fasst die neue
Monatsansicht über `MonthlyViewService` zusammen.

## 4. Abhängigkeiten und minimaler Integrationsschritt

1. **W0 → W1:** Kataloge, E1A-Kennzahlen, Standard-Betriebsanteile, USt-Arten und
   SV-API müssen stehen, bevor Buchungsfelder und WEB darauf zugreifen.
2. **W1 → W2:** USt-Art, E1A-Kennzahl, Belegnummer und WEB-Zeilen sind die
   Datengrundlage von USt, E1A/E1B und Anlagenverzeichnis.
3. **W2 → W3:** USt-Zeilen, AfA-Buchungen, SV-Engine, ESt-Ergebnis und Stundenbuch
   sind die Grundlage von Monatsansicht und Auswertungen. EUE-123 liegt bewusst in
   W2 (`travel-km`), weil EUE-080 denselben Stundenbestand liest.
4. **Innerhalb W2 keine Modul-Imports:** EUE-099/100 übernehmen AfA ausschließlich
   über gebuchte Kennzahlen; die Engines kommunizieren über dokumentierte
   Service-APIs, nicht über direkte Klassenreferenzen anderer Agents.
5. **Integrationsschritt je Welle (ein small commit, keine Fachlogik):**
   `db/schema.sql` DDL/Indizes → `Router.php` Routen → `tests/smoke.php` eine
   `require`-Zeile je Smoke-Include → `App.jsx` Tab-Registrierung (und W3: eine
   `RowWarnings`-Mountstelle) → `docs/openapi.yaml` Pfade/Responses.
6. **Gates je Welle:** `git diff --check`, Smoke-Suite, die betroffenen Playwright-
   Specs, `python3 tools/count_long_files.py --list` (Baseline darf nicht steigen),
   `python3 tools/parity_report.py --check`. Kein Agent ändert
   `rule-coverage.json` oder `parity-report.md`; Statuspflege folgt erst nach DoD
   durch den Parität-Owner.

## 5. Definition of covered

`covered` setzt **beide** Nachweise voraus: einen Smoke-/Unit-Eintrag und eine
Playwright-Referenz; beide müssen in `rule-coverage.json` namentlich stehen.
`partial` bleibt bei nur einem Nachweis oder offener Lücke und braucht eine
Begründung. Ohne Smoke **und** Playwright gibt es kein `covered` — `@check`
lehnt `covered` ohne die beiden Felder ab. Nicht ausgeführte Tests zählen nicht
als Nachweis.

## 6. `not-applicable` (unverändert, 10 Regeln)

| Regel | Grund |
|---|---|
| EUE-025 … EUE-028 | Duplikatwarnungen editierbarer Stammdatenlisten; Arbeitsmappen-UI-Artefakt ohne Anwendungsfall im Prototyp |
| EUE-029 | Verwendungsanzeige (❯/99+) ist ein reines Tabellen-Artefakt |
| EUE-035, EUE-044 | Laufende Journalnummern werden durch die Datenbank-ID ersetzt |
| EUE-060 | Laufende Anlagennummern werden durch die Datenbank-ID ersetzt |
| EUE-128 | Bedingte Formatierung der Arbeitsmappe; die App nutzt Theme-Farben |
| EUE-130 | Fußzeile 1001 enthält laut Regel keine gültigen Regeln |

## 7. Extern blockiert

**#11 (externe QES-Anbindung)** bleibt offen: provider-spezifische API,
Identitätsbindung und qualifizierte Vertrauenskette sind nicht implementiert; der
Mock erzeugt keine qualifizierte Signatur. Keine Regel dieser Plane ist von #11
abhängig — die Parität aller 114 Regeln ist ohne #11 erreichbar. Features mit
fachlichem Prüfvorbehalt (insbesondere B05 laut `asset-register.md` §6) bleiben
bis zur externen Bestätigung als „fachlich noch nicht freigegeben“ gekennzeichnet;
die Tests sichern ausschließlich die dokumentierte Rechenlogik, keine steuerliche
Würdigung. Dies ist keine Steuerberatung.
