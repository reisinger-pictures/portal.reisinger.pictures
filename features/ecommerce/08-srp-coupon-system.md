# SRP Coupon / Discount Code System

**Status:** Draft  
**Epic:** SRP-01 / SRP-01-ext  
**Tags:** `coupon`, `discount`, `pricing`, `srp`, `photographer`, `scope`, `Org`, `organisation`, `max-items`

## Target State (Soll-Zustand)

### 1. Coupon Types

Two atomic discount types, defined by the `type` column:

| Type | `value` meaning | Example |
|---|---|---|
| `fixed` | Fixed discount in Euro (stored as DECIMAL, converted to cents) | `value=5` → −5,00 € |
| `percentage` | Percentage discount (0–100). When `max_items` is set, applies only to the X cheapest items. | `value=10` → −10 % (entire cart) or `value=50, max_items=3` → 50% off the 3 cheapest items |

### 2. Scope

Coupons can be restricted by scope:

| Scope | Behaviour |
|---|---|
| `global` | Valid for any cart regardless of gallery / meta-gallery |
| `gallery` | Valid only when the cart contains items from the specified `scope_id` (galleries.id) |
| `meta_gallery` | Valid only when the cart contains items from the specified `scope_id` (gallery_groups.id) |
| `photographer` | Valid only when the cart contains items from **any** gallery owned by the coupon creator (via `photographer_gallery_groups`). `scope_id` is ignored. |
| `organisation` | Valid only when the authenticated user belongs to the B2B Org specified by `scope_id` (tenants.id). Primarily for mandantenweite Rabattcodes in B2B contexts. `scope_id` contains the Org UUID. |

If a cart contains items from multiple galleries, a gallery-scoped coupon applies when *any* item belongs to the matching gallery.

**`percentage` + `max_items` (Limiting to Cheapest Items):**

When `type = 'percentage'`, an optional `max_items` (unsigned integer) limits the discount to the X cheapest items in the cart:

| `max_items` | Behaviour |
|---|---|
| **NULL** (default) | Percentage applies to the **entire cart** total |
| **X** | Sort items by `priceCents` ascending, take the first X items, apply the percentage only to those items |

**Use Case – 50% auf die 3 günstigsten Bilder:**

- `type=percentage`, `value=50`, `max_items=3`
- Cart contains 5 items priced [3000, 2500, 2000, 1500, 1000]
- Cheapest 3: 1000 + 1500 + 2000 = 4500 → 50% of 4500 = 2250 discount
- Total after discount: 7750

**Use Case – Mandantenweiter Organisations-Code:**

- `scope_type=organisation`, `scope_id=<Org-UUID>`, `type=fixed`, `value=10`
- Nur Benutzer, die dem Org `scope_id` angehören, können diesen Code einlösen.
- Org-Zugehörigkeit wird via `user->tenants()->first()` geprüft (Standard-B2B-Org des Users).

### 3. Business Rules

- Only **one** coupon can be active at a time (user enters a single code).
- The volume tier discount (built-in) and a coupon stack: coupon is applied **after** the volume price is calculated.
- Coupons are brand-isolated (SRP vs B2B). A coupon created for SRP cannot be used in a B2B cart.
- **Coupon REDEMPTION is SRP-exclusive (Klärung 2026-07-06):** Coupons are designed for the SRP
  volume-pricing model and are applied exclusively by `VolumeLicensingStrategy`. The
  `ScopeLicensingStrategy` (RP/B2B) does NOT apply coupons. Consequently `CheckoutService` MUST
  skip coupon resolution (`resolveCoupon`) and usage increment (`incrementUsage`) entirely when
  the active strategy is `ScopeLicensingStrategy`. Reason: a coupon carries no discount in the
  scope-licensing price path, so validating + consuming it would silently burn usage limits
  without granting any discount.
  - **IST-Divergence (Bug B-02, AGENTS.todo.md 2026-07-06):** Today `CheckoutService::resolveCoupon()`
    runs for both strategies — it validates the coupon, and `createOrder()` increments
    `used_count`/`coupon_user_usage` and stores `coupon_id`/`coupon_discount_cents` on the order,
    while `ScopeLicensingStrategy` always returns `discountCents: 0`. Net effect for RP: coupon
    usage is consumed, no discount applied, order records a mismatched `coupon_discount_cents`.
    SOLL: gate `resolveCoupon`/`incrementUsage` on `VolumeLicensingStrategy`.
  - Coupon **management** (CRUD) remains available for both brands via API/CLI (see §9); only the
    UI is SRP-only. The SRP-exclusivity above concerns the *redemption at checkout*, not management.
