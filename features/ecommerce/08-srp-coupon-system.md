# SRP Coupon / Discount Code System

**Status:** Draft  
**Epic:** SRP-01 / SRP-01-ext  
**Tags:** `coupon`, `discount`, `pricing`, `srp`, `photographer`, `scope`, `tenant`, `organisation`, `per-sub-gallery`

## Target State (Soll-Zustand)

### 1. Coupon Types

Three atomic discount types, defined by the `type` column:

| Type | `value` meaning | Example |
|---|---|---|
| `fixed` | Fixed discount in Euro (stored as DECIMAL, converted to cents) | `value=5` → −5,00 € |
| `percentage` | Percentage discount (0–100) | `value=10` → −10 % |
| `free_items` | Number of items that become free (cheapest first) | `value=3` → 3 cheapest items → 0 € |

### 2. Scope

Coupons can be restricted by scope:

| Scope | Behaviour |
|---|---|
| `global` | Valid for any cart regardless of gallery / meta-gallery |
| `gallery` | Valid only when the cart contains items from the specified `scope_id` (galleries.id) |
| `meta_gallery` | Valid only when the cart contains items from the specified `scope_id` (gallery_groups.id) |
| `photographer` | Valid only when the cart contains items from **any** gallery owned by the coupon creator (via `photographer_gallery_groups`). `scope_id` is ignored. |
| `organisation` | Valid only when the authenticated user belongs to the B2B tenant specified by `scope_id` (tenants.id). Primarily for mandantenweite Rabattcodes in B2B contexts. `scope_id` contains the tenant UUID. |

If a cart contains items from multiple galleries, a gallery-scoped coupon applies when *any* item belongs to the matching gallery.

**`meta_gallery` + `scope_gallery_id` (Sub-Gallery Scoping):**

When `scope_type = 'meta_gallery'`, an optional `scope_gallery_id` narrows the scope to a single gallery within that group:

| `scope_type` | `scope_id` | `scope_gallery_id` | Behaviour |
|---|---|---|---|
| `meta_gallery` | group.id | **NULL** (default) | Cart must contain items from **any** gallery in the group |
| `meta_gallery` | group.id | **gallery.id** | Cart must contain items from **that specific** gallery (within the group) |

**Use Case – Sportklub Saison A:**

- Meta Gallery: "Sportklub Saison A" (GalleryGroup)
- Sub-Gallerien: "Spiel 1", "Spiel 2", "Spiel 3"… (jeweils eine Gallery in der Gruppe)
- Ein Photographer erstellt pro Spiel einen Coupon: `type=free_items`, `value=2`, `scope_type=meta_gallery`, `scope_id=<SaisonA-ID>`, `scope_gallery_id=<Spiel1-ID>`
- Ergebnis: Kunde erhält **2 Gratis-Fotos pro Spiel** (ein Coupon pro Sub-Gallery, jeder separat einlösbar)

**Use Case – Per-Sub-Gallery Free Items (Dynamic):**

- `per_sub_gallery = true` changes the semantics of `free_items` from "X cheapest items in the entire cart" to "X free items **per unique sub-gallery** within the meta-gallery".
- Example: `type=free_items`, `value=1`, `scope_type=meta_gallery`, `scope_id=<group>`, `per_sub_gallery=true`
- Cart contains items from 12 different galleries within the group → **12 free items** (1 per sub-gallery, cheapest per sub-gallery).
- If a new sub-gallery is added to the group and items are added to a cart, the coupon automatically covers it.
- `per_sub_gallery` is only valid when `type=free_items` AND `scope_type=meta_gallery` AND `scope_gallery_id IS NULL`.
- When `per_sub_gallery=false` (default), `free_items` apply globally to the entire cart as before.

**Use Case – Mandantenweiter Organisations-Code:**

- `scope_type=organisation`, `scope_id=<tenant-UUID>`, `type=fixed`, `value=10`
- Nur Benutzer, die dem Tenant `scope_id` angehören, können diesen Code einlösen.
- Tenant-Zugehörigkeit wird via `user->tenants()->first()` geprüft (Standard-B2B-Tenant des Users).

### 3. Business Rules

