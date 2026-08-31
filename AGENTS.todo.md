# Task Board — Portal Reisinger Pictures

> Stand: 2026-08-25. **Nur offene TODOs + erledigte Referenz-Blöcke.** Architekturentscheidungen in `features/`.
>
> Test-Regel (DoD): Backend → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.

---

## ✅ Erledigt (2026-08-19) — F3 + P1 + A1 Komplett

Alle drei Pakete implementiert, verifiziert und committed (22 Commits, `main` ahead of `origin/main`):

| Paket | Umfang | Tests |
|-------|--------|-------|
| **F3** Brand Settings Admin-UI | Backend (Service/Controller/Routes) + Frontend (Hook/Card/E2E) + Doku | 24 PHP, 4 Vitest, E2E spec |
| **P1** Coupon `photo_package` | V030 Migration, Service, Frontend (Form/Listen), E2E | 72 PHP, 4 Vitest, E2E spec (4 Tests) |
| **A1** Authorization Refactoring (Steps 1–7) | Model-Delegation, Gates, Middleware, Policies, Controllers (6a–6f), PurchaseService | 1192 PHP, Zero `$user->is_*` in Controllers/Policies |

**Letzter Stand:** 1192/0 PHPUnit, 593/0 Vitest, lint+build 0.

---

## ✅ Erledigt (2026-08-18) — CI/Test-Image + Tooling

- **portal-e2e Docker-Image:** CI-Beschleunigung (−33% Critical Path), Stripe-Idempotency-Race gefixt, Shard-Split 3→5. Doku: `features/infrastructure/28-ci-test-image.md`.
- **CodeGraph Pre-Commit-Hook:** `.githooks/pre-commit` → `codegraph sync -q`, fails open. Doku: `AGENTS.md` §11.
- **Zentrales Skills-Repo:** `agents-skills` (GitHub), Skills global registriert. Doku: `AGENTS.md` §12.

## ✅ Erledigt (2026-08-18) — WYSIWYG + PDF + Responsive UI

- Tasks A–L: WYSIWYG-Resize, PDF-Entduplizierung, Kalkulation-oben, Typografie (orphans/widows), Baustein-Select entfernt, Item-/Discount-Responsive-Layout, Löschen rechtsbündig, Rabatt-Gesamt-Spalte.
- Kanban PDF-Drop E2E-Test.

---

## 🟡 OFFEN (Future) — pricing_strategy als Brand-Setting

- Langfristig: `pricing_strategy` je Brand in Admin-UI editierbar (DB-Overlay, Choke-Point `BrandRegistry::buildFromArray()`). Doku: `features/infrastructure/17-pricing-strategy-pattern.md` §7.

## 🟡 OFFEN (manuell) — Prod-Infra

- **Portainer Stack-Redeploy** für `portal-base:8.5` (User-Notify erledigt, Deploy pending).

---

## ✅ ERLEDIGT (2026-08-31, magenta) — Prod-Bildlieferung: Header-Mismatch (X-Sendfile vs X-Accel-Redirect)

- **Fix:** `.env.production` → `PROXY_DELIVERY_HEADER=X-Accel-Redirect` + `PHOTO_STORAGE_PATH=/var/www/photos`; `deployment/docker-compose.yml` → Pass-through `PHOTO_STORAGE_PATH=${PHOTO_STORAGE_PATH}` ergänzt; Backend-Container neu deployt.
- **Verifiziert:** alle `/api/media/*`-Größen (250/400/800/1200/2000) + Original-Branch liefern echte WebP/JPEG-Bytes (10–780 KB), kein `x-sendfile`/`x-accel-redirect`-Leak mehr; `/context`, `license-terms`, alle SPA-Chunks 200.
- **Restbefund:** „Fehler persistierte“ war Browser-Cache der leeren `immutable`-Antworten (max-age 1 Jahr) + alte Bundle-Stände — nach Cache-Leeren + Voll-Refresh behoben.
- **Hinweis:** `deployment/docker-compose.yml`-Änderung (1 Zeile, git-getrackt) liegt noch als `M` im Working Tree — Commit+Pull steht aus.

---

## 🟠 OFFEN (2026-08-31) — Rücktrittsrecht-Compliance für Foto-Downloads

> Ziel: Rücktrittsrecht erlischt rechtskonform (nur digitale Produkte, kein physischer Mix → §13a Mischkorb n/a).
> Plan: `~/.opencode/plan/withdrawal-rights-compliance.md`. Hinweis: Rechtstext-Wording vor Go-live juristisch absegnen lassen.

