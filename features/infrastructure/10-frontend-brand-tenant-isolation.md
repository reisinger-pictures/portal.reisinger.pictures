# Frontend Mandanten-/Brand-Isolation — Konzept (entschieden, aktiv)

> **Status:** `active` — beschreibt den verbindlichen, entschiedenen Endzustand.
> Verknüpft: `AGENTS.todo.md` A-01 (aufgelöst), T-03 (ehem. R-15b), T-09,
> `features/infrastructure/08-tenant-brand-concept.md`,
> `features/auth/01-roles-and-access.md`.
> Erstellt 2026-06-29; Policy-Entscheidung (A-01) 2026-06-29.

## 1. Kontext

Das Portal läuft unter zwei White-Label-Brands: `reisinger.pictures` (B2B, vollständiges
Admin-/Mandanten-/CRM-/Invoicing-Portal) und `story.reisinger.pictures` (B2C, reduziertes Kunden-Portal).
Die Brand-Erkennung im Frontend erfolgt rein clientseitig via Hostname
(`frontend/src/logic/useBrand.ts:3-6`).

## 2. Ist-Stand — Das Problem

Die UI-Steuerung ist **rein rollenbasiert, nicht brandbasiert**. Das hat zwei Konsequenzen:

### 2.1 Dashboard-Weiche (rollenbasiert, brandblind)

`frontend/src/ui/ProtectedDashboard.tsx:16-20`:
```
if (user.is_super_admin || user.is_admin || user.is_photographer) return <ManagementDashboard/>;
return <ClientDashboard/>;
```
Ein Admin/Super-Admin landet auch auf `story.reisinger.pictures` im **vollen B2B-ManagementDashboard**
(Mandanten, CRM, Invoicing) — ungeachtet der Marke.

### 2.2 B2B-Routen ohne Brand-Guard

`frontend/src/App.tsx:97-105` schützt B2B-Admin-Routen (`/tenants`, `/tenants/:id`, `/admin-orders`,
`/admin-manual-invoice`, `/admin-manual-offer`, `/admin-customers`, `/admin-products`,
`/admin-snippets`, `/admin-payouts`) nur durch `ProtectedRoute` (Login-Pflicht). Die Routen sind
direkt ansteuerbar, unabhängig vom Brand.

### 2.3 Sidebar zeigt B2B-Mandanten-Link markenunabhängig

`frontend/src/ui/components/Sidebar.tsx:86` blendet „Mandanten (B2B)" ein, sobald
`user.is_admin || user.is_customer_manager` — unabhängig vom Hostnamen. Die Mandanten-Liste ist
auf `story.reisinger.pictures` direkt navigierbar.

### 2.4 Keine Backend-gestützte Brand-Assertion

`useBrand.ts:9` vertraut blind auf `window.location.hostname`. Die User-/Tenancy-Daten werden
nie gegen die Marke validiert (`useAuth.ts` kennt keine `brand`/`tenant`-Felder). Die letzte
Verteidigungslinie muss das Backend sein (API-Filter), was aus dem Frontend-Code nicht
verifizierbar ist.

## 3. Soll-Zustand — Isolations-Regel

> **Verbindliche Regel (Stakeholder-Entscheidung 2026-06-29):**

| Rolle | Brand-Scope | Verhalten |
|-------|-------------|-----------|
| **Super-Admin / Admin / Photographer** | übergreifend | Sehen/bedienen beide Marken; dürfen zwischen Mandanten verschieben. **Keine** Frontend-Blockade — bewusst dokumentiert, nicht per Guard geblockt. |
| **Client / Kunde** | **brandgebunden** | Kunden-Logins gelten nur für ihren Mandanten + ihre Marke. Auf dem jeweils fremden Brand sehen sie **keine** B2B-Admin-Funktionen (nur `ClientDashboard`). |

Das bedeutet: **Nur Kunden-Logins** werden mandanten-/brandgebunden isoliert. Die übergreifenden
Rollen (Admin/Fotograf/Super-Admin) behalten bewusst die volle Steuerung — das ist kein Bug,
sondern gewollt.

## 3a. Decision (A-01, 2026-06-29) — Policy A is binding

**Decision:** Policy A from T-02 is confirmed as the final, binding rule. **Only client-type
(external) accounts are brand-bound; all staff (Super-Admin, Admin, Photographer) are
cross-brand.**

**Rejected — T-09's broader requirement ("alle Rollen außer Super-Admin brandgebunden"):**
This was evaluated and **rejected**. Reasons:

