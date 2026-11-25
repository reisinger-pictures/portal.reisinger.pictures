---
domain: auth
topic: registration-hardening
status: active
---

# Technical Concept: Registration Hardening

## Contract

`POST /api/auth/register` passes the normalized email address to
`App\Services\RegistrationGuardService::guard()` immediately after
`EmailNormalizer::normalize()` and before the existing-user lookup. The guard
raises `HttpResponseException` with a JSON body; no user, activation token,
password-reset token or mail is written on a rejected attempt.

The decision order of the responses is:

1. blocked domain → 422,
2. rate limit (per IP, then per domain) → 429,
3. missing MX record, only when the check is enabled → 422.

Both counters are **incremented before** these decisions, so blocked,
rate-limited and already-known addresses all consume the budget. A bot cannot
stay under the limit by varying the address it submits. An address that already
has an account still gets the identical generic success (AUTH-2); only its
attempt count changes. The rest of the registration transaction, token issue
and activation mail are untouched.

Rejections are compared by exact domain after lowercasing and stripping a
trailing dot. Blocking is an exact match, not a suffix match: `team.example.com`
is not `example.com`. This keeps organization-domain registration paths working.

## Configuration

`backend/config/registration.php` reads five environment keys. Lowercase,
trim, drop empty entries and deduplicate for the blocklist; an explicitly empty
`REGISTRATION_BLOCKED_DOMAINS` falls back to the default list (fail closed),
and non-numeric or non-positive numeric values for the four limits fall back to
their defaults.

| Env key | Default | Meaning |
|---|---|---|
| `REGISTRATION_BLOCKED_DOMAINS` | `example.com,example.net,example.org,euploading.com` | Exact email domains refused at registration |
| `REGISTRATION_IP_LIMIT` | `3` | Attempts per client IP per window |
| `REGISTRATION_DOMAIN_LIMIT` | `3` | Attempts per email domain per window |
| `REGISTRATION_WINDOW_SECONDS` | `300` | Counter window |
| `REGISTRATION_MX_CHECK` | `false` | Opt-in DNS MX check |

The limits are independent: an attempt is counted once in the IP bucket and
once in the domain bucket, and either bucket can reject it. The keys are
`registration:ip:<sha1(ip)>` and `registration:domain:<sha1(domain)>`; counting
uses `App\Support\AtomicRateCounter` (count-first, no blind `put()`), whose
`:timer` companion lets `RateLimiter::availableIn()` produce `Retry-After`.
That header is at least one second and never longer than the window.

`clear(string $ip, string $domain)` forgets both counters and their timer
companions. It exists for deterministic test setup only; production never calls
it, because clearing a live budget would reopen it for an attacker.

## Rejection responses

| Condition | Status | Exact body | Header |
|---|---|---|---|
| Blocked or empty domain | 422 | `{"error":"Registrierung mit dieser E-Mail-Domain ist nicht möglich."}` | — |
| IP or domain over budget | 429 | `{"error":"Zu viele Registrierungsversuche. Bitte versuche es später erneut."}` | `Retry-After` (seconds) |
| MX check enabled, no MX record | 422 | `{"error":"Für diese E-Mail-Domain ist keine Zustellung möglich."}` | — |

Each rejection logs one `warning` event named `registration.rejected` with this
context:

```json
{
  "reason": "blocked_domain | rate_limit_ip | rate_limit_domain | mx_missing",
  "domain": "the lowercased domain, without a trailing dot",
  "ip": "the resolved client IP",
  "user_agent": "the request User-Agent",
  "timestamp": "ISO-8601, e.g. 2026-11-25T02:06:11+01:00"
}
```

The full email address is never included in the log context.

## MX check: opt-in, injectable

DNS delivery checks are off by default. DNS is an outbound dependency that can
be slow, unavailable, or behave differently in dev/CI than on the host; a
default-off check keeps local runs and the test suite deterministic and avoids
turning a resolver outage into a registration outage. The owner may enable it
in production with `REGISTRATION_MX_CHECK=true`.

`App\Contracts\DnsResolver::hasMailExchange(string $domain): bool` is bound to
`App\Support\SystemDnsResolver` (`checkdnsrr($domain, 'MX')`) in
`AppServiceProvider::register()`. Tests bind a fake resolver and verify both
that the resolver is not consulted while disabled and that a missing MX record
produces the 422 above while enabled.

## Trusted-proxy requirement

The guard uses `$request->ip()`. The API is only reachable through Caddy, and
`bootstrap/app.php:80-83` sets `trustProxies(at: 'REMOTE_ADDR', ...)` with
`X-Forwarded-For` and `X-Forwarded-Proto`. Only the direct peer (the proxy) is
trusted, so the rightmost forwarded entry — the one the proxy wrote — becomes
the client IP, while a client-supplied prefix cannot replace it. Without this
trust rule every request looks like the proxy address and all clients share one
per-IP bucket. `RegistrationGuardTest` pins the real client IP in both the
limiter identity and the log record.

## Human gates

This host has no production access. The **effective production values** of the
five environment keys above, and the **host-side incident checks** for the
25.11.2026 02:06–02:20 CET registration wave — accounts created in that window,
source IPs, log correlation and any password resets — remain with the owner.
The wave is classified "Nein / unklar" (not an authorized pentest; see D-92):
it is documented as a security incident, but no host-side result is asserted
from here. The portal response is the code hardening described above; it does
not replace the host checks.

## Related

- [Owner decision D-92](../owner-decisions.md#d-92)
- [AUTH-2 account-existence oracle (fixed)](../security/findings-registry.md) — registration identity is unchanged
- [Atomic rate counters and D-38](../tech/07-architectural-decisions.md)
