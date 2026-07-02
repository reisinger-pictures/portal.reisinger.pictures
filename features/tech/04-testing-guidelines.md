---
domain: technical
topic: testing-guidelines
status: active
---

# Technical Concept: Testing Guidelines

* **Mocking-Verbot in E2E-Tests (STRIKT):** Das Mocking von internen CRUD-API-Endpunkten (z.B. `page.route('**/api/galleries/*')`, `page.route('**/api/photos/*')`, `page.route('**/api/auth/me')`) in Playwright-Tests ist untersagt. E2E-Tests müssen die echte System-Integration validieren.
  * **Ausnahme 1 – Externe Integrationen:** Endpunkte, die ausschließlich als Proxy für externe Dienste fungieren (z.B. `/api/ai/*`, `/api/coupons/validate`), dürfen gemockt werden, wenn der externe Dienst in der Testumgebung nicht verfügbar ist. Dies muss im Test-Kommentar explizit dokumentiert werden.
  * **Ausnahme 2 – Nicht steuerbarer UI-State:** `page.route('**/api/auth/me', ...)` darf verwendet werden, um UI-Entscheidungen zu testen, die auf serverseitigen Konfigurations-Flags (`ai_is_unconfigured`) basieren, die über die Test-API nicht gesetzt werden können.
* **Golden Rule (Features First):** Der `features/`-Ordner ist die primäre Wissensbasis. Jede neue Logik muss dort im Soll-Zustand dokumentiert werden, bevor sie implementiert wird. Der Ordner ist bei jeder Änderung aktuell zu halten.

## 1. Testing Rules (UI-FIRST) & Philosophy
* **No Test-Environment Checks in Production (STRICT):** Production code must never alter its behavior based on test environments (e.g., checking `navigator.userAgent.includes('Playwright')`). Tests must validate the genuine application behavior. If tests flake due to realistic features (like `revalidateOnFocus`), fix the test assertions, do not cripple the application UX.
* **No Shared State / No Serial Execution (STRICT):** The use of `test.describe.serial` is strictly forbidden. Tests must be 100% isolated. Do not share variables (like URLs or IDs) across `test()` blocks. If steps depend on each other, combine them into a single, cohesive End-to-End `test()` block.
* **UI-First Synchronization (MANDATORY):**
  * Prefer `expect(locator).toBeVisible({ timeout: 15000 })` for simple UI updates.
  * Prefer `await expect(async () => { ... }).toPass()` for complex SWR/React state transitions where multiple re-renders occur.
  * `page.waitForResponse()` ist **ausnahmsweise erlaubt**, wenn eine Aktion keine sichtbare UI-Änderung erzeugt (z.B. Hintergrund-API-Calls beim Checkout, Locations-Suche). Der Response darf jedoch **nicht** zur Statuscode-Assertion verwendet werden — die Validierung muss immer über die sichtbare UI erfolgen. Die Nutzung muss im Test-Kommentar begründet werden.
* **No `page.goto` for SPA Navigation (STRICT):** Nach erfolgreichem Login MUSS die Seitennavigation ausschließlich über `sidebar.navigateTo()` (SPA Client-Side Routing) erfolgen. `page.goto()` ist nur in folgenden Ausnahmefällen erlaubt:
  * Initialer Seitenaufruf vor dem Login (z.B. `page.goto('/')` im `AuthHelper`)
  * Navigation zu externen URLs (Invite-Links, Magic-Links, Password-Reset-Tokens)
  * Reloads nach `localStorage`-Injektion (da der App-State nur beim Bootstrap aus `localStorage` gelesen wird).
  * Reloads zum Testen der Persistenz nach serverseitigen Änderungen (Roundtrip-Checks) — hier ist `page.reload()` dem `page.goto()` vorzuziehen.
* **Pragmatische Reload Policy (Asynchronous Processes):** Generell sollte `page.reload()` vermieden werden, um direktes UI-State-Management (z.B. SWR Mutations nach dem Erstellen einer Entität) zu testen.
  * **Ausnahme:** Bei unabhängigen, zeitversetzten oder entkoppelten serverseitigen Prozessen (z. B. das Aktualisieren eines "E-Mail senden"-Buttons, weil ein anderer Nutzer sich im Hintergrund in die Empfängerliste eingetragen hat) ist `page.reload()` oder erneutes Hin-Navigieren ausdrücklich **erlaubt**. In solchen Fällen spiegelt das Neuladen das natürliche Nutzerverhalten wider.
