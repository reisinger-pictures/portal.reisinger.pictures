# Clean-Room-Parität als JSON-Artefakt (Wirtschaftsjahr 2026)

Vorbereitung für `portal.reisinger.pictures` #7 (EÜR-Integration) bzw. #10
(versionierte Kompatibilitätstestvektoren). Hinweis: Portal-#17 sind die
vertagten Backup-/Deployment-Gates; die Paritätsvektoren gehören zu #10.

## Grundsatz

**Das JSON ist das Artefakt.** Die Clean-Room-Basis bleibt eine lokale Eingabe und wird
nicht versioniert (der Pfad ist gitignored). Das
JSON enthält für jedes Szenario die **Eingabezellen** und die **erwarteten
Ergebniszellen** — als gecachter Referenzwert aus der Clean-Room-Basis *und* als kanonischer Ganzzahlwert
(Cent bzw. Minuten).

```
verification/fixtures/cleanroom-parity-2026.json   <- committetes Artefakt
tools/cleanroom_parity.py                          <- Generator + Prüfer
prototype/backend/tests/smoke/cleanroom-parity.php <- Engine-Parität
```

## Ablauf

1. **Extrahieren** (nur bewusst, bei Update der Clean-Room-Basis):

   ```bash
   python3 tools/cleanroom_parity.py extract
   ```

   Liest die gecachten Formelergebnisse aus `local/input.ods`, schreibt den
   SHA-256 der Clean-Room-Basis und die Szenarien in die JSON-Datei.

2. **JSON → Clean-Room-Basis → Formeln → Prüfung** (der eigentliche Paritätstest):

   ```bash
   python3 tools/cleanroom_parity.py verify
   ```

   Prüft zuerst den Hash der Clean-Room-Basis, kopiert die Clean-Room-Basis, schreibt die JSON-Eingaben
   per UNO in die Kopie, ruft `calculateAll()` auf und vergleicht alle
   erwarteten Zellen mit den neu berechneten Werten. Bei Abweichung exit ≠ 0.

3. **Engine-Parität** (ku-online gegen dieselben JSON-Vektoren):

   ```bash
   cd prototype/backend && php tests/smoke.php
   ```

   Der Include `tests/smoke/cleanroom-parity.php` vergleicht die kanonischen Werte
   mit `SvYearTable(2026)`, `EstYearTable(2026)` und `Money::businessShare()`.

## Update-Erkennung

Der `basis.sha256` im JSON ist ein hartes Gate: ändert sich die Clean-Room-Basis, bricht
`verify` mit `CLEAN ROOM BASIS CHANGED` ab. Die Fixture muss dann bewusst neu
extrahiert werden. Damit sind Basis und Artefakt nicht still auseinander-
gelaufen. `python3 tools/cleanroom_parity.py hash` zeigt den aktuellen Hash.

## Voraussetzungen

- LibreOffice Calc und `python3-uno` (Recalc der Clean-Room-Basis mit Array-Formeln).
- `formulas` (reines Python) kann diese Clean-Room-Basis **nicht** parsen und wird
  nicht verwendet.
- Python-Standardbibliothek (`zipfile`, `xml.etree`) für die Extraktion der Clean-Room-Basis.

## Enthaltene Vektoren (19 Zellen)

| Szenario | Bereich/Zellen | Engine-Ziel |
|---|---|---|
| `sv-constants-2026` | `Einstellungen!I57:I66` | `SvYearTable::forYear(2026)` (EUE-014…022) |
| `est-constants-2026` | `E1A!J6:J7` | `EstYearTable::forYear(2026)` (EUE-096/097) |
| `business-share-100` | `A!L3 → Q3` | `Money::businessShare(5880, 100)` |
| `expense-totals-2026` | `Ü!F5/G5`, `E1A!K4`, `BA-ART!E18`, `ZA!E10` | End-to-End: Ausgabe → Summen, Vorzeichen |
| `hours-to-minutes` | `Stunden!F3 → Projekte!D78` | 3 h → 180 Minuten (Ganze-Zahl-Konvention) |

Die Werte liegen im JSON zweimal vor: `reference_value` und `inputs` stehen in
Basiseinheiten der Clean-Room-Basis (EUR-Float, Rate oder Stunden-Float; für
den Recalc-Vergleich), `canonical` ist der Integer-Cent, die ganze Minute bzw.
der exakte Raten-Bruchteil (für den Engine-Vergleich). Alle Szenarien sind auf
2026 beschränkt.

## Orakel (unabhängige Ergebnisquelle)

Das JSON enthält zusätzlich einen `oracle`-Abschnitt. Als Orakel dient der
autoritative Regelkatalog `business-rules/domain-rules.json` — ein unabhängig
aus der Clean-Room-Basis extrahiertes Artefakt, nicht der Generator selbst.
`extract` gleicht jeden kanonischen Wert mit der 2026-Regelkonstante ab und
vermerkt `matched`, `no-rule-constant` (reiner Szenariowert) oder `MISMATCH`.
`verify` bricht bei `MISMATCH` ab; der Smoke-Test prüft das Orakel zusätzlich
gegen die Engine. Aktuell: **12 Regelkonstanten gematcht, 7 Szenariowerte,
0 Mismatch**.

## Übergabepaket

`tools/build_handoff.py build` erzeugt `handoff.zip` ausschließlich aus diesen
Clean-Room-Dokumenten plus Test-Eingabe/-Ausgabe: Regeln, Schema, Registry,
Regelkatalog, Paritätsvektoren und Prüfberichte. `MANIFEST.json` enthält den
gepinnten Commit und SHA-256-Prüfsummen; `verify` erkennt geänderte, fehlende
oder nachträglich hinzugefügte Dateien und vergleicht das Paket mit dem Repo.
Die Basis selbst, Recalc-Helper und Engine-Code sind ausgeschlossen. Das Paket
ist die einzige Grundlage für die Portal-Entwicklung.

Die unabhängige Prüfung aller 131 Domänenregeln gegen **alle 16 Bereiche der Clean-Room-Basis**
(inklusive Vollständigkeitsmatrix) steht in
[`ods-rule-verification.md`](ods-rule-verification.md): 131/131 Regeln mit
Beleg in der Clean-Room-Basis, 0 Abweichungen.

## Erweiterung

Neue Vektoren: Eintrag in `BLUEPRINT` in `tools/cleanroom_parity.py`, dann
`extract` und `verify`. Der zugehörige Engine-Zugriff kommt in die `$actual`-Map
in `prototype/backend/tests/smoke/cleanroom-parity.php`. Neue Dateien bleiben
unter 400 Zeilen (Ratchet).

**Wichtig bei Updates der Clean-Room-Basis:** `extract` übernimmt
`reference_value` aus der Basis, aber `canonical` aus dem `BLUEPRINT`
(Cent/Minuten sind eine bewusste Interpretation). Wenn sich ein autoritativer
Wert ändert, müssen `canonical` im Blueprint **und** der Engine-Zugriff im
PHP-Test angepasst werden. Drei Wächter greifen ineinander: der Hash der Basis
deckt die Clean-Room-Seite ab, das Orakel den Regelkatalog, der PHP-Test die
Engine.
