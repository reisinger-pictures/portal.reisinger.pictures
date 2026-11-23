# EAR-Datenmodell und Persistenz

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
| `eur_entries` | Buchungen (Einnahme/Ausgabe) | Jahr 2026, Monat 1–12, Richtung, Art, Betrag in Cent, Betriebsanteil in 5-%-Schritten, Belegnummer, optionale externe ID der Rechnungsübernahme |
| `eur_assets` | Anlagegüter | Belegnummer, Anschaffungsdatum, Kosten in Cent, Nutzungsjahre 0/2–99, Betriebsanteil ≥ 50 %, AfA-Kennzahl, Methode |
| `eur_asset_years` | AfA- und Privatnutzungsplan je Jahr | Jahr, Methode, AfA in Cent, Restbuchwert in Cent, Privatanteil, Status |
| `eur_audit_logs` | Audit | Aktion, Akteur, Objekt; keine Dateiinhalte und keine Secrets |
| `eur_travel_trips` | gespeicherte Reisezeilen (D-83a) | Jahr 2026, Brand, Fahrzeug, Kilometer, Land, Tage, Betriebsanteil; Cent-Beträge vom Server berechnet |
| `eur_warehouse_entries` | Wareneingangsbuch WEB (D-83b) | Jahr 2026, Monat, Tag, Lieferant, Warenbezeichnung, Belegnummer, Ausgaben-Art, Betriebsanteil aus dem Katalog |

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

### Schema-Entscheidung V065 (automatische Rechnungsübernahme)

**Schema.** `eur_entries` erhält nullable `external_id` (190) und den
Unique-Index `(brand, external_id)`. `receipt_no` bleibt Freitext für
Handbuchungen und CSV-Import und wird nicht als Identitätsfeld benutzt: es ist
weder eindeutig noch technisch kontrolliert und würde mit menschlich
vergebenen Belegnummern kollidieren. Die externe ID der Übernahme ist
`invoice:<Rechnungsnummer>`; die `invoice_snapshots`-PK stammt aus der
gemeinsamen `InvoiceSequence` und ist damit quellenübergreifend stabil. Der
Brand ist Teil des Schlüssels, damit gleiche Nummern verschiedener Mandanten
getrennt bleiben.

**Backfill.** Keiner. Bereits vorhandene Buchungen behalten `external_id =
null` und werden nicht nachträglich einer Rechnung zugeordnet. Ein Replay
einer bereits versandten P-Rechnung erzeugt erst beim erneuten Durchlauf des
Sendepfads eine Übernahme; Lieferscheine und Angebote sind keine Buchungsquellen.

**Rollback.** `down()` ist nach Repository-Policy leer. Die Spalte ist
nullable; ein Entfernen würde nur die Zuordnung zur Quelle verlieren, nicht
die Buchung selbst. Ohne Unique-Index ist ein Replay-Schutz nicht mehr
datenbankseitig garantiert — das ist der Grund für den Index.

Der fachliche Vertrag der Übernahme steht in
[`08-invoice-takeover.md`](08-invoice-takeover.md).

### Schema-Entscheidung — signierte Fassung eines Belegs

**Schema.** `eur_documents` erhält fünf nullable Spalten für die extern
signierte Fassung: `signed_storage_path` (255), `signed_sha256` (64),
`signed_mime_type` (40), `signed_size_bytes` (bigint) und `signed_at`
(timestamp). Die Additionsreihenfolge steht in der AD-2-Frontier.
Der private Pfad der signierten Datei wird verschlüsselt auf dem `local`-Disk
gehalten; die Antwort des Uploads enthält ihn als Metadatum, niemals eine
öffentliche URL. Die ursprünglichen Spalten
(`storage_path`, `sha256`, `size_bytes`, `mime_type`, `original_name`) bleiben
beim Signatur-Upload unverändert. Der Status wechselt von `unsigned` nach
`signed`.

**Backfill.** Keiner. Bestehende Belege bleiben ohne signierte Fassung; es
gibt keine automatische Ersetzung durch Altdaten und keine Annahme, ein
vorhandenes `eur_signatures`-Zeichen sei eine hochgeladene Datei.

**Rollback.** `down()` ist nach Repository-Policy leer. Die neuen Spalten sind
nullable; ein Entfernen würde nur die signierte Fassung und ihre Metadaten
verlieren, nicht den ursprünglichen Beleg oder seine Audithistorie.

