---
domain: photos
topic: metadata-versioning
status: active
---

# Technical Concept: IPTC Metadata Versioning

## 1. Client Participation
- If a gallery allows it, clients with the `can_edit_metadata` role can modify the IPTC title, description, and keywords of photos (Delivery galleries only).

## 2. Versioning & Integrity
- **Snapshots:** Every metadata modification (regardless of role) automatically creates a snapshot (`PhotoMetadataVersion`) of the *original* data, providing a complete audit trail.
- **Reverting:** Photographers and Admins can revert metadata to any previous state.
- **Security:** Clients are strictly prohibited from overwriting the `artist` / copyright field.


## 3. IPTC Interface Extension
- **Field 'headline':** Added to the IptcData interface and the metadata editor to improve compatibility with professional image metadata.

## 4. Build Optimization
- **Vite Configuration:** The `chunkSizeWarningLimit` was increased to 1000 KB to suppress warnings from integrating large libraries such as Recharts and PhotoSwipe.

## 5. Role-Based UI Cleanup (Selection View)
- **Logic:** In selection galleries (rating workflow), the "Details & Metadata" button is now disabled even for admins/photographers to maintain a strict separation from the delivery workflow.