# File Delivery Controller — Media Serving Architecture

> **Status:** Soll-Zustand.
> Describes the public/unauthenticated media delivery endpoint for serving gallery images with access control, watermarking, and lazy thumbnail generation.
> References: `features/delivery/01-downloads-and-injection.md`, `features/delivery/02-audit-logs.md`.

## 1. URL Structure & Routing

**Route:** `GET /api/media/{slug}/{filename}` (defined in `routes/api.php` with `where('filename', '.*')` to allow dots and slashes in the filename segment).

The `{filename}` parameter carries sub-path information. Three URL patterns are supported:

| Pattern | Example | Resource |
|---|---|---|
| `{photoId}.{ext}` | `/api/media/my-gallery/550e8400-e29b-41d4-a716-446655440000.jpg` | Original-resolution photo |
| `watermarked/{photoId}.{ext}` | `/api/media/my-gallery/watermarked/550e8400-e29b-41d4-a716-446655440000.jpg` | Watermarked original |
| `_thumbs/{size}/{photoId}.webp` | `/api/media/my-gallery/_thumbs/1024/550e8400-e29b-41d4-a716-446655440000.webp` | Lazy-generated thumbnail |

## 2. Gallery Resolution

The `{slug}` parameter is resolved with UUID priority:

1. If `Str::isUuid($slug)` → `Gallery::where('id', $slug)->first()` (direct UUID lookup).
2. Otherwise → `Gallery::where('slug', $slug)->first()` (slug lookup).
3. If no gallery found → JSON 404 `"Galerie nicht gefunden"`.

## 3. Expiry Check

Galleries have an `expires_at` timestamp. Access rules:

- If `expires_at` is in the past AND the user is NOT an admin or authorized photographer → **403** `"Galerie abgelaufen"`.
- Admin/photographer bypass: `$user->is_admin` OR (`$user->is_photographer` AND `$user->canAccessGallery($gallery->id)`) exempts from expiry checks.
- Expired galleries remain accessible to authorized staff for management purposes.

## 4. Auth Gate (Public vs Private Galleries)

- **Public galleries** (`$gallery->is_public = true`): Accessible without authentication. The `$user` may be null.
- **Private galleries** (`$gallery->is_public = false`):
  - Unauthenticated requests → 401 `"Unauthenticated"`.
  - Authenticated but unauthorized → 403 `"Forbidden"`.
  - Authorization uses `$user->canAccessGallery($gallery->id)`.

## 5. Watermark Logic

### 5.1 Conceptual Watermark Decision

The controller determines whether the requesting user *needs* a watermarked version:

```php
$logicalNeedsWatermark = true;
if ($gallery->effective_is_free_download) $logicalNeedsWatermark = false;
elseif ($user && ($user->is_admin || $user->is_photographer)) $logicalNeedsWatermark = false;
elseif ($user && $user->canAccessGallery($gallery->id)) {
    if (flatrate_level >= 1) $logicalNeedsWatermark = false;
}
```

| Condition | Watermark Needed? |
|---|---|
| Gallery is free download | No |
| User is admin or photographer | No |
| User has flatrate level ≥ 1 (any active subscription) | No |
| Authenticated user without flatrate on non-free gallery | Yes |
| Guest on public non-free gallery | Yes |

### 5.2 Watermarked URL Path

Requests to `/{slug}/watermarked/{identifier}` explicitly request a watermarked version, bypassing the conceptual check for the original path (but are subject to physical watermark existence).

If the client requests a non-watermarked path AND `$logicalNeedsWatermark` is true → **403** `"Zugriff auf Original-Ressource verweigert"`.

### 5.3 Physical Watermark Generation

Watermarks are only applied when:
1. The URL contains the `watermarked/` prefix (`$isWatermarkedRequest = true`).
2. A global watermark raster exists: `photos/_watermarks/master_500.png` exists on disk.

