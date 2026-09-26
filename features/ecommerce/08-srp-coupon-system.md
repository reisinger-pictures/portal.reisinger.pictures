# Coupon / Discount Code System (legacy filename: SRP)

**Status:** Current SOLL / implemented (reviewed 2026-09-24). The filename and
some `SRP` tags retain historical naming; the live system has one configured
`rp` brand and brand-scoped coupons.
**Epic:** SRP-01 / SRP-01-ext  
**Tags:** `coupon`, `discount`, `pricing`, `srp`, `photographer`, `scope`, `Org`, `organisation`, `max-items`

## Target State (Soll-Zustand)

### 1. Coupon Types

Three coupon types, defined by the `type` column:

| Type | `value` meaning | Example |
|---|---|---|
| `fixed` | Fixed discount in Euro (stored as DECIMAL, converted to cents) | `value=5` → −5,00 € |
| `percentage` | Percentage discount (0–100). When `max_items` is set, applies only to the X cheapest items. | `value=10` → −10 % (entire cart) or `value=50, max_items=3` → 50% off the 3 cheapest items |
| `photo_package` | **Until N photos for a flat price Y €** (Foto-Paket / Volume-Licensing-Gutschein). N = `package_quantity`, Y = `package_price_cents`. Semantics, calculation & data model: **§3a**. | `package_quantity=10`, `package_price_cents=4000` → „10 Fotos für 40 €" |

### 2. Scope

Coupons can be restricted by scope:

| Scope | Behaviour |
|---|---|
| `global` | Valid for any cart regardless of gallery / meta-gallery |
| `gallery` | Valid only when the cart contains items from the specified `scope_id` (galleries.id) |
| `meta_gallery` | Valid only when the cart contains items from the specified `scope_id` (gallery_groups.id) |
| `photographer` | Valid only when the cart contains items from **any** gallery owned by the coupon creator (via `photographer_gallery_groups`). `scope_id` is ignored. |
| `organisation` | Valid only when the authenticated user's current `org` matches `scope_id`. Primarily for organisation-wide discount codes in B2B contexts. |

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
- Org-Zugehörigkeit wird über `User::find($userId)->org` geprüft.

### 3. Business Rules

- Only **one** coupon can be active at a time (user enters a single code).
- The volume tier discount (built-in) and a coupon stack: coupon is applied **after** the volume price is calculated. For `max_items` and `photo_package`, the coupon calculation receives each item's effective qualifying-tier price; invoice item lines remain at the base price and retain the separate volume-tier breakdown.
- Coupons are brand-isolated. The current configured brand is `rp`; a coupon
  created in another brand context cannot be used in this cart. The old
  `SRP`/`B2B` host distinction is historical.
- **Coupon redemption follows strategy capability (current):**
  `VolumeLicensingStrategy` supports coupons; `ScopeLicensingStrategy` does not.
  `CheckoutService` therefore resolves, applies, and increments coupons only for
  the combined volume subtotal. Validation considers every non-quote volume
  item (including items in later volume galleries), and the discount is applied
  once at order level. A scope-only cart must not consume a coupon or record a
  discount without applying one. Coupon management/UI availability is separate
  and is not hidden solely because a gallery uses scope pricing.
- `max_uses_global` limits total redemptions across all users (NULL = unlimited).
- `max_uses_per_account` limits redemptions **per user account** (NULL = unlimited). Tracked via `coupon_user_usage` table.
- `expires_at` defines an expiry datetime.
- `used_count` increments atomically on each successful application; `coupon_user_usage.used_count` increments per user.
- **Coupon invalidation (active toggle):** Admins and photographers can deactivate coupons via `active = false`. A deactivated coupon is invalid until reactivated.
- **Coupon deletion:** Super Admin and Admin (brand-bound) can delete any coupon unconditionally. Photographers may only delete their own coupons where `used_count = 0` (invoice integrity). The `used_count > 0` guard is skipped for any user with `is_admin` or `is_super_admin`.

### 3a. Photo Package Type (`photo_package`) — Foto-Paket-Gutschein

**Status:** ✅ Implementiert (Backend `V030__add_photo_package_to_coupons.php` + `CouponService::applyCoupon` `case 'photo_package'`; Frontend `CouponFormDrawer`, `useCoupon`, `CouponInput`, Listen `GalleryCouponsTab` / `GalleryGroupCouponsTab` / `ManagementCouponsView`). SOLL = Ist.

**Herkunft:** User-Request (2026-08-19): „Gutscheine für Volume Licensing à 10 Fotos für 40 €".
Bestehende Typen decken das nicht ab — `fixed`/`percentage` sind reine Rabatt-Codes, die automatische
Volume-Staffel kennt keinen einlösbaren Paket-Preis. `photo_package` ist ein dritter Coupon-Typ, der
**N Fotos zum Festpreis Y €** gewährt. Tracking: `AGENTS.todo.md` → Plan **P1**.

