---
domain: search
topic: search-and-discovery
status: active
---

# Technical Concept: Search & Discovery

## 1. Meilisearch & Laravel Scout
- Meilisearch provides typo-tolerant search across galleries and photos. Indexing is strictly synchronous (`SCOUT_QUEUE=false`).

### Maintenance: Meilisearch-Volume zurücksetzen
Bei Versionskonflikten (z.B. `Your database version (X) is incompatible with your current engine version (Y)`) muss das Meilisearch-Volume gelöscht werden:

1. Für die lokale Testinstanz zuerst stoppen und entfernen:

   ```bash
   docker compose -p portal -f docker-compose.local.yml stop search
   docker compose -p portal -f docker-compose.local.yml rm -f search
   docker volume rm portal_search_data_local
   docker compose -p portal -f docker-compose.local.yml up -d --wait search
   ```

2. Für Produktion das bestehende Volume nur nach geprüftem Backup löschen. Der
   Stack bleibt im fest benannten Verzeichnis
   `/opt/stacks/portal-reisinger-pictures`; deploybare Compose-Änderungen kommen
   aus `deployment/deploy.sh`. Siehe außerdem das
   [Portainer-Exit-Runbook](../../../strato-vps/docs/plans/portainer-exit.md).

   ```bash
   COMPOSE=/opt/stacks/portal-reisinger-pictures/compose.yaml
   docker compose -f "$COMPOSE" stop search
   docker compose -f "$COMPOSE" rm -f search
   docker volume rm portal-reisinger-pictures_search_data
   docker compose -f "$COMPOSE" up -d --wait search
   docker compose -f "$COMPOSE" exec -T backend php artisan app:search-rebuild
   ```

The rebuild command repopulates all Scout indexes after the volume reset.

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
