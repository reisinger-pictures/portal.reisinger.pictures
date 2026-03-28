# 📝 Backlog / Nächster Sprint

🎉 **Release 1.0 ist erfolgreich in Produktion!**
Alle Kern-Tasks für diesen Meilenstein sind offiziell abgeschlossen.

## Feature-Lücken & Zukünftige Iterationen

### 1. Smart Assistance (IPTC Auto-Complete via Meilisearch)
- [ ] **Backend / Tooling:** Transformer-Skript schreiben, das zwei Datensätze in den neuen Meilisearch-Index (`locations`) pusht:
    1. GeoNames-Datensatz für Österreich (als `type: city`, inkl. Bundesland, Land, ISO).
    2. Weltweite Länderliste Deutsch -> ISO (als `type: country`).
- [ ] **Frontend:** `IptcMetadataEditor.tsx` um Meilisearch-Anbindung erweitern.
    - **Feld Stadt:** Sucht nach `type: city`. Bei Treffer werden `state`, `country` und `iso_country` vorausgefüllt.
    - **Feld Land:** Sucht nach `type: country`. Nutzt Meilisearch Typo-Toleranz. Bei Treffer wird `iso_country` vorausgefüllt.
    - Bei Mehrdeutigkeit: Dropdown anzeigen. Alle Felder bleiben manuell überschreibbar.

### 2. E2E-Tests: Metadaten-Vorlagen (Defaults)
- [ ] **QA / Testing:** Playwright E2E-Test schreiben. 
    - Ablauf: Galerie-Modal öffnen -> Metadaten-Defaults (Titel, Ort, etc.) ins UI eintragen -> Bild hochladen -> Bild herunterladen -> Mittels ExifTool prüfen, ob die Metadaten physisch im JPG gelandet sind.