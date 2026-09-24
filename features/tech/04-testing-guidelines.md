---
domain: technical
topic: testing-guidelines
status: active
---

# Technical Concept: Testing Guidelines

* **Internal route-mock ban (STRICT):** Primary E2E flows must use the real internal API and prove the actual system integration. `page.route`/`route.fulfill` for any internal `/api/*` endpoint—including CRUD, authentication/authorization, status/failure fixtures, and configuration responses—is forbidden. Use a real backend/test fixture or a component/integration test for those states.
  * **External boundary exception:** A provider/widget or proxy for an unavailable external service (for example Stripe.js, Turnstile, or an explicitly documented AI proxy) may be stubbed when the boundary and dummy values are documented. External stubs must not seed application storage or replace the internal application contract.
* **Golden Rule (Features First):** Der `features/`-Ordner ist die primäre Wissensbasis. Jede neue Logik muss dort im Soll-Zustand dokumentiert werden, bevor sie implementiert wird. Der Ordner ist bei jeder Änderung aktuell zu halten.

## 1. Testing Rules (UI-FIRST) & Philosophy
* **No Test-Environment Checks in Production (STRICT):** Production code must never alter its behavior based on test environments (e.g., checking `navigator.userAgent.includes('Playwright')`). Tests must validate the genuine application behavior. If tests flake due to realistic features (like `revalidateOnFocus`), fix the test assertions, do not cripple the application UX.
* **No Shared State / No New Serial Suites (STRICT):** Tests must be isolated and must not depend on state from another `test()` block. New suites must not use `test.describe.configure({ mode: 'serial' })` (or an equivalent serial mode); combine dependent steps into one cohesive test or make setup independent. The existing board/settings regression suites remain a known exception: four suites use serial mode, and the dedicated CI selection also isolates the global billing-details suite. See `features/e2e-test-strategy.md` for the current file list. Do not copy this exception; its isolation/refactoring work is tracked separately.
* **UI-First Synchronization (MANDATORY):**
  * Prefer `expect(locator).toBeVisible({ timeout: 15000 })` for simple UI updates.
  * Prefer `await expect(async () => { ... }).toPass()` for complex SWR/React state transitions where multiple re-renders occur.
  * `page.waitForResponse()` ist **ausnahmsweise erlaubt**, wenn eine Aktion keine sichtbare UI-Änderung erzeugt (z.B. Hintergrund-API-Calls beim Checkout, Locations-Suche). Der Response darf jedoch **nicht** zur Statuscode-Assertion verwendet werden — die Validierung muss immer über die sichtbare UI erfolgen. Die Nutzung muss im Test-Kommentar begründet werden.
* **No `page.goto` for SPA Navigation (STRICT):** Nach erfolgreichem Login MUSS die Seitennavigation ausschließlich über `sidebar.navigateTo()` (SPA Client-Side Routing), UI-Klicks oder API-Aufrufe erfolgen. `page.goto()` ist nur für einen initialen Seitenaufruf vor dem Login, externe URLs (Invite-/Magic-/Reset-Links), Route-Guard-/Brand-Isolations-Tests, Stripe-Redirect-Simulationen und echte Persistenz-Roundtrips erlaubt. Für Persistenz ist `page.reload()` dem `page.goto()` vorzuziehen.
* **LocalStorage injection is forbidden (STRICT):** Do not write application state or `localStorage` with `page.evaluate()` or `addInitScript`, and do not use `page.goto()` to bootstrap injected state. Use the real login/navigation/form flow or the API-based `E2ESessionHelper` setup instead. A documented external-provider widget stub (for example Turnstile) is a separate mocking exception and must not seed application storage.
* **Pragmatische Reload Policy (Asynchronous Processes):** Generell sollte `page.reload()` vermieden werden, um direktes UI-State-Management (z.B. SWR Mutations nach dem Erstellen einer Entität) zu testen.
  * **Ausnahme:** Bei unabhängigen, zeitversetzten oder entkoppelten serverseitigen Prozessen (z. B. das Aktualisieren eines "E-Mail senden"-Buttons, weil ein anderer Nutzer sich im Hintergrund in die Empfängerliste eingetragen hat) ist `page.reload()` oder erneutes Hin-Navigieren ausdrücklich **erlaubt**. In solchen Fällen spiegelt das Neuladen das natürliche Nutzerverhalten wider.