**Grenze der Aussage.** Die geprüfte Bindung ist CONTENT-IDENTITY: Der Marker
im unsigned PDF trägt einen signierten JWT-Hash der Ursprungsbytes; beim
Wiedereingang müssen die Upload-Bytes mit den gespeicherten Bytes beginnen und
der Marker muss Marke, Art, Jahr, Monat und diesen Hash binden. Zusätzlich
verifiziert `EurPdfSignatureService` (D-84) den eingebetteten CMS-/PKCS#7-Block
mit der PHP-Bibliothek `lsnepomuceno/signet-pdf`: der `/ByteRange` muss bei 0
beginnen und die Ursprungsbytes abdecken, die Signatur muss über genau diese
Bytes kryptografisch gültig sein und die gesamte Datei abdecken. Das ist
weiterhin **keine** qualifizierte elektronische Signatur: kein Trust-Store,
keine Ketten- oder Revocation-Prüfung, kein Netzwerkzugriff und kein `pdfsig`
im Deployment-Image. `isTrusted` wird nicht ausgewertet. Ein erfolgreicher
Upload besagt, dass eine verifizierbare Signatur über dem Inhalt unseres Belegs
liegt — nicht, dass ein Signer vertrauenswürdig ist.

### Schema-Entscheidung V067 (Reisezeilen, D-83a)

**Schema.** Neue Tabelle `eur_travel_trips` mit den Eingaben des bestehenden
`travel/calc`-Endpunkts (`year`, `month`, `vehicle`, `kilometres`, `passengers`,
`country`, `days`, `meals`, `with_receipts`, `business_share`) und den vom Server
berechneten `kilometre_cents`, `day_cents`, `night_cents`, `total_cents`,
`business_cents` sowie `note`. Wie im AfA-Plan wird das Ergebnis beim Anlegen
berechnet und gespeichert, nicht bei jeder Anzeige neu erzeugt. Geld ist
ausschließlich ganzzahlige Cent, Anteile ganze Prozent in 5-%-Schritten; es gibt
keine Float-Spalten. Die Tabelle trägt `brand` und wird über `forCurrentBrand()`
gescoped; der Index `(brand, year, month)` deckt die Monatsansicht ab.

**Backfill.** Keiner. Reisen existierten vorher nur als flüchtige
Berechnungsergebnisse ohne Identität und werden nicht nachträglich angelegt.
Die bestehenden Journale und Auswertungen werden nicht umgeschrieben; die
neue Tabelle ergänzt die Monatsansicht, ohne Summen zu verdoppeln, solange
keine Reisezeile gespeichert ist.

**Rollback.** `down()` ist nach Repository-Policy leer. Die Tabelle ist additiv
und verändert keine bestehende Tabelle; ein Entfernen würde nur gespeicherte
Reisen und ihre Audit-Hinweise verlieren, nicht Buchungen, AfA-Pläne oder
Belege. Der schreibende Pfad (`POST travel`) ist die einzige Quelle neuer
Zeilen und fällt mit der Tabelle weg.

### Schema-Entscheidung V068 (WEB-Datenmodell, D-83b)

**Schema.** Die bestehende Tabelle `eur_warehouse_entries` (V062) erhält drei
nullable Spalten: `supplier` (Lieferant, 190), `expense_kind` (Ausgaben-Art,
190) und `business_share` (ganze Prozent, default-null). Die Pflichtfelder nach
EUE-047 — Monat, Tag, Lieferant, Warenbezeichnung und Belegnummer — werden an
der Schreibgrenze mit 422 erzwungen, nicht als nachträglicher NOT-NULL-Constraint.
Bei Belegnummern mit Präfix `WE` ist zusätzlich eine definierte Ausgaben-Art
nötig (EUE-045/047); sie muss einer `type` aus `expenseKinds()` des gepinnten
Referenzartefakts entsprechen. Der Betriebsanteil wird daraus abgeleitet
(`share_percent`, sonst 100) und ist kein Client-Eingabefeld — `business_share`
ist im Request `prohibited`. Damit bleibt die serverseitige Regelquelle der
Katalog (EUE-045).

**Backfill.** Keiner. Das WEB existiert seit V062 und kann Zeilen enthalten;
diese behalten `null` in den neuen Spalten und werden in der Antwort als `null`
ausgeliefert. Es wird kein Lieferant und keine Ausgaben-Art aus Alttext
erraten, und bestehende Beträge werden nicht umgerechnet.

**Rollback.** `down()` ist nach Repository-Policy leer. Die Spalten sind
nullable und die Tabelle ist sonst unverändert; ein Entfernen würde nur die
neuen WEB-Metadaten verlieren, nicht die Wareneingangsbeträge selbst.

### Schema-Entscheidung V069 (DEG-Ausschlussmerkmale)

**Schema.** `eur_assets` erhält fünf Merkmale für die Zulässigkeitsprüfung der
degressiven AfA: `is_used` (DEG-006), `is_immaterial` (DEG-007),
`immaterial_exception` (nullable Enum: `digitalisation`, `eco`, `health`),
`special_depreciation` (DEG-008, gesetzliche Sonder-AfA z. B. Gebäude) und
`is_electric_vehicle` (DEG-008, ausdrücklich zulässig). Die vier Booleans sind
`default false`, das Enum ist `nullable`. Die zulässigen Ausnahmen kommen aus
dem Parameter „Zulässige Ausnahmen" des gepinnten Artefakts
`business-rules/degressive-afa-rules.json` (DEG-007), nicht aus einer zweiten
Hardcoded-Liste. Die Prüfung läuft beim Anlegen: gebrauchte Güter, immaterielle
Güter ohne Ausnahme und Sonder-AfA werden mit 422 abgelehnt; ein Elektroauto ist
auch bei `special_depreciation = true` zulässig. Für lineare Güter sind die
Merkmale zulässig, aber ohne fachliche Wirkung. Der Ausnahme-Enum wird im
Anlagendatensatz gespeichert und in der Übersicht angezeigt (DEG-007).

