# EAR · API-Vertrag für die Buchhaltungsansicht

Alle Endpunkte liegen unter `/api/management/eur/*`, sind authentifiziert und
brand-scoped. Geld ist immer ganzzahlige Cent, Zeit ganze Minuten, Anteile
ganze Prozent in 5-%-Schritten. Ein fremder Datensatz ist ein 404, eine
ungültige Eingabe ein 422. Alle Antworten enthalten keine Paritäts- oder
Technikdetails.

**Abgrenzung zur gepinnten OpenAPI:** Das Handoff-Artefakt
`prototype/docs/openapi.yaml` beschreibt eine Server-to-Server-Integrations-API,
die das Portal nicht implementiert und nicht produktiv nutzt. Es ist
Übergabeinformation, nicht der Portal-Vertrag ([D-71](../owner-decisions.md#d-71-the-pinned-openapi-contract-is-reference-information-not-a-portal-api-contract)).
Token-Scopes, `Idempotency-Key`, `409`, `429`/`Retry-After`, Ratenlimit und
`POST /integrations/invoices` sind daher keine Portal-Anforderungen. Der
Betriebsanteil wird an der Portal-Grenze zwischen Katalog (0.0–1.0) und API
(0–100) explizit konvertiert; die Engine wird serverseitig gerufen, niemals vom
Browser.

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

Portal #58 ergänzt zwei **nicht blockierende** Hinweise zur realen SV-Buchung
(38,00 € je Quartal, KZ 9225). `GET /eur/summary` liefert zusätzlich
`sv_reminder` mit `target_per_quarter_cents` (3800), `quarters`
(`quarter`, `booked_cents`, `done` für Q1–Q4), `open_quarters` und einem
deutschen `message` (null, wenn alle Quartale gedeckt sind). Die Summe zählt
brand-scoped alle 9225-Buchungen der jeweiligen Quartalsmonate; mehrere
Buchungen addieren sich in ganzen Cent. Der Wert wird **nicht** aus
`svMonthly()` hergeleitet und verändert weder `booked_sv_cents` noch die
SV-Berechnung (EUE-109/115, D-79 bleiben unberührt). `GET /eur/month` enthält
in den Quartalsmonaten 3/6/9/12 zusätzlich einen Check mit `code: "SV-9225"`
im bestehenden `checks`-Array (`JournalRules::check`-Form mit ⚠/💡); außerhalb
dieser Monate erscheint er nicht. Der Monatsabschluss bleibt uneingeschränkt
möglich.

`GET /eur/help-texts` und `GET /eur/help-texts?kz=9216` liefern die
Ausfüllhilfe je Kennzahl aus dem gepinnten Artefakt
`artifacts/verification/e1a-ausfuellhilfe.md`, Abschnitt 3. Jede Zeile hat
`kz`, `bezeichnung`, `hilfetext` und `portal_note` (`null` oder ein
Portalseitiger Hinweis). Die Spalte „Zelle" aus der Quelle wird nicht
ausgeliefert; die Texte sind reine Hilfe, keine zweite Regelquelle und werden
nicht verändert. Für `kz=9225` ergänzt `portal_note` den Doppelzählungs-Hinweis
aus EUE-023/EUE-115. Ein ungültiges `kz` ist ein 422.

`GET /eur/reference` ergänzt `kz_catalogue` mit `expense_codes` (27 diverse
Kennzahlen), `income_codes` (5), `e1b_codes` (6, inklusive der geteilten 9135)
und `distinct_expense_count`. Die Vektoren stammen aus der gepinnten `DOMAIN.md`,
nicht aus der Ausfüllhilfe und nicht aus einer zweiten Liste im Code; die
Ausfüllhilfe hat einen anderen Scope (Einnahmen/Pauschalen ohne E1B).

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

`GET /eur/analytics/by-project?year=2026` (EUE-121) adds the project × month
cross-tab: one row per project with twelve month cells (`income_cents`,
`expense_cents`, `hours_minutes`, `result_cents`, `result_per_hour_cents`),
project and annual totals, `month_totals`, `undistributed_hours_minutes`,
`sv_excluded_cents` and `checks`/`match`. All money is whole cents, all time
whole minutes; `result_per_hour_cents` is integer cents **per minute** of
logged time (null without hours). Booked 9225 expenses are removed from
project expenses outside the UV-only mode and reported as `sv_excluded_cents`.
`prior_year_afa_cents` is deliberately a single top-level figure: assets have
no project relation in the portal (D-85), so it is never distributed across
projects. Hours without a project appear in the "Ohne Projekt" row and are
also reported as `undistributed_hours_minutes` so no time is dropped.

### Anlagen (#34)

`GET /eur/assets?private=1` liefert nur Privateinlagen. `GET /eur/assets/compare`
akzeptiert zusätzlich `business_share` (50–100 in 5-%-Schritten) und `name` und liefert
`first_year: {linear_cents, degressive_cents, linear_business_cents,
degressive_business_cents}` sowie `suggested_category` aus dem gepinnten
Katalog (keine hartcodierte Kategorie). Neue Anlagegüter übernehmen den
Kategorievorschlag, sofern nichts anderes angegeben wird.

Bei `business_share < 100` legt das Portal zusätzlich die **Entnahme** nach
EUE-055 automatisch an: eine Einnahme mit `kind_type = "Entnahme"`, `e1a_code =
"9040"` (die gepinnte Ausfüllhilfe beschreibt 9060 als Erlös eines *abgegangenen*
Anlageguts, hier liegt kein Abgang vor), `receipt_no = invoice_no` des
Anlageguts und `amount_cents = cost_cents × (100 − business_share)` in ganzen
Cent. Sie ist über Belegnummer + Art idempotent. Das Anlagegut liefert
`entnahme: {done, entry_id, amount_cents}`; fehlende Entnahmen erzeugen eine
nicht blockierende Warnung `EUE-055`.

Bei `scope = private` erzeugt das Portal den Eigenbeleg **automatisch** mit dem
AfA-Plan (beim Anlegen und beim Jahreswechsel, `EurAssetEigenbelegService`);
die Jahreszeile trägt `eigenbeleg_no`, `theoretical_depreciation_cents`,
`usage_percent` (`100 - private_use_percent`), `eigenbeleg_cents` und
`eigenbeleg_created_at`. `POST /assets/{id}/book-depreciation` bleibt erhalten,
meldet bei einem automatisch erzeugten Eigenbeleg aber `already_created: true`
und schreibt kein zweites Mal. Es entsteht keine Journalbuchung und keine
Gewinnminderung.

Die lineare Fortführung im privaten Verzeichnis ist nicht an die
`useful_life_years` gebunden: `EurAssetCarryOver` erzeugt für jede weitere
Jahresübernahme eine Zeile mit `(1 / Nutzungsdauer) × Betriebsanteil ×
Anschaffungswert`, solange das Gut aktiv ist. Die kumulierten Eigenbelege
dürfen den initialen Anschaffungswert nicht übersteigen; eine Zeile, die die
Grenze überschreiten würde, entsteht nicht. Der Restbuchwert bleibt dabei gemäß
EUE-050 auf dem Mindestwert von 1 Cent. Betriebliche Güter behalten ihren
festen Plan. `EurAssetsPanel.tsx` zeigt die privaten Güter in der eigenen
Ansicht `Privateinlagen`.

### Anlagegruppen (Portal #57, D-89)

`GET/POST /eur/asset-groups`, `PUT /eur/asset-groups/{id}`,
`POST /eur/asset-groups/{id}/assets` (`{asset_id}`),
`DELETE /eur/asset-groups/{id}/assets/{assetId}` und
`DELETE /eur/asset-groups/{id}` verwalten Abrechnungsgruppen. Eine Gruppe hat
`id`, `name`, `business_share`, `scope`, `asset_ids`, `assets` und `years`.
Der Gruppenanteil überschreibt den Anteil der Mitglieder; ein gruppiertes Gut
trägt `eur_asset_group_id` und wird nicht mehr einzeln abgerechnet. Belege
bleiben an den einzelnen Gütern. Eine Zuordnung mit gebuchtem Jahr wird mit
422 abgewiesen. Betriebliche Gruppen buchen **eine** Entnahme unter
`GR-<group id>`; private Gruppen erzeugen **einen** Eigenbeleg je Jahr
(`EBG-<group id>-<year>`). Alle Beträge sind ganze Cent.

### Beleg-Positionen (Portal #59, D-90)

`GET/POST /eur/documents/{id}/positions`,
`DELETE /eur/documents/positions/{positionId}` und
`PUT /eur/documents/{id}/group` (`{group_id: number|null}`) bilden die beiden
Richtungen derselben Verknüpfung. Eine Position ist eine angereicherte Zeile
der bestehenden Pivot `eur_asset_documents` mit `id`, `asset_id`,
`asset_name`, `label`, `amount_cents` und `ordinal`. Der Dokumenteintrag
liefert zusätzlich `total_cents` (nullable), `allocated_cents` und
`remaining_cents` (nullable). Das Splitten legt je Position ein eigenes
Anlagegut mit eigener AfA und eigener Entnahme/Eigenbeleg an, solange es nicht
gruppiert ist; ein gesetzter Belegbetrag darf nicht überschritten werden
(sonst 422), ein Restbetrag bleibt erlaubt. Ein zweiter Split desselben Belegs
wird mit 422 abgewiesen; das Löschen einer Position entfernt nur die
Verknüpfung, nicht das Gut und seine Buchung. Die Gruppenzuordnung fasst alle
mit dem Beleg verknüpften Güter in einer bestehenden Gruppe zusammen (eine
Abrechnungseinheit je D-89) und stellt beim Lösen die Einzelabrechnung wieder
her. `GET /eur/documents` liefert dieselben Zusatzfelder je Beleg;
`GET /eur/asset-groups` ergänzt `document_ids` und `documents`, abgeleitet aus
den verknüpften Gütern. Alle Abfragen sind brand-scoped (fremder Brand → 404),
alle Beträge ganze Cent.

Die Antwort enthält je Anlage `warnings: [{code, text}]`. Neben EUE-053/058
meldet `AssetPlausibility` die nicht blockierenden Hinweise EUE-056
(Vorjahres-AfA weicht vom gepinnten Plan um mehr als 1 Cent ab) und EUE-057
(überzählige Vorjahres-AfA im Anschaffungsjahr, fehlender oder abweichender
Restbuchwert). Der Restbuchwert-Vergleich rundet wie die ODS auf ganze Euro;
die angezeigte Differenz bleibt in Cent. Nach einem vollzogenen Methodenwechsel
(DEG-009/014) ist der gespeicherte Plan maßgeblich; für solche Anlagen werden
keine EUE-056/057-Hinweise erzeugt, weil `switched_to_linear_at` den
Entscheidungszeitpunkt und nicht das Wechseljahr des Plans speichert.

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

Die Antwort enthält zwei additive Blöcke (EUE-095/103/126):

- `est_allocation`: `total_income_cents` (U14), `tax_cents` (U23/`ue_est`),
  `employment_tax_cents` (U27), `rental_tax_cents` (U25),
  `business_tax_cents` (U24, im Bruttolohn-Modus `null`) und `due_cents` (U29).
  `kz.U12` ist das E1B-Ergebnis.
- `monthlyResults`: `year`, `mode`, `annual` und `months` (zwölf Einträge).
  Optionaler Request-Block `months: [{month: 1..12, gross_profit_cents,
  hours_minutes?}]` liefert die Monatsgewinn-/Stundenbasis. Ohne Monatsdaten
  ist `annual: null` und jeder Monatswert `null`. Je Monat werden
  `est_sv_cents` (J12), `net_profit_cents` (K12), `net_per_hour_cents` (N5,
  ganzzahlige Cent pro Stunde) sowie `basis_cents` (I16),
  `loss_basis_cents` (I14) und `minimum_sv_cents` (I12) ausgeliefert.
  `hours_minutes` sind ganze Minuten; bei 0 Minuten bleibt
  `net_per_hour_cents` leer. Alle Geldbeträge sind ganze Cent.

## Querschnitt

- Jeder Schreibzugriff schreibt `eur_audit_logs` ohne Dateiinhalte.
- Zeilenhinweise haben `code`, `ok`, `message` (deutsch, mit ⚠ bei Problemen)
  und `hint` (deutscher Lösungsweg mit 💡).
- Keine Antwort enthält `.xlsx`/`.ods`-Inhalte oder Rohdumps aus der Quelle.
