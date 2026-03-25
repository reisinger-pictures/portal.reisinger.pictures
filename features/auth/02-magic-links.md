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
