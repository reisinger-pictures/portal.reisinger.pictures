# Photo Ratings Feature

**Status:** active  
**Tags:** `ratings`, `selection`, `gallery`, `lightroom-export`

## Overview

Ratings allow gallery guests to rate individual photos (1–5 stars) and leave comments in **selection galleries** (`type = 'selection'`). Delivery galleries do not support ratings. The feature includes a client-side rating UI (star input + comment field) in the PhotoSwipe lightbox, a management overview modal (`RatingStatusModal`) showing per-user progress and per-photo average scores, and a Lightroom-compatible CSV export for photographers.

## Current Scope (Ist-Zustand)

- **Backend:** `GalleryFrontendController::rate()` delegates the mutation to `RatingService::upsertForActor()`. The service serializes each `(photo, actor)` mutation with an actor-scoped lock, locks the matching row, and retries a database uniqueness race. Gallery type `selection` is enforced at controller level.
- **Frontend client:** `DaisyUIRatingBridge` component in the PhotoSwipe lightbox. Star rating (1–5) + comment input with auto-save on blur.
- **Frontend management:** `RatingStatusModal` shows per-user rating progress and per-photo average scores via `/api/management/galleries/{id}/rating-status` and export endpoints.
- **Rating model:** Eloquent model with `photo_id`, `user_id`, `guest_id`, `guest_name`, `rating`, `comment`, and the V038-derived `actor_key`. No timestamps.

### Datenmodell (V038, verifiziert 2026-09-24)
- `ratings` table: `photo_id`, `user_id` (nullable), `guest_id` (nullable), `guest_name` (nullable), `rating` (tinyInteger), `comment` (nullable), `actor_key` (nullable), no timestamps (`$timestamps = false`)
- Ein Rating hat entweder `user_id` ODER `guest_id` + `guest_name` (nie beide). `actor_key` is `user:<id>` or `guest:<id>`; ownerless legacy rows keep `NULL`.
- The historical unique constraint on `(photo_id, user_id, guest_id)` remains, preserving registered-user compatibility. V038 adds the portable unique index `(photo_id, actor_key)`; nullable `actor_key` intentionally permits multiple ownerless legacy rows on both MySQL/MariaDB and SQLite without requiring a partial index.
- V038 normalizes identifiable legacy rows deterministically: duplicate `(photo_id, actor)` rows retain the lexicographically smallest rating UUID, while ownerless rows are retained untouched. The mutation path uses the actor-key index as the durable uniqueness guard.

## Future

This document is a stub. A full specification covering Lightroom CSV export schema, sync workflow, and rating aggregation will be added here when the feature is extended.
