---
domain: delivery
topic: downloads-and-injection
status: active
---

# Technical Concept: Downloads & Leak Tracing

## 1. ExifTool Metadata Injection
- To trace leaked high-res images without using visible watermarks, the `DownloadController` dynamically manipulates the file before delivery.
- The name of the downloader (e.g., "Max Mustermann" or "Gast") is injected into the `SpecialInstructions` IPTC field of the JPEG.
- The copyright notice is also forcefully re-applied based on the photographer's profile settings.

## 2. Watermarking (Fallback)
- If an unprivileged user (guest) accesses a public gallery, a visible SVG watermark is rasterized and stamped onto the image (via `Imagick`).
- To prevent heavy CPU load during mass-downloads, the rasterized SVG is cached in resolution "buckets" (`watermark_master_*.png`).
