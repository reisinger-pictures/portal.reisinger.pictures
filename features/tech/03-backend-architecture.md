---
domain: technical
topic: backend-architecture
status: active
---

# Technical Concept: Backend Architecture

## 1. Stateless API & Processing
- The backend serves exclusively as a stateless JSON API.
- All processing tasks (ExifTool) are executed synchronously to keep infrastructure simple (no queue workers), with the exception of lazy thumbnail generation.
- **Fail Fast:** File uploads are strictly validated before touching the disk. Corrupt files yield a 422 error.

## 2. On-The-Fly Delivery
- **Zip-Streaming:** Full gallery ZIPs are not pre-calculated. They are streamed on-the-fly directly to the client via `maennchen/zipstream-php`.
- **Benefits:** This saves massive amounts of storage space, drastically improves the Time To First Byte (TTFB), and increases perceived interactivity for the user.
