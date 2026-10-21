# Content Security Policy

## Status: Implemented (2026-10-21) — the production policy is now readable in this repository

## The policy

The production policy is served by Caddy in front of the portal (the
`Content-Security-Policy` response header, `proxy-stack`'s `Caddyfile`). Its
text is mirrored here in one machine-readable place, so it can be reviewed
without access to the host:

```
default-src 'self'; script-src 'self' https://js.stripe.com; style-src 'self' 'unsafe-inline'; img-src 'self' data: https:; font-src 'self'; connect-src 'self' https://*.stripe.com; frame-src 'self' https://js.stripe.com https://*.stripe.com; object-src 'none'; base-uri 'self'; form-action 'self'
```

The authoritative copy is [`deployment/content-security-policy.txt`](../../deployment/content-security-policy.txt) — the file, not this prose. The canonical source stays the Caddy volume of `proxy-stack`; this repository holds a **readable mirror** plus a drift check (see below).

## What each directive means for this application

| Directive | Why the application needs it |
|---|---|
| `default-src 'self'` | Everything is served from the portal's own origin (the SPA and the API are same-origin, see [`csrf-strategy.md`](csrf-strategy.md)). |
| `script-src 'self' https://js.stripe.com` | Stripe.js is loaded from Stripe's CDN — the checkout iframe and the card testing path need it. |
| `style-src 'self' 'unsafe-inline'` | React's inline `style` attributes and imperative style writes need it: `frontend/src/ui/components/KanbanBoard.tsx:58`, `frontend/src/ui/components/WysiwygEditor.tsx:339`, `frontend/src/logic/brandRegistry.ts:45`. Tailwind compiles at build time and injects no runtime style. |
| `img-src 'self' data: https:` | `data:` for the generated previews (the watermark preview is a `data:` URI). The galleries deliver same-origin via `/api/media/…` (`backend/app/Models/Photo.php:177`), so the `https:` allowance has **no consumer in the code** — it is a leftover that the portal does not need and does not use. |
| `font-src 'self'` | The portal serves its own fonts; no third-party font host. |
| `connect-src 'self' https://*.stripe.com` | The API calls Stripe (payment intents, card testing) — same-origin for everything else. |
| `frame-src 'self' https://js.stripe.com https://*.stripe.com` | The Stripe payment iframe and the 3DS/SCA challenge frames. |
| `object-src 'none'` | No plugins, no `<object>`, no `<embed>`. |
| `base-uri 'self'` | A `<base>` tag may not redirect the relative URLs. |
| `form-action 'self'` | Forms may only post to the portal's own origin — no form-action hijacking into a third party. |

## Other policies in this repository

This document describes the **page** policy the portal serves. Two other CSPs exist and are not covered by the mirror:

- **The document policy** for the generated invoice and contact-sheet files: `backend/app/Http/Controllers/SettingsController.php:110` answers those downloads with `default-src 'none'; style-src 'unsafe-inline'; sandbox`. It is stricter than the page policy and belongs to the download, not to the SPA.
- **The E2E environment has no CSP at all** (see the blind spot below).

## Enabling Turnstile requires a policy change

The card-testing protection can enable Cloudflare Turnstile as a risk-based
challenge. The widget loads its script from Cloudflare
(`frontend/src/ui/client/components/TurnstileWidget.tsx:5`) and renders in a
Cloudflare frame, so the policy must allow `https://challenges.cloudflare.com`
in `script-src`, `frame-src` and `connect-src`. **The current policy does not
allow it** — with Turnstile switched on, the widget script would be blocked and
the checkout button would stay disabled for every challenged attempt.

The host serves the policy from the Caddy volume of `proxy-stack`, so the
change belongs there first; afterwards the mirror in
`deployment/content-security-policy.txt` is updated and the drift check proves
that both agree:

```sh
./scripts/check-csp-policy.sh
```

Until that change is made on the host, Turnstile must stay switched off — the
production configuration currently has no `TURNSTILE_SITE_KEY`, which is
consistent with the policy as served.

## The E2E suite is structurally blind for CSP violations

This is a property of the test environment, and it is **not** a defect of the application:

- The CI and local E2E servers run Vite's dev server, and `frontend/vite.config.ts` sets **no** `headers` key — so the suite runs **without any CSP header at all**.
- The production policy lives in the Caddy volume of `proxy-stack`, not in this repository (until 2026-10-21 it was in no file of this repository at all). A copy had to be *guessed*, and a wrong guess produces more than a thousand failures that say nothing about the product.

**What is checked instead:** the renderer test enforces the concrete invariant in an `Image` mock — the allowlist `['data:', 'https:']` — and rejects everything else with an `error`. Two additional tests pin the mock itself. That is the substitute for the missing header: the suite proves the concrete rule instead of re-creating the environment.

**Remaining blind spot:** other future CSP blocks (scripts, fonts, `connect-src`) stay invisible for E2E. The precondition for closing that gap is a policy text that is readable in the repository — which this document provides. Adding the header to the E2E environment is then a deliberate step (not a guess), and it belongs to the owner together with `proxy-stack`, because the policy is served from there.

## Drift check

The mirror can drift from the host. One command proves they still agree:

```sh
./scripts/check-csp-policy.sh
```

It reads the policy from the host (the Caddy container's `Caddyfile`) and compares it with `deployment/content-security-policy.txt`. Exit 0 means they are identical; exit 1 means the host serves a different policy than this repository documents — then the mirror is updated here (and the change is reviewed like any other contract change).

The check follows the same pattern as [`check-license-pricing.sh`](../../scripts/check-license-pricing.sh) and [`check-seeded-settings.sh`](../../scripts/check-seeded-settings.sh): it runs from the checkout against the host, it needs no secret, and it is read-only.