**Semantik (Einlöse-Regeln):**

Parameter: **N** = `package_quantity` (Anzahl Fotos im Paket), **Y** = `package_price_cents` (Festpreis in Cent).

- **M** = Anzahl zahlbarer Warenkorb-Items (`priceCents > 0`; Quote-Items mit `priceCents = 0` werden **nicht** gezählt — siehe Annahme unten).
- **M ≤ N** → Gesamtpreis der Paket-Fotos = **Y €** (alle M Fotos im Paket enthalten).
- **M > N** → die ersten **N** Fotos kosten gesamt **Y €**; die verbleibenden **(M − N)** Fotos werden zum
  **regulären Volume-Preis** berechnet (kein weiterer Rabatt).
- **Übercharge-Guard:** Falls Y € höher wäre als der reguläre Preis der abgedeckten Fotos, zahlt der Kunde
  **nie mehr** als den regulären Preis. Effektiver Paketpreis = `min(Y, Summe(priceCents der abgedeckten Items))`.

**Beispiel „10 Fotos für 40 €" (Volume-Einzelpreis 5 €/Foto):**
- 7 Fotos → **40,00 €** (statt 35 € regulär → Guard greift: effektiv 35 €, kein Aufschlag).
- 10 Fotos → **40,00 €** (statt 50 € regulär).
- 13 Fotos → **40,00 € + 3 × 5,00 € = 55,00 €** (10 im Paket, 3 zum Normalpreis).

**Berechnung (`CouponService::applyCoupon`):** neuer `case 'photo_package'` im bestehenden `switch`:

```
M             = count(items where priceCents > 0)                 // zahlbare Items
covered       = min(M, coupon.package_quantity)
prices        = sort(effective qualifying-tier item prices where priceCents > 0 ascending)
normalCovered = sum(prices[0 .. covered-1])                       // effektiver Preis der abgedeckten Fotos
effPackage    = min(coupon.package_price_cents, normalCovered)    // Übercharge-Guard
discountCents = normalCovered - effPackage
totalCents    = currentTotalCents - discountCents                 // Rest-Items (M - covered) unverändert
```

Integriert sich nahtlos in `VolumeLicensingStrategy::calculateCart()`, das `applyCoupon` bereits aufruft
(Konsistenz mit `fixed`/`percentage`). Für `max_items`/`photo_package` wird eine separate
Coupon-Item-Liste mit effektiven Qualifying-Tier-Preisen übergeben; die Rechnungspositionen
bleiben auf dem Basispreis und zeigen den Mengenrabatt separat. Coupon wird **nach** der
Volume-Preisberechnung angewendet.

**Data Model (Migration V030):** eigene, separate Migration
(`V030__add_photo_package_to_coupons.php`). V038 ist die aktuelle
Repository-Migrations-Frontier; V030 beschreibt nur die Coupon-Erweiterung.
Tabelle `coupons` erweitern um `package_quantity INT UNSIGNED NULL` (N) und
`package_price_cents INT NULL` (Y, Stripe-konform). `Coupon::$fillable` + `$casts` ergänzen.
Für `photo_package` sind `value` und `max_items` **ungenutzt** (bleiben NULL/0). `down()` darf leer bleiben.

**Validation (`CouponStoreRequest` / `CouponUpdateRequest`):** `type → in:fixed,percentage,photo_package`;
bei `photo_package` (conditional via `withValidator`): `package_quantity → required|integer|min:1`,
`package_price_cents → required|integer|min:0`. Frontend sendet den Preis zweckmäßig in **Euro**;
`CouponAdminController::store()`/`update()` mappt `package_price` (float) → `package_price_cents` (int)
vor `Coupon::create()`/`update()` (Cent-Konvention wie `value`→`*_cents` andernorts).

**Frontend:**
- **Admin-Formular** `CouponFormDrawer.tsx`: Typ-Option **„Foto-Paket"**; bei `type === 'photo_package'`
  statt `value`/`max_items` zwei Felder: *„Anzahl Fotos (N)"* (`package_quantity`) + *„Festpreis in € (Y)"* (`package_price`).
  `couponSchema` (Zod, `type: z.enum([... , 'photo_package'])`), lokales `Coupon`-Interface, `TYPE_VALUE_LABEL`,
  `toFormValues`, `onSubmit`-Payload anpassen.
- **Einlösen/Anzeige**: `useCoupon.ts` (`CouponSummary.type` um `'photo_package'`), `CouponInput.tsx`,
  `ClientCartView.tsx` → Label *„10 Fotos für 40 €"* + Rabatt-Vorschau (`discount_cents` aus Backend).
- **Listen** `GalleryCouponsTab.tsx` / `GalleryGroupCouponsTab.tsx` / `ManagementCouponsView.tsx`
  (`formatValue`/`formatUsage`) → *„10 Fotos / 40 €"* für Paket-Typ.

