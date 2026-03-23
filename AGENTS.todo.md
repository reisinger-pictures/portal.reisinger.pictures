# Backlog & Task Management

## Offene Punkte

### Phase 9: Globale Modal- & Toast-Architektur
- [ ] **Architektur: Globaler Confirm-Dialog**: Ersetzen der blockierenden `window.confirm()` Aufrufe (z. B. beim Löschen von Galerien, Bildern oder Usern) durch ein asynchrones, globales DaisyUI-Confirm-Modal.
- [ ] **Architektur: Toasts standardisieren**: Die derzeit isolierten Toast-States (z. B. in `ManagementUserView`) in einen globalen Zustand (`ToastContext` oder Zungstand/Zustand) auslagern, um `alert()` im gesamten Projekt elegant zu ersetzen.

### Phase 10: Robustheit & Tests (DB Transactions & Mails)
- [ ] **DB Transactions ausweiten:** Evaluieren und Einbauen von `DB::transaction()` bei weiteren Multi-Model-Operationen, um Partial-Writes zu verhindern (z.B. `GalleryController@storeGallery`, `PhotoController@updateMetadata`, `FtpController@process`).
- [ ] **E-Mail Tests validieren:** In allen betroffenen Feature Tests (z.B. `UserControllerTest`, `AuthControllerTest`, `InviteTest`) `Mail::fake()` Assertions (z.B. `Mail::assertSent`) einbauen. Es muss getestet werden, dass bei erfolgreichen Transaktionen E-Mails wirklich in die Queue/an den Mailer übergeben werden.
- [ ] **Soft-Fails für Benachrichtigungen:** Prüfen, ob `try-catch` beim E-Mail-Versand in bestimmten Kontexten sinnvoller ist (z.B. `MailController@finishRating`). Wenn ein Kunde seine Auswahl abschließt, sollte das Speichern dieses Status nicht am Mail-Versand an den Fotografen scheitern (harte 500er Fehler vermeiden).

### Phase 11: Deep Testing (Ratings & Sessions)
- [ ] **Test: Anonymer Rating-Workflow**: E2E Test, der einen Magic Link ohne Session aufruft, ein Rating abgibt und prüft, ob der Datensatz in `ratings` mit der `invite.local` E-Mail landet.
- [ ] **Test: Angemeldeter User Magic Link**: E2E Test, der prüft was passiert, wenn ein Admin einen Magic Link klickt (Erwartung: Entweder Übernahme in bestehenden Account oder sauberer Logout/Login Wechsel).
- [ ] **Fix: Weiße Seite Debugging**: Validieren, ob `ErrorBoundary` in `App.tsx` bei Identitätswechseln korrekt greift oder ob Root-Komponenten wegen null-Referenzen crashen.