* **Fail-Fast and retry budget:** The current Playwright config uses `maxFailures: 10` in CI and `0` locally; it does not stop after two failures. Playwright itself retries each CI test up to two times, while an agent gets at most three fix attempts for a failing test. After three unsuccessful fix attempts, stop and return an analysis to the user instead of looping.
* **No `force: true` in Playwright:** Bypassing actionability checks defeats the purpose of E2E tests. If Playwright cannot click an element naturally, a human probably can't either. Always wait for elements to become stable and uncovered (e.g., wait for animations to finish or modals to close via `toBeHidden()`) instead of forcing clicks.
* **No Try-Catch Anti-Pattern:** Never mask failing tests by wrapping production code or assertions in `try-catch` blocks purely to pass a test. Exceptions must bubble up and fail the test clearly.
* **Single Reason to Fail (SRP):** Tests (PHPUnit & Playwright) MUST focus on a single behavior. Avoid monolithic 20-step tests.
* **Semantic Scoping & Landmarks (REQUIRED):** Um "Strict Mode Violations" zu vermeiden (z.B. wenn ein Passwort-Feld sowohl in der Sidebar als auch im Hauptinhalt existiert), MÜSSEN Locators über semantische HTML-Landmarks eingeschränkt werden. Nutze bevorzugt `page.locator('main').locator(...)` anstatt dich auf wechselnde Utility-CSS-Klassen (wie `.input-warning`) zu verlassen.
* **User-Facing Locators (REQUIRED):**
  * Avoid technical selectors (CSS class, ID) if possible.
  * Use role-based locators: `page.getByRole('button', { name: 'Login' })`.
  * Use text-based locators: `page.getByText('Success')`.
  * This ensures tests remain stable against layout changes and verify accessibility.
* **Web-First Assertions:**
  * Use `expect(locator).toBeVisible()` or `expect(locator).toHaveText()` instead of generic `expect(await locator.isVisible()).toBe(true)`.
  * Web-first assertions automatically retry until the condition is met or a timeout occurs.
* **Lazy Loading & Viewports (Mobile):** Bilder mit `loading="lazy"` werden in E2E-Tests auf mobilen Viewports oft nicht geladen, wenn sie sich außerhalb des initialen Sichtbereichs befinden. Bevor Bildeigenschaften (wie `naturalWidth`) geprüft werden, MUSS das Element zwingend mit `scrollIntoViewIfNeeded()` in den Viewport geholt werden, um den Netzwerk-Download des Browsers zu erzwingen.

## 2. E2E Tests (Playwright)

- **Playwright tag:** Playwright uses the singular test option `{ tag: ['@smoke', '@feature:<name>'] }` (or a single tag string). `tags` is not a valid test option. New E2E tests must carry at least one functional tag (`@smoke`, `@regression`, or `@feature:<name>`); device-specific coverage uses `@mobile` in addition to that functional tag.
- **Selection:** Run a subset with `pnpm test:e2e:smoke` or
  `pnpm test:e2e:grep @feature:<name>`. A feature grep is not a reason to run
  untagged tests; untagged legacy tests remain part of the full suite only.
- **Trace Viewer & Debugging:** CI never records or uploads Playwright traces, reports, screenshots, videos, or `test-results`, because browser storage and request data can contain credentials or PII. For local debugging, opt in with `PW_TRACE=1` and inspect the trace/report only on the local machine (`npx playwright show-report`).
- **Test Parallelism & Isolation (CRITICAL):** The default local E2E setup is the isolated backend from `scripts/e2e-up.sh` with `backend/database/database.e2e.sqlite`; CI uses its own test services and database. Tests MUST be isolated and non-destructive and must never be pointed at the developer's working database.
  - Never share or hardcode specific user emails, gallery names, or order IDs.
  - Always use highly dynamic identifiers (e.g., `Math.random().toString(36)`).
  - Cross-contamination between parallel tests will cause flaky CI pipelines and false positives.
- **Page Object Model (POM):** Do not duplicate Playwright logic. Use provided helper classes (e.g., `ModalHelper`, `SidebarHelper`).
- **Mobile-First Validation:** E2E tests must be explicitly executed against mobile viewports to verify touch targets and z-index issues.