* **Fail-Fast:** Der Testlauf wird nach 2 fehlgeschlagenen Tests (z.B. `maxFailures: 2` in Playwright) sofort abgebrochen.
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
* **Lazy Loading & Viewports (Mobile):** Bilder mit `loading="lazy"` werden in E2E-Tests auf mobilen Viewports oft nicht geladen, wenn sie sich außerhalb des initialen Sichtbereichs befinden. Bevor Bildeigenschaften (wie `naturalWidth`) geprüft werden, MUSS das Element zwingend mit `scrollIntoViewIfNeeded()` in den Viewport geholt werden, um den Netzwerk-Download des Browsers zu erzwingen.

## 2. E2E Tests (Playwright)
- **Trace Viewer & Debugging:** Always analyze the Playwright trace (`playwright show-report`) to understand failures. Traces provide a full execution timeline, snapshots, and network logs.
- **Test Parallelism & Isolation (CRITICAL):** E2E tests run **in parallel** across multiple workers directly against the local dev environment (`portal_db`). They MUST be 100% isolated and non-destructive.
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
- **Instance-based Tracking:** Every test file must instantiate a fresh `E2EUserHelper`.
- **Automatic Teardown:** Resources (Users, Galleries, Groups) created during a test must be registered in the helper instance and cleaned up in `test.afterEach()`.
- **Usage Pattern:**
  ```typescript
  let helper: E2ESessionHelper;
  test.beforeEach(({ request }) => { helper = new E2ESessionHelper(request); });
  test.afterEach(async () => { await helper.teardown(); });
  ```
- **No Static Seeders for Tests:** Never rely on static test users generated by `DatabaseSeeder.php` (except the initial `florian@reisinger.pictures` super-admin used for bootstrapping).
- **On-the-fly Creation:** E2E tests MUST create their own isolated users on-the-fly via the API in `test.beforeAll()`. Use the `E2ESessionHelper.createIsolatedUser()` utility to generate a fresh user with the required role.

## 5. Helper Classes & DRY (Don't Repeat Yourself)
- **SRP in Helpers:** E2E Helpers müssen strikt nach Domänen getrennt sein. Vermeide God-Objects.
- **Aktuelle Helper-Struktur:**
  - `AuthHelper`: Login, Logout, Session Handling.
  - `E2EUserHelper`: API-basiertes Erstellen von isolierten Test-Usern.
  - `ModalHelper`: Steuerung und Assertion von DaisyUI Modals.
  - `SidebarHelper`: Navigation und Mobile-Menu Handling.
  - `UploadHelper`: Hochladen von Testdateien und Warten auf das Bild-Rendering.
  - `GalleryHelper`: Kapselt wiederkehrende Prozesse wie das Erstellen und Öffnen von Galerien.

## 6. Form Validation & HTML5
- **Required Fields:** E2E Tests dürfen niemals blind auf Submit-Buttons klicken, wenn native HTML5 `required` Felder existieren. Der Browser blockiert die Navigation stumm, und Playwright läuft in Timeouts. Fülle Formulare immer vollständig aus.

## 7. Brand-Switching in E2E Tests (Referer Header)

* **Brand-Kontext via `Referer` Header (NICHT via `data.brand`):** Um Management-API-Calls im Kontext einer bestimmten Brand auszuführen (z.B. User anlegen, Flaterate-Level setzen), MUSS der `Referer`-Header auf die entsprechende Frontend-Domain gesetzt werden. Das direkte Setzen von `data.brand: 'srp'` im Request-Body ist **verboten**, da die Brand-Zuweisung ausschließlich über den Brand-Kontext (ermittelt via `Referer`) erfolgt und `UpdateUserRequest` keine Brand-Überschreibung via Body erlaubt.
  * **RP (Default):** `Referer: http://localhost:4321/` oder weglassen (Default-Fallback im Backend)
  * **SRP:** `Referer: http://buy.localhost:4321/`
  * **Anmerkung:** `createIsolatedUser('client', { brand: 'srp' })` setzt den Referer automatisch.
* **Verbotene Muster (anti-pattern):**
  ```typescript
  // NIEMALS brand direkt im data-Body setzen:
  await request.put(`/api/management/users/${userId}`, {
      data: { brand: 'srp', ... }  // → 422 Error
  });
  ```
  ```typescript
  // NIEMALS role_ids ohne Brand-Kontext erneut senden:
  await request.put(`/api/management/users/${userId}`, {
      data: { role_ids: [clientRoleId], ... }  // → 422 "Brand-Zuweisung erforderlich"
  });
  ```
* **Korrektes Muster:**
  ```typescript
  // Brand-Kontext via Referer (wenn nicht bereits durch createIsolatedUser gesetzt):
  await request.put(`/api/management/users/${userId}`, {
      data: { flatrate_level: 'print' },
      headers: {
          'Cookie': adminToken,
          'Accept': 'application/json',
          'Referer': 'http://localhost:4321/'
      }
  });
  // role_ids nicht erneut senden — createIsolatedUser hat sie bereits gesetzt.
  ```
