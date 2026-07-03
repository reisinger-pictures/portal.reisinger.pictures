# Task Board — Portal Reisinger Pictures

> **Stand:** 2026-07-04 — Phasen 0–7d abgeschlossen. 17 E2E-Tests offen.

---

## Finale Test-Verifikation (2026-07-04)

### ✅ Bestanden

| Suite                  | Ergebnis       |
|------------------------|----------------|
| PHPUnit (Backend)      | **852 passed** |
| Vitest (Frontend Unit) | **379 passed** |
| Playwright E2E         | **277 passed** |

### ❌ 17 E2E-Failures (9 Root Causes × Desktop + Mobile, minus 1 Desktop-only gallery-modals)

Zur genaueren Analyse die betroffenen test Dateien mit 999 max failures laufen lassen und die Implementierung der fixers
an Sub Agenten weiterleiten

| # | Root Cause                                                                                       | Files                                              | D | M |
|---|--------------------------------------------------------------------------------------------------|----------------------------------------------------|---|---|
| 1 | `gallery-modals`: Test timeout — summary pencil button not found after reload (Desktop only)     | `gallery-modals.spec.ts:48`                        | ❌ | — |
| 2 | `h1 "Bestellungen & Anfragen"` not visible on `/admin-orders`                                    | `order-status.spec.ts:86`, `ecommerce.spec.ts:132` | ❌ | ❌ |
| 3 | Org filter select lacks tenant `option[value]` — options not loaded for photographer             | `organisation-filter.spec.ts:73`                   | ❌ | ❌ |
| 4 | `tenantData.users` empty after invite — user not attached or API missing users                   | `invite-org.spec.ts:139`                           | ❌ | ❌ |
| 5 | "Kalkulieren & Antworten" button not found in admin orders table                                 | `quote-cart.spec.ts:98`                            | ❌ | ❌ |
| 6 | Email button stays disabled after client toggles opt-in                                          | `communication.spec.ts:68`                         | ❌ | ❌ |
| 7 | "Du hast noch keine eigenen Galerien oder Bilder." empty state not visible                       | `empty-feed.spec.ts:29`                            | ❌ | ❌ |
| 8 | Coupon code not visible after "Anwenden" — Gallery-scoped coupon apply → replaced org-scope test | `coupon-gallery-scope.spec.ts`                     | — | — |
| 9 | "Coupon code not found" instead of scope error — replaced org-scope test                         | `coupon-gallery-scope.spec.ts`                     | — | — |

---

## Abkürzungen & Regeln

- `page.route()` Mock → nur für externe Dienste (AI, Coupon-Validate)
- `E2ESessionHelper` → Pflicht für alle neuen Tests
- Kein `page.goto()` für SPA-Navigation (Ausnahme: initialer Aufruf, Magic Links)
- Negativtests (IDOR, Auth, 403/404) → Pflicht für Admin/API-Tests
- `down()` in Migrationen → immer leer lassen (`// Intentionally empty`)

---

### Validation Analysis Results

**Validierungseinstiegspunkte:**

| Entry Point | File | Request Class | Scope ID Field | Scope ID Rules |
|---|---|---|---|---|
| `store()` | `CouponController.php:99` | `CouponStoreRequest` | `scope_id` | `nullable\|string\|required_if:scope_type,gallery,meta_gallery` |
| `update()` | `CouponController.php:122` | `CouponUpdateRequest` | `scope_id` | `nullable\|string\|required_if:scope_type,gallery,meta_gallery` |
| `storeGalleryCoupon()` | `CouponController.php:210` | `CouponStoreRequest` | `scope_id` (injected) | same as store (via `prepareForValidation`) |
| `storeGroupCoupon()` | `CouponController.php:283` | `CouponStoreRequest` | `scope_id` (injected) | same as store (via `prepareForValidation`) |
| `validateCoupon()` | `CouponController.php:314` | `Validator::make()` inline | `gallery_id`, `meta_gallery_id` | `nullable\|string` (no `required_if`, no DB existence check) |

**Inconsistencies & Issues:**

