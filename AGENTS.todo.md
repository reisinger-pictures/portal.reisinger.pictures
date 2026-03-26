### ✉️ E-Mail System & Notifications (Testing)
- [ ] TODO: PHPUnit - E-Mail Opt-In Logik testen. Verifizieren, dass `finishRating` und `sendCustom` im `MailController` strikt nur an User (Kunden & Fotografen) senden, bei denen das `wants_notifications` Flag auf `true` gesetzt ist. -> [Link](features/infrastructure/02-email-system.md)
- [ ] TODO: E2E - E-Mail Versand & Notifications. Testen des UI Toggles im Management und Client Bereich. Senden einer manuellen E-Mail über das Modal und verifizieren des erfolgreichen E-Mail-Empfangs in der Mailpit API. -> [Link](features/tech/05-testing-guidelines.md)

### 🚨 Prio 1 Hotfixes
- [x] TODO: Crash in `DeliveryView.tsx` beheben (`user is not defined`).
- [x] TODO: UX-Fix: Tab-Ansicht (Verwaltung / Kundenansicht) aus dem Floating-Overlay nehmen und nativ als `GalleryHeader` in den Inhaltsbereich integrieren.
- [x] TODO: Feature: Breadcrumbs (Ordner-Pfad) in der Galerieansicht hinzufügen.

# Backlog & Task Management
- [x] TODO: Routing refactoring auf `/galleries` (REST Pattern) und Fix der E2E Helfer nach dem UX Umbau. -> [Link](features/tech/04-frontend-architecture.md)
- [x] (Ready for Review) TODO: Zentrale Mail-Management Ansicht für Kunden (Opt-In/Opt-Out für alle zugewiesenen Galerien und Gruppen). -> [Link](features/infrastructure/02-email-system.md)

### 🎨 UX Refactoring: Galerie-Verwaltung auslagern
- [x] (Ready for Review) TODO: Sidebar.tsx: Galerie-Baum (`tree`) und Erstell-Buttons entfernen. Neuen Menüpunkt "Galerien" (`/manage-galleries`) hinzufügen. -> [Link](features/tech/04-frontend-architecture.md)
- [x] (Ready for Review) TODO: ManagementDashboard.tsx: Default-Dashboard Ansicht bereinigen. Alten Text entfernen, FTP-Inbox behalten, Feed-Limits auf exakt 3 Galerien und 20 Fotos setzen. -> [Link](features/gallery/01-core-architecture.md)
- [x] (Ready for Review) TODO: Neue Ansicht `ManagementStructureView.tsx` erstellen: Beinhaltet die Erstell-Buttons und den Galerie-Baum auf voller Bildschirmbreite (z.B. als großzügige Liste/Tabelle). Routing in `App.tsx` und Dashboard ergänzen. -> [Link](features/gallery/01-core-architecture.md)

### 🔍 Such-Feature & UX Refinement (Tests)
- [x] TODO: PHPUnit - Search Authorization & Feed: `SearchTest.php` erweitern (Personal Feed Mode, strikte rollenbasierte Filterung in Meilisearch). -> [Link](features/search/01-search-and-discovery.md)
- [x] TODO: E2E - Search UX & Header Dropdown: Live-Search Dropdown im `PageLayout.tsx` testen (ab 2 Zeichen, Dropdown öffnet/schließt). -> [Link](features/search/01-search-and-discovery.md)
- [x] TODO: E2E - Search Mobile Interaction: Sicherstellen, dass das Suchfeld auf mobilen Viewports benutzbar ist. -> [Link](features/search/01-search-and-discovery.md)
- [x] (Ready for Review) TODO: E2E - Dashboard Personal Feed: Verifizieren, dass Fotografen auf dem Dashboard ihren Feed korrekt sehen. -> [Link](features/search/01-search-and-discovery.md)
- [ ] TODO: E2E - Testing Guidelines: Sicherstellen, dass keine E2E- oder PHPUnit-Tests fehlschlagende Assertions in `try-catch`-Blöcken maskieren. -> [Link](features/tech/05-testing-guidelines.md)

### 🎨 Rollen & Ansichten-Trennung (UX für Fotografen)
- [ ] TODO: UI: In der `ManagementGalleryView` einen auffälligen Button "Zur Kundenansicht wechseln" einbauen. -> [Link](features/gallery/01-core-architecture.md)
- [ ] TODO: Routing: Angemeldete Fotografen/Admins müssen die `ClientGalleryView` aufrufen können (z.B. `?view=client`), ohne ins Management-Dashboard gezwungen zu werden. -> [Link](features/gallery/01-core-architecture.md)
- [ ] TODO: Bereinigung: Den Button "Details & Metadaten" (Bild öffnen) in Selection-Galerien auch für Admins komplett entfernen. -> [Link](features/gallery/01-core-architecture.md)

### ✉️ E-Mail System Bereinigung
- [x] (Ready for Review) TODO: E-Mail System komplett auf Hardcoded Blade-Views umstellen. (Entfernt `EmailTemplate` Model, `EmailTemplateController`, DB-Tabelle und `ManagementMailTemplatesView`). Textfeld-Injektion für individuelle Nachrichten in das Blade-Layout einbauen inkl. Browser-Preview. -> [Link](features/infrastructure/02-email-system.md)

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