- `max_uses_global` limits total redemptions across all users (NULL = unlimited).
- `max_uses_per_account` limits redemptions **per user account** (NULL = unlimited). Tracked via `coupon_user_usage` table.
- `expires_at` defines an expiry datetime.
- `used_count` increments atomically on each successful application; `coupon_user_usage.used_count` increments per user.
- **Coupon invalidation (active toggle):** Admins and photographers can deactivate coupons via `active = false`. A deactivated coupon is permanently invalid until reactivated.
- **Coupon invalidation (active toggle):** Admins and photographers can deactivate coupons via `active = false`. A deactivated coupon is permanently invalid until reactivated.
- **Coupon deletion:** Super Admin and Admin (brand-bound) can delete any coupon unconditionally. Photographers may only delete their own coupons where `used_count = 0` (invoice integrity). The `used_count > 0` guard is skipped for any user with `is_admin` or `is_super_admin`.

### 4. Validation Flow (Frontend → Backend)

1. User enters coupon code in checkout.
2. Frontend calls `POST /api/coupons/validate` with `{code, gallery_id?, meta_gallery_id?}`.
3. Backend validation checks (in order):
   - Brand match
   - Active flag
   - Expiry
   - Global usage limit (`max_uses_global`)
   - Per-account usage limit (`max_uses_per_account` via `coupon_user_usage` table)
    - Scope match (gallery / meta_gallery / photographer / organisation)
4. Backend returns `{valid: true, coupon: {code, type, value}, discount_cents: int}` or `{valid: false, error: "..."}`. The response includes `type` and `value` (needed for frontend display) but omits internal fields `id` and `scope_type` (least-information principle).
5. On checkout-submit (`POST /api/orders/checkout`), the coupon code is passed in the request body.
6. **Checkout re-validation (critical):** Before applying the coupon, the backend re-validates it atomically. If the coupon is no longer valid (expired, limit reached, scope mismatch), the checkout is **rejected** with HTTP 422 and a user-facing error message. Silent fallback (ignoring the coupon) is **forbidden** — the user must be informed that their coupon became invalid between preview and checkout.
7. `CheckoutService` → `VolumeLicensingStrategy` applies the coupon after volume pricing.
8. `coupon_id` and `coupon_discount_cents` are stored on the `orders` table.
9. On successful order, `incrementUsage` atomically increments both `coupons.used_count` (global counter) and `coupon_user_usage.used_count` (per user).

### 4a. Checkout Re-Validation (Coupon-Validität zum Zeitpunkt des Kaufs)

Zwischen Client-seitiger Validierung (Schritt 2) und tatsächlichem Checkout (Schritt 5) kann ein Coupon ungültig werden:
- Ein anderer User hat das letzte globale Kontingent aufgebraucht
- Der Coupon ist abgelaufen (`expires_at` in der Vergangenheit)
- Ein Admin hat den Coupon deaktiviert (`active = false`)

**Regel:** Gibt ein User beim Checkout einen Coupon-Code an (`coupon_code` im Request-Body), MUSS der Backend-Checkout:
1. Den Coupon **erneut** via `CouponService::findValidCoupon()` validieren
2. Bei `null`-Rückgabe (ungültig): **Abbruch** des Checkouts mit HTTP 422 + `{error: "Der Rabattcode ist nicht mehr gültig."}`
3. Nur bei gültigem Coupon: Bestellung fortsetzen

Die Pricing-Strategie (`VolumeLicensingStrategy`) darf niemals lautlos auf den Coupon verzichten, wenn einer angefordert wurde.

### 5. Integration Points

