---
domain: auth
topic: magic-links
status: active
---

# Technical Concept: Magic Links & Invites

## 1. Invite Links Workflow
- Photographers can generate invite links for a gallery (`/invite/{token}`).
- **Unified Redemption Workflow:** 1. A user clicks an anonymous or personal invite link.
  2. The system prompts for an email address (if not already known via a personal link).
  3. The system sends a *personal* Magic Link to that email.
  4. Clicking the emailed Magic Link seamlessly logs the user into the frontend via a redirect.
- Gallery invite tokens are **revocable access grants, not one-time registration tokens**: redeeming one does not consume the `GalleryInvite` record, so the same link can be redeemed again until it is explicitly revoked. The one-time-token contract belongs to the separate CRM model-registration flow.

## 2. Implicit Rights (Transient Tokens)
- We DO NOT create "dummy users" or background user records in the database just because an invite link was clicked.
- Instead, redeeming an invite link grants **transient access rights** as structured abilities on a Sanctum token, carried by the `sanctum_transient` guard (D-73). The abilities are transport only — `sanitizeTransientClaims()` / `filterGuestTransientClaims()` revalidate them against live invite state on every request (D-39/D-44).
- If an *already logged-in* user clicks an invite link from someone else, they are granted transient access to view/rate that specific gallery for the duration of their session, but the gallery is NOT permanently written to their `user_galleries` pivot table (dashboard).

## 3. Transient Abilities & Database Decoupling (Architecture Update)
- **Problem:** Redeeming links used to create dummy users (e.g., `@invite.local`) or permanently attach galleries to existing users via the `user_galleries` pivot table, polluting the database.
- **Current State (Transient Access):** When an anonymous link is redeemed, the backend issues a Sanctum token whose `TransientClaims` carry `guestId`, `transientInviteIds`, `transientGalleries` and `transientMetaGalleries`. The `sanctum_transient` guard hydrates a DB-less `User` for the guest (`guest_<uuid>`), so there is **no** row in the `users` table and no JWT (removed in D-77).
  - If a logged-in user redeems a link, their token is reissued with the appended transient gallery/invite claims. The gallery is **not** saved to `user_galleries`, keeping their dashboard clean.
- **Database Schema Clash:** The `ratings` table keeps `user_id` nullable, and `guest_id` (UUID/string) associates ratings with the transient guest identity. V038 adds the nullable `actor_key` (`user:<id>` or `guest:<id>`) plus a portable per-photo uniqueness index; ownerless legacy rows remain nullable and are not assigned a guessed identity.

## Related
- [Roles & Access Management](../auth/01-roles-and-access.md) � role-based access model that magic links complement
- [Roles & Access Management (Single Org)](../auth/03-roles-and-rbac.md) � enterprise RBAC alignment with transient access
