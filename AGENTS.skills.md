# Skills-Marker

agents-skills-consumed: aad25d145e92360b8ee7631151c1cdb6dae679eb
geprüft am: 2026-10-04

> Stand: geprüft bis `aad25d14` (= HEAD des Skills-Repos zum Prüfzeitpunkt).
> Der Working Tree des Skills-Repos enthält zusätzlich **uncommittete Änderungen**
> (`agent-config`, `codegraph-project-setup`, `github-ci-filters`, Stand 2026-10-04);
> deren Übernahme erfordert ein separates Marker-Advancement — dieser Marker deckt sie nicht ab.
> Format und Ablauf: Skill `skills-marker` im Skills-Repo.

## Skill-Stand

| Skill | angewendet? | wo im Projekt | offene Position |
|---|---|---|---|
| `agent-config` | nein | | Maschinen-Setup (opencode.jsonc, MCP, Skills-Registrierung); das Projekt definiert kein Agent-Setup — trifft nicht zu |
| `build-verify` | nein | | Reiner Doku-Pass ohne Implementierungsauftrag und ohne Verify-Lauf; das Projekt definiert seinen Delegations-Flow in `AGENTS.md:46-58`, keinen build-verify-Flow — trifft nicht zu |
| `codegraph-project-setup` | ja | `AGENTS.md:161-167` (`.codegraph/`-Verzeichnis und `.githooks/pre-commit` vorhanden) | Lokaler Index `Not initialized` (`codegraph status`, 2026-10-04); optionales Setup, kein Commit-Gate |
| `docker-test-image` | ja | `deployment/Dockerfile.e2e:30,69`, `.github/workflows/e2e-image.yml:41` | Playwright/Browser-Dreiklang muss gemeinsam bewegt werden → Board „Skills-Stand (2026-10-04)", Position 2 |
| `ghcr-visibility` | ja | `.github/workflows/ci.yml:43-51,335` | Sichtbarkeit 2026-10-04 neu belegt (beide `public`) → Board „Skills-Stand (2026-10-04)", Position 4 |
| `github-ci-filters` | nein | | Keine `paths`/`paths-ignore`-Filter in `.github/workflows/ci.yml` — trifft nicht zu |
| `model-updater` | nein | | Modell-Auswahl ist Maschinen-/Account-Sache; das Projekt definiert keine Modelle — trifft nicht zu |
| `node-deps` | ja | `frontend/package.json:71`, `frontend/pnpm-lock.yaml:767` | Playwright-Bump nur als Range + Lockfile via pnpm, nie als Pin → Board „Skills-Stand (2026-10-04)", Position 1 |
| `permissions` | nein | | Kein `.opencode/` im Projekt; Secrets-Regeln stehen in `AGENTS.md:96-108`, nicht als opencode-Permissions — trifft nicht zu |
| `playwright-parallel` | ja | `.github/workflows/ci.yml:374-380`, `frontend/playwright.config.ts:30-31` | Matrix-Muster + Named-Locks-Voraussetzung (≥ 1.63.0) → Board „Skills-Stand (2026-10-04)", Positionen 1 und 3 |
| `skills-marker` | ja | `AGENTS.skills.md` (diese Datei) | |
| `tailscale-serve` | nein | | Kein Dev-Server-Expose im Projekt definiert — trifft nicht zu |
| `ui-review` | nein | | `AGENTS.md:177-178` dokumentiert: Screenshot-Loop bewusst noch nicht angewendet — Projekt-Entscheidung, kein Code-Befund |
| `update-opencode-models` | nein | | Wie `model-updater`: Maschinen-Sache, kein Projekt-Zustand — trifft nicht zu |
| `vision-agents` | nein | | Keine visuelle Prüfung in diesem Pass, kein Screenshot-Artefakt erzeugt — trifft nicht zu |

## Offen aus dem Bereich `—..aad25d14`

_Kein Vor-Stand: neu angelegter Marker, keine Range — Vollprüfung aller 15 Skills aus
`.agents/skills/` des Skills-Repos in der Tabelle oben. Dieser Abschnitt wird beim ersten
`git pull` im Skills-Repo durch die Range `<aad25d14>..HEAD` ersetzt._

## Fortschreiben

1. `agents-skills-consumed` ablesen → `<alt>`.
2. Im Skills-Repo die Range bilden:
   `git -C <skills-repo> log --oneline <alt>..HEAD`
   `git -C <skills-repo> log --name-only --format= <alt>..HEAD -- .agents/skills | sort -u`
3. Jeden betroffenen Skill gegen **dieses** Projekt prüfen. Trifft eine Änderung zu, wenn
   sie eine Pflicht, Schwelle, Ausnahme, Konvention oder ein Kommando betrifft, das
   **hier** gilt:
   - trifft zu und ist drin → `angewendet: ja` + `Datei:Zeile`
   - trifft zu, ist nicht drin → offene Position: Eintrag in `AGENTS.todo.md` anlegen
   - trifft nicht zu (das Projekt hat das nicht) → `angewendet: nein` + kurzer Grund
4. Offene Positionen nach `AGENTS.todo.md`; angewendete Zeilen mit `Datei:Zeile` belegen.
5. Erst wenn für **jeden** Skill aus der Range eine Zeile steht:
   `agents-skills-consumed: $(git -C <skills-repo> rev-parse --short HEAD)`,
   `geprüft am:` auf heute, Abschnitte ersetzen.
6. Falls Regeln oder Skills betroffen waren:
   `touch ~/.config/opencode/opencode.jsonc`.
