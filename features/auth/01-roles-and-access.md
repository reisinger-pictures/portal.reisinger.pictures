---
domain: auth
topic: roles-and-access
status: active
---

# Technical Concept: Roles & Access Management

## 1. Authentication Concept
- **Sanctum tokens, no JWT package:** The `api` guard keeps its name but is backed by the custom `sanctum_transient` driver (D-73/D-74). `php-open-source-saver/jwt-auth`, its config and its legacy driver were removed in Phase 4 (D-77). There are no PHP sessions (the `web` guard is deactivated) and no `/sanctum/csrf-cookie`; CSRF for mutations stays double-submit, [D-77](../owner-decisions.md#d-77-signed-links-move-completely-to-hmac--jwt-package-removed).
- **Storage & XSS Prevention:** Tokens are NEVER sent in the JSON body or stored in `localStorage`. They travel in two secure, `HttpOnly`, `SameSite=Lax` cookies: `rp_jwt` (access) and `rp_jwt_refresh` (refresh), [D-75](../owner-decisions.md#d-75-two-httponly-cookies-with-sanctum-tokens-no-php-session). The cookie names are unchanged from the JWT era so already-issued credentials and the frontend keep working.
- **Cookie precedence:** A request that carries the auth cookie and no `Authorization` header is a browser session, and the cookie decides who it is. `AuthenticateJwtCookie` promotes the cookie to the header the guard resolves first, so a mutation body that carries a `token` (redeeming an organization or gallery invite) is not mistaken for anonymous. An explicit bearer token keeps precedence; a request without the cookie is untouched. `SanctumApiGuard` clears a credential-resolved user when the request changes, so a reused process cannot inherit the previous caller's identity.
- **Silent Refresh:** The frontend Axios/Fetch layer intercepts `401 Unauthorized` errors, calls `/api/auth/refresh` to get a new cookie, and retries the original request seamlessly. The endpoint stays a shim with the same windows (D-76).
- **Session Lifetime:** Access-Token TTL 240 min; Refresh-Fenster **7 Tage**. Die Werte liegen jetzt in `auth.migration.access_ttl` / `auth.migration.refresh_ttl` (`backend/config/auth.php`, überschreibbar über `AUTH_ACCESS_TTL` / `AUTH_REFRESH_TTL`), nicht mehr in `config/jwt.php` (entfernt). Nach 7 Tagen Inaktivität ist ein Re-Login erforderlich.

## 2. Role System
Roles are strictly separated.
- **Super Admin (`is_super_admin`):** Highest privilege level. Can manage billing details, system settings, payouts, watermark SVG, license catalog, and Org-wide configurations. Has implicit access to all galleries and resources without explicit permission checks. Used in `infrastructure/10-frontend-brand-tenant-isolation.md` for Org-unrestricted access.
- **Admin (`is_admin`):** Broad management access. Manages users, roles, and global settings (watermark). Can view statistics for ALL galleries across the system. Does **not** have implicit super-user access to private galleries or files.
- **Accounting (`accounting`):** Bookkeeping staff with access restricted to the EAR endpoints (`/api/management/ea/*`). Has no access to gallery, billing, user or other management areas; EAR settings changes remain `super_admin`-only. `super_admin` holds this capability implicitly (like the admin capability). Not an org-delegable role — bookkeeping is a studio capability, not a customer-account permission. See [Roles & RBAC](03-roles-and-rbac.md).
- **Photographer (`is_photographer`):** Operational user. Can create/edit/delete galleries, upload photos, manage metadata, send invites, and view statistics ONLY for their *own* galleries or when they are added to a specific gallery.
- **Client (`is_client`):** Standard registered user. Has explicit access to specific galleries. 
  - In *Selection Galleries*: Can view, rate, and comment.
  - In *Delivery Galleries*: Can view, download, and edit IPTC metadata (if the gallery allows it and the user has the `can_edit_metadata` flag).
- **Guest (Transient Access):** Unregistered or unregistered-equivalent users accessing the system via Magic Links. Their access rights are bound to structured Sanctum abilities on a DB-less user, transported by the `sanctum_transient` guard (D-73), not persistent database user records and not JWT claims. The abilities are transport only: `sanitizeTransientClaims()` / `filterGuestTransientClaims()` revalidate them against live invite state on every request (D-39/D-44).
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
- **Narrow exception — the caller's own notification subscription (D-45, 2026-10-20).** `NotificationController::toggleGalleryOptIn()` verifies the link through `canAccessGallery()` (which resolves `getAllowedGalleryIds()`) whenever a request would **create** a subscription row: without a verified link that request is refused with `403`. A request for a gallery the caller is **already subscribed to** is answered from the caller's own subscription row instead — it touches only that row of the caller and grants nothing: it either removes the row (a revoke) or refreshes its `updated_at` (a repeated subscribe). It never creates a row that does not exist and never grants access. The exception exists because a subscription outlives the access that justified it (the membership that justified the subscription can be revoked), and the caller must be able to revoke what is left. It does not widen any access: the creation of a subscription row stays fully guarded, and the row of another user is never read or written.
- **Narrow exception — the caller's own group notification subscription (D-46, 2026-10-21).** `NotificationController::toggleGroupOptIn()` verifies the link through the group membership (`user_gallery_groups`) whenever a request would **create** a subscription row: without a verified membership that request is refused with `403` — unchanged from before the decision. A request for a group the caller is **already subscribed to** is answered from the caller's own subscription row instead — it touches only that row of the caller and grants nothing: it either removes the row (a revoke) or refreshes its `updated_at` (a repeated subscribe). It never creates a row that does not exist and never grants access, and the row of another user is never read or written. The exception exists for the same reason as for the gallery subscription: the subscription outlives the membership that justified it, and the caller must be able to revoke what is left.
- **The two subscription contracts are pinned end-to-end.** Since 2026-10-21 the
  Playwright specs walk the real client flow and prove both halves of D-45 and
  D-46: an opt-in **never** keeps the gallery or the group reachable after the
  membership that justified it is revoked, and the subscription **outlives**
  that membership, stays visible (with the name redacted) and can be revoked
  again. `frontend/tests/e2e/client/notification-subscription.spec.ts`; the
  subscriber count of the „E-Mail senden" dialog is pinned in
  `frontend/tests/e2e/admin/galleries/gallery-notified-count.spec.ts`. The
  revert-to-red proof is in the commit that introduced them: with the
  pre-D-45/D-46 write path the specs fail, with the current path they pass.

## 5. Read-time re-validation of persistent grants (D-39)
- A persistent client grant (`user_galleries`, `user_gallery_groups`) is **re-validated on every read**, not only when it is granted. A grant that became unsafe after it was issued is fail-closed and hands out nothing.
- **Direct grants:** a selection gallery never travels through a persistent client grant. Only a delivery gallery does.
- **Group grants:** a group grant resolves the whole subtree of the granted group. When that subtree holds a selection gallery, the grant is refused as a whole — the delivery galleries of that subtree are handed out neither. The same holds for a grant on an *ancestor* of that unsafe group: it carries the same broad subtree permission.
- The group rule is the same one the new-grant path applies (`getOrgAssignableGalleryGroupIds()`), so a group grant that could not have been issued cannot survive on disk. Both paths share one computation. The direct-assignment writers are narrowed to delivery galleries by D-43, so a direct grant that could not have been issued does not survive either.
- **Paths that are not client grants keep their behaviour:** the transient invite claim (the way a client legitimately reaches a selection gallery to rate it), the photographer surface (`photographer_galleries`, `photographer_gallery_groups`, the unrestricted gallery list), the organization scope (a delivery-only projection) and the cross-brand Super-Admin, whose management access never depends on a client grant.
- The exemption is narrow: only a **cross-brand** Super-Admin (persisted, brand `null`, Super-Admin) is exempt. A brand-bound admin or a brand-bound Super-Admin is bound by the re-validation, so a legacy selection assignment of such an actor no longer appears in `getAllowedGalleryIds()`.
- **Consequence for a brand-bound admin:** the client-facing gallery view of a legacy selection assignment disappears (`GET /api/galleries/{slug}` → 403) while the management surface (`canManageGallery()`) stays. An actor without such an assignment already got that refusal before the re-validation, so it removes an inconsistency rather than opening a gap.
- The reserved-null-brand checks are applied **before** the re-validation (early returns); the brand scoping filter is applied after it and is unaffected by it.

## 6. The read path: resolve once, do not cache

The read path (`AuthorizationService::getAllowedGalleryIds()`) is on every request of the SPA
(`/api/auth/me` runs on each load). It resolves two things that used to be resolved repeatedly
per gallery and per request. Both changes are **performance only** — the access decision itself
is unchanged, and a test proves it (the set-based filter and the per-gallery walk must select the
same galleries). Measured: the brand filter goes from **35** statements and **30** chain lookups
to **7** and **2** for 15 galleries (and from 11/6 to 7/2 for 3), `/api/auth/me` from **30**
statements with **3** scope reads to **22** with **1**, the org detail endpoint from 29/2 to
25/1. The pinned baselines carry the numbers.

- **The brand filter resolves the group chain set-based.** The complete tree of every candidate
  gallery is loaded at once (the candidate groups plus their ancestor closure) and the brand
  decision is taken in memory. The number of statements depends on the **depth** of the group
  tree, not on the number of galleries. The two paths are pinned against each other: an
  equivalence test computes the set the **per-gallery** walk produces and requires the
  set-based filter to select exactly the same galleries — including the edge cases (a gallery
  without a group, a gallery whose group row is missing, a null brand somewhere in the chain, a
  foreign-brand ancestor, a chain deeper than the depth limit). A change that lets the two
  diverge turns that test red.
- **The management path resolves the group chain set-based too.** `canManageGalleryGroup()`
  (the rename/delete path, the access sync, the group request) resolved the chain of the
  group and of **every gallery of its subtree** per gallery: one `GalleryGroup::find()` per
  level, plus one `Gallery::find()` per gallery. The subtree's groups and their ancestor
  closure are now loaded **once**, the gallery list is loaded **once**, and the role
  decisions (super-admin, admin, photographer) are taken **before** the loop — they are
  actor-scoped, not gallery-scoped. What stays in the loop is genuinely per gallery: the
  gallery's own brand, the chain of *its* group (read from the loaded map, no statement),
  its own restriction rule and its own assignment. Measured: a subtree with one restricted
  gallery 18 → 10 statements, a gallery in a nested group 20 → 10, a three-gallery subtree
  40 → 12. The number of statements no longer grows with the **group-chain lookups** of
  the subtree — the chain of a gallery is no longer resolved per gallery. The **total**
  still grows with the number of galleries, by one statement per restricted gallery (the
  personal-assignment check) and one per gallery whose own restriction is inherited (the
  model's group load). Measured, own restriction: 1 gallery 10, 3 galleries 12, 10
  galleries 19 statements (before: 18, 40, 117).
  **Scope:** this is **not** a read path — the callers are `GalleryController:91/445`,
  `GroupRequest:44`, `GalleryService:459`, one group per call. The gain is **0–10
  statements per call** (≈8–10 in the typical restricted-gallery case) and grows with the
  subtree (98 statements for ten galleries) — measured, not a target. It applies only to a
  photographer who holds no personal assignment to the **target** group and whose subtree
  holds galleries: the loop over the subtree is skipped only for a photographer who holds
  the target group itself — an assignment to an ancestor or a descendant does not skip it.
  What the baseline actually buys is the proof that the **group-chain lookups** no longer
  scale with the subtree, and that the decisions of the per-gallery walk are reproduced.
  **The remaining per-gallery cost** is (a) the personal-assignment check
  (`photographer_galleries`), one statement per restricted gallery, and (b) the gallery's
  own restriction rule (`Gallery::effective_restricted_photographers`), which reads the
  gallery's group and its parent chain per gallery (1 + the group's depth) when the
  gallery's own flag is null. (b) belongs to the model, not to the brand chain, and was
  deliberately not reimplemented here.
- **The org group scope is resolved once per request.** `getOrgGroupScope()` reads the
  `gallery_group_org` pivot and walks the subtree; it is memoized per org id **inside the
  request**. The memo lives in a `scoped` container store, so the invalidation reaches every
  instance of the service — a directly constructed service cannot hold a stale scope either.
  The store is flushed with the other scoped instances: by Laravel before every queue job, and
  in the HTTP path by this deployment's request model (a fresh application instance per request
  under php-fpm). A long-lived worker that reuses one application instance would have to flush
  the scoped instances itself.
- **The memo is dropped by every write that can revoke a delegation.** A write to the
  `gallery_group_org` pivot or to the `gallery_groups` tree invalidates the memo. The
  invalidation is wired on the database connection **and** on the query event, so that neither a
  forgotten dispatcher listener (the query-counting test helpers remove those) nor a replaced
  connection can leave a stale scope behind.
- **There is deliberately no cache across requests.** A cached scope would hand out a delegation
  that was revoked in the meantime — a detached group, a changed tree, a moved gallery. That is
  a fail-open, so the service stays stateless across requests and only the repeated resolution
  *within* one request is removed.

The baselines are pinned in `OrgScopeQueryBaselineTest`, `BrandFilterChainQueryBaselineTest`
and `GalleryGroupManageQueryBaselineTest`. They pin a **measured** state, not a target: a
deliberate change of the cost is expected to turn them red and to update them in the same
change. The management baseline pins the **decisions** of its fixture cases against a
reproduction of the per-gallery walk *and* the statement count per case, so an optimisation
that changes an access decision and one that only changes the cost are distinguishable.
It pins the four rules of the loop that a mutation could silently drop — the gallery's own
brand, the unrestricted gallery, the photographer's group subtree in both directions, and
the chain of the gallery's own group (the last one an access-widening mutation that no
other test in the repository caught) — and the measured scaling pairs
(1 gallery 18 → 10, 3 galleries 40 → 12, 10 galleries 117 → 19).