**Security / Brand Isolation:** Coupon ist bereits brand-isoliert (`forCurrentBrand`/`byBrand`;
`CouponAdminController` setzt `brand`). `photo_package` erbt Scope/Limits/Expiry/Usage-Count automatisch —
**kein neuer Guard nötig**; bestehende IDOR-Guards (H2/H3) greifen weiter. Wie alle Coupons ist
`photo_package` wird im aktuellen Contract von `VolumeLicensingStrategy` angewendet;
der Begriff „SRP" im Dateinamen ist historisch.

**Annahme „zahlbar" = `priceCents > 0`:** Quote-Items sind 0 und werden nicht gezählt. Edge-Case: ein
reguläres Item mit `priceCents = 0` würde fälschlich nicht gezählt — im Volume-Pfad unrealistisch; bei
Bedarf `is_quote`-Flag in `pricedItems` durchreichen statt der `> 0`-Heuristik.

**Tests (DoD):** Backend PHPUnit (`CouponServiceTest` erweitern): `photo_package` mit M ≤ N, M > N,
Übercharge-Guard, sowie `CouponStoreRequest`-Validierung (conditional required). Frontend Vitest
(`CouponFormDrawer.test.tsx`: Paket-Option rendert N + Y-Felder + Validation). E2E Playwright
`@feature:coupon` (bzw. `@smoke`): Paket-Gutschein anlegen → im Cart einlösen → Assert Gesamtpreis = Y bei
≤ N und Y + Rest bei > N.

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
4. Backend returns `{valid: true, coupon: {code, type, value, package_quantity?, package_price_cents?}, discount_cents: int}` or `{valid: false, error: "..."}`. The response includes the public display fields but omits internal `id` and `scope_type`.
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
| `CouponService` | New service: find valid coupon (incl. organisation scope), apply discount calculation (`fixed` / `percentage`+`max_items` / `photo_package` bundle, see §3a), increment usage. |
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
| `brand` | string(20) NOT NULL | Brand-Isolation; aktuell wird die konfigurierte `rp`-Brand verwendet |
| `code` | VARCHAR(50) NOT NULL | Human-readable code |
| `type` | ENUM('fixed','percentage','photo_package') NOT NULL | Discount type (see §1 / §3a) |
| `value` | DECIMAL(10,2) NOT NULL | Amount / percent (unused for `photo_package`) |
| `max_items` | INT UNSIGNED NULL | When type=percentage: limit discount to X cheapest items (NULL = entire cart) |
| `package_quantity` | INT UNSIGNED NULL | When type=photo_package: N (number of photos included in the bundle) |
| `package_price_cents` | INT NULL | When type=photo_package: flat price Y € in cents (Stripe-conform) |
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
| `brand` | aus User-/Brand-Kontext | aus User-Brand (forced) | aus User-Brand (forced) |
| `code` | ✓ | ✓ | ✓ |
| `type` / `value` | ✓ | ✓ | ✓ |
| `scope_type` | alle (inkl. `organisation`) | alle (inkl. `organisation`) | `gallery`, `meta_gallery`, `photographer` (nur eigene) |
| `scope_id` | jede ID | jede ID der Brand | nur eigene Galleries/Groups |
| `max_items` | ✓ | ✓ | ✓ |
| `package_quantity` / `package_price_cents` | ✓ | ✓ | ✓ |
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

### 9. Coupon Management UI — current brand-scoped contract

Coupon management is available in the configured portal regardless of whether
an individual gallery uses scope or volume pricing. There is no live `SRP`
host/brand branch in this contract.

| Aktion | Endpoint/UI | Sichtbar |
|---|---|---|
| Coupons verwalten | `portal.reisinger.pictures` + `/api/management/coupons` | Management-Rollen; Liste/Schreiben brand-scoped |
| Gallery-Coupons | `/api/management/galleries/{id}/coupons` | Management-/Zugriffsrechte der Gallery |
| Group-Coupons | `/api/management/gallery-groups/{id}/coupons` | Management-/Zugriffsrechte der Gallery-Group |

**Implementierung:**
- Alle Management-Endpoints filtern über `BrandRegistry::currentOrDefault()`
  und erzielen Ownership-/Brand-Guards.
- `CouponAdminController` setzt `brand` aus dem aktuellen Host-Kontext; ein
  Super-Admin kann nicht durch einen alten UI-Guard zwischen Hosts schalten.
- Frontend-Navigation und Formulare werden nicht mehr an `pricing_strategy`
  oder einen historischen `isSrp`-Guard gekoppelt.
- Historische `buy.reisinger.pictures`-/SRP-Zeilen in älteren Revisionen
  beschreiben einen entfernten Produktnamen, nicht den aktuellen Vertrag.

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
