# CSRF Strategy

## Status: Implemented (2026-07-14)

## Architecture

- **Frontend (SPA)**: `portal.reisinger.pictures` (port 443)
- **Backend (API)**: `api.reisinger.pictures` (port 443)

Both URLs share the same registrable domain (`reisinger.pictures`), making them **same-site** per the [SameSite specification (RFC 6265bis)](https://datatracker.ietf.org/doc/html/draft-ietf-httpbis-rfc6265bis). Cross-origin (different subdomain) does **not** imply cross-site.

## Protection Mechanism

### SameSite=Lax

The auth cookie `rp_jwt` is set with `SameSite=Lax`:

```php
cookie('rp_jwt', $token, $ttl, '/', null, $secure, true, false, 'Lax');
```

`SameSite=Lax` ensures:

| Request origin | Request type | Cookie sent? |
|---|---|---|
| `portal.rp` → `api.rp` | SPA fetch/XHR (same-site) | ✅ Yes |
| `portal.rp` → `api.rp` | Navigation (same-site) | ✅ Yes |
| `attacker.com` → `api.rp` | `<form>` POST (cross-site) | ❌ No |
| `attacker.com` → `api.rp` | `fetch()` with `credentials: 'include'` (cross-site) | ❌ No |
| `attacker.com` → `api.rp` | Top-level GET navigation (cross-site) | ⚠️ Safe methods only |

### Defense-in-Depth

1. **JSON-only API**: Every endpoint expects `Content-Type: application/json`. A `<form>` POST sends `application/x-www-form-urlencoded` — the API does not process it.
2. **CORS restriction**: The API only allows the SPA origin (`portal.reisinger.pictures`) with `Access-Control-Allow-Credentials: true`. Cross-site `fetch()` preflight fails.
3. **httpOnly cookie**: The JWT is inaccessible to JavaScript (`HttpOnly=true`), preventing token theft via XSS.
4. **Role-based authorization**: All state-changing operations require specific roles (`management`, `super_admin`). Even if a CSRF succeeded, the attacker would need privilege escalation.

## Why not Sanctum Stateful-CSRF?

- Sanctum is not installed and would require a new dependency.
- The app uses JWT (stateless) for all auth — adding Sanctum's stateful SPA auth alongside would introduce two parallel authentication mechanisms without clear benefit.
- `SameSite=Lax` provides sufficient protection for same-site architecture.

## History

- **Pre-2026-07-14**: `Controller.php` set `SameSite=None` in production (`$secure ? 'None' : 'Lax'`). This was incorrect — it treated cross-origin (different subdomain) as cross-site, needlessly disabling SameSite protection. `SameSite=None` allowed CSRF via cross-site `<form>` POSTs.
- **2026-07-14**: Changed to always use `SameSite=Lax`. This fixes cross-site CSRF exposure while maintaining full SPA functionality (same-site requests are unaffected).