| Component | Change |
|---|---|
| `VolumeLicensingStrategy` | After `$result` is produced, apply coupon if present and valid. If coupon is requested but invalid, throw exception. |
| `CheckoutService` | Pass coupon code from request → validate before strategy call → reject if invalid. Store `coupon_id` and `coupon_discount_cents` on order. |
| `CouponService` | New service: find valid coupon (incl. organisation scope), apply discount calculation (incl. percentage+max_items), increment usage. |
| `Order` model | Add `coupon_id` (nullable FK) and `coupon_discount_cents` (integer, default 0). |

### 6. API Endpoints

| Method | Path | Auth | Description |
|---|---|---|---|
| `GET` | `/api/management/coupons` | super_admin / admin (brand) / photographer (own) | List coupons (paginated; rollenabhängig gefiltert) |
| `POST` | `/api/management/coupons` | super_admin / admin (brand) / photographer (own) | Create coupon (rollenabhängige Felder; `organisation`-Scope nur für super_admin/admin) |
| `PUT` | `/api/management/coupons/{id}` | super_admin / admin (brand) / photographer (own) | Update coupon |
| `DELETE` | `/api/management/coupons/{id}` | super_admin / admin (brand) / photographer (own) | Delete coupon (only if `used_count = 0` für non-super-admin) |
| `GET` | `/api/management/galleries/{id}/coupons` | super_admin / admin (brand) / photographer (own) | List coupons scoped to a specific gallery |
| `POST` | `/api/management/galleries/{id}/coupons` | super_admin / admin (brand) / photographer (own) | Create coupon pre-scoped to this gallery |
| `GET` | `/api/management/gallery-groups/{id}/coupons` | super_admin / admin (brand) / photographer (own) | List coupons scoped to a gallery group |
| `POST` | `/api/management/gallery-groups/{id}/coupons` | super_admin / admin (brand) / photographer (own) | Create coupon pre-scoped to this group |
| `POST` | `/api/coupons/validate` | auth:api | Validate coupon and return discount preview |

### 7. Database Schema

**`coupons` table:**

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `brand` | ENUM('rp','srp') NOT NULL | Brand isolation |
| `code` | VARCHAR(50) NOT NULL | Human-readable code |
| `type` | ENUM('fixed','percentage') NOT NULL | Discount type |
| `value` | DECIMAL(10,2) NOT NULL | Amount / percent |
| `max_items` | INT UNSIGNED NULL | When type=percentage: limit discount to X cheapest items (NULL = entire cart) |
| `scope_type` | ENUM('global','gallery','meta_gallery','photographer','organisation') NOT NULL DEFAULT 'global' | Scope type |
| `scope_id` | CHAR(36) NULL | Target ID (galleries / gallery_groups / tenants) |
| `max_uses_global` | INT UNSIGNED NULL | Global usage limit (NULL = unlimited) |
| `max_uses_per_account` | INT UNSIGNED NULL | Per-account usage limit (NULL = unlimited) |
| `used_count` | INT UNSIGNED NOT NULL DEFAULT 0 | Atomic counter (global) |
| `expires_at` | TIMESTAMP NULL | Expiry |
| `active` | TINYINT(1) NOT NULL DEFAULT 1 | Manual toggle |
| `created_by` | CHAR(36) NULL | UUID of creating user (photographer) |
| `created_at` / `updated_at` | TIMESTAMP | Laravel timestamps |

Unique: `(brand, code)`

**`coupon_user_usage` table:**

| Column | Type | Notes |
|---|---|---|
| `id` | BIGINT UNSIGNED AUTO_INCREMENT | Primary key |
| `coupon_id` | BIGINT UNSIGNED NOT NULL | FK → coupons.id (onDelete cascade) |
| `user_id` | CHAR(36) NOT NULL | UUID of user |
| `used_count` | INT UNSIGNED NOT NULL DEFAULT 0 | Per-user usage counter |

Unique: `(coupon_id, user_id)`

**`orders` table additions:**

| Column | Type | Notes |
|---|---|---|
| `coupon_id` | BIGINT UNSIGNED NULL | FK → coupons.id |
| `coupon_discount_cents` | INT NOT NULL DEFAULT 0 | Discount amount in cents |

