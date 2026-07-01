# Strict User Brand Isolation — Login Rejection + Staff Brand-Bound (U-01, U-02)

> **Status:** `active` — verbindlicher Soll-Zustand.
> Erstellt 2026-07-01; ersetzt Policy A (A-01) aus `10-frontend-brand-tenant-isolation.md`.
>
> **Verknüpfte Tasks:** U-01 (Login Brand-Mismatch Rejection), U-02 (Staff brand-bound),
> `AGENTS.todo.md` Entscheidungen E-01, E-02, E-03 (2026-06-30, User).

## 1. Kontext

Bisher galt Policy A (A-01, 2026-06-29): **Nur Client-Accounts sind brand-gebunden; alle
Staff-Rollen (Super-Admin, Admin, Photographer) sind cross-brand** (`brand=null`).

Der User hat am 2026-06-30 entschieden, dass diese Policy **umgekehrt** wird:

> **Neue Regel (U-02):** Staff (Admin, Photographer, Customer Manager, Power User) wird
> **brand-bound** — jeder Account gehört zu genau einer Brand (`'rp'` oder `'srp'`).
> **Einzige Ausnahme:** `florian@reisinger.pictures` (Super-Admin, `brand=null`).

Diese Isolation wird sowohl auf **Login-Ebene** (U-01) als auch auf **API/Controller-Ebene**
(U-02) durchgesetzt.

## 2. Soll-Zustand

### 2.1 Login Brand-Mismatch Rejection (U-01)

| Bedingung | Verhalten |
|-----------|-----------|
| User `brand = 'rp'`, Login an `story.reisinger.pictures` (SRP) | **403** mit JSON `{"error": "Dieser Account ist für ein anderes Portal registriert."}` |
| User `brand = 'srp'`, Login an `reisinger.pictures` (B2B) | **403** mit gleicher Meldung |
| User `brand = null` (Super-Admin) an beliebigem Portal | **200** — immer erlaubt |
| User `brand = 'rp'` an B2B, `brand = 'srp'` an SRP | **200** — Match |

**Implementierung:** `AuthController::login()` nach erfolgreichem Password-Check, VOR
JWT-Ausstellung.

### 2.2 Staff Brand-Bound (U-02) — Policy A Umkehrung

| Rolle | Brand-Verhalten |
|-------|-----------------|
| **Super-Admin** (`brand = null`) | Cross-brand — einzige Ausnahme |
| **Admin** | Brand-bound: `brand` MUSS `'rp'` oder `'srp'` sein |
| **Photographer** | Brand-bound: `brand` MUSS `'rp'` oder `'srp'` sein |
| **Customer Manager** | Brand-bound: `brand` MUSS `'rp'` oder `'srp'` sein |
| **Power User** | Brand-bound: `brand` MUSS `'rp'` oder `'srp'` sein |
| **Client** | Brand-bound: `brand` MUSS `'rp'` oder `'srp'` sein |

**Ausnahme:** Der einzige Super-Admin ist `florian@reisinger.pictures`.

### 2.3 Daten-Migration (bestehende User korrigieren)

Alle bestehenden User mit `brand = null` außer `florian@reisinger.pictures` erhalten
`brand = 'rp'` (historischer B2B-Default, siehe E-03).

### 2.4 Selbstregistrierung (AuthController::register)

Neue User erhalten automatisch `brand = BrandRegistry::currentOrDefault()` — nie `null`.
Cross-brand bleibt dem manuell angelegten Super-Admin vorbehalten.

## 3. API-Validierung

### 3.1 UpdateUserRequest (Backend)

- Super-Admin-Rolle → `brand` MUSS `null` sein (oder wird ignoriert).
- Jede andere Rolle → `brand` MUSS `'rp'` oder `'srp'` sein — NICHT `null`.

### 3.2 UserController::update

- `$request->has('brand')` Block: Alte Policy-A-Logik (`$isStaffAccount ? null : $request->brand`)
  wird ersetzt durch:
  - Ist die Rolle **Super-Admin** → `brand = null`
  - Sonst → `brand = $request->brand` (validierter Wert)

## 4. Frontend UserPermissionsModal

| Zustand | Brand-Select |
|---------|--------------|
| Super-Admin-Rolle ausgewählt | disabled, Wert = `null` (cross-brand), Label "Übergreifend (cross-brand)" |
| Admin/Photographer/andere Rolle | enabled, User MUSS `'rp'` oder `'srp'` wählen |
| Wechsel von Staff → Super-Admin | Brand wird auf `null` gesetzt |
| Wechsel von Super-Admin → Staff | Brand-Select enabled, User muss wählen |

## 5. Datenfluss

```
Login-Request
  → AuthController::login()
    → Password-Check (Hash::check)
    → Brand-Mismatch-Check: $user->brand !== null && $user->brand !== currentBrand()
      → 403 mit Brand-Mismatch-Fehler
    → JWT ausstellen

User-Update-Request
  → UpdateUserRequest (Validation)
    → after_validation: super_admin → brand=null; sonst → brand required
  → UserController::update()
    → brand = $isSuperAdmin ? null : $request->brand
```

## 6. Abgrenzung

- Die `AccessControlService::getAllowedGalleryIds()`-Logik (`brand=null` = cross-brand,
  `brand!=null` = brand-scoped) **ändert sich nicht** — nur die Policy, WELCHE User
  `brand=null` haben dürfen.
- Photographer-Doppelrolle (E-02): Ein Fotograf, der für beide Brands arbeitet, erhält
  **zwei separate Accounts** pro Brand. Kein Sonderfall-Code.
- Historische Rechnungen (E-03): Alle existierenden Bestellungen/Rechnungen sind `'rp'`.

## 7. Decision Log

| Datum | Eintrag |
|-------|---------|
| 2026-06-30 | **E-01 (Login-Portal-Bindung):** Brand-Mismatch → Abweisung, Super-Admin ausgenommen. |
| 2026-06-30 | **E-02 (Photographen-Doppelrolle):** Getrennte Accounts pro Brand. |
| 2026-06-30 | **E-03 (Historische Rechnungen):** Alle `'rp'`. |
| 2026-07-01 | **U-01/U-02 implementiert.** Policy A (A-01) aus `10-frontend-brand-tenant-isolation.md` ist superseded. |
