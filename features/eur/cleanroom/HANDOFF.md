# Clean-Room-Handoff (Wirtschaftsjahr 2026)

Dieses Paket enthält ausschließlich abgeleitete Clean-Room-Dokumente sowie
Test-Eingaben und -Erwartungswerte. Es ist die einzige Grundlage für die
Portal-Entwicklung; die lokale Clean-Room-Basis und jeglicher Engine-Code
bleiben im privaten Ausgangsprojekt.

## Inhalt

- `MANIFEST.json` — Version, gepinnter Commit und SHA-256 aller Dokumente
  sowie von `HANDOFF.md`.
- Regeln: `domain-rules.json`, `domain-rules.schema.json`, `rules.json`, `DOMAIN.md`
  (131 Regeln, 11 Bereiche, 26 jahresabhängig).
- Paritätsvektoren: `cleanroom-parity-2026.json` — 16 Szenarien, 45 Zellen;
  mindestens ein Szenario je Fachbereich B01–B11 und alle 26 jahresabhängigen
  Regeln mit 2026-Wert. Das Orakel weist `mismatch: 0` aus.
  `inputs` und `reference_value` stehen in Basiseinheiten (EUR, Rate, Stunden);
  `canonical` ist der ganze-Cent-/Minuten- bzw. exakte Ratenwert für den
  Engine-Vergleich. Szenarien ohne eigene Regel-ID sind als `SCENARIO`
  gekennzeichnet und prüfen zusammengesetzte Werte (z. B. Summen).
  Abgeleitete Regelzellen nennen im Feld `parameter` den gepinnten Parameternamen
  und im Orakel `oracle_value` den gepinnten Wert; die USt-Zahllast (EUE-086)
  wird über die portal-eigene `VatCalculator`-Kette aus dem ersten Teilsatz des
  gepinnten Mehrratenwerts abgeleitet. Die Herkunft steht in
  [`PROVENANCE.md`](PROVENANCE.md).
- Verfahren und Nachweise: `cleanroom-parity.md`, `ods-rule-verification.md`,
  `ods-coverage-matrix.md`, `ods-evidence-by-sheet.md`
  (131/131 Regeln mit Beleg, 0 Abweichungen) und `re-verification.md`
  (unabhängige Zweitprüfung derselben 131 Regeln).

Basiskennung: die aktuelle Clean-Room-Basis ist `local/input.ods`
(SHA-256 cf5060fb11aab96d692f44a1a0c6da1c583388322a110fbb15c943d817e90790);
sie ist die Nachfolgerin der früheren Eingabedatei `local/input.xlsx`
(SHA-256 38fe51cd…), die nur historisch in älteren Berichten erscheint.

## Verwendung im Portal

- Die Regeln sind die einzige fachliche Quelle. Keine zweite, redaktionell
  gepflegte Regelquelle im Portal; Änderungen werden über den gepinnten Commit
  und die Prüfsummen nachgezogen.
- Die Vektoren werden als Unit-Test-Fixtures konsumiert — ohne Formeln, ohne
  Neuberechnung und ohne Code aus dem Ausgangsprojekt.
- Beträge sind ganzzahlige Cent; Zeitmengen ganzzahlige Minuten.
- Nur Wirtschaftsjahr 2026 ist für die Integration relevant.

## Nicht enthalten

Lokale Basisdateien, Berechnungs-Helper, Prüf-Engine, Frontend- oder
Datenbankcode. Das Paket ist schreibgeschützt zu behandeln.
