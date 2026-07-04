# Task Board — Portal Reisinger Pictures

## Dependency Audit — 2026-07-04

> Audit run: `composer outdated --direct` (backend), `pnpm outdated` (frontend), Docker Hub API (images).

### Backend (Composer — `backend/composer.json`)

| Package | Current | Latest | Type |
|---|---|---|---|
| laravel/framework | 13.6.0 | **13.18.1** | minor |
| laravel/pail | 1.2.6 | **1.2.7** | patch |
| laravel/pint | 1.29.1 | **1.29.3** | patch |
| laravel/scout | 11.1.0 | **11.3.0** | minor |
| php-open-source-saver/jwt-auth | 2.9.0 | **2.9.2** | patch |
| phpunit/phpunit | 12.5.23 | **13.2.2** | **major** |
| stripe/stripe-php | 13.18.0 | **20.3.0** | **major** |
| symfony/html-sanitizer | 8.0.8 | **8.1.1** | minor |

### Frontend (pnpm — `frontend/package.json`)

| Package | Current | Latest | Type |
|---|---|---|---|
| @hookform/resolvers | 5.2.2 | **5.4.0** | minor |
| @stripe/react-stripe-js | 6.6.0 | **6.7.0** | minor |
| @stripe/stripe-js | 9.8.0 | **9.9.0** | minor |
| @iconify-json/material-symbols | 1.2.79 | **1.2.82** | patch |
| @playwright/test | 1.61.0 | **1.61.1** | patch |
| @tailwindcss/vite | 4.3.1 | **4.3.2** | patch |
| @types/node | 25.6.0 | **26.1.0** | **major** |
| @vitejs/plugin-react | 6.0.2 | **6.0.3** | patch |
| daisyui | 5.5.23 | **5.6.13** | minor |
| eslint | 10.5.0 | **10.6.0** | minor |
| globals | 17.6.0 | **17.7.0** | patch |
| recharts | 3.8.1 | **3.9.2** | minor |
| react-router-dom | 7.18.0 | **7.18.1** | patch |
| swr | 2.4.1 | **2.4.2** | patch |
| tailwindcss | 4.3.1 | **4.3.2** | patch |
| typescript-eslint | 8.61.1 | **8.62.1** | patch |
| vite | 8.0.16 | **8.1.3** | minor |

### Root (`package.json`)

| Package | Current | Latest | Type |
|---|---|---|---|
| repomix | 1.13.1 | **1.16.0** | minor |

### Docker Images

| Service | Current Tag | Latest Available | Status |
|---|---|---|---|
| **mariadb** (db) | `11.4` | `11.4.12` (2026-07-02) | ✅ Up-to-date (floating tag) |
| **meilisearch** (search) | `v1.42` | **`v1.48.3`** (2026-06-29) | ⚠️ **6 minor versions behind** |
| **nginx** (frontend) | `alpine` | floating tag (2026-06-25) | ✅ Up-to-date |
| **mailpit** | `latest` | `v1.30.3` (2026-06-27) | ✅ Up-to-date (also `edge` available 2026-07-04) |
| **composer** | `latest` | floating tag (2026-06-19) | ✅ Up-to-date |
| **ghcr.io/reisi007/php-apache-mod2rewrite-exiftool-maria-pdo** (backend) | `8.5` | custom image, no public releases found | ⚠️ Needs manual check |

---

## Scoping: Required Breaking Changes & Migration Effort

### 1. Meilisearch v1.42 → v1.48.3

**Risk: HIGH — known complex upgrade path, no official migration guide found.**

Key breaking changes between v1.42 and v1.48:
- **v1.43.0** — "New settings indexer" introduced (opt-in via `MEILI_EXPERIMENTAL_NO_EDITION_2024_FOR_SETTINGS`). Big internal rework for settings tasks. In v1.47.0 it became feature-complete.
- **v1.45.1** — Reverted autobatching of deletions by filter with additions (regression fix).
- **v1.45.2** — Fixed vector store quantization config desync affecting dumpless upgrades from < v1.33.1.
- **v1.47.0** — Small breaking change: some error codes changed (`MultiSearch<Error>` ↔ `Search<Error>`). If the app catches specific error codes, this needs code changes.
- **v1.48.0** — Foreign filters no longer supported on write routes (`POST /indexes/{uid}/documents/edit`, `POST /indexes/{uid}/documents/delete`, `POST /export`). If the app uses foreign filters on these endpoints, this is **breaking**.
- **v1.48.1** — Reverted a prefix search fix due to a **dumpless upgrade bug** — this shows the upgrade path is fragile.
- **v1.48.2** — Security fixes for CVE-2026-57823 and CVE-2026-57824 (privilege escalation + info disclosure).

**Recommendation:** Upgrade gradually (v1.42 → v1.43 → ... → v1.48) with a database dump/restore between each step. Test foreign filter usage on write routes. Budget 1–2 days for testing.

### 2. stripe/stripe-php 13.18.0 → 20.3.0

**Risk: HIGH — 7 major versions behind, but migration guide exists.**

