# Backlog & Task Management

## Offene Punkte

### Phase 6: Lightroom Plugin (Feature Parity)
- [ ] **UI-Erweiterung (`GalleryDialog.lua`)**: Checkboxen für `allow_client_metadata_edit` und `apply_metadata_to_photos` einbauen (nur sichtbar bei Delivery-Galerien).
- [ ] **UI-Erweiterung (`GalleryDialog.lua`)**: Eingabefelder für IPTC-Defaults (Titel, Beschreibung, Keywords etc.) hinzufügen, die bei Aktivierung von `apply_metadata_to_photos` eingeblendet werden.
- [ ] **Payload-Anpassung (`GalleryDialog.lua`)**: Die neuen UI-Werte beim Speichern auslesen und in den JSON-Payload für `/api/management/galleries` (POST/PUT) integrieren.
