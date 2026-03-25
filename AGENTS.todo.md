# Backlog & Task Management

### 🔍 Such-Feature & UX Refinement (Tests)
- [ ] TODO: PHPUnit - Search Authorization & Feed: `SearchTest.php` erweitern (Personal Feed Mode, strikte rollenbasierte Filterung in Meilisearch). -> [Link](features/search/01-search-and-discovery.md)
- [ ] TODO: E2E - Search UX & Header Dropdown: Live-Search Dropdown im `PageLayout.tsx` testen (ab 2 Zeichen, Dropdown öffnet/schließt). -> [Link](features/search/01-search-and-discovery.md)
- [ ] TODO: E2E - Search Mobile Interaction: Sicherstellen, dass das Suchfeld auf mobilen Viewports benutzbar ist. -> [Link](features/search/01-search-and-discovery.md)
- [ ] TODO: E2E - Dashboard Personal Feed: Verifizieren, dass Fotografen auf dem Dashboard ihren Feed korrekt sehen. -> [Link](features/search/01-search-and-discovery.md)
- [ ] TODO: E2E - Testing Guidelines: Sicherstellen, dass keine E2E- oder PHPUnit-Tests fehlschlagende Assertions in `try-catch`-Blöcken maskieren. -> [Link](features/tech/05-testing-guidelines.md)

### 🎨 Rollen & Ansichten-Trennung (UX für Fotografen)
- [ ] TODO: UI: In der `ManagementGalleryView` einen auffälligen Button "Zur Kundenansicht wechseln" einbauen. -> [Link](features/gallery/01-core-architecture.md)
- [ ] TODO: Routing: Angemeldete Fotografen/Admins müssen die `ClientGalleryView` aufrufen können (z.B. `?view=client`), ohne ins Management-Dashboard gezwungen zu werden. -> [Link](features/gallery/01-core-architecture.md)
- [ ] TODO: Bereinigung: Den Button "Details & Metadaten" (Bild öffnen) in Selection-Galerien auch für Admins komplett entfernen. -> [Link](features/gallery/01-core-architecture.md)

### ✉️ E-Mail System Bereinigung
- [ ] TODO: E-Mail System komplett auf Hardcoded Blade-Views umstellen. (Entfernt `EmailTemplate` Model, `EmailTemplateController`, DB-Tabelle und `ManagementMailTemplatesView`). Textfeld-Injektion für individuelle Nachrichten in das Blade-Layout einbauen inkl. Browser-Preview. -> [Link](features/infrastructure/02-email-system.md)

### 🏗️ Architektur-Updates & Refactorings (VOR DEM DEPLOYMENT)
- [ ] TODO: Frontend Refactoring (URL State): Pagination in `ManagementStatsView.tsx` muss den State via URL-Parameter (`?page=2`) anstatt via lokalem React-State abbilden. -> [Link](features/tech/04-frontend-architecture.md)
- [ ] TODO: Frontend Refactoring (Native Alerts): Alle nativen `alert()` und `window.confirm()` Aufrufe restlos durch `showToast()` und `confirm()` aus dem `UIContext` ersetzen. (Betrifft: `ManagementFtpInbox.tsx`, `EmailComposerModal.tsx`, `InviteModal.tsx`). -> [Link](features/tech/04-frontend-architecture.md)
- [ ] TODO: Backend Testing (Leak Prevention): PHPUnit Tests für API Endpunkte (z.B. Galerien, User) überarbeiten, sodass sie explizit via `assertJsonStructure` prüfen, dass keine ungewollten Attribute (wie `password_hash`) geleakt werden. -> [Link](features/tech/05-testing-guidelines.md)
- [ ] TODO: Backend (Commands): Konsolen-Befehl `php artisan admin:update` erstellen (liest `ADMIN_EMAIL`/`ADMIN_PASSWORD` aus `.env`, aktualisiert Admin inkl. Rollen). -> [Link](features/infrastructure/01-deployment.md)
- [ ] TODO: Backend: Datenbank-Primärschlüssel von Auto-Increment BIGINT auf UUIDs/ULIDs umstellen (inkl. Anpassung der `V001` Migration und aller Foreign Keys in Pivot-Tabellen und Logs). -> [Link](features/tech/01-database-schema.md)
- [ ] TODO: Auth/Backend: Magic Link Workflow refactoren. Keine Dummy-User mehr anlegen, sondern transiente JWT-Claims für den temporären Galerie-Zugriff ausstellen. -> [Link](features/auth/02-magic-links.md)
- [ ] TODO: Frontend/Auth: Einlöse-Workflow für Magic Links anpassen. Wenn bereits eingeloggt: Transiente Rechte für die aktuelle Session gewähren, ohne die Galerie permanent in die `user_galleries` Pivot-Tabelle zu schreiben. -> [Link](features/auth/02-magic-links.md)
- [ ] TODO: Photos/Backend: Thumbnail-Generierung auf "Lazy Generation" umbauen und Unterstützung für multiple Größen (`srcset`) hinzufügen, um Transferraten zu optimieren. -> [Link](features/photos/01-upload-and-processing.md)
- [ ] TODO: Feature Documentation: Check if markdown references are valid and no information is duplicated. Agents.md should be the entry point and introduce how to navigate the project on a high level. It should be concise and reference to other files / folder

### 🚀 Produktion & Deployment
- [ ] TODO: Deployment: `backend-init` Container in der `docker-compose.yml` aktualisieren, um sicherzustellen, dass die Init-Chain für Version 1.0 korrekt durchläuft (Datenbankbereitschaft, Migrationen, JWT-Key-Generierung). -> [Link](features/infrastructure/01-deployment.md)
- [ ] TODO: DevOps: Migration Policy definieren (Ab Prod-Deployment zwingend neue Migrationsdateien für Schema-Änderungen nutzen). -> [Link](features/tech/01-database-schema.md)