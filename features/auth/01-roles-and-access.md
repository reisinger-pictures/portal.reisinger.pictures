---
domain: auth
topic: roles-and-access
status: active
---

# Technical Concept: Roles & Access Management

## 1. Authentication Concept
- **Stateless JWT:** The API uses JSON Web Tokens (JWT) via `php-open-source-saver/jwt-auth`. There are no PHP sessions (the `web` guard is deactivated).
- **Storage & XSS Prevention:** Tokens are NEVER sent in the JSON body or stored in `localStorage`. They are attached as secure, `HttpOnly`, `SameSite=Lax` cookies (`rp_jwt`).
- **Silent Refresh:** The frontend Axios/Fetch layer intercepts `401 Unauthorized` errors, calls `/api/auth/refresh` to get a new cookie, and retries the original request seamlessly.

## 2. Role System
Roles are strictly separated.
- **Admin (`is_admin`):** Broad management access. Manages users, roles, and global settings (watermark). Can view statistics for ALL galleries across the system.
- **Photographer (`is_photographer`):** Operational user. Can create/edit/delete galleries, upload photos, manage metadata, send invites, and view statistics ONLY for their *own* galleries or when they are added to a specific gallery.
- **Client (`is_client`):** Standard registered user. Has explicit access to specific galleries. 
  - In *Selection Galleries*: Can view, rate, and comment.
  - In *Delivery Galleries*: Can view, download, and edit IPTC metadata (if the gallery allows it and the user has the `can_edit_metadata` flag).
- **Guest (Transient Access):** Unregistered or unregistered-equivalent users accessing the system via Magic Links. Their access rights are bound to temporary tokens/JWT claims, not persistent database user records.
- **Pending:** Users who have registered an account but have no assigned roles or gallery mappings yet.
- **Registered users with roles and magic links:** Users who have registered an account must have access based on their role and additionally must temporarily have the permissionset of the accessed magic link.

## 3. Domain Mapping (Evaluation & Revocation)
- Used for B2B clients (e.g., editorial offices).
- If a user registers with a specific email domain (e.g., `@firma.com`), they are automatically mapped to a specific `Role` and `GalleryGroup`.
- **Constraint:** Domain Mapping grants access *only* to `delivery` galleries within the mapped group, never to `selection` galleries.
- **Revocation Behavior (Important):** - The access to Delivery galleries is evaluated **dynamically** on every request (`getAllowedGalleryIds()`). If you delete a domain mapping, the user *instantly* loses access to those galleries.
  - However, the `Role` and the `GalleryGroup` assignment granted during registration are synced persistently to the user's database record (`user_roles`, `user_gallery_groups`). Deleting the mapping does **not** revoke the Role or the Group assignment automatically. This requires manual adjustment in the User Management UI.

## 4. IDOR Protection
- Every controller method accessing a specific resource (e.g., a photo or a gallery) MUST verify that the authenticated user's ID (or transient claim) is linked to that resource via the `getAllowedGalleryIds()` logic.
