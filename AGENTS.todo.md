# Backlog & Task Management

### Architektur-Refactoring (Artist Foreign Key & Scout Sync)
- [x] **Backend (Schema):** `V001__initial_portal_schema.php` anpassen. Spalte `artist` entfernt, `user_id` hinzugefügt.
- [x] **Backend (Models):** `Photo` und `User` Models mit Relationen und Accessor (`getArtistAttribute`) versehen.
- [x] **Backend (Uploads):** `ImageController` und `FtpController` speichern nun `user_id`.
- [x] **Backend (Profil-Update):** `AuthController@updateProfile` triggert Meilisearch-Sync in DB-Transaktion.
- [x] **Backend (Tests):** Vorhandene Tests an neues Schema angepasst.
- [x] **Backend (Tests):** Neuen Testcase (`ProfileUpdateScoutTest.php`) für Meilisearch-Sync erstellt.

### PhotoSwipe Fullscreen-Interaktion
- [x] **Frontend: PhotoSwipe Rating-UI (Selection):** Sterne-Rating und Kommentarfeld interaktiv in die PhotoSwipe-Ansicht injizieren (inkl. Event-Propagation-Fixes).
    - *Test-Requirement (E2E):* Nutzer kann im Fullscreen bewerten; Status muss sich beim Wischen synchronisieren; Tastatursteuerung (0-5) implementieren.

### Such-Feature & UX Refinement (Tests)
- [ ] **PHPUnit - Search Authorization & Feed:** `SearchTest.php` erweitern. 
  - Teste den Personal Feed Mode (`?personal=true`) für Fotografen.
  - Teste die strikte rollenbasierte Filterung in Meilisearch.
- [ ] **E2E - Search UX & Header Dropdown:** Live-Search Dropdown im `PageLayout.tsx` testen.
  - Prüfen, ob ab 2 Zeichen das Dropdown öffnet.
  - Prüfen, ob der Klick auf ein Suchergebnis korrekt weiterleitet und das Dropdown schließt.
- [ ] **E2E - Search Mobile Interaction:** Sicherstellen, dass das Suchfeld auf mobilen Viewports benutzbar ist.
- [ ] **E2E - Dashboard Personal Feed:** Verifizieren, dass Fotografen auf dem Dashboard ihren Feed korrekt sehen.

### Rollen & Ansichten-Trennung (UX für Fotografen)
- [ ] **UI:** In der `ManagementGalleryView` einen auffälligen Button "Zur Kundenansicht wechseln" einbauen.
- [ ] **Routing:** Angemeldete Fotografen/Admins müssen die Möglichkeit haben, die `ClientGalleryView` (Selection/Delivery) aufzurufen, ohne dass sie in das Management-Dashboard gezwungen werden (z.B. über einen URL-Parameter `?view=client` oder eine saubere Trennung der Routen).
- [ ] **Bereinigung:** Den Button "Details & Metadaten" (Bild öffnen) in Selection-Galerien auch für Admins komplett entfernen.

### Invite-Link Priorität & Implizite Rechte (Gäste)
- [ ] **Backend (Auth/Invites):** Wenn ein eingeloggter User (User B) einen Invite-Link öffnet, der von User A erstellt wurde, soll User B die Galerie sehen können, *ohne* dass die Galerie fest in die Pivot-Tabelle `user_galleries` von User B geschrieben wird.
- [ ] **JWT/Session:** Das Recht zum Betrachten/Bewerten soll implizit sein (transienter Claim im JWT oder Session-Cookie).
- [ ] **Tests (E2E/PHPUnit):** Neues Test-Szenario: Galerie ist nur für User A freigegeben. User A erstellt Invite-Link und gibt ihn an User B (ebenfalls eingeloggt). User B hat Zugriff auf die Selection-UI der Galerie, aber sie taucht nicht in seinem permanenten Dashboard auf.

### E-Mail System & Admin-Setup Vereinfachung
- [ ] **Backend (Commands):** Konsolen-Befehl (z.B. `php artisan admin:update`) erstellen, der das Admin-Passwort und die E-Mail aus der `.env` (`ADMIN_EMAIL`, `ADMIN_PASSWORD`) liest und den Haupt-Admin aktualisiert. Bei E-Mail-Änderung einen Bestätigungslink versenden (Double Opt-In für Admins).
- [ ] **Backend/Frontend (E-Mails Hardcoden):** E-Mail-Vorlagen-Verwaltung (`EmailTemplate` Model, Controller, DB-Tabelle, UI-View) komplett aus dem System entfernen.
- [ ] **Backend (Mails):** Alle ausgehenden E-Mails überprüfen und sicherstellen, dass sie als saubere HTML-Mails (Blade Views) implementiert sind. Bei manuellen Mails ("E-Mail senden" Funktion) ein Textfeld für einen individuellen Absatz anbieten, der in das feste Layout injiziert wird.

- [ ] **UI Refinement:** Alle `alert()` Aufrufe im Code (z.B. beim Link kopieren, FTP Import) durch `showToast()` aus dem UIContext ersetzen.
- [ ] **Architektur:** Prüfen, ob Selection und Delivery Galerien im Code und UI in Zukunft noch stärker technisch getrennt werden sollen.

### Produktion & Deployment
- [ ] **DevOps: Portainer Deployment:** Deployment-Anleitung in `DEPLOYMENT.md` finalisieren (Volume-Mappings für Photos/FTP prüfen).
- [ ] **DevOps: Migration Policy (V002+):** Ab dem Prod-Deployment zwingend neue Migrationsdateien für Schema-Änderungen nutzen.
