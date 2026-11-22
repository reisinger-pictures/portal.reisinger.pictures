# Herkunft und Geltungsgrenze der kopierten Artefakte

**Status:** verbindlich · **Stand:** 2026-11-22

## Was hier liegt

`artifacts/` ist eine byte-exakte Kopie der Daten- und Spezifikationsartefakte aus
`ku-online@75f230c07010db91755f78fdbd9ae591d52c7e85`. Die Originalpfade bleiben
erhalten (`artifacts/analysis/tables.json`, `artifacts/prototype/docs/openapi.yaml`, …),
damit jedes Artefakt auf seinen Ursprung zurückführbar ist.

Die acht abgeleiteten Anforderungsdokumente unter
`artifacts/verification/requirements/` stammen aus `ku-online`, Stand der Antwort
auf `ku-online#45` (2026-11-22). Sie waren dort zum Kopierzeitpunkt noch nicht
committet; ihre SHA-256-Werte stimmen mit den in jenem Issue veröffentlichten
Prüfsummen überein:

| Dokument | SHA-256 |
|---|---|
| `assets-requirements.md` | `788424f0…` |
| `coverage-status.md` | `2fdab712…` |
| `data-model-requirements.md` | `b6501061…` |
| `deg-scope.md` | `13815e4c…` |
| `est-requirements.md` | `08cdd715…` |
| `travel-requirements.md` | `f617293e…` |
| `ui-requirements.md` | `03718141…` |
| `vat-requirements.md` | `0b22b22f…` |

Die Prüfsummen und Byte-Anzahlen stehen in [`MANIFEST.json`](MANIFEST.json) unter
`artifacts`; `artifact_count`, `artifact_version` und `artifact_source_commit` sind
Teil des Manifests. Eine inhaltliche Änderung eines Artefakts lässt
`CleanRoomFixtureIntegrityTest` rot werden, bis Version und Prüfsummen bewusst
nachgezogen sind.

## Warum kopiert wurde

Der ursprünglich gepinnte Handoff (`handoff.zip`, 12 Einträge) enthält nur die
Regeln und Paritätsvektoren. Die neun Portal-Issues #22–#30 verlangen zusätzliche
abgeleitete Artefakte, die in `ku-online` committet, aber nie in den Handoff
gebaut wurden. Der Eigentümer hat die Clean-Room-Grenze **für diesen einen
Kopierschritt** aufgehoben (Entscheidung dokumentiert in
[`../../owner-decisions.md`](../owner-decisions.md)).

Damit gilt ausdrücklich:

- Die frühere Aussage „keine Dateien aus dem Quellprojekt" ist für die
  Datenartefakte **aufgehoben**; sie bleibt für Engine-Code, Formeln, Rohdumps und
  das Quellschema bestehen.
- Es gibt weiterhin **keine zweite Regelquelle**: Regeln kommen ausschließlich aus
  `domain-rules.json`, Werte ausschließlich aus den kopierten Artefakten.
- Die Basis wird nicht neu berechnet und nicht direkt verglichen.

## Was ausgeschlossen wurde

| Ausgeschlossen | Grund |
|---|---|
| `verification/tax-engine.md`, `vat-engine.md`, `travel-engine.md` | Engine-Integrationsnotizen mit Klassennamen, Methoden, Formelstrings und PHP |
| `prototype/db/schema.sql` | Quellschema; Issue #27 verbietet den Dump |
| `analysis/*.txt`, `rule_analysis.txt` | Rohdumps und Formelanalyse |
| `verification/domain-verification.md`, `independent-report.md`, `parity-report.md` | Zellbezüge und Formelstrings als Beleg |
| Jeglicher PHP/JS/SQL-Code, Frontend-Komponenten, Tests | Clean-Room-Grenze |

## Verbleibende Einschränkungen

- Drei kopierte Dateien enthalten Zellbezüge ausschließlich als **Annotation** in
  Beschreibungs- oder Testnamen (`prototype/docs/openapi/schemas.yaml`,
  `paths-settings-year-warning.yaml`, `verification/rule-coverage.json`). Sie sind
  kein Rechenpfad und werden vom Portal nicht ausgewertet.
- Die Paritätsvektoren bleiben bei 5 Szenarien / 19 Zellen. Die Erweiterung auf
  alle elf Fachbereiche und alle 26 jahresabhängigen Regeln ist als
  `ku-online#45` angefordert; bis dahin ist #23 blockiert.
- Die engine-nahen Anforderungsdokumente (ESt, USt, Reisen, AfA), das
  Datenmodell-Dokument und die fachlichen UI-Anforderungen fehlen weiterhin und
  sind ebenfalls in `ku-online#45` angefordert.
