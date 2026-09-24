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

## 2. Implicit Rights (Transient Tokens)
- We DO NOT create "dummy users" or background user records in the database just because an invite link was clicked.
- Instead, redeeming an invite link grants **transient access rights** via a temporary JWT claim or specialized token. 
- If an *already logged-in* user clicks an invite link from someone else, they are granted transient access to view/rate that specific gallery for the duration of their session, but the gallery is NOT permanently written to their `user_galleries` pivot table (dashboard).


## 3. Transient JWT Claims & Database Decoupling (Architecture Update)
- **Problem:** Currently, redeeming links creates dummy users (e.g., `@invite.local`) or permanently attaches galleries to existing users via the `user_galleries` pivot table. This pollutes the database.
- **Target State (Transient Access):** - When an anonymous link is redeemed, we generate a custom JWT containing transient claims: `transient_galleries: [gallery_id]` and a `guest_id` (UUID). We do **not** insert a row into the `users` table.
  - If a logged-in user redeems a link, we reissue their JWT to append the `transient_galleries` claim. The gallery is **not** saved to `user_galleries`, keeping their dashboard clean.
- **Database Schema Clash:** To implement this, the `ratings` table must be migrated. The `user_id` foreign key is nullable, and `guest_id` (UUID/string) associates ratings with the transient JWT claim. V038 adds the nullable `actor_key` (`user:<id>` or `guest:<id>`) plus a portable per-photo uniqueness index; ownerless legacy rows remain nullable and are not assigned a guessed identity.

## Related
- [Roles & Access Management](../auth/01-roles-and-access.md) � role-based access model that magic links complement
- [Roles & Access Management (Single Org)](../auth/03-roles-and-rbac.md) � enterprise RBAC alignment with transient access
