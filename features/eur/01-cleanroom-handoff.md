# Clean-Room-Übergabe: Fixture-Version und Update-Erkennung

**Status:** gültig für Wirtschaftsjahr 2026

## Gepinnter Stand

`cleanroom/` ist ein byte-exakter, schreibgeschützter Abzug der abgeleiteten
Dokumente der Clean-Room-Basis. `cleanroom/MANIFEST.json` ist die einzige
Versionsangabe und pinnt:

| Feld | Bedeutung |
|---|---|
| `schema_version` | Version des Manifest-Formats |
| `business_year` | einzig relevantes Wirtschaftsjahr: 2026 |
| `source_commit` | Identifikator des festgehaltenen Stands der Clean-Room-Basis |
| `handoff_sha256` | Prüfsumme von `cleanroom/HANDOFF.md` |
| `files[]` | Name, SHA-256 und Byte-Anzahl jedes einzelnen Dokuments |

Das Pinning wird nicht redaktionell nachgezogen. Ein Update entsteht
ausschließlich durch einen neuen, geprüften Dokumentenstand.

## Provenienz

Das Übergabepaket wird als Anlieferungsbeleg lokal unter `handoff/` vorgehalten
und ist nicht Teil der Versionierung. Kanonisch und checksummengeprüft ist
ausschließlich `cleanroom/`.

## Was gilt

- Der Regelkatalog unter `cleanroom/` ist die einzige fachliche Quelle. Regeln
  werden im Portal nicht in einer zweiten Datei gepflegt; fachliche Bezüge
  laufen über die Regel-IDs.
- Die Paritäts-Fixture `cleanroom/cleanroom-parity-2026.json` ist die einzige
  Grundlage für die Portal-Unit-Tests. Sie enthält `inputs` in Basiseinheiten
  und `expected[].canonical` als ganze Cent, ganze Minuten bzw. exakten
  Raten-Bruchteil.
- Es werden keine Formeln, keine Neuberechnung und kein Code aus der
  Clean-Room-Basis übernommen. Es gibt keinen direkten Vergleich gegen die
  Basis im Portal-Repo.
- Beträge sind ganzzahlige Cent, Zeitmengen ganzzahlige Minuten. Relevant ist
  nur das Wirtschaftsjahr 2026.

## Update-Erkennung

1. **Prüfsummen:** Jede Datei in `cleanroom/` gegen `files[].sha256` aus dem
   Manifest prüfen, zusätzlich `HANDOFF.md` gegen `handoff_sha256`.
2. **Version:** `business_year`, `schema_version`, `source_commit` sowie die
   Anzahl der Szenarien und Zellen sind Teil des Vertrags.
3. **Neuer Stand:** Geänderte Prüfsummen oder ein geänderter Vektorsatz
   bedeuten eine neue Fixture-Version. Der Diff der Vektoren wird geprüft,
   Implementierung und Tests werden bewusst nachgezogen; bis dahin bleibt der
   Test rot.

Reproduzierbare Prüfung (Python-Standardbibliothek):

```sh
python3 - <<'PY'
import hashlib, json, pathlib

root = pathlib.Path("features/eur/cleanroom")
manifest = json.loads((root / "MANIFEST.json").read_text())

def digest(path):
    return hashlib.sha256(path.read_bytes()).hexdigest()

failures = [e["name"] for e in manifest["files"]
            if digest(root / e["name"]) != e["sha256"]]
if digest(root / "HANDOFF.md") != manifest["handoff_sha256"]:
    failures.append("HANDOFF.md")

fixture = json.loads((root / "cleanroom-parity-2026.json").read_text())
if manifest["business_year"] != 2026 or fixture["oracle"]["year"] != 2026:
    failures.append("business_year")
if len(fixture["scenarios"]) != 5 or sum(len(s["expected"]) for s in fixture["scenarios"]) != 19:
    failures.append("vector_count")

print(f'{manifest["file_count"]} documents, {len(failures)} failures', failures)
raise SystemExit(1 if failures else 0)
PY
```

Dieselbe Prüfung läuft als Unit-Test: `php artisan test --filter=CleanRoomFixtureIntegrityTest`.

## Abnahme und Vorbehalte

- Feste Vektoranzahl: 5 Szenarien / 19 Zellen. Eine Änderung der Anzahl ist eine Vertragsänderung.
- Wirksamkeit: Ein absichtlich veränderter Erwartungswert muss den Test rot werden lassen.
- Keine Kopie der Clean-Room-Basis und kein direkter Vergleich im Portal-Repo.
- Parität „covered" erst mit Smoke- und Playwright-Referenz.
- Die externe steuerliche Prüfung bleibt ein dokumentierter Vorbehalt, kein Blocker.
