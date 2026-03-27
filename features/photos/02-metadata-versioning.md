---
domain: photos
topic: metadata-versioning
status: active
---

# Technical Concept: IPTC Metadata Versioning

## 1. Client Participation
- If a gallery allows it, clients with the `can_edit_metadata` role can modify the IPTC title, description, and keywords of photos (Delivery galleries only).

## 2. Versioning & Integrity
- **Snapshots:** Any client modification automatically creates a snapshot (`PhotoMetadataVersion`) of the *original* data.
- **Reverting:** Photographers and Admins can revert metadata to any previous state.
- **Security:** Clients are strictly prohibited from overwriting the `artist` / copyright field.


## 3. IPTC Interface Erweiterung
- **Feld 'headline':** Wurde dem IptcData-Interface und dem Metadaten-Editor hinzugefügt, um die Kompatibilität mit professionellen Bild-Metadaten zu erhöhen.

## 4. Build-Optimierung
- **Vite-Konfiguration:** Das `chunkSizeWarningLimit` wurde auf 1000 KB erhöht, um die Warnungen durch die Integration großer Bibliotheken wie Recharts und PhotoSwipe zu unterdrücken.

## 5. Rollenbasierte UI-Bereinigung (Selection View)
- **Logik:** In Selection-Galerien (Bewertungs-Workflow) ist der Button "Details & Metadaten" nun auch für Admins/Fotografen deaktiviert, um eine strikte Trennung zum Delivery-Workflow zu wahren.