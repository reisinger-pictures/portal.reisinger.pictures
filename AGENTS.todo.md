# 📝 Backlog / Next Sprint

## UI/UX Bugs & Refactoring
- [ ] **Global Error Handling:** `api.ts` mit Interceptor erweitern, der bei 500er Fehlern oder Netzwerkverlust globale Toasts via `UIContext` auslöst.

## Portal Logic & Features (Single-Tenant)
- [ ] **Pricing Matrix & Delta-Pricing:** DB-Tabelle für Preisfaktoren erstellen. Logik: Neuer Preis - Bezahlter Preis = Delta. Checkout-Bypass bei Delta <= 0.
- [ ] **Accounting Data Models & Documents:** Append-only `invoice_snapshots` Tabelle erstellen. Pessimistic Locking für `P-YYYY-NNNN` Sequenzer. Blade-Templates (AT 2025 Standard).
- [ ] **Email Queues:** Umstellung des Mail-Versands auf Laravel Queues inkl. BCC-Logik für die Buchhaltung.

## Technical Refactoring & Tooling
- [ ] **Lightroom Plugin Validation:** Ensure the plugin uploads strictly master files (leaving scaling to the server) and correctly passes `is_editorial_only` and `is_hidden` boolean flags in the API payload.

## UI Test-Abdeckung & Qualität
- [ ] **E2E-Tests: Meta-Galerie Opt-In:** Test schreiben für die Ansicht `/notifications`, in der Nutzer Meta-Galerien (Ordner) abonnieren können.
- [ ] **E2E-Tests: Struktur-Management (Flow D):** Löschen von Galerien implementieren.
- [ ] **E2E-Tests: Admin-Features (Flow G & H):** Domain-Mapping Lifecycle und Wasserzeichen-Slider testen.
- [ ] **E2E-Tests: Monitoring (Flow I & J):** RatingStatusModal und FTP-Zielzuweisung testen.

## PHPUnit Test-Abdeckung (Backend)
- [ ] **PHPUnit: Meta-Galerie Opt-In:** `NotificationOptInTest.php` erweitern, um `toggleGroupOptIn` und das korrekte Auflösen der Ordner-Hierarchie beim Mail-Versand zu verifizieren.
- [ ] **PHPUnit: Flow B (Bildlöschung):** Test für `PhotoController@destroy` (DB & Filesystem-Cleanup).
- [ ] **PHPUnit: Flow E (Invite Lifecycle):** Tests für `InviteController` inkl. IDOR-Prüfung.
- [ ] **PHPUnit: Flow G (Domain Mapping):** Integrationstest für `DomainMappingController`.
- [ ] **PHPUnit: Flow H (Watermark):** Test für `SettingsController@updateWatermark` (Validierung & Cache-Purge).
- [ ] **PHPUnit: Ordner-Management (Flow N & O):** Tests für `updateGroup` (Slug-Check) und `deleteGroup` (Cascade-to-Root).

## Architektur & Refactoring Tasks
- [ ] **Phase 2: Heavy Component Extraction:** Management- vs. Client-Logik in getrennte Chunks aufteilen (Vite Code-Splitting).
- [ ] **Phase 4: Verification:** Build-Size Analyse vor und nach dem Splitting durchführen.