## 3. Backend Tests (PHPUnit)
- **Negative Testing (IDOR):** Jeder Endpunkt, der auf eine spezifische Ressource zugreift (Galerie, Foto, Download), MUSS explizit mit einem unberechtigten Nutzer getestet werden (Insecure Direct Object Reference Protection). Es muss zwingend ein 403 (Forbidden) oder 404 (Not Found) Statuscode erwartet werden.
- **API Resources (Leak Prevention):** In PHPUnit, do not just check HTTP status codes. Always assert the JSON response structure (`assertJsonStructure` or `assertJsonMissing`) to ensure no unintended fields (e.g., `password_hash`) are leaked.
- **Email & Link Integrity:**
  - Mocking emails is forbidden. Tests must query the local Mailpit API.
  - Tests MUST parse the HTML body of the email, extract generated action links (e.g., Magic Links), and confirm that navigating to these links resolves successfully (HTTP 200).

## 4. Resource Tracking & Isolation (CRITICAL)
- **No Global Cleanups:** Never use global admin scripts to wipe all E2E data. This breaks parallelism.
- **Instance-based Tracking:** Every test file must instantiate a fresh `E2ESessionHelper`.
- **Automatic Teardown:** Resources (Users, Galleries, Groups) created during a test must be registered in the helper instance and cleaned up in `test.afterEach()`.
- **Usage Pattern:**
  ```typescript
  let helper: E2ESessionHelper;
  test.beforeEach(({ request }) => { helper = new E2ESessionHelper(request); });
  test.afterEach(async () => { await helper.teardown(); });
  ```
- **No Static Seeders for Test Data:** Do not rely on static users or resources generated by `DatabaseSeeder.php` for test-specific setup. The documented bootstrap admin may be used only for its intended login/bootstrap path; all feature-specific users and resources are created dynamically through the API helpers.
  - **Reference-data exception:** A dedicated, checked-in fixture may provide immutable cross-browser reference data when the production UI depends on it. The current example is `E2ELocationSeeder` for the Salzburg/Graz/Linz location contract. It must be loaded explicitly only for E2E setup, indexed before tests, and must not contain mutable user/gallery/order state.
- **On-the-fly Creation:** E2E tests MUST create their own isolated users on-the-fly via the API in `test.beforeEach()` (or a single cohesive test's setup). Use the `E2ESessionHelper.createIsolatedUser()` utility to generate a fresh user with the required role.

## 5. Helper Classes & DRY (Don't Repeat Yourself)
- **SRP in Helpers:** E2E Helpers müssen strikt nach Domänen getrennt sein. Vermeide God-Objects.
- **Aktuelle Helper-Struktur:**
  - `AuthHelper`: Login, Logout, Session Handling.
  - `E2ESessionHelper`: API-basiertes Erstellen isolierter Test-User und Sitzungsverwaltung.
  - `ModalHelper`: Steuerung und Assertion von DaisyUI Modals.
  - `SidebarHelper`: Navigation und Mobile-Menu Handling.
  - `UploadHelper`: Hochladen von Testdateien und Warten auf das Bild-Rendering.
  - `GalleryHelper`: Kapselt wiederkehrende Prozesse wie das Erstellen und Öffnen von Galerien.

## 6. Form Validation & HTML5
- **Required Fields:** E2E Tests dürfen niemals blind auf Submit-Buttons klicken, wenn native HTML5 `required` Felder existieren. Der Browser blockiert die Navigation stumm, und Playwright läuft in Timeouts. Fülle Formulare immer vollständig aus.

## 7. Brand Context in E2E Tests (Referer Header)

* **Brand context via request origin (never via `data.brand`):** The current
  static brand configuration contains only `rp`. Management API calls may set a
  matching frontend origin through the `Referer` header; the brand assignment is
  resolved by the server-side brand context. Sending a `brand` field in a request
  body is not an override and is forbidden.
  * **Current default:** `Referer: http://localhost:4321/` or the configured
    frontend origin may be omitted when the server's default applies.
  * **Legacy names:** `srp`, `buy.localhost`, and `createIsolatedUser(...,
    { brand: 'srp' })` are historical and must not be used by new tests.
* **Forbidden pattern:**
  ```typescript
  await request.put(`/api/management/users/${userId}`, {
      data: { brand: 'rp' }, // not an override
  });
  ```
* **Supported pattern:**
  ```typescript
  await request.put(`/api/management/users/${userId}`, {
      data: { flatrate_level: 'print' },
      headers: {
          Cookie: adminToken,
          Accept: 'application/json',
          Referer: 'http://localhost:4321/'
      }
  });
  ```
  Do not resend `role_ids` after `createIsolatedUser()` has already assigned
  them. A new brand requires a reviewed config/enum change, not a test-only
  fixture value.