- Only **one** coupon can be active at a time (user enters a single code).
- The volume tier discount (built-in) and a coupon stack: coupon is applied **after** the volume price is calculated.
- Coupons are brand-isolated (SRP vs B2B). A coupon created for SRP cannot be used in a B2B cart.
- `max_uses_global` limits total redemptions across all users (NULL = unlimited).
- `max_uses_per_account` limits redemptions **per user account** (NULL = unlimited). Tracked via `coupon_user_usage` table.
- `expires_at` defines an expiry datetime.
- `used_count` increments atomically on each successful application; `coupon_user_usage.used_count` increments per user.
- **Coupon invalidation (active toggle):** Admins and photographers can deactivate coupons via `active = false`. A deactivated coupon is permanently invalid until reactivated.
- **Coupon invalidation (active toggle):** Admins and photographers can deactivate coupons via `active = false`. A deactivated coupon is permanently invalid until reactivated.
- **Coupon deletion:** Super Admin and Admin (brand-bound) can delete any coupon unconditionally. Photographers may only delete their own coupons where `used_count = 0` (invoice integrity). The `used_count > 0` guard is skipped for any user with `is_admin` or `is_super_admin`.

### 4. Validation Flow (Frontend → Backend)

1. User enters coupon code in checkout.
2. Frontend calls `POST /api/coupons/validate` with `{code, galleryId?, metaGalleryId?, scopeGalleryId?}`.
3. Backend validation checks (in order):
   - Brand match
   - Active flag
   - Expiry
   - Global usage limit (`max_uses_global`)
   - Per-account usage limit (`max_uses_per_account` via `coupon_user_usage` table)
   - Scope match (gallery / meta_gallery / meta_gallery+scope_gallery_id / photographer / organisation)
4. Backend returns `{valid: true, coupon: {...}, discount: {...}}` or `{valid: false, error: "..."}`.
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
| `CouponService` | New service: find valid coupon (incl. organisation scope, per-sub-gallery logic), apply discount calculation (incl. per-sub-gallery free_items), increment usage. |
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
| `type` | ENUM('fixed','percentage','free_items') NOT NULL | Discount type |
| `value` | DECIMAL(10,2) NOT NULL | Amount / percent / count |
| `scope_type` | ENUM('global','gallery','meta_gallery','photographer','organisation') NOT NULL DEFAULT 'global' | Scope type |
| `scope_id` | CHAR(36) NULL | Target ID (galleries / gallery_groups / tenants) |
| `scope_gallery_id` | CHAR(36) NULL | When scope_type=meta_gallery: narrows to a specific gallery in the group (NULL = any gallery in group) |
| `per_sub_gallery` | TINYINT(1) NOT NULL DEFAULT 0 | When type=free_items + scope_type=meta_gallery + scope_gallery_id IS NULL: apply free_items per unique sub-gallery in cart |
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
| `scope_gallery_id` | jede ID | jede ID der Brand | nur eigene Galleries |
| `per_sub_gallery` | ✓ | ✓ | ✓ (nur wenn type=free_items + scope=meta_gallery) |
| `max_uses_global` | ✓ | ✓ | versteckt / null |
| `max_uses_per_account` | ✓ | ✓ | ✓ |
| `active` | ✓ | ✓ | versteckt (aktiv via expires_at + used_count) |
| `expires_at` | ✓ | ✓ | ✓ |
| Löschen | immer | immer | wenn `used_count=0` + nur eigene |

**Scope-Einschränkung Photographer:**
- Darf nur `scope_id` / `scope_gallery_id` auf Galleries/Groups setzen, zu denen er via `photographer_gallery_groups` berechtigt ist
- Bei `scope_type = 'photographer'` wird der Coupon automatisch auf alle seine Gallerien angewandt
- `scope_type = 'organisation'` ist für Photographer nicht erlaubt (kein Tenant-Zugriff)

**Organisation-Scope Einschränkungen:**
- Nur Super Admin und Admin (brand-bound) dürfen `scope_type = 'organisation'` setzen
- `scope_id` muss eine gültige Tenant-UUID der aktuellen Brand sein
- Validierung: `Tenant::byBrand(...)->where('id', scope_id)->exists()`

### 9. Super Admin Brand-Context

Auch der Super Admin muss sich **auf der korrekten URL** anmelden, um die Daten des Mandanten zu sehen:

| Aktion | URL | Sichtbar |
|---|---|---|
| SRP-Coupons verwalten | `buy.reisinger.pictures` | Nur SRP-Coupons |
| RP-Coupons verwalten | `portal.reisinger.pictures` | Nur RP-Coupons |
| SRP-Gallerie-Coupons | `buy.reisinger.pictures` | Nur Gallerien mit brand='srp' |
| RP-Gallerie-Coupons | `portal.reisinger.pictures` | Nur Gallerien mit brand='rp' |

**Implementierung:**
- Alle Management-Endpoints nutzen `BrandRegistry::currentOrDefault()` zur Filterung
- Super Admin wird nicht mehr cross-brand auf API-Ebene behandelt (kein `brand=null`-Override mehr in Coupon-Controller/Service)
- Der Login bleibt cross-brand (Super Admin kann sich an beiden Portalen anmelden), aber nach dem Login gilt der Host-Kontext

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
