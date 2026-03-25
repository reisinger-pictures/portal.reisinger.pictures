---
domain: gallery
topic: core-architecture
status: active
---

# Technical Concept: Gallery Architecture & Types

## 1. Structural Entities
- **GalleryGroup (Meta-Gallery):** Used for hierarchical organization. Groups can be nested infinitely (`parent_id`).
- **Gallery:** The actual container for photos. Must belong to a `GalleryGroup` or sit at the root level. 

## 2. Gallery Types (Strict Separation)
Galleries are strictly divided into two mutually exclusive workflows:

### A. Selection (Rating Workflow)
- **Purpose:** Client selects favorites for final editing.
- **Visibility:** MUST ALWAYS be private. Cannot be made public.
- **Available Features:** 5-star ratings, comments, PhotoSwipe full-screen rating UI.
- **DISABLED Features:** NO metadata editing. NO high-res downloads.
- **Security (DAU Protection):** Frontend implements basic protections (prevent right-click, `draggable={false}`) to deter downloading of unedited preview images.

### B. Delivery (Download Workflow)
- **Purpose:** Final delivery of high-res images.
- **Visibility:** Can be private (assigned users/Magic Link/Password) or public. Email domain mapping groups apply here.
- **Available Features:** Single downloads, ZIP downloads, IPTC metadata editing (if permitted). Live Mode (10s auto-refresh).
- **DISABLED Features:** NO ratings. NO client comments.

## 3. Caching
- The entire gallery tree structure is cached infinitely (`gallery_tree_admin`).
- Flushed via Eloquent Model Events (`booted` -> `saved`/`deleted`).
