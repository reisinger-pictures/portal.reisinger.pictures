---
domain: auth
topic: permissions-hook
status: active
---

# Technical Concept: Frontend Permissions Hook

## 1. Motivation

The legacy `useBrandAccess` hook mixed two unrelated concerns:

- **Brand identification** (which hostname/theme): `isB2B`, `isATR`
- **Authorization** (what the user is allowed to see/do): `isStaff`, `canAccessB2BFeatures`, etc.

This was incorrect because feature access should be derived from the **authenticated user's role**, not from the current brand hostname. A staff user on the ATR brand should still see B2B management features, and a regular customer on the B2B brand should **not** see admin features.

## 2. Separation of Concerns

| Hook | Responsibility |
|------|---------------|
| `useBrand` | Brand identity & styling (logo, portal name, theme) |
| `usePermissions` | Role-based feature access booleans |
| `useAuth` | Authentication state (user, login, logout) |

`useBrand` remains the single source of truth for brand-specific UI/styling.
`usePermissions` is the single source of truth for role-derived permission flags.

## 3. usePermissions API

Consumes `useAuth` and exposes derived booleans:

```
isStaff                  → super_admin || admin || photographer || org_admin
canAccessB2BFeatures     → isStaff (not brand-dependent)
showOrgsSection       → canAccessB2BFeatures
showCRM                  → canAccessB2BFeatures
showInvoicing            → canAccessB2BFeatures
showPayouts              → is_super_admin
```

## 4. Migration

- `useBrandAccess.ts` is removed.
- All consumers of `canAccessB2BFeatures`, `showOrgsSection`, `isStaff` etc. import from `usePermissions` instead.
- `isB2B` / `isATR` are available through `useBrand` if needed.

## Related

- [Roles & Access Management](01-roles-and-access.md)
- [Frontend Brand & Org Isolation](../infrastructure/10-frontend-brand-Org-isolation.md)
