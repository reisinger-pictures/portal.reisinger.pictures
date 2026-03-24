# Backlog & Task Management

### Phase 11.1: E2E & Schutz-Mechanismen
- [x] **E2E: DAU Protection (Right-Click/Drag):** Testen, dass in Selection-Galerien das Kontextmenü blockiert ist, damit Bilder nicht einfach via "Bild speichern unter" heruntergeladen werden können.
    - *Test-Requirement:* Playwright Test triggert 'contextmenu' Event auf Image und prüft `isDefaultPrevented`.
- [ ] **Frontend: Rating Filter (Selection View):** In der Auswahl-Ansicht nach "Meinen Bewertungen" (Sternen) und "Neu" filtern können.
    - *Test-Requirement (E2E):* Klick auf Filter-Buttons blendet Bilder aus; PhotoSwipe-Lightbox wischt nur durch sichtbare Bilder.

### Phase 11.2: UI/UX Refactoring Detailansicht (PhotoDetailView) & Profil
- [ ] **Frontend: Responsive Grid & Read-Only Status:** Detailansicht auf Grid umbauen. Für Gäste ohne Rechte sauberen Text statt `disabled` Inputs rendern. Das Urheber-Feld ist immer Read-Only.
    - *Test-Requirement (E2E):* Gast-User prüfen (sieht keine Formularfelder, nur Text). Layout darf keine horizontale Scrollbar haben.
- [ ] **Frontend: Keyword-Chips (Schlagwörter):** Interaktive Custom-Komponente für Keywords (DaisyUI Badges mit "✕", Paste-Support, Trennung bei Enter/Komma).
    - *Test-Requirement (E2E):* Paste von kommagetrennten Tags prüfen; Löschen von Chips muss `iptcData.keywords` aktualisieren.
- [ ] **Backend/Frontend: Fotografen-Profil (Urheber):** Endpunkt (`PUT /api/auth/profile`) und UI in den Einstellungen, um `metadata_copyright` global zu setzen.
    - *Test-Requirement (PHPUnit):* Validierung des Profil-Updates. Neu hochgeladene Bilder müssen diesen Wert als Default übernehmen.
- [ ] **Backend/Frontend: Download-Counter & Button:** "Herunterladen"-Button in der Detailansicht inklusive Anzeige der bisherigen Downloads für dieses Bild.
    - *Test-Requirement (PHPUnit):* API muss aggregierten Counter aus den `download_logs` pro Bild liefern.

### Phase 11.3: PhotoSwipe Fullscreen-Interaktion
- [ ] **Frontend: PhotoSwipe Rating-UI (Selection):** Sterne-Rating und Kommentarfeld interaktiv in die PhotoSwipe-Ansicht injizieren (inkl. Event-Propagation-Fixes).
    - *Test-Requirement (E2E):* Nutzer kann im Fullscreen bewerten; Status muss sich beim Wischen synchronisieren; Tastatursteuerung (0-5) implementieren.


### Phase 11.4: UI Polish & Bugfixes (In Progress)
- [x] **Frontend: Sidebar Layout & Sortierung:** Sidebar horizontalen Overflow verhindern (Text abkürzen, Tooltip). Galerien und Gruppen alphabetisch statt chronologisch sortieren.
- [x] **Frontend: Galerie-Modal Architektur:** Passwort-Helper-Label nach unten verschieben und Platzhalter im Edit-Modus anpassen.

### Phase 12: Produktion & Deployment
- [ ] **DevOps: Portainer Deployment:** Deployment-Anleitung in `DEPLOYMENT.md` finalisieren (Volume-Mappings für Photos/FTP prüfen).
- [ ] **DevOps: Migration Policy (V002+):** Ab dem Prod-Deployment zwingend neue Migrationsdateien für Schema-Änderungen nutzen.
