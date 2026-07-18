# Lightroom Plugin — admin.lrplugin

**Stand:** 2026-07-18  
**Version:** 1.1.1  
**Brand:** RP-only (portal.reisinger.pictures)

## Übersicht

Das Lightroom Plugin erlaubt Fotografen, direkt aus Adobe Lightroom Classic heraus Galerien auf dem Reisinger Foto Portal zu verwalten, Bilder hochzuladen und Bewertungen zu synchronisieren.

## Architektur

```
admin.lrplugin/
├── Info.lua                 — Plugin-Metadaten, VERSION, MenuItems
├── Api.lua                  — API-Client (Login, Call, Upload, Retry)
├── ManagerCore.lua          — Haupt-Logik (Login, Galerie-Baum, Upload, Sync)
├── SelectionManager.lua     — Einstieg Bewertungs-Galerien (mode=selection)
├── DeliveryManager.lua      — Einstieg Delivery-Galerien (mode=delivery)
├── GalleryDialog.lua        — Galerie Create/Edit-Modal
├── MetaGalleryDialog.lua    — Meta-Galerie Create/Edit-Modal
├── InviteDialog.lua         — Einladungs-Links verwalten
├── RatingStatusDialog.lua   — Bewertungs-Status + Sync (neu in 1.1.1)
├── Utils.lua                — slugify, flattenGroups, flattenGalleries
├── PluginInfoProvider.lua   — Plugin-Settings-Tab (URL, Login)
└── json.lua                 — JSON-Parser (Lightroom 8+ kompatibel)
```

## Unterstützte Features

### Galerien verwalten

| Feature | Status | Endpunkt |
|---------|--------|----------|
| Galerie-Baum laden (filter_type=selection\|delivery) | ✅ | `GET /api/management/galleries` |
| Galerie anlegen (selection/delivery) | ✅ | `POST /api/management/galleries` |
| Galerie bearbeiten (alle Felder) | ✅ | `PUT /api/management/galleries/{id}` |
| Galerie löschen | ✅ | `DELETE /api/management/galleries/{id}` |
| Meta-Galerie anlegen/bearbeiten/löschen | ✅ | gallery-groups CRUD |
| Einladungs-Links generieren (mass/personal) | ✅ | `POST /api/management/galleries/{id}/invites` |
| Einladungs-Links widerrufen | ✅ | `DELETE /api/management/invites/{id}` |
| Org-Zuordnung (org_ids) | 🔲 *nicht im Plugin* | — |

### Upload

| Feature | Status | Details |
|---------|--------|---------|
| JPEG-Export (Q80, sRGB) | ✅ | Inklusive |
| Selection: max 3000px long edge | ✅ | |
| Delivery: Full Size | ✅ | |
| lr_uUID im Upload | ✅ | Backend ersetzt via UUID |
| replace=1 Flag | ✅ 1.1.1 | Immer gesendet |
| Retry bei 5xx/Curl-Fehlern | ✅ 1.1.1 | 1× nach 2s Sleep |
| Temp-Dir Cleanup | ✅ 1.1.1 | Bei errorCount==0 |
| Log-Datei (upload_log.txt) | ✅ | Im Temp-Ordner |

### Selection-Workflow (Rating Sync)

| Feature | Status | Details |
|---------|--------|---------|
| Bewertungs-Export laden | ✅ | `GET /api/management/galleries/{id}/export` |
| Rating in LR-Metadata schreiben | ✅ | `photo:setRawMetadata("rating", avg_rating)` |
| Pick-Flag bei Ø≥4 Sterne | ✅ 1.1.1 | `setRawMetadata("pick", 1)` |
| Kommentare in LR-Instructions | ✅ | `setRawMetadata("instructions", all_comments)` |
| Sync-Optionen wählbar (Rating/Pick/Comments) | ✅ 1.1.1 | Checkboxen im Dialog |
| Rating-Status anzeigen (wer hat bewertet) | ✅ 1.1.1 | `GET rating-status` + Export-Tabelle |
| Personen-Übersicht (Name, E-Mail, Fortschritt) | ✅ 1.1.1 | |
| Bilder-Übersicht (Ø Sterne, Kommentare) | ✅ 1.1.1 | |

### Branding

| Feature | Status |
|---------|--------|
| RP (portal.reisinger.pictures) | ✅ |
| SRP (buy.reisinger.pictures) | ❌ *entfernt in 1.1.1* |

## Backend-Endpunkte (Plugin-Nutzung)

| Methode | URI | Plugin-Nutzung |
|---------|-----|----------------|
| POST | `/api/auth/login` | Login (JWT) |
| GET | `/api/auth/me` | Role-Check (is_photographer/is_admin) |
| GET | `/api/management/galleries?filter_type=selection\|delivery` | Galeriebaum |
| POST | `/api/management/galleries` | Galerie anlegen |
| PUT | `/api/management/galleries/{id}` | Galerie updaten |
| DELETE | `/api/management/galleries/{id}` | Galerie löschen |
| POST | `/api/management/gallery-groups` | Meta-Galerie anlegen |
| PUT | `/api/management/gallery-groups/{id}` | Meta-Galerie updaten |
| DELETE | `/api/management/gallery-groups/{id}` | Meta-Galerie löschen |
| POST | `/api/management/upload` | Bild-Upload (multipart) |
| GET | `/api/management/galleries/{id}/export` | Bewertungen exportieren |
| GET | `/api/management/galleries/{id}/rating-status` | Bewertungs-Status |
| POST | `/api/management/galleries/{id}/invites` | Einladung generieren |
| GET | `/api/management/galleries/{id}/invites` | Einladungen auflisten |
| DELETE | `/api/management/invites/{id}` | Einladung löschen |

## Version History

| Version | Date | Changes |
|---------|------|---------|
| 1.0.0 | — | Initial release |
| 1.1.0 | — | Meta-Galerien, Einladungen, Delivery, SRP |
| 1.1.1 | 2026-07-18 | Brand-Konsolidierung (RP-only), Upload-Robustheit (replace=1, Retry, Temp-Cleanup), Rating-Status-Dialog, Pick-Flag bei Ø≥4 |
