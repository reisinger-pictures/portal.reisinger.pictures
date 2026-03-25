---
domain: photos
topic: metadata-versioning
status: active
---

# Technical Concept: IPTC Metadata Versioning

## 1. Client Participation
- If a gallery allows it, clients with the `can_edit_metadata` role can modify the IPTC title, description, and keywords of photos (Delivery galleries only).

## 2. Versioning & Integrity
- **Snapshots:** Any client modification automatically creates a snapshot (`PhotoMetadataVersion`) of the *original* data.
- **Reverting:** Photographers and Admins can revert metadata to any previous state.
- **Security:** Clients are strictly prohibited from overwriting the `artist` / copyright field.
