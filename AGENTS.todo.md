### 🏗️ Architektur-Updates & Refactorings (Backend & DB)
- [ ] TODO: Backend (Commands): Konsolen-Befehl `php artisan admin:update` erstellen (liest `ADMIN_EMAIL`/`ADMIN_PASSWORD` aus `.env`, aktualisiert Admin inkl. Rollen). -> [Link](features/infrastructure/01-deployment.md)
- [ ] TODO: Backend: Datenbank-Primärschlüssel von Auto-Increment BIGINT auf UUIDs/ULIDs umstellen (inkl. Anpassung der `V001` Migration und aller Foreign Keys in Pivot-Tabellen und Logs). -> [Link](features/tech/01-database-schema.md)
- [ ] TODO: Photos/Backend: Thumbnail-Generierung auf "Lazy Generation" umbauen und Unterstützung für multiple Größen (`srcset`) hinzufügen, um Transferraten zu optimieren. -> [Link](features/photos/01-upload-and-processing.md)

### ✉️ E-Mail System & Notifications (Testing)
- [ ] TODO: PHPUnit - E-Mail Opt-In Logik testen. Verifizieren, dass `finishRating` und `sendCustom` im `MailController` strikt nur an User (Kunden & Fotografen) senden, bei denen das `wants_notifications` Flag auf `true` gesetzt ist. -> [Link](features/infrastructure/02-email-system.md)
- [ ] TODO: E2E - E-Mail Versand & Notifications. Testen des UI Toggles im Management und Client Bereich. Senden einer manuellen E-Mail über das Modal und verifizieren des erfolgreichen E-Mail-Empfangs in der Mailpit API. -> [Link](features/tech/05-testing-guidelines.md)

### 🔍 Testing & QA
- [ ] TODO: E2E - Testing Guidelines: Sicherstellen, dass keine E2E- oder PHPUnit-Tests fehlschlagende Assertions in `try-catch`-Blöcken maskieren. (Codebase Scan notwendig) -> [Link](features/tech/05-testing-guidelines.md)
- [ ] TODO: Backend Testing (Leak Prevention): PHPUnit Tests für API Endpunkte (z.B. Galerien, User) überarbeiten, sodass sie explizit via `assertJsonStructure` prüfen, dass keine ungewollten Attribute (wie `password_hash`) geleakt werden. -> [Link](features/tech/05-testing-guidelines.md)

### 🎨 Rollen & Ansichten-Trennung (UX für Fotografen)
- [ ] TODO: UI: In der `ManagementGalleryView` einen auffälligen Button "Zur Kundenansicht wechseln" einbauen. -> [Link](features/gallery/01-core-architecture.md)
- [ ] TODO: Routing: Angemeldete Fotografen/Admins müssen die `ClientGalleryView` aufrufen können (z.B. `?view=client`), ohne ins Management-Dashboard gezwungen zu werden. -> [Link](features/gallery/01-core-architecture.md)
- [ ] TODO: Bereinigung: Den Button "Details & Metadaten" (Bild öffnen) in Selection-Galerien auch für Admins komplett entfernen. -> [Link](features/gallery/01-core-architecture.md)

### 🏗️ Architektur-Updates & Refactorings (Frontend)
- [ ] TODO: Frontend Refactoring (URL State): Pagination in `ManagementStatsView.tsx` muss den State via URL-Parameter (`?page=2`) anstatt via lokalem React-State abbilden. -> [Link](features/tech/04-frontend-architecture.md)
- [ ] TODO: Frontend Refactoring (Native Alerts): Alle nativen `alert()` und `window.confirm()` Aufrufe restlos durch `showToast()` und `confirm()` aus dem `UIContext` ersetzen. (Betrifft: `ManagementFtpInbox.tsx`, `EmailComposerModal.tsx`, `InviteModal.tsx`). -> [Link](features/tech/04-frontend-architecture.md)
- [ ] TODO: Frontend/Auth: Einlöse-Workflow für Magic Links anpassen. Wenn bereits eingeloggt: Transiente Rechte für die aktuelle Session gewähren, ohne die Galerie permanent in die `user_galleries` Pivot-Tabelle zu schreiben. -> [Link](features/auth/02-magic-links.md)

### 🚀 Produktion & Deployment
- [ ] TODO: Feature Documentation: Check if markdown references are valid and no information is duplicated. Agents.md should be the entry point and introduce how to navigate the project on a high level. It should be concise and reference to other files / folder.
- [ ] TODO: Deployment: `backend-init` Container in der `docker-compose.yml` aktualisieren, um sicherzustellen, dass die Init-Chain für Version 1.0 korrekt durchläuft (Datenbankbereitschaft, Migrationen, JWT-Key-Generierung). -> [Link](features/infrastructure/01-deployment.md)
- [ ] TODO: DevOps: Migration Policy definieren (Ab Prod-Deployment zwingend neue Migrationsdateien für Schema-Änderungen nutzen). -> [Link](features/tech/01-database-schema.md)
