---
domain: auth
topic: roles-and-rbac
status: planned
---

# Technical Concept: Roles & Access Management (Single Tenant)

## 1. Enterprise Role Model (RBAC 2.0)
To support scalable B2B governance without cluttering the `users` table with boolean flags, permissions are strictly managed via the n:m `roles` table.

**Defined Roles:**
- **Global Admin (`admin`):** Full system access across all tenants.
- **Photographer (`photographer`):** Operational user. Manages assigned galleries and uploads.
- **Customer Manager (`customer_manager`):** Tenant-specific admin. Can manage users within their own organization (domain) and view tenant-wide audit logs. UI is shared with Global Admins but scoped to their `tenant_id`.
- **Power-User (`power_user`):** Authorized to purchase resolution upgrades on invoice (Delta-Pricing).
- **Client (`client`):** Standard user. Can only consume included flat-rate downloads. Upgrades are hidden.

*Note on Flatrates:* The `flatrate_level` remains a string column on the `users` table, as it represents a contractual quota (e.g., 'web', 'print', 'original'), not a true/false permission.

## 2. External Onboarding (Blind Invites)
- **GDPR Compliance:** To prevent user enumeration, inviting external users is done via a "Blind Invite". The UI always confirms the email dispatch, regardless of whether the account already exists.
- **Opt-In:** The invited user receives a token link. Joining the platform/gallery requires a mandatory, explicit opt-in click by the user.

## 3. Self-Service Registration
- **Double Opt-In:** Users can register themselves. The system sends a verification email.
- **Password Assignment:** The password is only assigned *after* the email has been successfully verified via the token link.
