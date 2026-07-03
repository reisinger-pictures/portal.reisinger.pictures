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


## 4. Gallery Routing & Breadcrumbs
- **Deep-Link Support:** Public galleries are directly addressable via their slug (e.g. `/:slug`). Breadcrumbs are rendered server-side for navigation through nested `GalleryGroup` parents.
- **Namespace Safety:** The breadcrumb resolver MUST use the fully qualified model class `\App\Models\GalleryGroup::find()`. An unqualified `AppModelsGalleryGroup::find` call caused a fatal `HTTP 500` on deeply nested galleries during a breadcrumb render.
- **Prevention:** Any route rendering breadcrumbs for nested galleries MUST have a PHPUnit test asserting HTTP 200 on a deep gallery URL.

## 5. Role & View Preview (Tab-Switcher)
- **Preview Capability:** Ein Tab-Switcher (implementiert über den URL-Parameter `?view=client`) erlaubt den fließenden Wechsel zwischen der Verwaltungsansicht (`ManagementGalleryView`) und der Kundenansicht (`ClientGalleryView`).
- **Strict Access Control:** Dieser Switcher wird **ausschließlich** angezeigt, wenn der eingeloggte Nutzer für diese spezifische Galerie sowohl Verwaltungsrechte (Fotograf/Admin) als auch Kundenrechte besitzt. Hat ein Fotograf über einen Gast-Link Zugriff auf eine fremde Galerie, bleibt er strikt in der Kundenansicht gefangen.

## 6. Management UI Pattern
- **Explorer-Ansicht:** Die Struktur- und Galerieverwaltung befindet sich nicht in der Sidebar, sondern in einer eigenen, großzügigen Hauptansicht (`/galleries`).
- **Modulare Dialoge:** Um den Haupt-Erstellungsdialog für Galerien schlank zu halten, wurden die komplexen IPTC-Standard-Metadaten und Berechtigungen in einen separaten Dialog (`GalleryMetadataDefaultsModal`) ausgelagert. 
### Tree Editing Rules (learned)
- **Edit-Button Disambiguation:** The `TreeNode` component MUST split edit handlers into `onEditGroup` and `onEditGallery` instead of a single `onEdit` prop. A shared handler caused the parent Group's edit dialog to open when clicking "Edit" on a nested Gallery.
- **Inline Creation:** Inline `+` buttons (Folder/Gallery) on each `TreeNode` summary reduce UX friction. They MUST pass a `defaultGroupId` to the creation modal, so the folder dropdown is pre-filled with the correct parent group.
- **Dashboard:** Das Root-Dashboard (`/`) dient ausschließlich als "Activity-Hub" (FTP-Inbox Status, die 3 neuesten Galerien und 20 neuesten Bilder).

- [Search & Discovery](../search/01-search-and-discovery.md) � gallery discoverability and permission-filtered search
- [Roles & Access Management](../auth/01-roles-and-access.md) � role-based gallery visibility and permissions
- [Magic Links & Invites](../auth/02-magic-links.md) � transient gallery access through invite links
- [Downloads & Leak Tracing](../delivery/01-downloads-and-injection.md) � delivery gallery download behavior
- [Image Upload & Processing](../photos/01-upload-and-processing.md) � photo lifecycle tied to gallery types
- [IPTC Metadata Versioning](../photos/02-metadata-versioning.md) � metadata editing rules differ by gallery type
- [Search & Discovery](../search/01-search-and-discovery.md) � gallery discoverability and permission-filtered search
