---
domain: search
topic: search-and-discovery
status: active
---

# Technical Concept: Search & Discovery

## 1. Meilisearch & Laravel Scout
- Meilisearch provides typo-tolerant search across galleries and photos. Indexing is strictly synchronous (`SCOUT_QUEUE=false`).

## 2. Tenant Isolation & Permissions
- `whereIn('gallery_id', [...])` is used directly in the Scout query to filter search results based on the `getAllowedGalleryIds()` logic.
- **Maintenance:** The `gallery_id` must be a `filterableAttribute` in Meilisearch.

## 3. Search Modes
- **Discovery:** Empty queries return a "Latest Discoveries" feed containing only public galleries.
- **Personal Feed:** Requested via `?personal=true`. Bypasses Meilisearch entirely and returns an Eloquent-based feed of a photographer's own recently uploaded galleries/photos.
