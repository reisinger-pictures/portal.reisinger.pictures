# Skills-Marker

agents-skills-consumed: 6629ad289f6685d58f900a023a8bfa6ab3ba70c4
geprüft am: 2026-10-04

> Stand: geprüft bis `6629ad2` (= HEAD des Skills-Repos zum Prüfzeitpunkt, 2026-10-04).
> Range `aad25d14..6629ad2`: `2969d5a` (Size-Trim + English-Pass über zwölf Skills,
> neuer `node-deps`-Boundary-Absatz) plus `6629ad2` (README). Der Working Tree des
> Skills-Repos ist sauber — keine uncommitteten Änderungen, kein separates Advancement offen.
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

## Offen aus dem Bereich `aad25d14..6629ad2`

_Range: `2969d5a` (Trim auf die Größen-Ziele + English-Pass über zwölf Skills, neuer
`node-deps`-Boundary-Absatz) plus `6629ad2` (README). Jede geänderte Skill-Datei gegen
dieses Projekt geprüft; wo nur gekürzt und übersetzt wurde, steht das dabei._

- `agent-config` (226→194 Zeilen, Wortlaut nach `references/snippets.md`): Trim, keine Verhaltensänderung — Zeile bleibt `nein` (Maschinen-Setup).
- `codegraph-project-setup` (±0 Zeilen, MCP-Klarstellung: CLI plus globaler MCP-Server): keine Verhaltensänderung — Zeile bleibt `ja`.
- `github-ci-filters` (358→172 Zeilen, Begründung nach `references/notes.md`): Trim, keine Verhaltensänderung — Zeile bleibt `nein`.
- `model-updater` (308→153 Zeilen, Details nach `references/notes.md`): Trim — Zeile bleibt `nein`.
- `node-deps` (40→48 Zeilen, neuer Absatz „Boundary: manifests vs images"): **trifft zu** — benennt den `ARG`-Mechanismus der Board-Positionen 1+2; Notiz 2026-10-04 in `AGENTS.todo.md` bei Position 2, Positionen selbst unverändert. Zeile bleibt `ja`.
- `skills-marker` (Drift-Check als Schritt 5.3, Konventionen als Zeilen in §4): Drift-Check ist Maschinen-Sache (globale `AGENTS.md`), berührt das Projekt nicht; Konventions-Beispiel passt zur hiesigen Lage (eigene Doku-Konvention, vgl. Tabellenzeile `ui-review`). Zeile stand bereits auf `ja` mit `AGENTS.skills.md` — kein Wechsel nötig.
- `tailscale-serve` (251→191 Zeilen, Details nach `references/notes.md`): Trim — Zeile bleibt `nein`.
- `ui-review` (±0 Zeilen, nur Projektnamen ausgeschrieben): keine Verhaltensänderung — Zeile bleibt `nein` (bewusste Projekt-Entscheidung).
- `update-opencode-models` (Snapshot-Datierung 2026-10-02): keine Verhaltensänderung — Zeile bleibt `nein`.
- `vision-agents` (110→108 Zeilen, Historie nach `references/notes.md`): Trim — Zeile bleibt `nein`.
- Nicht in der Range, Referenzen nachgeprüft und aktuell: `docker-test-image` (`deployment/Dockerfile.e2e:30,69`, `.github/workflows/e2e-image.yml:41`), `ghcr-visibility` (`.github/workflows/ci.yml:43-51,335`); kein Verweis ins Registry-Material, das nach `ghcr-visibility` wanderte.
- Kein Trim hat eine Pflicht gekostet: `github-ci-filters` (Precondition, `paths-ignore`-Asymmetrie, beide Trigger), `model-updater` (Hard Requirements, Variants-Verbot), `tailscale-serve` (Socket-Pfad, Secret), `agent-config` (§2a/§8, Drift) — alles noch da oder nach `references/` gewandert.

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
