# AGENTS.md — Frontend (React Vite SPA)

Module-scoped operating guidelines for the React frontend in `frontend/`.

Global rules (Definition of Done, AI workflow & TODO management, E2E tag policy, agent roles, security risk register, IntelliJ run-config conventions) live in the repo root [`AGENTS.md`](../AGENTS.md) and apply here as well. This file only covers what is specific to the frontend module.

## Stack

- React 19 + Vite (TypeScript strict, `noUnusedLocals`) + Tailwind CSS v4 + daisyUI v5
- React Router v7, SWR, react-hook-form + zod (`@hookform/resolvers/zod`), Lingui (i18n, UI strings German)
- Vitest (unit tests) + Playwright (E2E)

## React Compiler (STRICT)

React Compiler is enabled in `vite.config.ts` via `reactCompilerPreset` passed to `@rolldown/plugin-babel`. Order matters: the Lingui macro preset is listed **second** because Babel runs presets in reverse order, so Lingui expands macros *before* the compiler runs:

```ts
babel({presets: [reactCompilerPreset(), linguiTransformerBabelPreset()]})
```

- `useMemo`, `useCallback`, `React.memo`, and `forwardRef` are **antipatterns — do not use them**; write plain functions/values and let the compiler memoize.
- Effects whose deps no longer contain a manual callback may re-run more often — that is intended. Do not add `useCallback` back.
- The compiler bails safely on unsupported constructs (try/catch around value blocks, throw inside try/catch, try/finally, mutation of module-scope variables, ref access during render). Keep such logic in module-level helper functions or effect bodies so components/hooks stay compilable.
- If a genuinely problematic infinite loop appears, restructure minimally (e.g. the ref-delegation pattern: sync a `useRef` with the unstable function, use the ref inside the effect).

## Commands

Frontend Unit tests (pnpm, NOT npm):

```bash
cd frontend && pnpm run test:run
```

Frontend Lint + Build (pnpm, NOT npm; `build` runs `tsc -b && node scripts/check-i18n.mjs` via prebuild, then `vite build`):

```bash
cd frontend && pnpm lint:fix && pnpm build
```

E2E (Playwright):

- Full suite, only before deployment: `cd frontend && npx playwright test`
- Only @smoke, after every code change: `cd frontend && npx playwright test --grep @smoke`
- Only a specific feature, e.g. checkout: `cd frontend && npx playwright test --grep @feature:checkout`
- Only re-run failed tests: `cd frontend && npx playwright test --last-failed`

Playwright starts the servers itself: `playwright.config.ts` declares a `webServer` array (backend via `bash scripts/e2e-up.sh` on port 8001, frontend via `pnpm dev` on 4321 with `VITE_API_PROXY=http://127.0.0.1:8001`) and waits for both. `scripts/e2e-up.sh` performs the full setup (services, `.env.e2e`, `migrate:fresh --seed`, fixtures, Scout index). `reuseExistingServer` is true.

- **Under CI the block is not set**: the workflow starts its own backend on 8000 and its own Vite on 4321.
- Without `VITE_API_PROXY` the Vite proxy points at `https://portal.test` and needs Valet/Herd.

Every new E2E test requires at least one functional tag (`@smoke`, `@regression`, or `@feature:<name>`); `@mobile` is only an additional device tag and does not replace a functional tag.

E2E workflow:

1. After every code change: `pnpm test:e2e:smoke`
2. Feature-specific: `npx playwright test --grep @feature:<name>`
3. Only before deployment: `npx playwright test` (full suite)
4. Document flaky tests in `AGENTS.todo.md` with file + test name, cause (if known), and a `flaky` tag in the commit/PR.
5. Reproduce a focused failure with `npx playwright test --last-failed`; at most three fix attempts per failing test, then return the root-cause analysis instead of continuing a retry loop.

E2E Timeout Policy (STRICT):

- Before each session measure the current minimum runtime (`npx playwright test`) and set the timeout to twice the measured value; document the rule and the runtime in `AGENTS.todo.md`. Re-measure after E2E changes.
- The approved exception stands: `globalTimeout` is **25 minutes (`1500000` ms)** and the per-test timeout is **120 s (`120000` ms)**. Do not change it silently; any change needs a new measurement and explicit owner approval. Contract and derivation: [`features/e2e-test-strategy.md`](../features/e2e-test-strategy.md).

## STRICT Frontend Rules

### useEffect & Derived State Policy (STRICT)
`useEffect` for side effects triggered by user events (e.g. creating object URLs) is forbidden; handlers MUST perform these actions. `useState` for values that can be derived during rendering is forbidden.

### Tailwind JIT Policy (STRICT)
Dynamische String-Konkatenation für Tailwind-Klassen (z.B. `btn-${color}`) ist **strikt verboten**, da der JIT-Compiler diese beim Build-Prozess übersieht und restlos entfernt (Purge). Klassen müssen immer vollständig und statisch ausgeschrieben werden (z.B. per explizitem Mapping-Objekt oder Ternary-Operator).