### 8. Role-Based Field Permissions

| Feld | Super Admin | Admin (brand-bound) | Photographer |
|---|---|---|---|
| `brand` | `'rp'` / `'srp'` wählbar | aus User-Brand (forced) | aus User-Brand (forced) |
| `code` | ✓ | ✓ | ✓ |
| `type` / `value` | ✓ | ✓ | ✓ |
| `scope_type` | alle (inkl. `organisation`) | alle (inkl. `organisation`) | `gallery`, `meta_gallery`, `photographer` (nur eigene) |
| `scope_id` | jede ID | jede ID der Brand | nur eigene Galleries/Groups |
| `max_items` | ✓ | ✓ | ✓ |
| `max_uses_global` | ✓ | ✓ | versteckt / null |
| `max_uses_per_account` | ✓ | ✓ | ✓ |
| `active` | ✓ | ✓ | versteckt (aktiv via expires_at + used_count) |
| `expires_at` | ✓ | ✓ | ✓ |
| Löschen | immer | immer | wenn `used_count=0` + nur eigene |

**Scope-Einschränkung Photographer:**
- Darf nur `scope_id` auf Galleries/Groups setzen, zu denen er via `photographer_gallery_groups` berechtigt ist
- Bei `scope_type = 'photographer'` wird der Coupon automatisch auf alle seine Gallerien angewandt
- `scope_type = 'organisation'` ist für Photographer nicht erlaubt (kein Org-Zugriff)

**Organisation-Scope Einschränkungen:**
- Nur Super Admin und Admin (brand-bound) dürfen `scope_type = 'organisation'` setzen
- `scope_id` muss eine gültige Org-UUID der aktuellen Brand sein
- Validierung: `Org::byBrand(...)->where('id', scope_id)->exists()`

### 9. Coupon Management UI — SRP-only

Die Coupon-Verwaltung via UI wird **ausschließlich auf SRP** (`buy.reisinger.pictures`) angeboten. Bei Aufruf auf RP (`portal.reisinger.pictures`) erscheint ein Hinweis: "Gutscheincodes sind nur auf buy.reisinger.pictures verfügbar."

| Aktion | URL | Sichtbar |
|---|---|---|
| SRP-Coupons verwalten | `buy.reisinger.pictures` | UI + API |
| RP-Coupons verwalten | `portal.reisinger.pictures` | **Nicht im UI** — nur API (Super Admin / Admin) |
| SRP-Gallerie-Coupons | `buy.reisinger.pictures` | Nur Gallerien mit brand='srp' |
| RP-Gallerie-Coupons | `portal.reisinger.pictures` | **Nicht im UI** — nur API |

**Implementierung:**
- Alle Management-Endpoints nutzen `BrandRegistry::currentOrDefault()` zur Filterung
- Die Backend-API unterstützt beide Brands (`rp` und `srp`) gleichermaßen — Super Admin und Admin können RP-Coupons über API-Requests oder CLI erstellen/verwalten
- Die UI-Komponente (`ManagementCouponsView`) blendet sich auf RP aus (`brand === 'srp'` Guard)
- Der Login bleibt cross-brand (Super Admin kann sich an beiden Portalen anmelden), aber nach dem Login gilt der Host-Kontext
- **RP-seitige Coupons** werden (falls zukünftig benötigt) via Backend/CLI erstellt — UI-Support ist nicht geplant

### 10. Error Messages (User-facing)

| Scenario | Message |
|---|---|
| Code not found | `Coupon code not found.` |
| Expired | `This coupon has expired.` |
| Global usage limit reached | `This coupon has reached its global usage limit.` |
| Per-account usage limit reached | `You have reached the usage limit for this coupon.` |
| Not yet active / inactive | `This coupon is not active.` |
| Scope mismatch | `This coupon is not valid for your selected items.` |
| Organisation scope mismatch | `This coupon is not valid for your account.` |
| Brand mismatch | `This coupon is not valid for this portal.` |
| Checkout: coupon became invalid | `Der Rabattcode ist nicht mehr gültig.` |
