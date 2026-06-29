# Meilisearch — Typo-Toleranz (SOLL/Ist-Stand)

> **Status:** Beschreibt den **Stand des Systems** nach dem Typo-Toleranz-Fix (2026-06-29).
> Kein Umsetzungsplan. Verknüpft: `features/search/01-search-and-discovery.md`.
> Zugehörige Aufgabe: `AGENTS.todo.md` M-01.

## 1. Stand

Die Foto-/Galerie-Suche nutzt **Meilisearch** (via Laravel Scout, `SCOUT_DRIVER=meilisearch`).
Typo-Toleranz ist konfiguriert in `backend/config/scout.php` (Abschnitt `index-settings`):

- **Photo:** explizite `searchableAttributes` (`title`, `keywords`, `headline`, `description`,
  `artist`, `city`, `location`, `state`, `country`) + `typoTolerance` aktiv, mit
  `minWordSizeForTypos: oneTypo=4, twoTypos=8`.
- **Gallery:** `searchableAttributes` = `['name']` + analoge `typoTolerance`.

Daraus folgt: ab **4 Zeichen** Wortlänge korrigiert Meilisearch 1 Tippfehler, ab 8 Zeichen 2
Tippfehler. Beispiel: „MountainPnaorama" findet „MountainPanorama".

## 2. Vorheriges Problem (behoben)

Bis 2026-06-29 hatte `Photo` in `scout.php` **nur** `filterableAttributes`, aber weder
`searchableAttributes` noch einen `typoTolerance`-Block. Konsequenz:
- Meilisearch leitet `searchableAttributes` aus den ersten indizierten Dokumenten ab — die
  Reihenfolge ist nicht deterministisch, IDs/UUIDs können dominieren und relevante Textfelder
  (`title`, `keywords`) in der Gewichtung zurückdrängen.
- `typoTolerance.enabled` ist zwar per Meilisearch-Default `true`, aber ohne explizite
  `searchableAttributes` greift die Typo-Korrektur nicht verlässlich für die gewünschten Felder.

## 3. Sync der Index-Settings

Damit Meilisearch die in `scout.php` definierten Settings übernimmt, muss der Sync-Befehl
ausgeführt werden (nach jeder Änderung an `index-settings`):

```bash
php artisan scout:sync-index-settings
```

Der Befehl liest `config('scout.meilisearch.index-settings')` und überträgt die Settings pro
Index an Meilisearch. In den Feature-Tests wird er in `SearchTest::setUp()` automatisch
aufgerufen (zusammen mit `scout:flush`).

## 4. Verifikation

- Test: `tests/Feature/SearchTest.php::test_search_is_typo_tolerant_for_photos` —
  „MountainPnaorama" findet „MountainPanorama". Nutzt das dortige `waitForSearchIndex()`-Helper,
  das ausstehende Meilisearch-Tasks abwartet.
- Aufruf: `cd backend && php artisan test --filter=SearchTest`.

## 5. Pflegehinweise

- Bei Erweiterung von `Photo::toSearchableArray()` um neue durchsuchbare Felder: diese auch in
  `config/scout.php` → `searchableAttributes` aufnehmen, sonst sind sie nicht typo-tolerant
  durchsuchbar.
- Nach jeder `scout.php`-Änderung `php artisan scout:sync-index-settings` ausführen.
- `SCOUT_QUEUE=false` (Dev-Default): Writes sind asynchron; frisch angelegte Dokumente sind evtl.
  erst nach kurzem Delay im Index. Tests warten daher via `waitForSearchIndex()`.
