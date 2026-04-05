# 📝 Backlog / Next Sprint

## SYSTEM A: Technical Refactoring & Tooling
- [ ] **Lightroom Plugin Validation:** Ensure the plugin uploads strictly master files (leaving scaling to the server) and correctly passes `is_editorial_only` and `is_hidden` boolean flags in the API payload.

## SYSTEM B: Portal Logic & Features (Single-Tenant)
- [ ] **Pricing Matrix & Delta-Pricing:** DB-Tabelle für Preisfaktoren erstellen. Logik: Neuer Preis - Bezahlter Preis = Delta. Checkout-Bypass bei Delta <= 0.
- [ ] **Hierarchical Governance:** `Inherit | Force True | Force False` Logik in Gruppen, Galerien und Fotos implementieren. Rekursiver Accessor für effektiven Status.
- [ ] **Accounting Data Models & Documents:** Append-only `invoice_snapshots` Tabelle erstellen. Pessimistic Locking für `P-YYYY-NNNN` Sequenzer. Blade-Templates (AT 2025 Standard).
- [ ] **Email Queues:** Umstellung des Mail-Versands auf Laravel Queues inkl. BCC-Logik für die Buchhaltung.
- [ ] **Storage Lifecycle & Cache Registry:** 7-Tage Downscale-CRON für Master-Files. Hit-Tracking Drosselung via Cache (max. 1 Write pro 24h/Asset).
- [ ] **QA Enforcement:** `maxFailures: 2` in `playwright.config.ts` setzen. CI-Linting gegen `{ force: true }` einführen.

## UI Test-Abdeckung & Qualität
- [ ] **Authentic User Behavior (Rest-Cleanup):** Verbleibende Tests (`metadata.spec.ts`, `photographer.spec.ts`, `photoswipe.spec.ts`) auf SWR-native `toBeVisible` Asserts umstellen (Entfernung `page.reload()`).
- [ ] **E2E-Tests: Struktur-Management (Flow D):** Löschen von Galerien implementieren.
- [ ] **E2E-Tests: Admin-Features (Flow G & H):** Domain-Mapping Lifecycle und Wasserzeichen-Slider testen.
- [ ] **E2E-Tests: Monitoring (Flow I & J):** RatingStatusModal und FTP-Zielzuweisung testen.

## PHPUnit Test-Abdeckung (Backend)
- [ ] **PHPUnit: Flow B (Bildlöschung):** Test für `PhotoController@destroy` (DB & Filesystem-Cleanup).
- [ ] **PHPUnit: Flow E (Invite Lifecycle):** Tests für `InviteController` inkl. IDOR-Prüfung.
- [ ] **PHPUnit: Flow G (Domain Mapping):** Integrationstest für `DomainMappingController`.
- [ ] **PHPUnit: Flow H (Watermark):** Test für `SettingsController@updateWatermark` (Validierung & Cache-Purge).
- [ ] **PHPUnit: Ordner-Management (Flow N & O):** Tests für `updateGroup` (Slug-Check) und `deleteGroup` (Cascade-to-Root).

## UI/UX Bugs & Refactoring
- [ ] **Slug Auto-Fill:** Visuelle Aktualisierung des Slugs in Echtzeit während der Namenseingabe robuster machen.
- [ ] **Global Error Handling:** `api.ts` mit Interceptor erweitern, der bei 500er Fehlern oder Netzwerkverlust globale Toasts via `UIContext` auslöst.

## Architektur & Refactoring Tasks
- [ ] **Phase 2: Heavy Component Extraction:** Management- vs. Client-Logik in getrennte Chunks aufteilen (Vite Code-Splitting).
- [ ] **Phase 4: Verification:** Build-Size Analyse vor und nach dem Splitting durchführen.
