# 📝 Projekt-Master-Backlog (Kumulativ)

## 🚀 Epic: Refinement & Missing Workflows

### PDF-Mail-System & Dokumentation
- [x] **Plan/Maker (Mail Logic):** `InvoiceMail.php` so erweitern, dass sie ein Array von Dokumenten akzeptiert. Falls ein Kauf mehrere Belege erzeugt (z.B. Korrektur + neue Rechnung), müssen diese in einer Mail kombiniert werden.
- [x] **Checker (PHPUnit):** Test in `MailDeliveryTest.php`: Verifiziere über die Mailpit-API, dass das PDF-Attachment existiert, den korrekten MIME-Type hat und die Dateigröße > 0 ist.

### Epic: Photographer Team Access & Inheritance

- [x] **Phase 1: Database & Models (Backend)**
  - **Migration:** Füge `restricted_photographers` (boolean, nullable, default null) zu `galleries` und `gallery_groups` hinzu.
  - **Models:** Implementiere den Accessor `effective_restricted_photographers` in `Gallery` und `GalleryGroup`. Aktualisiere `$fillable`, `$visible` und `$casts`.
  - **User Model:** Erweitere `getAllowedGalleryIds()` so, dass für Fotografen alle IDs aus dem `gallery_tree_admin` Cache hinzugefügt werden, bei denen `effective_restricted_photographers` `false` ist.

- [x] **Phase 2: API Controller & Routing**
  - **GalleryController:** Erweitere `storeGroup`, `updateGroup`, `storeGallery`, `updateGallery` um das neue Feld.
  - **Tree Filter Logic:** Stelle sicher, dass die `indexAdmin` Methode die neuen Rechte bei der Baum-Generierung korrekt berücksichtigt.

- [x] **Phase 3: Frontend UI & Modals**
  - **Typing:** Erweitere die Interfaces `Gallery` und `GalleryGroup` in `useGalleries.ts`.
  - **Neues Modal:** Erstelle `PhotographerAccessModal.tsx` (Status: Erben / Alle / Restriktiv).
  - **UI Integration:** Füge den Button "Fotografen-Team" in `ManagementGalleryActions.tsx` und im Tree-Node (`ManagementStructureView.tsx`) hinzu.

- [x] **Phase 4: Strict Security Tests (PHPUnit & E2E)**
  - **Test 1 (Inheritance):** `GalleryGroup` auf "Restricted" setzen -> Child-Gallery erbt den Schutz.
  - **Test 2 (Cross-Access Open):** Fotograf A erstellt Galerie (Default: Open). Fotograf B kann sie sehen und ein Bild hochladen.
  - **Test 3 (Cross-Access Restricted):** Fotograf A ändert Galerie auf "Restricted". Fotograf B erhält `403` beim Upload und sieht sie nicht in der Suche.
  - **Test 4 (Explicit Assignment):** Fotograf A weist Fotograf B via `user_galleries` zu. Fotograf B hat trotz "Restricted" Status wieder Zugriff.

### Infrastructure & Deployment
- [x] **Plan/Maker (Infrastructure)**: Überprüfen und Aktualisieren der Deployment-Abhängigkeiten und der `backend-init` Logik in der `docker-compose.yml` vor dem Release von Version 1.3 (Referenz: features/infrastructure/01-deployment.md).

### Invoicing & Bank Details
- [x] **Checker**: Verifiziere PDF-Generierung mit echten Bankdaten über Mailpit-Anhang.

### Order UI Refinement
- [x] **Plan/Maker**: In `ClientOrdersView.tsx` den Status visuell noch stärker trennen...