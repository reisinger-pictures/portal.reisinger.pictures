---
domain: auth
topic: roles-and-rbac
status: active
---

# Technical Concept: Roles & Access Management (Single Org)

## 1. Enterprise Role Model (RBAC 2.0)
To support scalable B2B governance without cluttering the `users` table with boolean flags, permissions are strictly managed via the n:m `roles` table.

**Defined Roles:**
- **Super Admin (`super_admin`):** Highest enterprise role. Full system access across all tenants including billing, payout, watermark, license catalog, and system-level configuration. Not scoped by `tenant_id` — operates globally. See `infrastructure/10-frontend-brand-Org-isolation.md`.
- **Global Admin (`admin`):** Full system access across all tenants.
- **Photographer (`photographer`):** Operational user. Manages assigned galleries and uploads.
- **Organisation Admin (`org_admin`):** Org-specific admin. Can manage users within their own organization (domain) and view Org-wide audit logs. UI is shared with Global Admins but scoped to their `tenant_id`.
- **Power-User (`power_user`):** Authorized to purchase resolution upgrades on invoice (Delta-Pricing).
- **Client (`client`):** Standard user. Can only consume included flat-rate downloads. Upgrades are hidden.

*Note on Flatrates:* The `flatrate_level` remains a string column on the `users` table, as it represents a contractual quota (e.g., 'web', 'print', 'original'), not a true/false permission.

## 2. Notes & Compatibility

- **Role rename:** `customer_manager` was renamed to `org_admin` in 2026. All new code uses `org_admin` exclusively.
- **Deprecated alias `is_customer_manager`:** The backend exposes `is_customer_manager` as a `@deprecated` attribute (aliased to `is_org_admin`) for backward compatibility. New code must use `org_admin` / `is_org_admin` exclusively. The frontend `UserRole` enum still lists `CUSTOMER_MANAGER = 'customer_manager'` for legacy DB entries; the `org_admin` role maps to the same permission set.

## 3. External Onboarding (Blind Invites)
- **GDPR Compliance:** To prevent user enumeration, inviting external users is done via a "Blind Invite". The UI always confirms the email dispatch, regardless of whether the account already exists.
- **Opt-In:** The invited user receives a token link. Joining the platform/gallery requires a mandatory, explicit opt-in click by the user.

## 4. Self-Service Registration
- **Double Opt-In:** Users can register themselves. The system sends a verification email.
- **Password Assignment:** The password is only assigned *after* the email has been successfully verified via the token link.

## 5. Related
- [Roles & Access Management](../auth/01-roles-and-access.md) � current boolean-flag role model that RBAC replaces
- [Magic Links & Invites](../auth/02-magic-links.md) � transient access handling in the RBAC model
