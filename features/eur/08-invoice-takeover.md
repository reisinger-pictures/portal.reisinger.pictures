# EAR · Automatische Übernahme versandter Rechnungen

Dieser Vertrag beschreibt den Geltungsbereich der automatischen
Rechnungsübernahme im Buchhaltungsmodell. Die aktuelle Migrationsfront steht
ausschließlich in AD-2 in
[`../tech/07-architectural-decisions.md`](../tech/07-architectural-decisions.md#ad-2)

## Geltungsbereich

Jede fiskalische Rechnung, die das Portal automatisch erstellt und zum Versand
freigibt, erzeugt genau eine Buchung in `eur_entries`. Fiskalisch ist nur die
`P-`-Nummer aus der gemeinsamen `InvoiceSequence`. Maßeinheit ist der
Sendepfad, nicht die Anzeige: der maßgebliche Schritt ist der durable
Versand-Claim, nicht der spätere SMTP-Status einer Queue.

Übernommen werden:

- Bestell-/Rechnungssnapshots: `InvoiceMailDispatcher::queueOnce()` nach dem
  durable `InvoiceSnapshot::claimInvoiceMailDispatch()`. Das umfasst die
  Checkout- und Mollie-Paid-Wege sowie die Sammelrechnung aus
  `InvoiceService::generateForOrg()`.
- Vertragsrechnungen: `ContractCloseService::createInvoice()` im selben
  Transaktionsrahmen wie der Abschluss der Vertrags-Position.

Nicht übernommen werden:

- **Lieferscheine (`L-`)** und **Angebote (`A-`)**. Ein Lieferschein hat nach
  [`../infrastructure/03-accounting-and-lifecycle.md`](../infrastructure/03-accounting-and-lifecycle.md)
  §2 keine fiskalische Rechnungsnummer; sein Bruttobetrag wird erst in der
  `P-`-Sammelrechnung realisiert. Beide Sendepfade laufen zwar durch
  `InvoiceMailDispatcher::queueOnce()`, dürfen aber keine eigene Buchung
  erzeugen — sonst würde derselbe Umsatz einmal je Lieferschein und einmal
  in der Sammelrechnung gebucht.
- **Payout-Auszüge.** Die buchhalterische Klassifikation der
  Fotografen-Auszahlungen ist nicht festgelegt; ohne Entscheidung wird keine
  Kategorie erfunden. D-58 regelt nur negative Korrekturen nach
  Monatsabschluss, nicht die Zuordnung der Auszahlung selbst.
- **Der manuelle Rechnungsdialog** (`InvoiceController::generateManualInvoice`).
  Er erzeugt einen PDF-Download, auch für Angebote; ein Download ist kein
  automatischer Versand.
- Weitere automatisch erzeugte Dokumente ohne definierten Sendepfad in der
  Buchhaltung.

## Identität und Wiedergabe

Die externe ID ist `invoice:<Rechnungsnummer>`. Die Rechnungsnummer stammt aus
der gemeinsamen `InvoiceSequence` und ist Primary Key von
`invoice_snapshots`; sie ist damit über alle oben genannten Quellen hinweg
stabil. Der eindeutige Schlüssel ist `(brand, external_id)`.

- Ein Replay mit identischem Betrag, Datum, Belegnummer, Betriebsanteil und
  Quelle ist ein No-op: es entsteht keine zweite Buchung und kein zweiter
  Anlage-Audit.
- Ein Replay mit abweichendem Payload überschreibt nichts. Der bestehende
  Eintrag bleibt maßgeblich; der Konflikt wird als Audit-Eintrag
  `invoice_takeover_conflict` festgehalten.
- Replays erreichen die Übernahme auch dann, wenn der Versand-Claim bereits
  gesetzt ist (etwa bei Snapshots aus der Zeit vor der Übernahme).
- Ein Opt-out je Dokument unterdrückt die Buchung und wird als
  `invoice_takeover_opted_out` auditiert. Ohne gesetztes Opt-out gilt die
  Übernahme. Das Flag ist für einen künftigen UI-Schalter reserviert; die
  automatischen Pfade rufen ohne Opt-out auf.
- Das Überspringen eines Nicht-Rechnungsdokuments wird als
  `invoice_takeover_skipped_non_invoice` auditiert, ohne eine Buchung zu
  erzeugen.
- Parallele Schreiber werden über die Row-Locks der Aufrufer auf Snapshot bzw.
  Vertrag serialisiert. Der Unique-Index `(brand, external_id)` ist die letzte
  Instanz: seine Verletzung bricht den Sendepfad ab (fail closed), statt still
  eine zweite Buchung zu erzeugen.

## Buchungsinhalt

| Feld | Wert |
|---|---|
| `direction` | `income` |
| `amount_cents` | `total_gross` des Rechnungssnapshots, ganzzahlige Cent |
| `business_share` / `business_amount_cents` | `100` / identisch mit `amount_cents` |
| `year`, `month`, `day` | aus dem Erstellungsdatum des Snapshots; nur unterstützte Wirtschaftsjahre |
| `kind_type`, `category` | `Dienstleistung`, `Rechnung` |
| `e1a_code` | `9040` (Referenz-Fallback EUE-008 für betriebliche Einnahmen) |
| `receipt_no` | Rechnungsnummer |
| `note` | `Automatische Übernahme: <Quelle>` |

Es gibt keine Float- und keine gerundeten Euro-Werte im Schreibpfad. Ein
nicht unterstütztes Wirtschaftsjahr oder ein negativer Betrag bricht die
Übernahme ab; die Sendung wird nicht mit einer halben Buchung freigegeben.

## Mandantenfähigkeit und Audit

Brand und externe ID werden gemeinsam gelesen und geschrieben. Die Quelle
liefert den Brand; fehlt er, gilt `BrandRegistry::currentId()`. Ein Replay aus
einem anderen Mandanten trifft daher nicht auf den Eintrag des ersten Mandanten.
Jede erstmalige Übernahme, jeder Konflikt und jedes Opt-out erzeugt einen
`eur_audit_logs`-Eintrag mit demselben Brand und nur fachlichen Feldern
(Quelle, externe ID, Rechnungsnummer, Cent-Betrag, Betriebsanteil).

Korrekturen einer übernommenen Buchung laufen über die regulären
Buchhaltungs-Schreibpfade und erzeugen dort ihren eigenen Audit-Eintrag. Eine
neue Signatur ist ohne gesonderten Vertrag nicht Teil der automatischen
Übernahme.