Key breaking changes summary (from [Migration guide for v20](https://github.com/stripe/stripe-php/wiki/Migration-guide-for-v20) and [CHANGELOG](https://github.com/stripe/stripe-php/blob/master/CHANGELOG.md)):

| Version | Breaking Changes |
|---|---|
| **v14** | Unknown — no migration guide published for intermediate versions |
| **v15** | Unknown |
| **v16** | Unknown |
| **v17** | Migration guide exists ([wiki](https://github.com/stripe/stripe-php/wiki/Migration-guide-for-v17)) |
| **v18** | Partial API changes |
| **v19.0.0** | V2 array params now use indexed format (`?include[0]=foo&include[1]=bar`). Breaks unit test mocks. |
| **v20.0.0** | Drops PHP < 7.2 (we're on 8.3, fine). Webhook methods now throw on wrong event type. `Util::objectsToIds()` requires `$serializeNull` parameter. V2 null values preserved in request bodies. API version bump to `2026-03-25.dahlia`. |

Each major version pins a new Stripe API version. This means:
- Response shapes may have changed across 7 API versions
- Some fields may be removed/renamed
- Some request parameters may have changed

**Important for this project:** The app uses `@stripe/react-stripe-js` and `@stripe/stripe-js` on the frontend (minor bumps only). Backend PHP SDK is the main concern.

**Recommendation:** Upgrade incrementally through majors, run full PHPUnit test suite after each step. Review Stripe API changelogs for each pinned API version. Budget 2–3 days.

### 3. phpunit/phpunit 12.5.23 → 13.2.2

**Risk: LOW — mostly deprecations, few hard breaks.**

From [PHPUnit DEPRECATIONS.md](https://github.com/sebastianbergmann/phpunit/blob/main/DEPRECATIONS.md):

| Since | Deprecation | Replacement |
|---|---|---|
| 13.1.0 | `id()` and `after()` for mock expectations | — |
| 13.2.0 | `expectExceptionMessage()` | `expectExceptionMessageIsOrContains()` |
| 12.5.5 | `TestCase::any()` (hard) | Use test stub or configure real expectation |
| 13.0.2 | `atLeast()` with non-positive argument (hard) | Use positive argument |
| 13.0.2 | `with*()` without `expects()` (hard) | Use `expects()` or remove `with*()` |
| 13.2.0 | `--order-by duration` → `--order-by duration-ascending` | Update CLI/XML config |

**Recommendation:** Low effort. Fix deprecation warnings as they appear. Update XML configuration if needed. ~1 hour.

### 4. @types/node 25.6.0 → 26.1.0

**Risk: VERY LOW — just TypeScript type definitions.**

This is a types-only package. Node.js type definitions for version 26. No runtime impact. Only a concern if the project uses Node.js-specific type APIs that changed between 25 and 26.

**Recommendation:** Update freely. ~5 minutes.

---

## Scoping: Minor/Patch Updates (Safe)

These can be batch-updated with low risk:
- `laravel/framework` 13.6.0 → 13.18.1 — minor updates within v13, test-first approach recommended
- `laravel/scout` 11.1.0 → 11.3.0 — minor, check Scout changelog for index sync changes
- `daisyui` 5.5.23 → 5.6.13 — minor UI lib update, visual regression check recommended
- All remaining patch updates are safe
- `repomix` 1.13.1 → 1.16.0 — minor tool update

---

## Meilisearch Upgrade — Applied Changes (2026-07-04)

### Changes Made

| File | Change |
|---|---|
| `docker-compose.local.yml` | Meilisearch `v1.42` → `v1.48.3` |
| `docker-compose.test.yml` | Meilisearch `v1.42` → `v1.48.3` |
| `deployment/docker-compose.yml` | Meilisearch `v1.42` → `v1.48.3` |
| `deployment/docker-compose.yml` | Added `php artisan queue:restart` after `scout:sync-index-settings` |
| `backend/app/Console/Commands/SearchRebuild.php` | New `app:search-rebuild` command (flush + sync + import + queue:restart) |

### Not Changed (Already Correct)

| Item | Status |
|---|---|
| `meilisearch/meilisearch-php` in `composer.json` | Already `^1.16` (satisfies `^1.11`) |
| `config/scout.php` index-settings | Already configured for all 5 searchable models |
| Models using `Searchable` trait | Photo, Gallery, Location, Customer, TextSnippet |

### Upgrade Procedure

After deploying the updated `docker-compose.yml` with the new Meilisearch image:

1. The existing `search_data` volume will be read by the new Meilisearch — may auto-migrate, but for **maximum reliability**, manually purge it:
   ```bash
   # On the server, before starting services:
   docker volume rm portal_search_data
   ```
2. After backend container starts successfully, run the rebuild:
   ```bash
   docker exec portal_backend php artisan app:search-rebuild
   ```
   This runs: `scout:flush` (all models) → `scout:sync-index-settings` → `scout:import` (all models) → `queue:restart`.

### What Already Works on Each Deploy

The startup command (`deployment/docker-compose.yml`) already runs `scout:sync-index-settings` on every container start. The `queue:restart` now ensures fresh model definitions are picked up by workers. The rebuild is only needed after a fresh Meilisearch instance or schema migration.
