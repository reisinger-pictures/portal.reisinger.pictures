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

Die Migrationen sind additiv und enthalten keinen Backfill; der aktuelle Stand
steht ausschließlich in
[`features/tech/07-architectural-decisions.md`](../tech/07-architectural-decisions.md#ad-2).

### Schema-Entscheidung V062 (Journal, Belege, Monat)

**Schema.** `eur_entries` erhält nullable `day`, `category`, `description`;
bestehende Zeilen bleiben ohne Tagesdatum gültig (Monat bleibt Pflichtfeld).
Neu: `eur_documents` (Belegmetadaten, Prüfsumme, privater Pfad), `eur_hours`
(Minuten), `eur_warehouse_entries` (Wareneingang in Cent), `eur_applications`
(Anträge), `eur_signatures` (Signaturstatus) und `eur_monthly_reports`
(Monatsstatus, eindeutig je Brand/Jahr/Monat). Alle Tabellen tragen `brand`,
Geld ist `*_cents`, Zeit `minutes`; es gibt keine Float-Spalten.

**Backfill.** Keiner. Bestehende Buchungen haben kein Tagesdatum; die Anzeige
zeigt dann nur den Monat. Ein Monatsbericht entsteht erst beim Abschluss.

**Rollback.** `down()` ist nach Repository-Policy leer. Der additive Weg
lässt bestehende Buchungen unverändert; neue Tabellen sind unabhängig.

### Schema-Entscheidung V063 (#34)

**Schema.** `eur_assets` erhält `category` (nullable, Kategorievorschlag aus
dem Referenzkatalog), `is_private_investment` (bool, default false für die
Privateinlagen-Ansicht), `switched_to_linear_at` (timestamp, nullable) und
`switch_reason` (text, nullable). `eur_asset_years` erhält `eur_entry_id`
(nullable FK auf `eur_entries`) und `booked_at` (timestamp, nullable) für die
idempotente Jahresabschlussbuchung.

**Backfill.** Keiner. Bestehende Anlagegüter bleiben ohne Kategorie und ohne
Wechselmarkierung; die Ableitung aus vorhandenen Jahreszeilen (`method=linear`)
bleibt als Lesefallback erhalten. Bereits gebuchte AfA-Jahre werden nicht
nachträglich verbucht.

**Rollback.** `down()` ist leer. Die neuen Spalten sind nullable; ein Entfernen
würde nur Kategorie, Wechselgrund und Buchungsverknüpfung verlieren, nicht die
AfA-Plane selbst. Ein Rückwechsel auf degressiv bleibt fachlich gesperrt, weil
es keinen Schreibpfad gibt, der `method` nach einem Wechsel wieder auf
`degressiv` setzt.

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
