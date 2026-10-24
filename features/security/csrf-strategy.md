# CSRF Strategy

## Status: Implemented with review requested (2026-10-23)

## Architecture

- The React SPA and `/api/*` are same-origin in production.
- The SPA uses `credentials: 'include'` and the API issues the `rp_jwt` access
  cookie and `rp_jwt_refresh` refresh cookie as `HttpOnly; SameSite=Lax`.
- Laravel trusts only the direct proxy peer for `X-Forwarded-For` and
  `X-Forwarded-Proto`; it deliberately does not trust `X-Forwarded-Host`.
  Vite's local proxy changes `Host`, so local browser origins are compared with
  the configured `app.frontend_url` as well as the effective request origin.
- There is no application `config/cors.php` allowlist. The production API is
  same-origin; cross-origin browser access is not part of the client contract.

## Unsafe-request contract

All `/api/*` methods other than `GET`, `HEAD`, and `OPTIONS` are treated as
unsafe:

1. If `Origin` or `Referer` is supplied, each supplied value must parse to the
   effective request origin or the origin configured by `app.frontend_url`.
   Invalid, `null`, and cross-origin values are rejected with `403`. If both
   headers are present, both must match.
2. An unsafe request with either JWT cookie (`rp_jwt` or `rp_jwt_refresh`) must
   include a valid same-origin check and the CSRF token described below. A
   missing/invalid origin, cookie token, or header token fails closed with
   `403`, even if an Authorization header is also present.
3. Cookie-less requests without Origin/Referer remain available to non-browser
   API clients using bearer credentials or high-entropy token-link credentials.
   Any supplied cross-origin Origin/Referer is still rejected. This preserves
   existing server/plugin and token-link flows without allowing browser form
   submissions to cross the boundary.
4. Mutations must use `application/json`. `multipart/form-data` is accepted only
   on the existing file endpoints:
   `/api/management/upload`, `/api/management/settings/watermark`,
   `/api/management/invoices/extract-offer`,
   `/api/model-registration/{token}`, and `/api/model-profil/{token}`.
   Form-encoded, text, and other mutation bodies are rejected with `415`.
5. Stripe webhook ingress is exempt from browser-origin and cookie-token checks
   because Stripe authenticates it with `Stripe-Signature`; the secret-gated
   internal FTP event ingress is similarly exempt because it authenticates its
   caller with `FTP_EVENT_SECRET`. Both still require JSON content.

## CSRF token protocol

- API responses set a host-only, `SameSite=Lax`, JavaScript-readable
  `XSRF-TOKEN` cookie. Its value is a nonce signed with `APP_KEY`; it is
  stateless and requires no database/session state. It expires with the refresh
  session (`JWT_REFRESH_TTL`, 14 days by default) and is re-issued on every API
  response, so a still-valid session never runs into a missing token.
- Cookie-authenticated unsafe requests must echo that value in the
  `X-XSRF-TOKEN` header. The server checks the signature, expiry, and constant-
  time equality between cookie and header.
- `frontend/src/api.ts` reads the cookie and adds the header to unsafe JSON,
  multipart, download-mutation, and refresh requests. It does not add a custom
  header to safe reads. Direct login, registration, and logout requests in
  `frontend/src/logic/useAuth.ts` use the same shared helper.
- Non-browser clients that authenticate with a JWT cookie are held to the same
  contract: they must send a matching `Origin`/`Referer` and the
  `X-XSRF-TOKEN` header. The E2E helpers do exactly that
  (`frontend/tests/e2e/helpers/BrowserAuthHeaders.ts`), and the backend tests use
  `Tests\TestCase::csrfCredentials()`, which mints the token through a real API
  response.
- Login is protected even before a JWT cookie exists: a browser-supplied
  cross-origin `Origin`/`Referer` is rejected, and an HTML form cannot satisfy
  the JSON-only mutation boundary. The token is additionally sent whenever the
  SPA already has one.

## Review note

The defensive contract for this round intentionally permits a cookie-less
request with no Origin/Referer so non-browser bearer-token and token-link clients
continue to work. Browser unsafe requests carry Origin or Referer and are
validated; cookie-authenticated unsafe requests additionally require the
signed double-submit token. The multipart allowlist is limited to the five
currently used upload paths above. Owner review is requested to confirm these
two compatibility boundaries; neither is silently expanded to other API
routes.

## Defense-in-depth

- `SameSite=Lax` prevents cookies on cross-site subresource/form requests in
  supporting browsers, but is not treated as the sole CSRF control.
- `HttpOnly` keeps JWT credentials inaccessible to JavaScript.
- The API is JSON-only except for the explicit multipart upload paths.
- Route authentication and role authorization remain required where specified;
  they are independent of the CSRF checks.