**WI-A Backend — Consent-Erzwingung + Protokollierung**
- [x] V032-Migration: `orders.withdrawal_waived` (bool, default false) + `orders.withdrawal_consent_at` (timestamp) → Persistenz-PHPUnit ✅ (`WithdrawalConsentTest`, 8 passed, 40 assertions).
- [x] `Order`-Model: fillable + casts.
- [x] `CheckoutService::processCheckout`: 422 wenn `!isQuoteRequest && !withdrawal_waived` → Regression-PHPUnit ✅ (false/missing → 422; Quote ohne Bedingung → OK; quote_token-Flow abgedeckt).
- [x] `createOrder` + `createInvoiceSnapshot`: Consent + Zeitstempel persistieren; Nachweis in `customer_details.withdrawal_consent` (immutables Evidence Package).
- [x] FAGG-Zitierung korrigiert: § 18 Abs. 1 **Z 11** (CheckoutService-Konstante + InvoiceMail); fix-Subagent verifiziert, kein „Z 10“ mehr im Backend.

**WI-B Backend — Widerruf-Absatz in Kaufmail + Rechnungs-PDF**
- [x] `InvoiceMail` customBody: Absatz „Zustimmung Sofort-Download + Erlöschen Rücktrittsrecht (inkl. Zeitstempel)“ → PHPUnit Mail-Render enthält Absatz ✅.
- [x] `pdf/invoice.blade.php`: Widerruf-Abschnitt (nur wenn Consent vorliegt) → PHPUnit PDF-Output enthält Abschnitt ✅.

**WI-C Frontend — Checkout-Text verfeinern („sofortiger Download“ explizit) + Schema-Factory**
- [x] Checkbox-Text in `ClientCartView.tsx` anpassen → **Test-TODO:** Vitest auf neuen Text (✅ 597 Vitest-Tests grün, `lint:fix` + `build` fehlerfrei, Prod-Probe ohne Lingui-pageerror).
- [x] `checkoutSchema` in Factory-Funktion umbauen (Lingui module-scope-Regel, frontend/AGENTS.md REG 2026-08-12).

**WI-D Frontend — Rechtliche Seiten AGB + Widerrufsbelehrung**
- [x] `LicenseTerms.tsx` (/license-terms) inkl. § 18 Abs. 1 Z 11 FAGG-Klausel → **Test-TODO:** Vitest (✅) + E2E (`tests/e2e/guest/legal-pages.spec.ts`, `@feature:legal` — E2E-Lauf steht in Verifikationsphase, Stack nicht gestartet).
- [x] `Widerrufsbelehrung.tsx` (/widerruf) mit Erlöschen-Absatz → **Test-TODO:** Vitest (✅) + E2E (s.o.).
- [x] Routen in `App.tsx`, Verlinkungen (Impressum/Footer), toter `/license-terms`-Link wird funktionsfähig.
  - Hinweis: Formulierung „Rücktritts- bzw. Widerrufsrecht“ (erfüllt Unit-Test-Regex `/widerrufsrecht/i` + Rechtstext). Vor Go-live juristisch absegnen.

**WI-E Docs**
- [x] `features/ecommerce/06-legal-evidence-and-disputes.md`: Consent als Teil des Evidence Package; „keine physischen Produkte“-Entscheidung festhalten (Abschnitt 3 + Related bereinigt).

**Verifikation (Subagent, nie Implementierer)** → ✅ abgeschlossen (31.08.2026): Backend 1200/2989, Frontend 597, lint+build 0, `@smoke` 58 passed, `@feature:legal` 6 passed (nach Fix der Test-Deklinationsform „sofortiger Download“). Diff-Review PASS, Gesamturteil READY_TO_COMMIT.
- [x] WI-A, WI-B, WI-C, WI-D, WI-E komplett implementiert und verifiziert.
- [x] Hinweis: Rechtstext-Wording (Checkbox, Mail/PDF, AGB, Widerrufsbelehrung) vor Go-live juristisch absegnen lassen.

---

## 📋 Archivierte Backlog-Pläne (2026-08-04)

> Nur Referenz. Umsetzung abgeschlossen oder obsolet.

- **A1** User-God-Entity → ✅ erledigt (Steps 1–7, siehe oben)
- **F3** Brand Settings UI → ✅ erledigt (siehe oben)
- **P1** Coupon photo_package → ✅ erledigt (siehe oben)
- **Stack-Konsolidierung** → ❌ OBSOLET (SQLite-Richtung)

---

## 🚫 Blockiert (Dependency-Migration 2026-08-23)

- **typescript 6→7:** Risiko durch TS7, erst nach Framework-Support. TS 7.0 ist zu frisch (kein Support durch Vite/Rolldown-Babel-Pipeline, ESLint-Typescript-Stack, React-Compiler-Preset). `frontend/package.json` bleibt bei `^6.0.3`. Nachzuziehen, sobald das Tooling TS7 deklariert.
  - Verifiziert 2026-08-25: weiterhin offen (`frontend/package.json`: `"typescript": "^6.0.3"`).
