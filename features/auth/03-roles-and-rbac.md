---
domain: auth
topic: roles-and-rbac
status: planned
---

# Technical Concept: Roles & Access Management (Single Tenant)

## 1. Simplified Role Model (RBAC)
With the removal of Multi-Tenancy, the role model is strictly linear:
- **Global Admin:** Full system access.
- **Photographer:** Can manage assigned galleries, upload photos, and view specific statistics.
- **Client (Power-User):** Can consume flat-rate downloads AND has the entitlement to purchase resolution upgrades on invoice.
- **Client (Standard-User):** Can only consume included flat-rate downloads. Upgrade options are hidden or disabled.

## 2. External Onboarding (Blind Invites)
- **GDPR Compliance:** To prevent user enumeration, inviting external users is done via a "Blind Invite". The UI always confirms the email dispatch, regardless of whether the account already exists.
- **Opt-In:** The invited user receives a token link. Joining the platform/gallery requires a mandatory, explicit opt-in click by the user.

## 3. Self-Service Registration
- **Double Opt-In:** Users can register themselves. The system sends a verification email.
- **Password Assignment:** The password is only assigned *after* the email has been successfully verified via the token link.
