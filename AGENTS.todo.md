# 📝 Backlog / Next Sprint

- [x] Test Refactoring: Extracted modal saving into `submitModal` utility and fixed flaky locator in `structure.spec.ts`.
- [x] Fixed SVG watermark persistence in rclone sync and removed /ftp/ prefix from user FTP paths.
- [x] Fixed E2E test `communication.spec.ts` (Vorschau anzeigen checkbox) via direct input interaction in `ModalHelper` and validated prose content.
- [x] **Refactoring: Authentic User Behavior in Tests:** `page.reload()` Aufrufe in `photoswipe.spec.ts`, `communication.spec.ts` und `structure.spec.ts` entfernt und durch SWR-native `toBeVisible` Asserts ersetzt.

## UI Test-Abdeckung erhöhen (Coverage Gaps)
- [ ] **Authentic User Behavior (Rest-Cleanup):** Verbleibende Playwright-Tests (`metadata.spec.ts`, `photographer.spec.ts`) auf das neue SWR-Warte-Schema umstellen.
- [ ] **E2E-Tests: Bildverwaltung (Flow A & B):** PhotoHistoryModal und Bildlösch-Funktion aus der Detailansicht testen.
- [x] **E2E-Tests: Struktur-Management (Flow C):** Löschen von Gruppen (mit Fallback in Root) implementiert und auf "Authentic Behavior" refactored.
- [ ] **E2E-Tests: Struktur-Management (Flow D):** Löschen von Galerien implementieren.
- [x] **E2E-Tests: Kommunikation & Errors (Flow E, F & L):** Link-Widerruf, Email-Vorschau (fixer Text + Variablen) und den **Invalid-Link-Logged-In** Edge Case abgesichert.
- [ ] **E2E-Tests: Admin-Features (Flow G & H):** Domain-Mapping Lifecycle und Wasserzeichen-Slider in den Settings testen.
- [ ] **E2E-Tests: Monitoring (Flow I & J):** `RatingStatusModal` rendern und FTP Ziel-Galerie Zuweisung auf dem Dashboard testen.

## PHPUnit Test-Abdeckung erhöhen (Backend)
- [ ] **PHPUnit: Flow B (Bildlöschung):** Test für `PhotoController@destroy` (Prüfung DB & physische Löschung aller Thumbnail-Varianten).
- [ ] **PHPUnit: Flow E (Invite Lifecycle):** Tests für `InviteController` (Generate, Update, Delete) inkl. IDOR-Prüfung.
- [ ] **PHPUnit: Flow G (Domain Mapping):** Integrationstest für den `DomainMappingController`.
- [ ] **PHPUnit: Flow H (Watermark):** Test für `SettingsController@updateWatermark` (Validierung & Cache-Purge).
- [ ] **PHPUnit: Flow J (FTP Import):** Tests für `FtpController@setTarget` und `process`.
- [ ] **PHPUnit: Ordner bearbeiten (Flow N):** Test für `updateGroup` (inkl. Slug-Kollisionsvermeidung und Auth-Checks).
- [ ] **PHPUnit: Ordner löschen (Flow O):** Test für `deleteGroup` (Prüfung der DB-Cascade-to-Root Logik).

## UI/UX Bugs (Frontend)
- [ ] **React Query Migration (Core):** `useGalleries.ts` und `useGallery.ts` auf `useQuery`/`useMutation` umstellen, um die 30s-Timeouts in `communication.spec.ts` und `photo-management.spec.ts` zu beheben.
- [x] **React Query Migration (Rest):** Alle restlichen SWR Imports entfernt und auf useQuery/useInfiniteQuery umgestellt.
- [x] **Bug Fix (E2E Timeouts):** SWR Cache-Flakiness in `communication.spec.ts` (beforeEach) und `photo-management.spec.ts` (Flow B) durch deterministisches React-Query Warten behoben.
- [ ] **Slug Auto-Fill:** Der Slug in `GalleryModal` und `GalleryGroupModal` wird beim Tippen des Namens visuell nicht zuverlässig aktualisiert. Das muss robuster gemacht werden.
- [ ] **Global Error Handling:** Wenn `apiMutate` einen unerwarteten Fehler wirft, gibt das UI nur generische "Fehler beim Speichern" Toasts aus. Die API-Utility muss Fehler besser parsen.

## Architektur & Refactoring Tasks

**Phase 1: Route Refactoring**
* Locate the main router file (e.g., App.tsx or routes.tsx).
* Convert static imports of Page components into dynamic imports using React Router’s lazy property or React.lazy().
* Ensure every route is wrapped in a `<Suspense>` boundary with a meaningful fallback (Skeleton UI or Spinner).

**Phase 2: Heavy Component Extraction**
* Identify components that use large 3rd-party libraries (e.g., Chart.js, Lottie, or RichTextEditor).
* Move these into their own files and import them lazily only where they are rendered.

**Phase 3: Vite Configuration**
* Modify `vite.config.ts` to use `build.rollupOptions.output.manualChunks`.
* Split `node_modules` into a separate vendor chunk to improve long-term caching.

**Phase 4: Verification**
* Run `npm run build` and provide a summary of the bundle sizes before and after the changes.