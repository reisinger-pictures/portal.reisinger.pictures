---
domain: infrastructure
topic: watermark-refactoring
status: planned
---

# Technical Concept: Watermark Refactoring (SVG to PNG via Frontend)

## 1. Architectural Shift (GD Only)
- **Backend Simplification:** The backend drops all dependencies on `Imagick`, `Ghostscript`, and `Fontconfig`. It uses the native PHP `GD` library exclusively for applying watermarks (`imagecopyresampled`).
- **ExifTool:** Used to copy/inject IPTC metadata after the GD processing, since GD strips EXIF/IPTC data upon saving.

## 2. Frontend Rendering & Buckets
- To minimize backend RAM usage and scaling artifacts, the React frontend renders the uploaded SVG logo onto a `<canvas>` applying the configured opacity.
- The frontend generates the transparent watermark in **three bucket sizes** (e.g., 500px, 1000px, 2000px) and uploads them to the API.

## 3. Watermark Application Logic (Centered)
- **Placement:** The watermark is placed exactly in the center of the image.
- **Sizing:** The size of the watermark is dynamically calculated based on the shortest side of the target image (e.g., 1/3 of the shortest edge). This ensures consistent visual weight for both landscape and portrait orientations, even for 60MP files.
- **Bucket Selection:** The backend automatically selects the closest available bucket size to minimize the scaling workload for the GD library.

## 4. Storage & Persistence
- The pre-rendered watermark PNG buckets are stored persistently in `/var/www/photos/_watermarks/` (the main media volume) to survive container restarts.

## 5. Global Admin Warning
- The `/api/auth/me` endpoint checks for the existence of the master watermark PNGs.
- If missing (e.g., fresh setup), a global warning banner is displayed to `super_admin` users across all views, prompting them to upload and save the watermark settings.

## 6. Docker Image Optimization
- `Dockerfile` will be stripped of `imagemagick`, `libmagickwand-dev`, `ghostscript`, `fonts-liberation`, and `fontconfig`.
- `pecl install imagick` will be removed.