**Watermark raster selection:**
- The watermark SVG is pre-rasterized at different resolution buckets (500, 1000, 2000) for performance.
- `applyCenteredWatermark()` in `ImageProcessor` selects the appropriate bucket based on `min(width, height) / 3`.
- For `gallery.type === 'selection'`, the `master_selection_` bucket prefix is used (lighter watermark for selection galleries).
- Brand-specific watermarks: `{brand_prefix}master_{bucket}.png` is preferred, falls back to `master_{bucket}.png`.

**Watermark caching:**
- Watermarked originals: `photos/{gallery_id}/_watermarked/{photo.filename}` — generated once, cached indefinitely.
- Watermarked thumbnails: `photos/{gallery_id}/_thumbs/_watermarked/{size}/{photo_id}.webp` — generated once, cached indefinitely.
- Cache invalidation: requires manual deletion of watermark cache files (e.g., after watermark design change).

## 6. Thumbnail Generation & Caching

Thumbnails are **lazy-generated** on first access:

1. Request pattern `_thumbs/{size}/{photoId}.webp` is matched with regex.
2. Photo is looked up by `photoId` + `gallery_id` → 404 if not found.
3. Original file path is resolved as `{gallery_id}/{photo.filename}`.
4. If thumbnail does not exist at `{gallery_id}/_thumbs/{size}/{photoId}.webp`:
   - Directory is created (`mkdir` with 0755).
   - `ImageProcessor::generateThumbnail()` uses **GD library**:
     - Loads the original image via `loadGdImage()` (supports JPEG, PNG, WebP; auto-rotates via EXIF orientation).
     - Resamples to fit within `{size}` pixel width (preserving aspect ratio).
     - Outputs **WebP** at quality 80.
5. If thumbnail generation fails (e.g., corrupt file), a 500 is returned.
6. Result is served directly from disk — no intermediate caching layer.

## 7. Headers

### 7.1 Content-Type
- If `$photo->mime_type` is set on the model, that value is used.
- Otherwise falls back to `mime_content_type($path)`.

### 7.2 Cache-Control
```
Cache-Control: private, max-age=31536000, immutable
```
- `private`: Response is specific to the authenticated user (or guest session via the URL).
- `max-age=31536000`: 1-year browser cache.
- `immutable`: The resource will not change during its freshness lifetime — browser can skip conditional revalidation.

## 8. Proxy Delivery via X-Accel-Redirect (Caddy handle_response)

If the environment variable `PROXY_DELIVERY_HEADER` is set, the controller uses **zero-copy delivery**:

```php
$headers[$proxyHeader] = $path;
return response()->make('', 200, $headers);
```

- Caddy intercepts the X-Accel-Redirect header via handle_response and serves the files directly from disk (see features/infrastructure/01-deployment.md §4).
- PHP process is freed immediately — no memory overhead for large files.
- The header name is generically configurable (e.g., `X-Sendfile`, `X-Accel-Redirect`); in production `PROXY_DELIVERY_HEADER` is fixed to `X-Accel-Redirect`.

When `PROXY_DELIVERY_HEADER` is NOT set, `response()->file($path, $headers)` is used (PHP reads and streams the file).

## 9. Audit Logging (last_accessed_at)

Every successful photo delivery updates `photos.last_accessed_at` via a **deduplication cache**:

```php
$cacheKey = 'photo_hit_' . $photo->id;
if (!Cache::has($cacheKey)) {
    $photo->update(['last_accessed_at' => now()]);
    Cache::put($cacheKey, true, now()->addHours(24));
}
```

- The DB write occurs at most once per 24 hours per photo.
- This prevents excessive writes on heavily accessed photos.
- The timestamp is used for gallery usage statistics and "cold storage" identification.

## 10. Error Response Summary

| HTTP Status | Condition |
|---|---|
| 200 | File served successfully |
| 400 | Invalid URL format (slug is UUID but filename doesn't match expected patterns) |
| 401 | Private gallery, no authentication |
| 403 | Gallery expired (non-admin); watermark required for original; photo inaccessible |
| 404 | Gallery not found; photo not found in gallery; original file missing on disk |
| 500 | Thumbnail generation failure; watermark application failure; file missing after processing |
