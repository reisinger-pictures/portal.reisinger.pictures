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

Das Artefakt `artifacts/verification/e1a-ausfuellhilfe.md` (Ausfüllhilfe je
Kennzahl, Abschnitt 3) wurde am 2026-11-22 ebenfalls byte-exakt aus
`ku-online/verification/e1a-ausfuellhilfe.md` übernommen. Es war zum
Kopierzeitpunkt noch nicht committet; seine SHA-256 ist
`d46a7f02fc53c083569befc158fc00a177c12f32a970619196365efe3861e37c`. Es folgt
demselben Muster wie die acht Anforderungsdokumente und ist mit der bewussten
Erhöhung auf `artifact_version 2026.3` in das Manifest aufgenommen.

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
- Die Paritätsvektoren sind lokal aus dem gepinnten `domain-rules.json`
  abgeleitet (Entscheidung D-70): 16 Szenarien / 45 Zellen, ein Szenario je
  Fachbereich B01–B11, alle 26 jahresabhängigen Regeln mit 2026-Wert,
  `mismatch: 0`. Jede abgeleitete Zelle nennt ihre EUE-ID und den gepinnten
  Parameternamen; `oracle_value` ist der unveränderte `wert` aus dem Katalog.
  Der Vektor für EUE-086 (`USt-Sätze`, `20/10/13/4,9 %`) ist aus dem ersten
  Teilsatz dieses gepinnten Werts und einem 10000-Cent-Eingabewert abgeleitet;
  die Ist-Wert-Kette ist der portal-eigene `VatCalculator`. Weitere Konstanten
  werden über `RuleValues::parameterFor` bzw. `canonicalFor` gelesen. Es wurden
  keine Formeln, Zellbezüge, Basiswerte oder Quellcode übernommen. Die
  Änderung ist mit `artifact_version 2026.4` und nachgezogenen Prüfsummen
  versioniert.
- Die engine-nahen Anforderungsdokumente (ESt, USt, Reisen, AfA), das
  Datenmodell-Dokument und die fachlichen UI-Anforderungen liegen als
  byte-exakte Kopien unter `artifacts/verification/requirements/` (siehe oben).
  Ein gesondertes Statusartefakt zum Paritätsstand ist nicht Teil dieses
  Handoffs; der Paritätsstand der Fixture steht in deren `oracle`-Block.