**Backfill.** Keiner. Bestehende Anlagegüter behalten `false`/`null` und bleiben
damit in der degressiven Methode zulässig; es wird kein Merkmal aus Name,
Kategorie oder Bezeichnung erraten. Bereits berechnete AfA-Pläne werden nicht
neu erzeugt, und ein bereits vollzogener Wechsel bleibt unberührt.

**Rollback.** `down()` ist nach Repository-Policy leer. Die Spalten sind
additiv mit Defaultwerten; ein Entfernen verliert nur die Ausschlussmerkmale,
nicht die Anlagegüter, ihre AfA-Pläne oder Buchungen.

### Schema-Entscheidung V070 (Reise-Verdrahtung)

**Schema.** `eur_travel_trips` (V067) erhält `receipt_no` (64), `start_km` und
`end_km` (bigint), `purpose` (32) und `e1a_code` (8) — alle nullable. Die
Belegnummer ist für neue Reisen Pflicht (EUE-077) und brand-weit eindeutig;
weil eine Zeile im Portal genau eine Reise ist, ist eine Wiederverwendung für
eine andere Reise ein Fehler. Dieselbe Belegnummer mehrerer Zeilen einer
zusammengehörigen Reise gibt es im Portal nicht. Nach EUE-067 wird entweder
die Distanz (`kilometres`) **oder** ein Start-/Endkilometerstand angegeben,
nie beides; der Endstand muss größer sein. Die gefahrene Distanz wird
serverseitig aus der Differenz berechnet und wie zuvor auf das Jahreskontingent
gekappt. Die Veranlassung ist `Geschäftlich` oder `Privat`; bei `Privat` sind
die betrieblichen Kosten 0 und die Reise trägt den Status
„Nein (VERANLASSUNG: Privat)" (EUE-073). `e1a_code` wird serverseitig auf die
Reisekennzahl 9160 gesetzt (EUE-081) und ist kein Eingabefeld.

**Backfill.** Keiner. Bestehende Reisen behalten `null` in allen fünf Spalten;
ihre bereits gespeicherten Cent-Beträge werden nicht neu berechnet oder
umgerechnet. Es wird keine Belegnummer aus Notizen oder Datum erfunden.

**Rollback.** `down()` ist nach Repository-Policy leer. Die Spalten sind
additiv und nullable; ein Entfernen verliert nur Belegnummer, Kilometerstände,
Veranlassung und Kennzahl, nicht die Reise selbst oder ihre Beträge.

## Beziehungen

- Buchung → genau ein Kunde und optional ein Projekt; Reise analog.
- Anlagegut → AfA-Plan je Jahr; der Plan wird beim Anlegen berechnet und
  gespeichert, nicht bei jeder Anzeige neu erzeugt.
- Ein Wechsel degressiv → linear ist einmalig, ab dem zweiten Jahr und nur mit
  Begründung; gebuchte Jahre bleiben unverändert (DEG-009 … DEG-014).
- Privatanteil > 50 % und < 100 % verlangt eine Entnahme-Buchung unter
  derselben Belegnummer (EUE-055).

## API-Vertrag

Alle EAR-Endpunkte liegen unter `/api/management/eur/*`, sind authentifiziert
und auf die Management-Rollen begrenzt. Geld geht als ganzzahlige Cent über
die Leitung, Anteile als ganze Prozent, Sätze als Basispunkte. Antworten sind
brand-scoped; ein fremder Datensatz liefert 404, eine ungültige Eingabe 422.

- `GET reference`, `GET/PUT settings`, `GET summary`
- `GET/POST/PUT/DELETE entries`
- `GET/POST assets`, `GET assets/compare`, `GET assets/{id}/plan`,
  `POST assets/{id}/switch-to-linear`
- `POST vat/calc`, `POST travel/calc`, `POST est/calc`
- `GET/POST travel`, `DELETE travel/{id}` (D-83a, brand-scoped, Cent-Beträge
  serverseitig berechnet und gespeichert)
- `GET/POST warehouse` (D-83b, Pflichtfelder und Ausgaben-Art validiert,
  Betriebsanteil aus dem Katalog abgeleitet)

Schreibende Aktionen erzeugen einen Audit-Eintrag ohne Dateiinhalte. Der
Servierer berechnet Preise, Summen und AfA; der Client rechnet nicht nach.