## 7. Sanctum-Token-Speicher (V066) — JWT → Sanctum migration

Phase 1 of Portal #9 added this storage table as a **schema-only, additive change**.
Phases 3/4 then flipped the `api` guard to `sanctum_transient` and removed
`php-open-source-saver/jwt-auth` entirely
([D-77](../owner-decisions.md#d-77-signed-links-move-completely-to-hmac--jwt-package-removed)).
The guard name stays `api` and only its driver changed (D-74); the two httpOnly
cookies keep their names and the 240-minute/7-day windows (D-75/D-76); roles stay
live DB queries, never stored abilities (D-78). The table below is unchanged by
the later phases — there is nothing to backfill, since JWT cookies were never
token rows. `backend/tests/Feature/Auth/LegacyAuthContractTest.php` now pins the
Sanctum cookie/guest contract and the `/api/auth/refresh` shim; the two old
JWT-wire-format pins were adapted to it.

**Why the table is needed.** [D-73](../owner-decisions.md#d-73-transient-guest-grants-as-structured-abilities-behind-a-custom-guard)
models transient guest rights (guest id, redeemed invites, gallery grants) as
structured Sanctum abilities transported by the custom guard `sanctum_transient`;
[D-75](../owner-decisions.md#d-75-two-httponly-cookies-with-sanctum-tokens-no-php-session)
carries the tokens in two httpOnly cookies (`rp_jwt`, `rp_jwt_refresh`) with no PHP
session and no `/sanctum/csrf-cookie`. Sanctum needs this table to store the token
records those mechanisms reference. Abilities remain **transport, not decision**:
`sanitizeTransientClaims()` / `filterGuestTransientClaims()` keep revalidating against
live invite state on every request (D-39/D-44), and roles stay live DB queries in
`AuthorizationService`, never stored abilities (D-78). The guard name `api` stays and
only its driver changes (D-74); `/api/auth/refresh` stays a shim with the unchanged
240-minute/7-day windows (D-76); no service-to-service scope tokens are introduced
(D-72, consistent with D-71).

**Schema decision.** `V066__create_personal_access_tokens.php` creates one new table
`personal_access_tokens` based on Sanctum's standard schema (as shipped in
`vendor/laravel/sanctum/database/migrations/`): `id`, `tokenable_type` (string),
`tokenable_id` (string), a composite `tokenable_type,tokenable_id` index, `name` (text),
`token` (string 64, unique), `abilities` (text, nullable), `last_used_at` (timestamp,
nullable), `expires_at` (timestamp, nullable, indexed), and `created_at`/`updated_at`.
Column and index creation is guarded with `Schema::hasTable` / `Schema::hasIndex` so a
replay is safe. No existing table, column or index is altered. The canonical migration
frontier and its measured count live only in [AD-2](../tech/07-architectural-decisions.md).

The one deliberate deviation from the Sanctum stock migration is `tokenable_id`:
`morphs('tokenable')` would create an `unsignedBigInteger` column, but portal actor IDs
are 36-character UUIDs (`users.id` via `HasUuids`) or, for DB-less guests,
`guest_<uuid>` (42 characters — `InviteController::redeem`, `ActorIdentity`). A bigint
column rejects those values on PostgreSQL and silently corrupts them on MySQL. A
`string` column holds both forms; Sanctum resolves the morph through `morphTo`
independently of the column type. This keeps [D-73](../owner-decisions.md#d-73-transient-guest-grants-as-structured-abilities-behind-a-custom-guard)'s
DB-less guest identity possible without a synthetic `users` row.

**No backfill.** There is nothing to copy: existing JWT cookies are not token rows, and
tokens are issued fresh after the cutover. Seeding the table would create credentials
outside the login/refresh flow, so none is performed.

**Rollback.** `down()` is deliberately empty — `backend/AGENTS.md` states `down()` is
never executed, and a rollback of this additive table must not drop data written after
the migration. Reversibility here means the change adds a table without touching any
existing state.

## Related
- [Magic Links & Invites](../auth/02-magic-links.md) � transient access for guests overriding role model
- [Roles & Access Management (Single Org)](../auth/03-roles-and-rbac.md) � enterprise RBAC evolution of the role system
- [Audit Logs & GDPR](../delivery/02-audit-logs.md) � access logging and data minimization
- [Frontend Brand & Org Isolation](../infrastructure/10-frontend-brand-tenant-isolation.md) � Super Admin Org-unrestricted access
