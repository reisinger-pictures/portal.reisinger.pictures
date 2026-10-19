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
- **Session Lifetime (SOLL, 2026-08-13):** Access-Token TTL 240 min (`JWT_TTL`); Refresh-Fenster **7 Tage** (`JWT_REFRESH_TTL=10080`), rolling via `refresh_iat=true`. Nach 7 Tagen Inaktivität ist ein Re-Login erforderlich (härtet den Rolling-Refresh ab — vorher 14 Tage). Prod-Steuerung über Portainer-Env `JWT_REFRESH_TTL`.

## 2. Role System
Roles are strictly separated.
- **Super Admin (`is_super_admin`):** Highest privilege level. Can manage billing details, system settings, payouts, watermark SVG, license catalog, and Org-wide configurations. Has implicit access to all galleries and resources without explicit permission checks. Used in `infrastructure/10-frontend-brand-tenant-isolation.md` for Org-unrestricted access.
- **Admin (`is_admin`):** Broad management access. Manages users, roles, and global settings (watermark). Can view statistics for ALL galleries across the system. Does **not** have implicit super-user access to private galleries or files.
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

## 5. Read-time re-validation of persistent grants (D-39)
- A persistent client grant (`user_galleries`, `user_gallery_groups`) is **re-validated on every read**, not only when it is granted. A grant that became unsafe after it was issued is fail-closed and hands out nothing.
- **Direct grants:** a selection gallery never travels through a persistent client grant. Only a delivery gallery does.
- **Group grants:** a group grant resolves the whole subtree of the granted group. When that subtree holds a selection gallery, the grant is refused as a whole — the delivery galleries of that subtree are handed out neither. The same holds for a grant on an *ancestor* of that unsafe group: it carries the same broad subtree permission.
- The group rule is the same one the new-grant path applies (`getOrgAssignableGalleryGroupIds()`), so a group grant that could not have been issued cannot survive on disk. Both paths share one computation. The direct-assignment writers are narrowed to delivery galleries by D-43, so a direct grant that could not have been issued does not survive either.
- **Paths that are not client grants keep their behaviour:** the transient invite claim (the way a client legitimately reaches a selection gallery to rate it), the photographer surface (`photographer_galleries`, `photographer_gallery_groups`, the unrestricted gallery list), the organization scope (a delivery-only projection) and the cross-brand Super-Admin, whose management access never depends on a client grant.
- The exemption is narrow: only a **cross-brand** Super-Admin (persisted, brand `null`, Super-Admin) is exempt. A brand-bound admin or a brand-bound Super-Admin is bound by the re-validation, so a legacy selection assignment of such an actor no longer appears in `getAllowedGalleryIds()`.
- **Consequence for a brand-bound admin:** the client-facing gallery view of a legacy selection assignment disappears (`GET /api/galleries/{slug}` → 403) while the management surface (`canManageGallery()`) stays. An actor without such an assignment already got that refusal before the re-validation, so it removes an inconsistency rather than opening a gap.
- The reserved-null-brand checks are applied **before** the re-validation (early returns); the brand scoping filter is applied after it and is unaffected by it.

## Related
- [Magic Links & Invites](../auth/02-magic-links.md) � transient access for guests overriding role model
- [Roles & Access Management (Single Org)](../auth/03-roles-and-rbac.md) � enterprise RBAC evolution of the role system
- [Audit Logs & GDPR](../delivery/02-audit-logs.md) � access logging and data minimization
- [Frontend Brand & Org Isolation](../infrastructure/10-frontend-brand-tenant-isolation.md) � Super Admin Org-unrestricted access
