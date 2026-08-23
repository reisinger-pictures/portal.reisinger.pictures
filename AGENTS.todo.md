# Task Board — Portal Reisinger Pictures

> Stand: 2026-08-19. **Nur offene TODOs + erledigte Referenz-Blöcke.** Architekturentscheidungen in `features/`.
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

## 🟡 Flaky-/CI-Failures Analyse (2026-08-23)

> Anlass: CI-Run [`32657588529`](https://github.com/reisi007/portal.reisinger.pictures/actions/runs/32657588529) (Commit `31287fc`, Actions-Version-Bumps checkout@v7/setup-node@v7) rot in Shards Desktop(1/2) + Mobile(1/2). Rerun (`--failed`) schlug **identisch** fehl → überwiegend deterministische Bugs, keine zufällige Flakiness. Die 22 Commits davor (F3/P1/A1) waren nie in CI gelaufen.

### 1. ✅ GEFIXT — Coupon `photo_package`: INSERT 500 auf MariaDB (deterministisch)

- **Wo:** Workflow `ci.yml` → E2E-Shards Desktop(1/2)/Mobile(1/2); Specs `frontend/tests/e2e/admin/coupon-photo-package.spec.ts:26` und `:60` (beide @feature:coupon).
- **Symptom:** Toast „Gutscheincode angelegt" erscheint nie, Coupon taucht nicht in der Liste auf (beide Tests, alle Retries, beide CI-Läufe).
- **Fehlermeldung (Trace-Network, Playwright-Report-Artifact):** `POST /api/management/coupons → 500` · `SQLSTATE[01000]: Warning: 1265 Data truncated for column 'type' at row 1` beim Insert `type=photo_package`.
- **Ursache:** V018 legte `coupons.type` als MySQL-ENUM(`fixed`,`percentage`,`free_items`) an. V030 ergänzte nur die Spalten `package_quantity`/`package_price_cents`, **nicht aber den ENUM-Wert**. SQLite (lokale E2E-DB, PHPUnit) speichert Laravel-Enums als plain VARCHAR → Bug fiel lokal unsichtbar durch; MariaDB (CI/Prod) rejected den Insert.
- **Behoben durch:** Migration `backend/database/migrations/V031__coupon_type_photo_package_enum.php` (ENUM um `photo_package` erweitert, SQLite-No-op, `down()` mit Row-Guard). Lokal verifiziert: `migrate:fresh --seed --env=e2e` grün, Coupon-Specs 6/6 grün (`--repeat-each=3`).

### 2. ✅ GEFIXT — ai-config Spec: Strict-mode violation „Einstellungen"-Heading (deterministisch)

- **Wo:** `frontend/tests/e2e/admin/ai-config.spec.ts:109` („AI configuration page loads for super_admin", @feature:admin:ai), alle Projekte/Shards mit diesem Test.
- **Symptom:** `strict mode violation: getByRole('heading', { name: /Einstellungen/i }) resolved to 2 elements` → `<h1>System-Einstellungen</h1>` + `<h2>Markeneinstellungen</h2>` (letzte aus der neuen F3 Brand-Settings-Card).
- **Ursache:** F3 fügte ein zweites, regex-matchendes Heading hinzu; der Test nutzte eine zu breite Regex.
- **Behoben durch:** Test-Fix — exakter Heading-Name `getByRole('heading', { name: 'System-Einstellungen' })`. Lokal verifiziert (vorher 5/5 Runs rot, nach Fix grün).

### 3. 🟡 MIGRIERT (Timeout erhöht) — AuthHelper.login: `main` nicht sichtbar nach Login (intermittierend)

- **Wo:** `frontend/tests/e2e/helpers/AuthHelper.ts:15`; beobachtet in CI Run 32657588529 (Rerun, `admin.spec.ts:69` „Admin can access settings"; bereits Run 1 in Shard-Logs `admin.spec.ts:34`) — Job scheiterte, Retry desselben Tests lief teils grün.
- **Symptom:** `expect(page.locator('main').first()).toBeVisible({timeout:5000})` → `element(s) not found` direkt nach `page.goto('/')`.
- **Mögliche Ursache:** Cold-start des Backends/Dev-Servers + 2 Worker pro Shard unter 2-Core-Runner → Initial-Render > 5 s. (Lokal trat die identische Signatur nur auf, als ein Vite-Devserver mit veraltetem node_modules-Stand ein Error-Overlay statt der App zeigte — Environment-Artefakt, nicht App-Bug.)
- **Maßnahme:** Timeout 5 s → 15 s (auch für `app-loader`). Beobachten; falls weiterhin rot, Trace im HTML-Report auswerten.

### 4. 🟡 MIGRIERT (Timeout erhöht) — SidebarHelper.navigateTo: Sidebar-Link-Timeout (intermittierend, lokal reproduziert 1×)

- **Wo:** `frontend/tests/e2e/helpers/SidebarHelper.ts:23`; lokal im exakten CI-Shard-Aufruf (Desktop 1/2): 1× flaky in `no-b2b-label.spec.ts:81`, auf Retry grün. In CI bisher nicht als Failure aufgefallen.
- **Symptom:** `link.waitFor({state:'visible', timeout:5000})` → Timeout beim Warten auf den Menü-Link.
- **Mögliche Ursache:** Sidebar-Rendering/Animation noch nicht abgeschlossen unter Last.
- **Maßnahme:** Timeout 5 s → 10 s.

---

## 🔬 Lokale E2E-Reproduktion (2026-08-23)

Setup: `scripts/e2e-up.sh` (isoliertes Backend :8001, SQLite `database.e2e.sqlite`, Meili :7701, natives Mailpit) + `VITE_API_PROXY=http://127.0.0.1:8001 pnpm dev` (:4321).

| Experiment | Ergebnis |
|---|---|
| Betroffene Specs (admin/ai-config/admin/coupon), 1× Desktop | ai-config **fehlgeschlagen (deterministisch)**, Rest grün |
| Dieselben 3 Dateien `--repeat-each=5` | 45 passed / **5 failed** = ausschließlich ai-config (5/5 Wiederholungen) → deterministisch, kein Timing |
| Exakter CI-Shard `--project="Desktop Chrome" --grep-invert … --workers=2 --shard=1/2` | **69 passed**, 1 flaky (no-b2b-label, SidebarHelper) — Coupon-Fehler **nicht** reproduzierbar gegen SQLite |
| Trace-Analyse CI-Artifact (`playwright-report-desktop-1`) | POST /api/management/coupons → 500 (ENUM-Truncation) → Root Cause von #1 nur in CI (MariaDB) sichtbar |

**Schlussfolgerung:** Fehler #1/#2 waren echte, durch SQLite-vs-MariaDB bzw. UI-Änderung maskierte Bugs (nur in CI sichtbar); #3/#4 sind Last-/Timing-Flakiness → Timeouts erhöht.

---

## 🟡 OFFEN (Future) — pricing_strategy als Brand-Setting

- Langfristig: `pricing_strategy` je Brand in Admin-UI editierbar (DB-Overlay, Choke-Point `BrandRegistry::buildFromArray()`). Doku: `features/infrastructure/17-pricing-strategy-pattern.md` §7.

## 🟡 OFFEN (manuell) — Prod-Infra

- **Portainer Stack-Redeploy** für `portal-base:8.5` (User-Notify erledigt, Deploy pending).

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

## 🔧 Backend Dependency-Migration 2026-08-23

- **composer self-update:** durchgeführt (2.10.1 → 2.10.2).
- **PHP-Constraint:** `^8.4` → `^8.5` (composer.json + lock).
- **MAJOR stripe/stripe-php:** `^20.3.0` → `^21.0.0` (lock: v20.3.0 → v21.2.1). Laut Stripe-Changelog ist v21 *funktional ein Patch-Release* (gleiche gepinnte API-Version `2026-06-24.dahlia`, Major nur aus Vorsicht). Breaking-Changes betreffen nur V2/Private-Preview-Ressourcen (`ReceivedCredit.balance_transfer.payout_v1` u.ä.) — nicht genutzt (App nutzt `Webhook::constructEvent`, `StripeClient`/`paymentIntents->create|retrieve`). Kein Code-Change nötig.
- **Minor/Patch:** laravel/framework v13.18.1 → v13.26.1, scout, pint, phpunit 13.2.2 → 13.3.1, jwt-auth 2.9.2 → v2.9.3, meilisearch 1.16.1 → 1.17.0, mockery 1.6.12 → 1.6.15, paratest v7.23.0 → v7.24.1, collision v8.9.4 → v8.9.5, symfony-* 8.1.x.
- **Transitive Majors — Verify:**
  - ✅ `hamcrest/hamcrest-php` v2.1.1 → v3.0.0 (automatisch via phpunit/mockery).
  - ⛔ `guzzlehttp/guzzle` **blieb bei 7.15.3** (kein 8.0.2): guzzle 8 verlangt `psr7 ^3.0` + `promises ^3.0.1`; `psr7 ^3.0` wird durch direktes `require http-interop/http-factory-guzzle ^1.2` blockiert (nur `psr7 ^1.7||^2.0`, keine 3.0-fähige Version existiert). Guzzle-8 wäre Scope-Erweiterung (Bump von `http-interop/http-factory-guzzle` nötig, nicht in Aufgabe) → bewusst NICHT erzwungen.
  - ⛔ `brick/math` **blieb bei 0.18.0** (kein 0.19): transitiv gedeckelt durch `ramsey/uuid 4.9.3` (`brick/math >=0.8.16 <=0.18`). 0.19 nur mit ramsey/uuid-Bump erreichbar (nicht in Aufgabe) → bewusst NICHT erzwungen.

## 🔴 CI-Status PR #10 (chore/deps-2026-08-23) — E2E rot, ABER nicht durch Dep-Migration

- **Backend (PHPUnit):** ✅ grün (u.a. P1 Coupon-Tests bestehen).
- **Frontend (Lint, Build, Vitest):** ✅ grün (593 Tests).
- **E2E (Playwright):** ❌ zwei Shards rot — `Desktop (1/2)` + `Mobile (1/2)`. Fehler:
  `admin.spec.ts:69` (Admin can access settings @smoke), `ai-config.spec.ts:101`
  (strict-mode: `heading /Einstellungen/i` matched 2 elements), `coupon-photo-package.spec.ts:26/:60`.
- **Ursache:** **NICHT** die Dependency-Migration. Beweis:
  1. Es wurden **keinerlei App-/Test-Quellcode** geändert (nur `package.json`,
     Lockfiles, `composer.json/.lock`). Backend-PHPUnit + 593 Vitest + Build/Lint
     sind grün; 3/5 E2E-Shards (inkl. kanban-serial, hohe App-Last) sind grün.
  2. `ai-config.spec.ts:101` ist **präexistent kaputt** und wird im Working-Tree
     (uncommitted, Parallel-Session) bereits gefixt: die Regex `/Einstellungen/i`
     matcht seit der Brand-Settings-Card ("Markeneinstellungen", h2) **zwei**
     Headings → exakter H1-Name `System-Einstellungen` im uncommitted Fix.
  3. Die Parallel-Session hat weitere uncommitted E2E-Fixes
     (`playwright.screenshots.config.ts`, `projects-board.spec.ts` +59 Zeilen) →
     E2E-Suite war bereits vor diesem Dep-PR instabil.
- **Maßnahme:** PR **offen lassen, NICHT mergen** (Regel). E2E wird grün, sobald
  die Parallel-Session ihre Test-Fixes committed (danach ggf. Rebase dieses PRs).
  Dependency-Arbeit selbst abgeschlossen & verifiziert.
