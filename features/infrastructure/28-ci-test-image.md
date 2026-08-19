# 28 — E2E-/Test-Image `portal-e2e` (CI)

**Status:** Implementiert 2026-08-18 (verifiziert via CI, `ci.yml` Job `e2e` grün).

## Problem

Jeder E2E-CI-Run lud Playwright-Chromium (~150 MB) + apt-System-Deps neu herunter
(`npx playwright install --with-deps chromium`) — obwohl die Browser-Version an
`@playwright/test` gebunden ist und sich zwischen Version-Bumps nicht ändert.
Dieser Download/Install war pro Run reiner Overhead (3 E2E-Shards, ~10–20 min pro Job).

## SOLL-Zustand

1. **`deployment/Dockerfile.e2e`** — Derivat von `ghcr.io/reisi007/portal-base:8.5`
   (PHP-8.5-Prod-Runtime inkl. exiftool/ImageMagick/Extensions; Debian trixie):
   - Composer (Dist-Binary via `COPY --from=composer:2`)
   - Node.js (aktuelles `v26`, offizielles Linux-Binary von nodejs.org)
   - pnpm (exakt `frontend/package.json#packageManager`)
   - Playwright-Chromium inkl. apt-Deps (`PLAYWRIGHT_BROWSERS_PATH=/ms-playwright`,
     Version == `@playwright/test` via Build-Arg)
2. **`.github/workflows/e2e-image.yml`** — Rebuild-Trigger:
   - Push auf main: `deployment/Dockerfile.e2e`, Workflow selbst, `frontend/pnpm-lock.yaml`
     (Dependabot-Playwright-Bumps → Browser müssen im Image nachziehen)
   - Weekly (Mo 02:00 UTC) als Frische-Untergrenze (analog zur daily 01:00 UTC
     für `portal-base` in `base-image.yml`)
   - `workflow_dispatch` manuell
   - Tags: `:latest` (mutable, von CI referenziert) + `:<playwright-version>` (immutable, Debug/Rollback)
3. **`ci.yml` Job `e2e`** läuft komplett im Container
   (`container: ghcr.io/reisi007/portal-e2e:latest`):
   - Backend direkt im Container: `composer install` → `key:generate` → `migrate` →
     `seed` → `php artisan serve --host=127.0.0.1 --port=8000 --no-reload`
     (kein Docker-in-Docker mehr; Workspace wird vom Runner gemountet)
   - Daten-Dienste per Service-Name (Container-Modus): `DB_HOST=mariadb`,
     `DB_PORT=3306`, `MEILISEARCH_HOST=http://meilisearch:7700`, `MAIL_HOST=mailpit`
     → `.env`-Override im Prepare-Step; `backend/.env.ci` selbst bleibt für den
     Backend-/PHPUnit-Job (`--network host`) unverändert
   - `MAILPIT_API_URL=http://mailpit:8025/api/v1` (Job-Env; `MailpitHelper.ts` liest
     die Env-Var seit jeher, Default bleibt `localhost:8025` für lokale E2E via `e2e-up.sh`)
   - `npx playwright install chromium` bleibt als **No-Op-Fallback** (Belt-and-Suspenders
     bei Versionsdrift zwischen Dependabot-Bump und Image-Rebuild; ohne `--with-deps`,
     da apt-Deps im Image gebacken sind)

## Shard-Strategie (2026-08-19, gemessen)

Matrix `strategy.matrix.include` in `ci.yml` (5 Shards, Jobs laufen parallel):

| Shard | Projekt | Filter | workers |
|---|---|---|---|
| Desktop (1/2) / (2/2) | Desktop Chrome | `--grep-invert "projects-board\|production-board\|billing-details" --shard=1/2 \| 2/2` | 2 |
| Mobile (1/2) / (2/2) | Mobile Chrome | dito (identische Dateien, anderer Viewport) | 2 |
| serial (kanban, billing) | beide | `--grep "projects-board\|production-board\|billing-details"` | 1 |

**Gemessener Critical Path:** vorher 12m05s (Desktop/Mobile je 1 Shard) → **8m05s** (Mobile 2/2; Desktop 2/2: 7m58s; 1/2-Shards ≈ 6m; serial 5m28s). **−33 %.**

**Bekannte deterministische Schieflage (akzeptiert):** Playwright shardet nach Test-**Anzahl** (60 vs 64 ≈ fair), nicht nach Laufzeit → die 2/2-Shards tragen 751s serielle Laufzeit vs 491s im 1/2-Shard (schwere Dateien: `stripe-checkout` 96s, `photo-management` 75s, `downloads` 55s, `metadata` 47s… clustern in 2/2). Perfekte Balance (130s seriell rüber) brächte nur ~1 min (→ ~7m Makespan), kostet aber fragile Titel-Greps bei jeder Umbenennung. `workers` > 2 wurde bewusst NICHT erhöht (Login-Rate-Limiter-Flake-Klasse, analog P2b-F9 in open-accreditation). **Korrekturweg bei Bedarf (nicht empfohlen):** schwere Specs per stabilem Titel-Grep von Shard 2 nach Shard 1 ziehen oder nach Laufzeit messen und Datei-Gruppen per CLI-Pfaden (statt `--shard`) aufteilen — dann aber Coverage-Guard für neue Dateien einplanen.

## Invarianten (nicht regredieren)

- **Nur die Umgebung einbacken** — nie App-Code, `node_modules/`, `vendor/`.
  Tests laufen gegen den aktuellen Commit; Dependencies werden pro Commit installiert.
- Browser-Version == `@playwright/test`; der `frontend/pnpm-lock.yaml`-Trigger in
  `e2e-image.yml` erzwingt den Image-Rebuild bei Dependabot-Bumps.
- Container-Modus: Dienste per Service-Namen, keine `127.0.0.1`-Port-Mappings.

## Fallback (nur falls Container-Modus-Probleme auftreten)

`e2e`-Job auf Runner-Hosted zurückstellen (`--network host`-Semantik wie zuvor) und
Browser aus dem Image extrahieren statt per `playwright install`:

```bash
docker create --name pw-cache ghcr.io/reisi007/portal-e2e:latest
docker cp pw-cache:/ms-playwright "$HOME/ms-playwright"
docker rm pw-cache
echo "PLAYWRIGHT_BROWSERS_PATH=$HOME/ms-playwright" >> "$GITHUB_ENV"
```