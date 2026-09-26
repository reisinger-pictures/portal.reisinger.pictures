# Index: PHOTOS

* [Upload and processing](01-upload-and-processing.md)
* [Metadata versioning](02-metadata-versioning.md)
* [AI batch edit (local, historical)](03-ai-batch-edit.md)
* [AI server-side (current SOLL)](04-ai-server-side.md)
* [Photo detail & lightbox](05-photo-detail-swipe.md)

## Current server-side AI authorization

The active [server-side AI contract](04-ai-server-side.md) follows the canonical [AI architecture](../ai/01-ai-service-architecture.md):

- Vision generation requires `updateMetadata` authorization for the resolved photo; `can_edit_metadata` alone is never sufficient.
- Text-only gallery defaults require the Gallery `create` policy, so only Photographers and Super-Admins may call them. Ordinary Admins receive `403`.

Both flows authenticate and authorize before AI availability, request validation, or provider work.
