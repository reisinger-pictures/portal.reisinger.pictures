---
domain: search
topic: search-and-discovery
status: active
---

# Technical Concept: Search & Discovery

## 1. Meilisearch & Laravel Scout
- Meilisearch provides typo-tolerant search across galleries and photos. Indexing is strictly synchronous (`SCOUT_QUEUE=false`).

### Maintenance: Meilisearch-Volume zurücksetzen (via Portainer)
Bei Versionskonflikten (z.B. `Your database version (X) is incompatible with your current engine version (Y)`) muss das Meilisearch-Volume gelöscht werden:

1. **Portainer → Volumes** → Volume `portal_search_data_local` auswählen → **Remove**
2. **Portainer → Containers** → Container `portal_search_local` stoppen & löschen (**Remove**)
3. **Portainer → Stacks** → Stack `portal` auswählen → **Pull & redeploy**

Danach im Backend `php artisan app:search-rebuild` ausführen, um alle Scout-Indizes neu aufzubauen.

## 2. Org Isolation & Permissions
- `whereIn('gallery_id', [...])` is used directly in the Scout query to filter search results based on the `getAllowedGalleryIds()` logic.
- **Maintenance:** The `gallery_id` must be a `filterableAttribute` in Meilisearch.

## 3. Search Modes
- **Discovery:** Empty queries return a "Latest Discoveries" feed containing only public galleries.
- **Personal Feed:** Requested via `?personal=true`. Bypasses Meilisearch entirely and returns an Eloquent-based feed of a photographer's own recently uploaded galleries/photos.

## 4. Location Data Lifecycle

- `DatabaseSeeder` is network-free and does not invoke `app:import-locations`.
  Production location data is refreshed by the weekly scheduler entry, guarded by
  Laravel's overlap/single-server mutex and the command's cache lock.
- Fresh E2E databases instead load the small checked-in
  `database/fixtures/e2e-locations.json` fixture through `E2ELocationSeeder`.
  The CI workflow and `scripts/e2e-up.sh` invoke that seeder explicitly, flush the
  `Location` index, synchronize index settings, and import the fixture. This keeps
  the Salzburg/Graz/Linz E2E contract deterministic without making application
  startup depend on GeoNames availability.

## Related
- [Meilisearch Typo-Tolerance](../search/03-meilisearch-typo-tolerance.md) � typo-tolerant search configuration and index settings
