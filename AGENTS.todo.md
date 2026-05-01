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

- [x] **Docker:** PHP-Image anpassen und `intl`-Extension permanent aktivieren.
- [x] **Clean-up:** Statisches Fallback-Array in `ImportLocations.php` entfernt (`\Locale` wird nun nativ genutzt).
- [ ] **Refactor:** Slug-Kollisions-Logik in `GalleryController` von `time()` auf iterative Suffixe (z.B. `-1`, `-2`)
  umstellen.
- [ ] **Database:** In der nächsten Migration (z.B. V014) sicherstellen, dass die UI-Flags `is_hidden`, `is_free_download`, `is_editorial_only` explizit als `boolean` mit `default(false)` migriert werden, um Nullable-Inkonsistenzen zu vermeiden.

## 🐛 Nächste Fehlerbehebungen (Robustheit)

- [x] **Error Handling:** `Carbon::parse` in `GalleryController` absichern (Validierungsfehler statt Exception).
- [x] **Error Handling:** `ZipArchive` in `ImportLocations.php` mit Fehlerprüfungen ausstatten (Prüfung von `open` und
  `extractTo`).

## 🧪 Ausstehende Tests

- [x] **Backend Test (Locations):** Verifizieren des Joins in `ImportLocations` (PLZ + Einwohner) und
  Population-Ranking.
- [x] **Backend Test (Stress-Keywords):** Speichern von IPTC-Keywords > 255 Zeichen validieren.
- [x] **Backend Test (Gallery Updates):** Validierung der Slug-Kollisions-Logik und `expires_at` Konvertierung.
- [x] **E2E Test (Transient Rights):** Magic-Link-Gäste Speichervorgänge und Versionierung prüfen. *(Wird bereits vollständig durch Flow A in photo-management.spec.ts validiert)*
- [x] **Frontend Tests:** E2E Roundtrip-Tests für alle UI-Flags in Modalen (Gallery & Group) über FormHelper und Backend-API Validierung erstellt.
## 🧹 E2E Testing & State-Management (Neu)

- [x] **E2E Teardown Erweitern:** `E2ESessionHelper.ts` anpassen, kaskadierendes Löschen von Orders und Statements integriert.
- [x] **E2E Security Tests:** Magic-Link Authentifizierung mit Passwort-Prompt abgedeckt.
- [~] **E2E Stripe Webhooks:** VERWORFEN. Keine lokalen Test-Routen (`/test/...`) in die Production-API mischen. Logik ist im Backend via `DisputeAccessTest.php` ausreichend abgesichert.
