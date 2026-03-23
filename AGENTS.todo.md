# Backlog & Task Management

### Phase 11: Deep Testing (E-Mails, Links, UI & Datei-Integrität)
- [ ] **PHPUnit: Cleanup Command:** Feature-Test für `app:cleanup-galleries`. Abgelaufenes Datum mocken und sicherstellen, dass `Storage::disk('photos')` restlos gelöscht wird.
- [ ] **E2E: PhotoSwipe & Lightbox UI (Desktop & Mobile):** Prüfen, ob der Klick auf ein Galerie-Bild die PhotoSwipe-Lightbox öffnet. Verifizieren, dass die benutzerdefinierten Metadaten (Title, Description, Artist) im `custom-caption` Overlay korrekt gerendert werden.
- [ ] **E2E: Download Trigger (UI):** Sicherstellen, dass die "Einzel-Download" und "ZIP-Download" Buttons in der Galerie-Ansicht und Detail-Ansicht auf Desktop und Mobile klickbar sind und den Download-Request (bzw. die Navigation zur Download-Route) korrekt feuern.
- [ ] **PHPUnit/E2E: Metadaten-Handling (IPTC) tiefergehend testen:** Edge-Cases prüfen, z.B. greifen Standard-Metadaten einer Galerie beim Upload wirklich? Können Kunden durch API-Manipulation verbotene Felder (z.B. Urheber/Artist) überschreiben (DAU/Hacking-Schutz)?

### Phase 11.2: Refactoring "Massen-Links" (Self-Invite)
- [ ] **Backend/Frontend: Self-Invite für Massen-Links:** Wenn Gäste einen anonymen Massen-Link öffnen, geben sie ihre E-Mail ein und erhalten einen Magic-Link per Mail (kein direkter Login mehr).
    - *Test-Requirement (PHPUnit):* Prüfen, ob der Redeem-Endpunkt bei fehlendem Namen im Token nicht einloggt, sondern den Mail-Versand anstößt.
    - *Test-Requirement (E2E):* Gast gibt E-Mail ein, sieht Erfolgsmeldung, holt Link aus Mailpit und ist danach eingeloggt.
- [ ] **Frontend: Rating Filter:** In der Auswahl-Ansicht nach "Meinen Bewertungen" (Sternen) und "Neu" filtern können.
    - *Test-Requirement (E2E):* Klick auf Filter-Buttons blendet falsche Bilder aus; PhotoSwipe-Lightbox wischt nur durch sichtbare Bilder (DOM/Selector-Update).

### Phase 11.3: UI/UX Refactoring Detailansicht (PhotoDetailView) & Profil
- [ ] **Frontend: Responsive Grid & Read-Only Status:** Detailansicht auf Grid umbauen. Für Gäste ohne Rechte sauberen Text statt `disabled` Inputs rendern. Das Urheber-Feld ist in dieser Maske *immer* Read-Only.
    - *Test-Requirement (E2E):* Gast-User prüfen (sieht keine Formularfelder, nur Text). Fotograf prüfen (sieht Inputs, aber Urheber ist gesperrt/Text). Layout darf keine horizontale Scrollbar haben.
- [ ] **Frontend: Keyword-Chips (Schlagwörter):** Interaktive Custom-Komponente für Keywords (DaisyUI Badges mit "✕", Paste-Support, Trennung bei Enter/Komma).
    - *Test-Requirement (E2E):* Text reinkopieren (Copy/Paste) und prüfen, ob korrekte Chips entstehen. Chips löschen und prüfen, ob `iptcData.keywords` korrekt aktualisiert wird.
- [ ] **Backend/Frontend: Fotografen-Profil (Urheber):** Endpunkt (`PUT /api/auth/profile`) und UI in den Einstellungen, um `metadata_copyright` zu setzen.
    - *Test-Requirement (PHPUnit):* Profil-Update Endpunkt validieren.
    - *Test-Requirement (E2E):* Fotograf ändert Urheber in den Settings; neu hochgeladenes Bild übernimmt diesen Wert automatisch.
- [ ] **Backend/Frontend: Download-Button & Counter:** "Herunterladen"-Button in der Detailansicht. Backend liefert `downloads_count` für dieses spezifische Bild.
    - *Test-Requirement (PHPUnit):* API muss den aggregierten Counter aus den Logs pro Bild korrekt ausgeben.
    - *Test-Requirement (E2E):* Counter wird angezeigt; Klick auf Button löst Download-Navigation aus.

### Phase 11.4: PhotoSwipe Fullscreen-Features (Selection & Delivery)
- [ ] **Frontend: PhotoSwipe Integration & Captions:** Bild öffnet sich bei Klick in der Lightbox. Metadaten (Titel, Beschreibung, Urheber) im `custom-caption` Overlay sauber formatiert anzeigen.
    - *Test-Requirement (E2E):* Lightbox öffnet sich korrekt, Captions sind lesbar und entsprechen den hinterlegten IPTC-Daten.
- [ ] **Frontend: PhotoSwipe Rating-UI (Selection):** Sterne-Rating und Kommentarfeld interaktiv in die PhotoSwipe-Ansicht injizieren (inkl. Event-Propagation-Fixes).
    - *Test-Requirement (E2E):* Nutzer kann im Fullscreen bewerten und kommentieren. Status synchronisiert sich beim Weiterwischen. Tastatursteuerung (0-5) funktioniert, blockiert aber nicht die Texteingabe im Kommentarfeld.

### Phase 12: Produktion & Deployment
- [ ] **DevOps: Portainer Deployment:** Deployment-Anleitung in `DEPLOYMENT.md` überprüfen und abschließen.
- [ ] **DevOps: Migration Policy (V002+):** Ab dem Prod-Deployment zwingend neue Migrationsdateien nutzen.