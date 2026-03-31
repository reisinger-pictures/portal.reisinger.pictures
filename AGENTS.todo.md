# 📝 Backlog / Next Sprint

- [x] Test Refactoring: Extracted modal saving into `submitModal` utility and fixed flaky locator in `structure.spec.ts`. (Ready for Review)
- [x] Fixed SVG watermark persistence in rclone sync and removed /ftp/ prefix from user FTP paths. (Ready for Review)
- [x] Fixed E2E test `communication.spec.ts` (Vorschau anzeigen checkbox) via direct input interaction in `ModalHelper`. (Ready for Review)

## UI Test-Abdeckung erhöhen (Coverage Gaps)
- [ ] **Refactoring: Authentic User Behavior in Tests:** Alle Tests (insbesondere `metadata.spec.ts`, `photographer.spec.ts` und `structure.spec.ts`) bereinigen und harte `page.reload()` Aufrufe durch "Geduldiges Warten" auf SWR-Updates ersetzen.
- [ ] **E2E-Tests: Bildverwaltung (Flow A & B):** PhotoHistoryModal und Bildlösch-Funktion aus der Detailansicht testen.
- [ ] **E2E-Tests: Struktur-Management (Flow C & D):** Löschen von Gruppen (mit Fallback in Root) und Löschen von Galerien implementieren.
- [x] **E2E-Tests: Kommunikation & Errors (Flow E, F & L):** Link-Widerruf, Email-Vorschau (fixer Text + Variablen) und den **Invalid-Link-Logged-In** Edge Case absichern.
- [ ] **E2E-Tests: Admin-Features (Flow G & H):** Domain-Mapping Lifecycle und Wasserzeichen-Slider in den Settings testen.
- [ ] **E2E-Tests: Monitoring (Flow I & J):** `RatingStatusModal` rendern und FTP Ziel-Galerie Zuweisung auf dem Dashboard testen.

## PHPUnit Test-Abdeckung erhöhen (Backend)
- [ ] **PHPUnit: Ordner bearbeiten (Flow N):** Test für `updateGroup` (inkl. Slug-Kollisionsvermeidung und Auth-Checks) implementieren.
- [ ] **PHPUnit: Ordner löschen (Flow O):** Test für `deleteGroup` (Prüfung der DB-Cascade-to-Root Logik) implementieren.

## UI/UX Bugs (Frontend)
- [ ] **Slug Auto-Fill:** Der Slug in `GalleryModal` und `GalleryGroupModal` wird beim Tippen des Namens visuell nicht zuverlässig aktualisiert. Das muss robuster gemacht werden.
- [ ] **Global Error Handling:** Wenn `apiMutate` einen unerwarteten Fehler wirft, gibt das UI nur generische "Fehler beim Speichern" Toasts aus. Die API-Utility muss Fehler besser parsen.