| # | Issue | Severity | Details |
|---|---|---|---|
| 1 | `validateCoupon()` verwendet `Validator::make()` statt FormRequest | MAJOR | Bricht mit Single-Fehler-String ab, keine konsistente Fehlerstruktur. Kein `prepareForValidation` möglich. |
| 2 | Feldnamen inkonsistent: `scope_id` (CRUD) vs `gallery_id`/`meta_gallery_id` (validate) | MAJOR | CRUD verwendet `scope_id`, validate verwendet separate `gallery_id` + `meta_gallery_id`. Clients müssen unterschiedliche Feldnamen senden. |
| 3 | Validate-Endpoint fehlt `required_if` Logik | MAJOR | CRUD erzwingt `scope_id` bei `scope_type=gallery\|meta_gallery`. Validate-Endpoint hat keine solche Prüfung — `gallery_id`/`meta_gallery_id` sind immer optional. |
| 4 | Validate-Endpoint prüft nicht auf DB-Existenz der gallery_id | CRITICAL | Ein Coupon mit `scope_type=gallery, scope_id=UUID` kann nicht gefunden werden, wenn der Client eine nicht-existierende `gallery_id` sendet. Der `CouponService::findValidCoupon()` matched nur via scope_id — ungültige gallery_id führt zu "not found" statt einem klaren Validierungsfehler. |
| 5 | V022 Migration überschreibt `scope_type` ENUM ohne `organisation` | CRITICAL (fixed) | `ALTER TABLE MODIFY COLUMN ... scope_type ENUM('global','gallery','meta_gallery','photographer')` entfernte `organisation`. Behoben durch Hinzufügen von `organisation` zum ENUM. |
| 6 | V022 Migration `CHANGE max_uses → max_uses_global` bricht ab | CRITICAL (fixed) | V018 erstellt `coupons` bereits mit `max_uses_global`. V022's `CHANGE max_uses ...` schlägt fehl. Behoben durch `Schema::hasColumn()` Guard. |
| 7 | `CouponResource` exponiert kein `brand` | MAJOR (fixed) | `toArray()` fehlte `brand`-Feld. Test `admin_can_list_brand_coupons` erwartete es. Behoben durch Hinzufügen. |
| 8 | Test `test_validate_coupon_with_scope_gallery` verwendet Integer-IDs | MINOR (fixed) | `scope_id => 123` und `gallery_id => 123` waren Integer, aber Validation erwartet `string`. Behoben durch Änderung auf String-Werte. |

**Empfohlene Fixes (noch offen):**

1. **Refactor `validateCoupon()` zu FormRequest** — Erstelle `CouponValidateRequest` mit konsistenten Feldnamen und `required_if` Logik
2. **DB-Existenz-Prüfung für gallery_id/meta_gallery_id** — Ergänze `exists:galleries,id` bzw. `exists:gallery_groups,id` in der Validation
3. **Feldname-Angleichung** — Verwende `scope_id` auch im validate-Endpoint (oder zumindest klare API-Dokumentation)

### PHP Test Fixes Summary (2026-07-04)

**Root Cause:** V022 migration was incompatible with V018 consolidated schema:
1. `V022__extend_coupons_and_user_usage.php:29` — `CHANGE max_uses → max_uses_global` failed because V018 already created the column as `max_uses_global`
2. `V022__extend_coupons_and_user_usage.php:27` — ENUM stripped `organisation` from scope_type
3. `CouponResource.php` — Missing `brand` field in `toArray()`
4. `CouponControllerTest.php:601` — Integer IDs instead of strings

**Files changed:**
- `backend/database/migrations/V022__extend_coupons_and_user_usage.php` — Added `Schema::hasColumn()` guards + preserved `organisation` ENUM value
- `backend/app/Http/Resources/CouponResource.php` — Added `'brand' => $this->brand->value`
- `backend/tests/Feature/Coupon/CouponControllerTest.php` — Fixed `test_validate_coupon_with_scope_gallery` to use string IDs

**Result:** All 85 coupon tests pass (was 24/24 CouponControllerTest, 34/34 CouponServiceTest, 15/15 CouponTest, 7/7 CheckoutCouponRevalidationTest).
