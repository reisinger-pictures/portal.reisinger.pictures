# 📝 Projekt-Master-Backlog

Aktuelle DB Version: 15

## Code Review Feedback

- [ ] Refactoring: Alle verbleibenden Hardcoded-Strings für Rollen im Frontend (`'admin'`, `'client'`, etc.) durch das
  `UserRole` Enum aus `useUsers.ts` ersetzen.
- [ ] UX: In `LicenseSelectorCard`, wenn `canBuy` false ist, einen Button "Upgrade-Angebot anfordern" hinzufügen, der
  ein Kontaktformular oder eine Mail-Interaktion triggert. (macht das fachlich Sinn)
- [ ] Backend: Den `AdminUpdate` Command erweitern, um auch `is_hidden` Flags initial korrekt zu setzen (entsprechend
  der neuen DB-Standards).