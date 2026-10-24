# Media Ingestion and Photo Context Security

## Raster image validation

`PhotoProcessingService::inspectRasterImage()` is the shared server-side content check for gallery uploads and FTP imports. It accepts only JPEG, PNG, and WebP content when the image header, detected file MIME, byte budget, dimensions, and total-pixel budget agree. Upload-provided MIME headers are not persisted. `ImageController` and `FtpInboxImporter` store the detected type and choose the stored extension from that type; an invalid FTP image is rejected before it is copied to the photo disk, and its inbox copy remains available for correction.

`ModelFileStore::storeUpload()` accepts raster images and PDFs. Raster files pass the same pre-decode image budget before GD metadata stripping. PDFs remain encrypted as PDFs and never enter a raster decoder. Unsupported file types are rejected.

`ImageProcessor` applies the same raster budget before decoding gallery originals, generating thumbnails, scaling, or preparing watermarks. File delivery derives `Content-Type` from the validated on-disk image, including generated previews and watermarked files; persisted `Photo::mime_type` and upload headers do not select the response type.

## Media replacement and derivative freshness

Replacing an existing gallery photo keeps its photo identity and original path, but advances `photos.updated_at`. The photo URL, thumbnail URL, and each `srcset` candidate use that updated timestamp as their cache version (with `created_at` as a legacy fallback), so clients request a new URL after replacement. The upload path removes the photo's cached thumbnails, legacy thumbnail files, watermarked originals and thumbnails, and watermark provenance markers. Thumbnails and watermarked variants are lazily regenerated from the replacement bytes on their next delivery request; an old derivative must not remain available under the stable server-side path.

## Photo context visibility

`SearchController::photoContext()` uses `MediaVisibilityService` for current photo/gallery visibility and retains the host-brand tree check. Expired galleries do not expose photo metadata or breadcrumbs to guests or ordinary gallery grantees. A user who can manage that gallery may view an expired gallery, while private-gallery access still requires the existing gallery-access check. Manager privileges do not bypass brand isolation or hidden-resource checks.
