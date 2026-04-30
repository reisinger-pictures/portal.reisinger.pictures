# 📝 Projekt-Master-Backlog

Aktuelle DB Version: 14

## ✅ Abgeschlossene Fixes (Kürzlich)

- [x] **Bugfix (Backend - ImportLocations):** `ImportLocations.php` umschreiben. Joins zwischen `AT.zip` (PLZ) und
  `AT.txt` (Einwohner) implementiert.
- [x] **Bugfix (Backend - ImportLocations):** Statischer Fallback für Ländernamen integriert (Überbrückung fehlendes
  `intl`).
- [x] **Bugfix (Backend - SearchController):** Sekundäre Sortierung (`postal_code`) und erweitertes
  Deduplizierungs-Logik (`name` + `state`).
- [x] **Bugfix (Backend - GalleryController):** Validierungs-Whitelist um `slug` und `expires_at` erweitert (Fix für
  PDOException).
- [x] **Bugfix (Backend - DB/Validation):** IPTC-Keywords auf `TEXT` migriert (Migration `V013`) und `max:255` Limit
  aufgehoben.

## 🏗️ Infrastruktur & Technical Debt

- [ ] **Docker:** PHP-Image anpassen und `intl`-Extension permanent aktivieren.
- [ ] **Clean-up:** Statisches Fallback-Array in `ImportLocations.php` entfernen, sobald `intl` im Image vorhanden ist.
- [ ] **Refactor:** Slug-Kollisions-Logik in `GalleryController` von `time()` auf iterative Suffixe (z.B. `-1`, `-2`)
  umstellen.

## 🐛 Nächste Fehlerbehebungen (Robustheit)

- [ ] **Error Handling:** `Carbon::parse` in `GalleryController` absichern (Validierungsfehler statt Exception).
- [ ] **Error Handling:** `ZipArchive` in `ImportLocations.php` mit Fehlerprüfungen ausstatten (Prüfung von `open` und
  `extractTo`).

## 🧪 Ausstehende Tests

- [ ] **Backend Test (Locations):** Verifizieren des Joins in `ImportLocations` (PLZ + Einwohner) und
  Population-Ranking.
- [ ] **Backend Test (Stress-Keywords):** Speichern von IPTC-Keywords > 255 Zeichen validieren.
- [ ] **Backend Test (Gallery Updates):** Validierung der Slug-Kollisions-Logik und `expires_at` Konvertierung.
- [ ] **E2E Test (Transient Rights):** Magic-Link-Gäste Speichervorgänge und Versionierung prüfen.
- [ ] **Frontend Tests:** E2E Roundtrip-Tests für Modale (is_editorial_only, is_hidden Speicherung).