# FTP Upload Pipeline — Architecture

> **Status:** Soll-Zustand.
> Describes the complete FTP upload pipeline: watch → parse → import → cleanup.
> References: `features/infrastructure/13-ftp-brand-isolation.md`.

## 1. Pipeline Overview

```
Photographer uploads JPG files via FTP
  → Files land in ftp_inbox/{user_slug}/
  → Photographer sets target gallery via API
  → Photographer triggers process() via API
  → Files are moved to photos/{gallery_id}/, metadata extracted
  → FTP inbox files are deleted
  → Photos are available immediately (thumbnails are lazy-generated on first request)
```

## 2. File Structure on FTP Server

Two storage disks are involved:

### 2.1 FTP Inbox (`ftp_inbox` disk)

- **Driver:** `local`
- **Root:** `env('FTP_STORAGE_PATH')`, defaults to `base_path('../ftp')`
- **Structure:**
  ```
  {ftp_storage_path}/
    {user_ftp_slug}/
      image001.jpg
      event_photo_02.jpeg
      ...
  ```
- Each authenticated photographer has a dedicated directory named by their `ftp_slug` (falls back to `user.id`).
- The FTP server (external) is configured to write incoming files into the correct user directory.

### 2.2 Photo Storage (`photos` disk)

- **Driver:** `local`
- **Root:** `env('PHOTO_STORAGE_PATH')`, defaults to `base_path('../photos')`
- **Structure:**
  ```
  {photo_storage_path}/
    {gallery_id}/
      {uuid}.jpg           ← renamed from original filename
      _thumbs/
        {size}/
          {photo_id}.webp  ← lazy-generated on first access
  ```

## 3. Controller Endpoints

All endpoints are under `auth:api` + `management` middleware.

### 3.1 `GET /api/management/ftp/status`

Returns:
- `ftp_folder`: The user's inbox directory name (`/` + `ftp_slug` or `id`).
- `file_count`: Count of `.jpg`/`.jpeg`/`.JPG`/`.JPEG` files in the inbox.
- `current_target_gallery`: Currently selected gallery (with loaded relation).

### 3.2 `POST /api/management/ftp/target`

Sets the target gallery for the next import.

- **Input:** `gallery_id` (nullable string, must exist in `galleries` table).
- **Brand isolation** (`features/infrastructure/13-ftp-brand-isolation.md`): If `gallery_id` is provided, it must be in the user's `getAllowedGalleryIds()` — otherwise 403.
- If `gallery_id` is null, clears the target (photographer must set one before process).
- Only photographers (`is_photographer=true`) can set a non-null target.

### 3.3 `POST /api/management/ftp/process`

Triggers the import pipeline.

**Pre-flight checks:**
1. User must be `is_photographer` — otherwise 403.
2. `current_ftp_gallery_id` must be set — otherwise 400.
3. Defense-in-depth: `current_ftp_gallery_id` must be in `getAllowedGalleryIds()` — otherwise 403.

**Processing loop:**
1. Enumerate all `*.{jpg,jpeg,JPG,JPEG}` files in the user's inbox.
2. For each file:
   - Strip original filename, keep original base name as metadata fallback.
   - **Rename to UUID:** Generate `Str::uuid()` — the original filename is only preserved as `photo.title` (if no better title is extracted).
   - Copy file to `photos/{gallery_id}/{uuid}.{ext}`.
   - **Delete** original from inbox (files are moved, not copied — after successful copy the inbox copy is unlinked).
   - Generate thumbnail metadata path (`_thumbs/md5({filename}1024).webp`) — but actual thumbnail generation is **lazy** (see §3.3.1).
3. `PhotoProcessingService::processImage()` extracts metadata via ExifTool and applies gallery defaults.

**Photo database record:**
- `id`: UUID
- `gallery_id`: Target gallery
- `lr_uuid`: `'ftp-' . uniqid()` (marks photo as FTP-imported, not from Lightroom)
- `user_id`: Uploading photographer
- `title`, `description`, `keywords`, `location`, `city`, `state`, `country`, `iso_country`, `captured_at`: From ExifTool extraction, falling back to gallery defaults
- `width`, `height`: From `getimagesize()`

#### 3.3.1 Lazy Thumbnail Generation

Thumbnails are NOT generated during FTP import. The old thumbnail generation in `PhotoProcessingService` was removed. Thumbnails are created on-the-fly by `FileDeliveryController::serve()` when a `_thumbs/{size}/{photoId}.webp` URL is requested (see `features/delivery/03-file-delivery-controller.md`).

## 4. Brand Isolation

See `features/infrastructure/13-ftp-brand-isolation.md` for full details. Summary:

- **`setTarget()`**: Rejects galleries not in `getAllowedGalleryIds()` (403).
- **`process()`**: Re-validates `current_ftp_gallery_id` against `getAllowedGalleryIds()` (defense-in-depth).
- Frontend displays gallery brand as a daisyUI `badge` in the target selector.

## 5. Gallery Auto-Assignment Logic

Gallery assignment is purely explicit via `setTarget()`:

1. Photographer selects a gallery from the frontend dropdown (which shows only allowed galleries, filtered by brand).
2. `setTarget()` persists `user.current_ftp_gallery_id`.
3. `process()` reads this value to determine the target.
4. There is no automatic gallery assignment based on filename, folder, or metadata — the photographer must always explicitly select the target.

## 6. Error Handling & Retry

| Scenario | Behavior |
|---|---|
| Inbox directory missing | `process()` returns `{processed: 0}` — no error |
| No image files in inbox | Returns `{processed: 0}` |
| File copy fails | Exception propagates → `process()` returns HTTP 500. Transaction per file (not wrapped in global transaction) — already-copied files remain imported. |
| Metadata extraction fails | `PhotoProcessingService` falls back to gallery defaults. The photo is still created. |
| Storage disk full | PHP file operations throw — HTTP 500. Admin must free space. |
| Concurrent process calls | No locking — duplicate processing of same files is possible. Design assumes single-user access. |
| Gallery deleted between setTarget and process | `Gallery::find()` returns null in process() loop — error may occur. Current code uses `Gallery::find()` after the check. |
| Brand isolation violation | 403 response — the user is informed before any import occurs. |