### Tailwind-Only Policy (STRICT)
Das `style`-Attribut ist **strikt verboten** – mit Ausnahme von dynamischen Werten, die sich zur Laufzeit ändern (z. B. berechnete Breiten/Höhen aus Benutzereingaben, animierte Werte). Statische Layout-Werte (insb. vh/vw/dvh/dvw-basierte Größen) MÜSSEN via Tailwind-Klassen gelöst werden. Werte in eckigen Klammern (JIT-Bracket-Syntax wie `w-[30%]`, `text-[10px]`, `max-w-[200px]`) sind ebenfalls **strikt verboten** — außer bei Iconify-Icons. Tailwind 4 bietet native Fraktionen (`w-3/10`, `w-1/5`), Spacing-Werte (`max-w-xs`, `text-xs`, `h-80`) und `dvh`-Utilities (`h-dvh`, `max-h-dvh`). Reichen diese nicht aus, ist eine Erweiterung der Tailwind-Konfiguration (z. B. via `@utility` in `index.css`) dem Inline-Style vorzuziehen.

### Validation (Zod) Policy (STRICT)
* Alle `react-hook-form` Implementierungen MÜSSEN `@hookform/resolvers/zod` nutzen.
* Daten aus unsicheren, lokalen Quellen (wie `localStorage`) MÜSSEN via Zod geparst werden (`safeParse` oder `catch`), bevor sie in den State übernommen werden.

### ESLint & TypeScript (STRICT)
The use of `eslint-disable`, `@ts-ignore`, or `any` is **strictly forbidden**. All typing issues must be resolved structurally using exact interfaces, `unknown`, or generic type constraints. ESLint runs with `--max-warnings 0` — unused imports and unused locals are errors.

### Semantic Locator Scoping
Agenten MÜSSEN Playwright-Locators über Landmarks (`main`, `aside`, `footer`) scopen, um Eindeutigkeit sicherzustellen und Abhängigkeiten von rein visuellen CSS-Klassen zu minimieren.

### No `page.goto` for SPA Navigation (STRICT)
`page.goto()` ist ein Anti-Pattern und darf nicht für SPA-Navigation verwendet werden. Ausnahmen:
* Externe Links (Invite, Magic-Link, Setup-Link)
* Initialer Seitenaufruf bei Gästen (`/`)
* Route-Guard-Tests (direkter URL-Zugriff testen)
* Brand-Isolation-Tests (Cross-Domain-Navigation)
* Stripe-Redirect-Simulation (return_url nach Zahlung)

Navigation MUSS via `sidebar.navigateTo()`, Klicks im UI oder API-Aufrufe erfolgen. localStorage-Injektion + page.goto('/cart') ist verboten — Cart-Items MÜSSEN via API hinzugefügt werden.

### localStorage Injection (STRICT ANTI-PATTERN)
Daten via `page.evaluate()` oder `addInitScript` in `localStorage` zu injizieren ist verboten. localStorage ist ein Implementierungsdetail des Frontends. Tests MÜSSEN den User-Flow abbilden: Login → Navigation → Formular-Interaktion. Ausnahme: `E2ESessionHelper` für Test-Setup (API-basiert).

### Field Label Policy (STRICT)
* Pflichtfelder MÜSSEN das `required`-HTML-Attribut tragen — der Star (`*`) wird automatisch via CSS angehängt (`.form-control:has(input[required]) .label-text::after`).
* `(Optional)` oder `(optional)` in Labels ist **strikt verboten**. Optionale Felder werden schlicht ohne Zusatz gekennzeichnet.
* Die CSS-Regel in `index.css` (`.form-control:has(input[required], select[required], textarea[required]) .label-text::after`) ist der zentrale Mechanismus und darf nicht umgangen werden.

### Lingui / i18n — No Module-Scope `t` (STRICT)
Das `t`-Makro MUSS innerhalb von Funktions- oder Render-Bodies aufgerufen werden. **Auf Modulebene ist es strikt verboten** (z. B. `const schema = z.object({ ... t\`...\` ... })`, `const msg = t\`...\``).

Regel bei Schemas in Shell-Komponenten (statisch von `App`/`PageLayout`/`DashboardLayout` erreichbar):
- Schema als **Factory-Funktion** anlegen und im Component-Body aufrufen:
  ```tsx
  const createLoginSchema = () => z.object({ email: z.string().email(t`...`), password: z.string().min(1, t`...`) });
  type LoginFormValues = z.infer<ReturnType<typeof createLoginSchema>>;
  // im Component: const loginSchema = createLoginSchema();
  ```
- Verifikation: `pnpm build` + `pnpm preview` (Port 4173) laden und im Konsole-Tab prüfen, dass kein Lingui-pageerror auftritt.
