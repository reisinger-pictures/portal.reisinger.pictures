# 📝 Projekt-Master-Backlog

Aktuelle DB Version: 13

## Kürzlich abgeschlossen
- [x] Formular-UI auf Standardgröße 'md' und CSS-Grids umgestellt.
- [x] Lightroom Plugin Upload-Logik konsolidiert.
- [x] Backend Bugfixes: `GalleryController` & `ImageProcessor`.
- [x] Frontend UX: Grid-Konflikte (`md:w-1/2` in CSS-Grids) in Modals behoben.

## Nächste Schritte (Testing & Stabilisierung)
- [ ] **Frontend Tests:** E2E Roundtrip-Tests (UI -> DB -> UI) für GalleryModal und GalleryGroupModal implementieren, die sicherstellen, dass ALLE Formularfelder (inkl. is_editorial_only, is_hidden) korrekt gespeichert und beim erneuten Bearbeiten wieder korrekt in die UI geladen werden.
- [ ] **Backend Tests:** `GalleryControllerTest` (bzw. entsprechende Feature-Tests) um Validierung für Passwort-Hashing und Galerie-Updates erweitern.
- [ ] **Backend Tests:** `ImageProcessorTest` um Wasserzeichen-Kachel-Generierung (mit ImagickDraw `setCompositeOperator`) erweitern, um künftige "Blindflüge" zu vermeiden.
- [ ] **Frontend Tests:** E2E/UI-Tests für die Formular-Darstellung optimieren (z.B. Playwright Visual Regression oder Check auf Sichtbarkeit von Rand-Elementen).
