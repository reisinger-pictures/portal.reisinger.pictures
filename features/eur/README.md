# EÜR-Integration

Die Einnahmen-Ausgaben-Rechnung wird im Portal aus den abgeleiteten Dokumenten
der Clean-Room-Basis implementiert. Dieses Verzeichnis enthält die portal-eigenen
Verträge und den unveränderlichen, versionierten Dokumentenstand.

## Inhalt

- [01-cleanroom-handoff.md](01-cleanroom-handoff.md) — Fixture-Version, Update-Erkennung, Geltungsgrenzen.
- [02-parity-vectors.md](02-parity-vectors.md) — 19 Paritätsvektoren, minimale Wertekette und Unit-Test (Issue #10).
- [cleanroom/](cleanroom/) — byte-exakter, gepinnter Stand der abgeleiteten Dokumente (schreibgeschützt).

## Geltende Einheiten

- Geld: ganzzahlige Cent. Nachkommastellen entstehen nur an der Lesegrenze und werden dort nach Cent überführt.
- Zeit: ganzzahlige Minuten.
- Wirtschaftsjahr: ausschließlich 2026.

## Grenzen

- Die Clean-Room-Basis selbst liegt nicht in diesem Repository.
- Es gibt keine zweite, redaktionell gepflegte Regelquelle; Regeln werden über ihre IDs referenziert, nicht umformuliert.
- Es werden keine Formeln, keine Neuberechnung der Basis und kein Engine-Code übernommen.
- Die Paritäts-Fixture ist die einzige Grundlage der Portal-Unit-Tests.
- Parität gilt als `covered` erst mit Smoke- und Playwright-Referenz; die externe steuerliche Prüfung bleibt dokumentierter Vorbehalt, kein Blocker.
- In portal-eigenem Code, Dokumentation, Commits und GitHub-Kommentaren wird ausschließlich die Bezeichnung „Clean-Room-Basis" verwendet. Der gepinnte Dokumentenstand ist ein unveränderlicher Fremdartefakt und davon ausgenommen.
