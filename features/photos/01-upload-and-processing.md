---
domain: photos
topic: upload-and-processing
status: active
---

# Technical Concept: Image Upload & Processing

## 1. Identification & Uniqueness (`lr_uuid` & Filename)
- Images can be identified by their Lightroom UUID (`lr_uuid`).
- The strict database-level unique constraint on `['gallery_id', 'lr_uuid']` has been relaxed. 
- **Replacement Logic:** A photo is only updated/replaced if:
  1. The `lr_uuid` is explicitly provided and originates from Lightroom (not a `web-` or `ftp-` placeholder).
  2. An explicit `replace=true` flag is passed during upload, which falls back to matching by `filename`.
- Otherwise, a new photo record is created.

## 2. Processing Pipeline
- **Metadata Extraction:** Executed synchronously via `exiftool` CLI. Skipped for 'selection' galleries to improve performance.
- **Thumbnails (Lazy & Multi-Size):**
  - We generate multiple sizes (`srcset`: 400w, 800w, 1200w) for better quality and mobile performance.
  - Thumbnails are generated *on-the-fly* (Lazy) when requested via the delivery controller.
  - **CPU Protection:** Imagick is strictly limited to 1 thread (`\Imagick::setResourceLimit(\Imagick::RESOURCETYPE_THREAD, 1)`) to prevent CPU locking on smaller servers (e.g., 4 vCPU) during parallel bulk requests.
  - **Race Condition Prevention:** Laravel's `Cache::lock()` is used. If multiple users request the same thumbnail simultaneously, only one process generates it while the others wait (up to 15s) and then serve the cached result.
  - **Smart Scaling:** If an image is smaller than the requested thumbnail size, it is *not* upscaled. It is simply converted to WEBP.
  - **Responsive Frontend (ResponsiveImage):**
  - The React frontend abstracts raw `<img>` tags into a `<ResponsiveImage>` component.
  - It provides a visual loading state (spinner/pulse) while the backend performs the on-the-fly thumbnail generation.
  - The browser requests specific sizes natively via `srcset`. If a size fails or isn't needed, the backend seamlessly falls back to the original image file.
  - PhotoSwipe utilizes the `2000w` size, while the grid layout dynamically selects between `400w`, `800w`, and `1200w` based on viewport constraints.
  - **PhotoSwipe:** The frontend `url` property points to a 2000px WEBP thumbnail instead of the massive original file. This drastically improves Lightbox performance while saving bandwidth.

- **ImageProcessor (Environment Resilience Wrapper):**
  - Setting up the PHP `Imagick` extension on local development environments (especially Windows/Mac) is notoriously difficult, whereas it is standard on Linux production servers.
  - All image manipulation operations (thumbnails, SVG rasterization, compositing) are routed through the `ImageProcessor` service to solve this discrepancy.
  - **The Switch:** It dynamically checks if the PHP extension is loaded (`class_exists('Imagick')`). If available, it executes natively via PHP (Production). If unavailable, it seamlessly falls back to executing CLI tools (`magick` or `convert`) via Symfony Process (Local Development).
  - **URL Obfuscation:** To prevent directory enumeration and file name leakage, original file names (like `sample.jpg`) are **never** exposed in media delivery URLs. The API outputs URLs containing the photo's UUID (e.g., `/_thumbs/800/uuid.webp`). The `FileDeliveryController` safely resolves this UUID against the database to fetch the original file from the hidden storage.

## 3. Upload Channels
- **Web Upload:** Direct upload via the frontend.
- **FTP Inbox:** Photographers can upload files via FTP. The `FtpController` processes the queue.


## 4. Metadata Application & Gallery Defaults
- **Upload Time:** Gallery metadata defaults (e.g., `default_city`) are copied directly to the `Photo` database row during upload if the original image lacks EXIF/IPTC data.
- **Retroactive Updates:** If a photographer changes the gallery defaults *after* photos have been uploaded, the system must retroactively update existing `Photo` records (that rely on defaults) and push the updated records to Meilisearch to maintain consistency across the UI, Search, and future Zip-Downloads.