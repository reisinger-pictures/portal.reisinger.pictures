# Task Board — Portal Reisinger Pictures

> Stand: 2026-07-31. **Nur offene TODOs.** Analyse & Architektur-Entscheidungen sind ausgelagert:
> - Brand-Architektur (SOLL, Commit `1831116`): `features/infrastructure/21-brand-config-driven.md`
> - Pricing-Strategy-Pattern: `features/infrastructure/17-pricing-strategy-pattern.md`
> - Security-Risiken (C1–C4) & Stärken: `AGENTS.md` §7 / §8
> - Email-Template-Kompatibilität: gemeinsames Layout `emails/layouts/app.blade.php` + Button-Partial, 7 Templates auf 100% Kompatibilität, Env-Fix `FRONTEND_URL=4321` (Commit `…Email-Kompatibilität…`)
>
> Test-Regel (DoD): Backend-Änderungen → PHPUnit, Frontend-Logik → Vitest, UI/Formulare → Playwright-E2E.

---

## 🔄 Session 2026-07-31 — nginx → Caddy-Direktauslieferung (Migration)

**Status: in Arbeit (Code fertig, Docs-Review offen)**

Kontext: Das Portal-Frontend wurde bisher via nginx-Container (`portal_frontend`) ausgeliefert. Caddy (Edge) hat dahin proxyt. Migration: Caddy liefert die statische `dist/` direkt (via `spa`-Snippet), nginx wird entfernt. Nebeneffekt: Die CSP war in `frontend/nginx.conf` definiert, kam aber wegen der nginx-`add_header`-Vererbungsregel nie an — jetzt direkt im Caddyfile (mit korrektem `sha256`-Hash fürs Inline-Script + `js.stripe.com`).

| Task | Status |
|------|--------|
| Caddyfile: Portal-Block auf `import spa` + CSP/XFO umgestellt | ✅ |
| `sync.sh`: nginx.conf-Sync entfernt | ✅ |
| `deployment/docker-compose.yml`: `frontend`-Service entfernt | ✅ |
| `frontend/nginx.conf` gelöscht | ✅ |
| Doku `features/infrastructure/01-deployment.md` §3 aktualisiert | ✅ |
| **Subagent-Review der Doku** auf veraltete nginx/portal_frontend/CSP-Referenzen | ✅ |
| **Doku-Fixes delegieren** (Implementer-Subagent) | ✅ |
| Verifikation der Doku-Fixes (Diff-Review + `SystemMiscTest` grün) | ✅ |

Review-Befunde & Fixes (alle umgesetzt):
- K1 `csrf-strategy.md`: Split-Domain-Architektur → same-origin `/api/*` (Tabelle, Defense-in-Depth #2)
- K2 `03-file-delivery-controller.md` §8: Apache/Nginx → Caddy `handle_response`
- M1/M2 `01-deployment.md`: §5-Titel → "Deployment-Pfade", `handled` → `handles`
- I1 `sync.bat`: nginx.conf-Sync-Block entfernt (Sync.sh war bereits bereinigt)
- I2 `SetSecurityHeaders.php`: Docblock "CSP enforced at Caddyfile"
- I3 `SystemMiscTest.php`: Test → `..._for_caddy` (läuft grün)

Deploy-Sequenz (nicht in dieser Session):
1. `ln -s /home/webadmin/websites/web-portal.reisinger.pictures /srv/websites/web-portal.reisinger.pictures`
2. Caddyfile validieren + `caddy reload`
3. Portainer-Stack neu deployen
4. Smoke: `curl -sI https://portal.reisinger.pictures/` → CSP + `server: Caddy`

---

## 🔙 Backlog (nicht in dieser Session)

| Task | Beschreibung | Grund |
|------|-------------|-------|
| **F3** | Admin-UI für Brand-Einstellungen (nur Settings für bestehende Brands, kein Full-CRUD). | Architekturfrage ungeklärt (Config-Write-Layer vs DB-Revert). Nicht für near future geplant. → `features/infrastructure/21-brand-config-driven.md` §Follow-up |

## 📊 Verification (2026-07-18)

| Suite | Result |
|-------|--------|
| PHPUnit | ✅ (unchanged — nur Lua-Plugin-Änderungen) |
| Vitest | ✅ (unchanged — nur Lua-Plugin-Änderungen) |
| ESLint | ✅ (unchanged) |
| Build (tsc+vite) | ✅ (unchanged) |
| Playwright `@smoke` | ✅ (unchanged) |
| Plugin Smoke | ✅ manuell durchgeführt |
