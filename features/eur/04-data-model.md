# EÜR-Datenmodell und Persistenz

**Status:** gültig für Wirtschaftsjahr 2026

## Grundsätze

- Jede Entität trägt eine Brand-Zuordnung (`brand`). Abfragen laufen über
  `forCurrentBrand()`; fremde IDs sind nicht adressierbar und enden als 404.
- Geld ist ausschließlich ganzzahlige Cent (`*_cents`), Zeit ganzzahlige
  Minuten. Es gibt keine Float-Spalten.
- Betriebsanteile sind ganze Prozent 0–100 in 5-%-Schritten; die API nimmt
  0–100, der Katalog nennt 0.0–1.0, die Umrechnung passiert an der Lesegrenze.
- Es gibt nur das Wirtschaftsjahr 2026; Jahresfelder werden serverseitig
  validiert.
- Das Schema folgt der Anforderung
  [`data-model-requirements.md`](cleanroom/artifacts/verification/requirements/data-model-requirements.md),
  nicht einem kopierten Zielschema.

## Tabellen

| Tabelle | Inhalt | Pflichtfelder / Regeln |
|---|---|---|
| `eur_customers` | Kunden | Name, Brand; Löschen nur Archivieren |
| `eur_projects` | Projekte | Name, Brand, aktiv/archiviert |
| `eur_entries` | Buchungen (Einnahme/Ausgabe) | Jahr 2026, Monat 1–12, Richtung, Art, Betrag in Cent, Betriebsanteil in 5-%-Schritten, Belegnummer |
| `eur_assets` | Anlagegüter | Belegnummer, Anschaffungsdatum, Kosten in Cent, Nutzungsjahre 0/2–99, Betriebsanteil ≥ 50 %, AfA-Kennzahl, Methode |
| `eur_asset_years` | AfA- und Privatnutzungsplan je Jahr | Jahr, Methode, AfA in Cent, Restbuchwert in Cent, Privatanteil, Status |
| `eur_audit_logs` | Audit | Aktion, Akteur, Objekt; keine Dateiinhalte und keine Secrets |

Die Migration ist `backend/database/migrations/V061__create_eur_bookkeeping_schema.php`.
Sie ist additiv und enthält keinen Backfill.

## Beziehungen

- Buchung → genau ein Kunde und optional ein Projekt; Reise analog.
- Anlagegut → AfA-Plan je Jahr; der Plan wird beim Anlegen berechnet und
  gespeichert, nicht bei jeder Anzeige neu erzeugt.
- Ein Wechsel degressiv → linear ist einmalig, ab dem zweiten Jahr und nur mit
  Begründung; gebuchte Jahre bleiben unverändert (DEG-009 … DEG-014).
- Privatanteil > 50 % und < 100 % verlangt eine Entnahme-Buchung unter
  derselben Belegnummer (EUE-055).

## API-Vertrag

Alle EÜR-Endpunkte liegen unter `/api/management/eur/*`, sind authentifiziert
und auf die Management-Rollen begrenzt. Geld geht als ganzzahlige Cent über
die Leitung, Anteile als ganze Prozent, Sätze als Basispunkte. Antworten sind
brand-scoped; ein fremder Datensatz liefert 404, eine ungültige Eingabe 422.

- `GET reference`, `GET/PUT settings`, `GET summary`
- `GET/POST/PUT/DELETE entries`
- `GET/POST assets`, `GET assets/compare`, `GET assets/{id}/plan`,
  `POST assets/{id}/switch-to-linear`
- `POST vat/calc`, `POST travel/calc`, `POST est/calc`

Schreibende Aktionen erzeugen einen Audit-Eintrag ohne Dateiinhalte. Der
Servierer berechnet Preise, Summen und AfA; der Client rechnet nicht nach.
