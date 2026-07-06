# Photo Ratings Feature

**Status:** active  
**Tags:** `ratings`, `selection`, `gallery`, `lightroom-export`

## Overview

Ratings allow gallery guests to rate individual photos (1–5 stars) and leave comments in **selection galleries** (`type = 'selection'`). Delivery galleries do not support ratings. The feature includes a client-side rating UI (star input + comment field) in the PhotoSwipe lightbox, a management overview modal (`RatingStatusModal`) showing per-user progress and per-photo average scores, and a Lightroom-compatible CSV export for photographers.

## Current Scope (Ist-Zustand)

- **Backend:** `GalleryFrontendController::rate()` handles upserts via `Rating` model (`photo_id`, `user_id`/`guest_id`, `rating`, `comment`). Gallery type `selection` is enforced at controller level.
- **Frontend client:** `DaisyUIRatingBridge` component in the PhotoSwipe lightbox. Star rating (1–5) + comment input with auto-save on blur.
- **Frontend management:** `RatingStatusModal` shows per-user rating progress and per-photo average scores via `/api/management/galleries/{id}/rating-status` and export endpoints.
- **Rating model:** Simple Eloquent model with `photo_id`, `user_id`, `guest_id`, `guest_name`, `rating`, `comment`. No timestamps.

### Datenmodell (verifiziert 2026-07-06)
- `ratings` table: photo_id, user_id (nullable), guest_id (nullable), guest_name (nullable), rating (tinyInteger), comment (nullable), kein Timestamp (`$timestamps = false`)
- Ein Rating hat entweder user_id ODER guest_id+guest_name (nie beide)
- guest_name wird nur bei Gästen ohne User-Account gesetzt
- Unique-Constraint auf (photo_id, user_id, guest_id) — ein Rating pro User/Gast pro Foto

## Future

This document is a stub. A full specification covering Lightroom CSV export schema, sync workflow, and rating aggregation will be added here when the feature is extended.
