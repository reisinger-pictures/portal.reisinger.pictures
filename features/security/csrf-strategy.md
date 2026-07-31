# CSRF Strategy

## Status: Implemented (2026-07-14)

## Architecture

- **Frontend (SPA)**: `portal.reisinger.pictures` (port 443)
- **Backend (API)**: same-origin at `portal.reisinger.pictures/api/*` (Caddy FastCGI to `portal_backend`)

The SPA and the API are served from the same origin, so `SameSite=Lax` applies trivially — there is no cross-origin scenario anymore, and same-origin requests always include the cookie.

## Protection Mechanism

### SameSite=Lax

The auth cookie `rp_jwt` is set with `SameSite=Lax`:

```php
cookie('rp_jwt', $token, $ttl, '/', null, $secure, true, false, 'Lax');
```

`SameSite=Lax` ensures:

| Request origin | Request type | Cookie sent? |
|---|---|---|
| `portal.reisinger.pictures` → `/api/*` (same-origin) | SPA fetch/XHR | ✅ Yes |
| `portal.reisinger.pictures` → `/api/*` (same-origin) | Navigation | ✅ Yes |
| `attacker.com` → `portal.reisinger.pictures` | `<form>` POST (cross-site) | ❌ No |
| `attacker.com` → `portal.reisinger.pictures` | `fetch()` with `credentials: 'include'` (cross-site) | ❌ No |
| `attacker.com` → `portal.reisinger.pictures` | Top-level GET navigation (cross-site) | ⚠️ Safe methods only |

### Defense-in-Depth

1. **JSON-only API**: Every endpoint expects `Content-Type: application/json`. A `<form>` POST sends `application/x-www-form-urlencoded` — the API does not process it.
2. **Same-origin API (no CORS needed)**: The API is served same-origin under `/api/*`, so the original CORS allowlist is obsolete. Cross-site requests cannot carry the cookie (`SameSite=Lax`) and fail at the auth layer.
3. **httpOnly cookie**: The JWT is inaccessible to JavaScript (`HttpOnly=true`), preventing token theft via XSS.
4. **Role-based authorization**: All state-changing operations require specific roles (`management`, `super_admin`). Even if a CSRF succeeded, the attacker would need privilege escalation.

## Why not Sanctum Stateful-CSRF?

- Sanctum is not installed and would require a new dependency.
- The app uses JWT (stateless) for all auth — adding Sanctum's stateful SPA auth alongside would introduce two parallel authentication mechanisms without clear benefit.
- `SameSite=Lax` provides sufficient protection for same-site architecture.

## History

- **Pre-2026-07-14**: `Controller.php` set `SameSite=None` in production (`$secure ? 'None' : 'Lax'`). This was incorrect — it treated cross-origin (different subdomain) as cross-site, needlessly disabling SameSite protection. `SameSite=None` allowed CSRF via cross-site `<form>` POSTs.
- **2026-07-14**: Changed to always use `SameSite=Lax`. This fixes cross-site CSRF exposure while maintaining full SPA functionality (same-site requests are unaffected).
