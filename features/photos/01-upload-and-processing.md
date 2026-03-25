---
domain: photos
topic: upload-and-processing
status: active
---

# Technical Concept: Image Upload & Processing

## 1. Identification (`lr_uuid`)
- Images can be identified by their Lightroom UUID (`lr_uuid`). 
- This UUID is ONLY available via Lightroom uploads (not via web upload).
- **Purpose:** Used to sync rating/comment data back to Lightroom (for Selection galleries) and to seamlessly *replace* existing photos instead of duplicating them.
- **Constraint:** An image can belong to exactly ONE Delivery gallery and ONE Selection gallery (max once per gallery type).

## 2. Processing Pipeline
- **Metadata Extraction:** Executed synchronously via `exiftool` CLI. Skipped for 'selection' galleries to improve performance.
- **Thumbnails (Lazy & Multi-Size):** - We generate multiple sizes for better quality (e.g., `srcset`) while limiting transfer sizes.
  - Thumbnails are generated *on lazy image requests*. 
  - Optionally, the most critical variants can be generated via background processes to improve immediate perceived performance.

## 3. Upload Channels
- **Web Upload:** Direct upload via the frontend.
- **FTP Inbox:** Photographers can upload files via FTP. The `FtpController` processes the queue.