1. **Business correctness / least surprise:** A photographer shooting for both B2B (`rp`) and
   SRP (`srp`), and an admin managing the whole portal, must see both brands' galleries. The
   role definitions in `features/auth/01-roles-and-access.md` already promise cross-system
   access (Photographer: "across the system"; Admin: "statistics for ALL galleries across the
   system"). Per-brand isolation would break their core workflows.
2. **SaaS best practice:** Staff are trusted internal operators → cross-tenant by default.
   External customer accounts → tenant-scoped. This is the standard multi-tenant pattern.
3. **Simplicity:** One rule — `brand != null` ⟺ external/client account, `brand == null`
   ⟺ staff. No per-role special cases.

The corresponding TODO checkbox "alle Rollen außer SA" (formerly referenced from T-09) is
therefore **superseded / removed**. The code mechanic in
`User::getAllowedGalleryIds()` (`brand != null` → scoped, `brand == null` → cross-brand) is
policy-agnostic and **unchanged** — under Policy A it is simply guaranteed that only
client-type accounts ever carry a non-null `brand`.

> Note on terminology: the binding criterion is the **account type** (external vs. staff),
> not the role label. In practice: `is_super_admin | is_admin | is_photographer` ⟹
> `brand = null`; `is_client` (and any non-staff external account) ⟹ `brand ∈ {rp, srp}`.

## 4. Ziel-Architektur (Konzept)

### 4.1 Brand-Access-Logik (pure, testbar)

Ein neuer pure-Logic-Hook (z. B. `frontend/src/logic/useBrandAccess.ts`) kapselt die Entscheidung:
`canAccessB2BAdmin(user, brand): boolean` —
- `true` für `is_super_admin || is_admin || is_photographer` (übergreifend).
- `false` für Kunden auf B2B-Admin-Funktionen.

### 4.2 Routing

- B2B-Admin-Routen bleiben für übergreifende Rollen erreichbar.
- **Kunden** werden auf B2B-Admin-Routen ihres fremden Brands umgeleitet (z. B. auf `/`),
  nicht geblockt für Admins.
- `ProtectedDashboard` sendet Kunden unverändert ins `ClientDashboard` (rollenbasiert); die
  Brand-Logik greift nur dort, wo B2B-Admin-Funktionen betroffen sind.

### 4.3 Sidebar

- B2B-Mandanten-/CRM-/Invoicing-Links werden für Kunden ausgeblendet (über die Brand-Access-Logik).
- Für übergreifende Rollen bleiben sie sichtbar.

## 5. Abgrenzung

- Diese Isolation ist eine **Frontend-UX-Maßnahme** (verhindert, dass Kunden B2B-Funktionen
  sehen). Die **echte Daten-Isolation** muss zwingend im Backend erfolgen (API-Endpunkte
  filtern nach Brand/Tenant) — das ist ein separates Thema und aus dem Frontend nicht allein
  lösbar.
- Der UI-Begriff „Mandant" wird ggf. im Rahmen von T-01 angepasst (siehe
  `08-tenant-brand-concept.md` §3).

## 6. Verifikation (später)

- Frontend-Unit-Tests (`brand.test.ts`): `getBrandFromHostname`, `useBrandAccess` für alle
  Rollen×Brand-Kombinationen, Theme-Werte (`b2b-light`/`srp-light`).
- E2E (Playwright via `ai_test_runner.mjs`): Kunde auf fremdem Brand sieht keine B2B-Kacheln;
  Admin sieht beide.

---

## 7. Decision Log

| Datum | Eintrag |
|-------|---------|
| 2026-06-29 | **A-01 resolviert.** Konflikt zwischen T-02 (Policy A: nur Kunden brandgebunden) und T-09 (Policy B: alle Rollen außer Super-Admin brandgebunden). **Entscheidung: Policy A (T-02) ist verbindlich.** Begründung: Staff (Admin/Fotograf/Super-Admin) brauchen Cross-Brand-Zugriff laut Rollendefinition (`features/auth/01-roles-and-access.md`); Standard-Multi-Tenant-Practice (Staff = cross-tenant, Externe = tenant-scoped); einfachste korrekte Regel (`brand != null` ⟺ externer/Client-Account). T-09s "alle Rollen außer SA"-Forderung verworfen; entsprechendes TODO superseded. Code-Mechanik (`User::getAllowedGalleryIds()`) bleibt unverändert. |
