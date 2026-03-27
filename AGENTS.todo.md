# 📝 Backlog / Nächster Sprint

### ✉️ E-Mail System & Notifications (Testing)
- [ ] TODO: PHPUnit - E-Mail Opt-In Logik testen. Verifizieren, dass `finishRating` und `sendCustom` im `MailController` strikt nur an User (Kunden & Fotografen) senden, bei denen das `wants_notifications` Flag auf `true` gesetzt ist.
- [ ] TODO: E2E - E-Mail Versand & Notifications. Testen des UI Toggles im Management und Client Bereich. Senden einer manuellen E-Mail über das Modal und verifizieren des erfolgreichen E-Mail-Empfangs in der Mailpit API.

### 🔍 Testing & QA
- [ ] TODO: E2E - Testing Guidelines: Sicherstellen, dass keine E2E- oder PHPUnit-Tests fehlschlagende Assertions in `try-catch`-Blöcken maskieren. (Codebase Scan notwendig)
- [ ] TODO: Backend Testing (Leak Prevention): PHPUnit Tests für API Endpunkte (z.B. Galerien, User) überarbeiten, sodass sie explizit via `assertJsonStructure` prüfen, dass keine ungewollten Attribute (wie `password_hash`) geleakt werden.

### 🎨 Rollen & Ansichten-Trennung
- [ ] TODO: Bereinigung: Den Button "Details & Metadaten" (Bild öffnen) in Selection-Galerien auch für Admins komplett entfernen.

### 🏗️ Architektur-Updates & Refactorings (Backend)
- [ ] TODO: Backend (Commands): Konsolen-Befehl `php artisan admin:update` erstellen (liest `ADMIN_EMAIL`/`ADMIN_PASSWORD` aus `.env`, aktualisiert Admin inkl. Rollen).


### 🚀 Produktion & Deployment
- [ ] TODO: Deployment: `backend-init` Container in der `docker-compose.yml` aktualisieren, um sicherzustellen, dass die Init-Chain für Version 1.0 korrekt durchläuft (Datenbankbereitschaft, Migrationen, JWT-Key-Generierung).
