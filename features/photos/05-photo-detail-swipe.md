# Photo Detail View & PhotoSwipe Lightbox

**Status:** active  
**Tags:** `photo`, `detail`, `lightbox`, `photoswipe`, `responsive-image`  
**Related:** `01-upload-and-processing.md`, `02-metadata-versioning.md`, `04-ai-server-side.md`

## 1. Photo Detail View (`PhotoDetailView.tsx`)

`frontend/src/ui/PhotoDetailView.tsx` is the full-page photo detail view available at `/photos/:id`.

### 1.1 Layout

- **Top bar:** Back-navigation button + breadcrumb trail (Dashboard → Gallery → Image details)
- **Main image:** Full-width `ResponsiveImage` component with drop-shadow, centered in a `bg-base-200` container
- **Delete button:** Visible only for photographer/admin/super-admin roles (below the image)
- **Two-column grid (xl breakpoint):** Left column = licensing card, Right column = IPTC metadata editor

### 1.2 IPTC Metadata Editor (IptcMetadataEditor)

The metadata editor displays fields: `title`, `description`, `headline`, `artist`, `keywords`, `location`, `city`, `state`, `country`, `iso_country`, and an editorial-only flag.

**State initialisation:** On first render and when the `photo.id` changes, IPTC data is copied from the server response into a local `useState<IptcData>` — this avoids overwriting unsaved edits during SWR revalidation.

**Editability (`canEdit`):**
- Super-admin, admin, and photographers (with gallery access): **always editable**
- Clients: editable only if `can_edit_metadata = true` AND `gallery.allow_client_metadata_edit = true`
- `artist` field: visible only for photographer/admin/super-admin (clients must not overwrite copyright)

**AI integration:**
- An AI context text input + "KI generieren" button is rendered below the metadata editor
- Clicking calls `useAI().generateMetadata(photoId, galleryDefaults, aiContext)`
- On success, the returned `{title, description, keywords, location}` fields are merged into the local state (preview only — user must click "Speichern" to persist)
- Gallery defaults (title/description/keywords) are shown as helper text

**Action buttons:**
- "Speichern" — calls `updateMetadata(photo.id, iptcData)` → SWR mutate → toast
- "Historie" (photographer/admin only) — opens `PhotoHistoryModal` for version history and revert

### 1.3 Licensing Card

For delivery galleries, the detail view renders either:
- `VolumeLicensingCard` (SRP/volume-licensing mode) — simplified B2C pricing
- `LicenseSelectorCard` (RP/scope-licensing mode) — full license catalog

Selection is driven by `useLicensingMode()`.

## 2. ResponsiveImage Component

`frontend/src/ui/components/ResponsiveImage.tsx` is a thin wrapper around `<img>`.

### 2.1 Behaviour

- **Loading state:** Renders a CSS spinner (`loading loading-spinner`) until the image fires `onLoad`
- **Transition:** The `<img>` fades in (`opacity: 0 → 1` with `duration-500`) once loaded
- **`loading="lazy"`:** Native browser lazy loading
- **`srcSet` / `sizes`:** Passed through to `<img>`; frontend does NOT generate srcsets — the backend delivers pre-generated thumbnail sizes
- **Container:** The wrapping `<div>` has `overflow-hidden` and `bg-base-300` to show the loading state

### 2.2 Default sizes

```
(max-width: 400px) 250px,
(max-width: 600px) 400px,
(max-width: 1200px) 800px,
1200px
```

## 3. PhotoSwipe Lightbox

`frontend/src/logic/usePhotoSwipe.ts` is a reusable React hook that initialises a `PhotoSwipeLightbox` instance.

### 3.1 Usage

```typescript
const galleryRef = useRef<HTMLDivElement>(null);
usePhotoSwipe({ galleryRef, trigger: photos.length });
// trigger is a reactive value — the lightbox re-initialises when it changes
```

### 3.2 Consumers

| View | File | Gallery Type |
|------|------|-------------|
| Client Gallery (Delivery) | `DeliveryView.tsx` | delivery |
| Client Gallery (Selection) | `SelectionView.tsx` | selection |
| Management Gallery | `ManagementGalleryView.tsx` | both |
| Management Meta-Gallery | `ManagementMetaGalleryView.tsx` | both (photos of sub-galleries) |

### 3.3 PhotoSwipe item markup

Each gallery thumbnail is an `<a>` element with the following attributes:

| Attribute | Value |
|-----------|-------|
| `href` | `photo.url` (full-resolution image URL) |
| `data-pswp-width` | `photo.width` (default 2000) |
| `data-pswp-height` | `photo.height` (default 1333) |
| `data-title` | `photo.title` |
| `data-desc` | `photo.description` |
| `data-artist` | `photo.artist` |
| `data-photo-id` | `photo.id` |
| CSS class | `pswp-item` |

All items are wrapped in a container `<div ref={galleryRef}>` which serves as the PhotoSwipe gallery root.

### 3.4 Custom Caption

A `custom-caption` UI element renders `title`, `description`, and `© artist` as an overlay at the bottom-left of the lightbox. It updates on every `change` event (slide transition) via `currSlide.data.element` attributes.

### 3.5 Selection View (Rating Portal)

In `SelectionView.tsx`, PhotoSwipe is extended with:
- A `rating-portal-container` element that portals a `DaisyUIRatingBridge` into the lightbox
- Keyboard rating: pressing `0`-`5` rates the current photo and advances to the next slide
- Context-aware: the lightbox uses `latestDataRef` to avoid stale closure issues with the `ratePhoto` callback

### 3.6 Configuration

| Setting | Value |
|---------|-------|
| `arrowKeys` | `true` |
| `showAnimationDuration` | `333` (0 in Playwright environment) |
| `hideAnimationDuration` | `333` (0 in Playwright environment) |

### 3.7 Cleanup

The hook returns a cleanup function in `useEffect` that calls `lightbox.destroy()` on unmount or when `trigger` changes.

## 4. Version History (`PhotoHistoryModal`)

Photographers and admins can view the version history of a photo's metadata via `PhotoHistoryModal`. The modal:
- Fetches versions via `usePhoto().getVersions(photoId)`
- Lists each version with timestamp and user name
- Provides a "Revert" button that calls `revertMetadata(photoId, versionId)`
- On successful revert, refreshes the parent data via SWR mutate and updates the local IPTC state

## 5. Image Loading Pipeline

```
Backend                        → Frontend
──────────────────────────────────────────
Original image (storage)       → PhotoSwipe lightbox (full-res via photo.url)
2000w WEBP thumbnail           → PhotoSwipe image (used as href if available)
1200w / 800w / 400w WEBP       → srcset attributes → ResponsiveImage
Thumbnail (thumb_url)          → Grid thumbnail (aspect-square, object-cover)
```

The frontend does NOT generate or transform images — the backend (`FileDeliveryController`) serves pre-generated or on-the-fly WEBP thumbnails.

## 7. Accessibility

- All images have `alt` text derived from `photo.title` (falls back to `'Bild'`)
- The loading spinner indicates activity without relying on colour alone
- PhotoSwipe respects keyboard navigation (arrow keys)
