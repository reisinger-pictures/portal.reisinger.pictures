# 18 — JWT-Format Machine-Readable Offer Tokens

> **Spec (Soll-Zustand).** Source of truth for J-01.
> Replaces the hand-rolled HMAC/base64 mechanisms in `QuoteLinkService`,
> `ManualInvoiceService`, and `OrderController::sendQuote` with signed tokens
> in JWT wire format.
> Stand: 2026-06-30; signing mechanism updated 2026-11-23 (D-77).
>
> **Current signing mechanism (D-77, Portal #9 Phase 4):** tokens keep the exact
> compact JWT wire format (`{"typ":"JWT","alg":"HS256"}` header, base64url
> segments, `hash_hmac` SHA-256 signature over `header.payload`) so already-issued
> links stay valid, but they are produced and verified natively in
> `OfferTokenService` — `php-open-source-saver/jwt-auth` and `config/jwt.php` are
> removed. The key comes from `services.signed_links.secret`
> (`SIGNED_LINK_SECRET ?? JWT_SECRET ?? APP_KEY`); no production env change is
> needed. The implementation notes below that name the package describe the
> original 2026-06-30 design and are superseded by D-77.

## 1. Goal

The two "machine-readable offer" pathways are migrated from hand-rolled HMAC-SHA256/base64
tokens (3 duplicated implementations) to a single signed-JWT mechanism:

- **Path A — PDF-embedded offer marker:** manual-offer PDFs carry a `%OFFER_JWT:{token}%`
  marker after EOF (replaces `%SMART_DOC:payload.signature%`). Re-importing the PDF decodes the
  JWT and restores the offer form.
- **Path B — Quote link token:** the photographer→client "individuelles Angebot" link uses
  `?quote_token={jwt}` (replaces the HMAC token). Opening the link restores the cart.

`exp` (JWT expiry) = the offer's validity date from the form (`customer_details.due_date` /
`validity`), not a hardcoded 14-day window.

## 2. Decisions (2026-06-30, User)

- **Both pathways on JWT** — one issuer/verifier replaces all 3 HMAC copies.
- **`exp` = offer validity from the form** (`due_date`/`validity`). Fallback to a default
  validity window when no date is provided.
- **Clean break:** old `%SMART_DOC%` HMAC PDFs are NO LONGER readable after the migration.
  Acceptable (no production data). No dual-mode extraction logic.

## 3. Architecture — `OfferTokenService`

New central service `backend/app/Services/OfferTokenService.php`:

```php
class OfferTokenService {
    public function issue(array $offerPayload, ?Carbon $expiresAt = null): string;
    public function verify(string $token): ?array;   // null on invalid/expired
}
```

- Signing key (current, D-77): `config('services.signed_links.secret')`, resolved as
  `SIGNED_LINK_SECRET ?? JWT_SECRET ?? APP_KEY`. This preserves the key material the
  old `config('jwt.secret')` / `JWT_SECRET` produced so already-issued links still verify.
- Implementation (current, D-77): native `hash_hmac` SHA-256 in `OfferTokenService` with
  strict base64url/JSON parsing and `hash_equals` signature comparison. No JWT package;
  the compact JWT wire format is kept for compatibility only.
- Original 2026-06-30 design (superseded by D-77): reuse the
  `PHPOpenSourceSaver\JWTAuth\JWT` factory via `app('tymon.jwt')` / `JWTAuth::customClaims([...])`.
  That package was removed in Phase 4 and must not be reintroduced.
- Claims: `iat` (issued-at), `exp` (expiry = offer validity), `jti` (unique id, `Str::uuid()`),
  and a custom `offer` claim carrying the payload (`items`, `customer_*`, `terms_html`, etc.).
- `issue()`:
  - `$expiresAt` defaults to `now()->addDays(14)` when null (fallback).
  - Returns the compact JWT string.
- `verify($token)`:
  - Decodes + verifies signature; returns the `offer` payload array on success.
  - Returns `null` on any failure (bad signature, malformed, expired `exp`).

## 4. Path A — PDF-embedded marker

- `ManualInvoiceService::generateOfferPayload(array $data): array` → delegates to
  `OfferTokenService::issue($data, $expiresAtFromDueDate)`. Returns `['token' => $jwt,
  'marker' => "%OFFER_JWT:{$jwt}%"]`. The marker format is the new contract.
- `prepareOfferData()` must include the offer validity date so it can drive `exp`. Derive
  `$expiresAt` from `$data['due_date']` / `$data['validity']` (Carbon parse; on parse failure
  fall back to the service default).
- `ManualInvoiceService::extractOfferFromPdf(string $content): ?array`:
  - Regex `/OFFER_JWT:([A-Za-z0-9_\.\-]+)/` (JWT chars: base64url + `.`).
  - Calls `OfferTokenService::verify($jwt)` → returns payload or null.
  - Remove the old `%SMART_DOC%` regex entirely (clean break).
- `OrderController::generateManualInvoice` (~line 184-196): marker embedding unchanged in
  structure — `$output .= "\n{$payloadData['marker']}\n";` (marker now JWT-based).
- `OrderController::extractOffer`: response on failure = German error message
  ("Angebot nicht auslesbar oder abgelaufen." ); 404 if no marker, 400 if invalid/expired.

## 5. Path B — Quote link token

- `QuoteLinkService::generateQuoteLink(...)`: builds `?quote_token=` from
  `OfferTokenService::issue($payload, $expiresAt)`. Remove `generatePayload`/`generateSignature`/
  `verifySignature`/`decodeQuoteToken` HMAC helpers — replaced by `OfferTokenService`.
- The `exp` for quote links: the photographer-set validity (`$validityDays`) still applies for
  quote links (they have no form-driven date) → `now()->addDays($validityDays)`. This is
  consistent with current behaviour.
- `OrderController::sendQuote` (~line 76-101): remove inline HMAC duplication; call
  `QuoteLinkService` (which now delegates to `OfferTokenService`).
- `OrderController::decodeQuoteLink` / `/api/orders/quote-decode`: call
  `OfferTokenService::verify($token)`; on null return German error / 410 Gone for expired.

## 6. Frontend

- `frontend/src/logic/usePdfExtraction.ts`: response shape (extracted customer fields + items +
  discounts) unchanged; add an explicit error toast on 400/404 ("Angebot abgelaufen oder nicht
  lesbar") instead of silent failure.
- `frontend/src/ui/client/ClientCartView.tsx` (quote-link restore): handle expired/invalid
  token with a clear German toast ("Angebot ist abgelaufen — bitte kontaktieren Sie den
  Fotografen.") and do not silently clear the cart.

## 7. Tests

- Backend unit `OfferTokenServiceTest`: issue → verify roundtrip; tampered token → null;
  expired `exp` → null; missing `exp` handling.
- `QuoteLinkTest`: link generation + decode via JWT; expired token rejected.
- `ManualInvoiceServiceTest` (or feature): PDF marker embed + extract via JWT; expired offer
  → extract returns null/error; old `%SMART_DOC%` PDF → 404/400 (clean break).
- E2E `quote-restore.spec.ts`, `quote-cart.spec.ts`: update if any token format assumption is
  hardcoded (shouldn't be — they hit the API).

## 8. Out of scope

- No persistence/revocation layer for JWTs (stateless). If offer revocation becomes needed, a
  `revoked_jti` table can be added later.
- No encryption of the payload (JWT is signed, not encrypted). Offer payloads are not secret
  (prices/items are visible to the recipient anyway). If confidentiality is later required,
  switch to JWE.
