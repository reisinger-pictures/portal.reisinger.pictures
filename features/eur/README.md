# EAR-Integration

Die Einnahmen-Ausgaben-Rechnung wird im Portal aus den abgeleiteten Dokumenten
der Clean-Room-Basis implementiert. Dieses Verzeichnis enthält die portal-eigenen
Verträge und den unveränderlichen, versionierten Dokumentenstand.

## Inhalt

- [01-cleanroom-handoff.md](01-cleanroom-handoff.md) — Fixture-Version, Update-Erkennung, Geltungsgrenzen.
- [02-parity-vectors.md](02-parity-vectors.md) — 16 Szenarien / 45 Zellen, minimale Wertekette und Unit-Test (Issue #10).
- [03-engine.md](03-engine.md) — Rechenverträge je Fachbereich (ESt, USt, Reisen, AfA/DEG), Einheiten und Rundung.
- [04-data-model.md](04-data-model.md) — Tabellen, Brand-Trennung, API-Vertrag.
- [05-ui.md](05-ui.md) — Panels, Lösungsaktionen, Sprache und Mobile.
- [cleanroom/](cleanroom/) — byte-exakter, gepinnter Stand der abgeleiteten Dokumente (schreibgeschützt), inklusive `artifacts/` und `verification/requirements/`.

## Geltende Einheiten

- Geld: ganzzahlige Cent. Nachkommastellen entstehen nur an der Lesegrenze und werden dort nach Cent überführt.
- Zeit: ganzzahlige Minuten.
- Wirtschaftsjahr: ausschließlich 2026.

## Mandantenfähigkeit

- Die Buchhaltung ist für mehrere Brands ausgelegt und richtet sich nach dem Brand-Kontext des Portals; es gibt keine fest verdrahtete Einzel-Buchhaltung.
- Alle späteren Buchhaltungsdaten werden brand-scoped gespeichert und abgefragt; fremde Brand-IDs sind nicht zugreifbar (404).
- Die Unternehmensdaten der EAR-Ansicht stammen ausschließlich aus den brand-scoped Einstellungen (`/api/settings/billing-details`); es gibt keine zweite Firmendatenquelle.

## Nutzeransicht

- Die Buchhaltung ist ein eigener Menüpunkt in der bestehenden App-Shell (Route `/steuern`, Alias `/eur`) mit einem expliziten Weg zurück ins Portal; keine zweite App-Shell.
- Die Ansicht zeigt ausschließlich fachlich nützliche Informationen: Unternehmensdaten aus den Einstellungen, die Grenze für geringwertige Wirtschaftsgüter und den betrieblichen Anteil. Technische Hinweise zur Paritätsprüfung sind kein Teil der Nutzeransicht.
- Die EAR-spezifischen Firmenfelder (Steuernummer, Finanzamt, UID, Rechtsform) werden in den Einstellungen gepflegt und in der Ansicht angezeigt; fehlende Werte erscheinen als Platzhalter.

## Umfang

- Referenzdaten, Einstellungen, Buchungen, Anlagen/AfA, DEG-Vergleich, USt und
  Reisen sind über die EAR-API angebunden.
- Die UI ist eine eigenständige Buchhaltungsansicht unter `/steuern` (Alias `/eur`) mit eigener
  Monatsleiste, Monats- und Jahresübersicht, 12 Tabs und Rücksprung ins Portal.
  Belege, Wareneingang, Auswertungen, Anträge und Signatur sind als eigene
  Tabs und Panels umgesetzt. Offen bleiben PDF-Erzeugung, OCR, echte
  QES-Signaturkette und die Filter der Jahresübersicht (Issue #31).

## Grenzen

- Die Clean-Room-Basis selbst liegt nicht in diesem Repository.
- Es gibt keine zweite, redaktionell gepflegte Regelquelle; Regeln werden über ihre IDs referenziert, nicht umformuliert.
- Es werden keine Formeln, keine Neuberechnung der Basis und kein Engine-Code übernommen.
- Die Paritäts-Fixture ist die einzige Grundlage der Portal-Unit-Tests.
- Parität gilt als `covered` erst mit Smoke- und Playwright-Referenz; die externe steuerliche Prüfung bleibt dokumentierter Vorbehalt, kein Blocker.
- In portal-eigenem Code, Dokumentation, Commits und GitHub-Kommentaren wird ausschließlich die Bezeichnung „Clean-Room-Basis" verwendet. Der gepinnte Dokumentenstand ist ein unveränderlicher Fremdartefakt und davon ausgenommen.
