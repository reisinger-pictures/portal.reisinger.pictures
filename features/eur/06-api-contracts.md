# EAR · API-Vertrag für die Buchhaltungsansicht

Alle Endpunkte liegen unter `/api/management/eur/*`, sind authentifiziert und
brand-scoped. Geld ist immer ganzzahlige Cent, Zeit ganze Minuten, Anteile
ganze Prozent in 5-%-Schritten. Ein fremder Datensatz ist ein 404, eine
ungültige Eingabe ein 422. Alle Antworten enthalten keine Paritäts- oder
Technikdetails.

## Jahr

`GET /eur/years`

```json
{
  "default_year": 2026,
  "supported": [2024, 2025, 2026],
  "active_years": [2024, 2026],
  "warnings": [
    {"year": 2027, "code": "EUE-002", "warning": "…", "supported": false}
  ]
}
```

`active_years` sind die Jahre mit mindestens einer Buchung im aktuellen Brand.
Die UI zeigt im Jahres-Selector nur diese Jahre plus das laufende Jahr; das ist
eine Anzeigeregel, keine Einschränkung der akzeptierten `year`-Werte.

Alle übrigen Endpunkte akzeptieren `year` als Query-Parameter (GET) oder im
Body (POST/PUT). Erlaubt sind 2024–2026; ein anderer Wert ist ein 422 mit
`message` aus `EurYear::warning()`. Ohne Parameter gilt 2026.

## Journal und Monat

`GET /eur/month?year=2026&month=5`

```json
{
  "year": 2026,
  "month": 5,
  "lines": [
    {"type": "E|A|WEB|FR", "day": 5, "description": "…", "amount_cents": 12000,
     "business_amount_cents": 12000, "vat_kind": null, "receipt_no": null,
     "warnings": [], "hints": []}
  ],
  "totals": {"income_cents": 0, "expense_cents": 0, "warehouse_cents": 0,
             "vat_cents": 0, "hours_minutes": 0},
  "checks": [{"code": "BE-ART", "ok": true, "message": "…", "hint": "…"}],
  "social_insurance": {"amounts_cents": {}, "rates_per_10000": {}},
  "income_tax": {"profit_cents": 0, "tax_cents": 0},
  "report": {"status": "open", "closed_at": null},
  "signature": {"status": "none", "provider": null, "is_test": false}
}
```

`GET /eur/help-texts` und `GET /eur/help-texts?kz=9216` liefern die
Ausfüllhilfe je Kennzahl aus dem gepinnten Artefakt
`artifacts/verification/e1a-ausfuellhilfe.md`, Abschnitt 3. Jede Zeile hat
`kz`, `bezeichnung`, `hilfetext` und `portal_note` (`null` oder ein
Portalseitiger Hinweis). Die Spalte „Zelle" aus der Quelle wird nicht
ausgeliefert; die Texte sind reine Hilfe, keine zweite Regelquelle und werden
nicht verändert. Für `kz=9225` ergänzt `portal_note` den Doppelzählungs-Hinweis
aus EUE-023/EUE-115. Ein ungültiges `kz` ist ein 422.

`POST /eur/month/close` mit `{year, month, note?}` schließt den Monat
idempotent (200, auch beim zweiten Aufruf) und erzeugt einen Audit-Eintrag.

`GET /eur/entries` liefert zusätzlich je Buchung `checks: [{code, ok, message,
hint}]`. Die Felder `day`, `category`, `description` sind optional; fehlende
Werte erzeugen einen Hinweis mit Lösungsweg statt eines Fehlers.

## Belege, Stunden, Wareneingang, Anträge, Signaturen

- `GET/POST /eur/documents` — Upload als `multipart/form-data` mit `file`,
  `year`, `month?`, `receipt_no?`. Antwort `{document: {...}}` ohne
  Storage-Pfad. Die Datei liegt im privaten Disk, `sha256` wird gespeichert.
- `GET /eur/documents/{id}/download` — authentifizierter Download,
  fremde ID → 404. `DELETE /eur/documents/{id}`.
- `GET/POST /eur/hours` — `{year, month, day, minutes, project?, description?}`,
  `minutes > 0`, Antwort `{hours: [...], total_minutes: n}`.
- `GET/POST /eur/warehouse` — `{year, month, day?, description, amount_cents,
  receipt_no?}`, Antwort `{entries: [...], total_cents: n}`.
- `GET/POST /eur/applications` — `{year, type, subject, note?}`, Antwort
  `{applications: [...]}`. Status `open|done`.
- `GET/POST /eur/signatures` — `{year, month, eur_document_id?, provider,
  status?, note?}`, Antwort `{signature: {...}, is_test: bool}`. Provider mit
  `test` im Namen ist als Testprovider gekennzeichnet.

## Auswertungen und Steuer

`GET /eur/analytics?year=2026`

```json
{
  "by_customer": [{"name": "…", "revenue_cents": 0, "cost_cents": 0,
                   "margin_cents": 0}],
  "by_project": [{"name": "…", "revenue_cents": 0, "cost_cents": 0,
                  "margin_cents": 0}],
  "by_month": [{"month": 1, "income_cents": 0, "expense_cents": 0,
                "profit_cents": 0}]
}
```

### Anlagen (#34)

`GET /eur/assets?private=1` liefert nur Privateinlagen. `GET /eur/assets/compare`
akzeptiert zusätzlich `business_share` (50–100 in 5-%-Schritten) und `name` und liefert
`first_year: {linear_cents, degressive_cents, linear_business_cents,
degressive_business_cents}` sowie `suggested_category` aus dem gepinnten
Katalog (keine hartcodierte Kategorie). Neue Anlagegüter übernehmen den
Kategorievorschlag, sofern nichts anderes angegeben wird.

`POST /eur/assets/{id}/switch-to-linear` setzt zusätzlich
`switched_to_linear_at` und `switch_reason`. Ein Rückwechsel auf degressiv
gibt es nicht: es existiert kein Schreibpfad, der `method` danach wieder auf
`degressiv` setzt; ein zweiter Wechsel liefert 422 (EUE/DEG-013/014).

`POST /eur/assets/{id}/book-depreciation` mit `{year}` erzeugt die
Jahresabschlussbuchung über den bereits im AfA-Plan enthaltenen
`business_use_cents`. Die Buchung ist über `eur_asset_years.eur_entry_id`
idempotent (zweiter Aufruf: 200 mit `already_booked: true`).

`POST /eur/est/calc` akzeptiert zusätzlich `mode`
(`total|business|rental|forecast`) und liefert `e1a`, `e1b`, `deductions_cents`
sowie `stages`. Der Client rechnet nichts nach.

## Querschnitt

- Jeder Schreibzugriff schreibt `eur_audit_logs` ohne Dateiinhalte.
- Zeilenhinweise haben `code`, `ok`, `message` (deutsch, mit ⚠ bei Problemen)
  und `hint` (deutscher Lösungsweg mit 💡).
- Keine Antwort enthält `.xlsx`/`.ods`-Inhalte oder Rohdumps aus der Quelle.
