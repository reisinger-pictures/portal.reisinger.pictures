---
domain: technical
topic: testing-guidelines
status: active
---

# Technical Concept: Testing Guidelines

## 1. Philosophy & Robustness
- **No `force: true` in Playwright:** Bypassing actionability checks defeats the purpose of E2E tests. If Playwright cannot click an element naturally, a human probably can't either. Always wait for elements to become stable and uncovered (e.g., wait for animations to finish or modals to close via `toBeHidden()`) instead of forcing clicks.
- **No Try-Catch Anti-Pattern:** Never mask failing tests by wrapping production code or assertions in `try-catch` blocks purely to pass a test. Exceptions must bubble up and fail the test clearly.
- **Patient Asserts (Auto-Retries):** Never use static `sleep()` or `waitForLoadState('networkidle')` in E2E tests. Always use asynchronous assertions with sufficient timeouts (e.g., `await expect(locator).toBeVisible({ timeout: 15000 })`).
- **Anti-Flakiness (Network vs. UI):** Do NOT use `page.waitForResponse()` when waiting for UI updates (like debounced search results). Network interception is highly prone to race conditions (e.g., firing too early or cache hits). Always trigger the action (e.g., `fill()`) and wait patiently for the resulting UI element to become visible using Playwright's auto-retrying assertions.
- **Single Reason to Fail (SRP):** Tests (PHPUnit & Playwright) MUST focus on a single behavior. Avoid monolithic 20-step tests.

## 2. E2E Tests (Playwright)
- **Test Isolation (No DB Reset):** E2E tests run directly against the local dev environment (`portal_db`). They MUST be non-destructive. Always use highly dynamic names/identifiers (e.g., `Date.now()`).
- **Page Object Model (POM):** Do not duplicate Playwright logic. Use provided helper classes (e.g., `ModalHelper`, `SidebarHelper`).
- **Mobile-First Validation:** E2E tests must be explicitly executed against mobile viewports to verify touch targets and z-index issues.

## 3. Backend Tests (PHPUnit)
- **Negative Testing (IDOR):** Jeder Endpunkt, der auf eine spezifische Ressource zugreift (Galerie, Foto, Download), MUSS explizit mit einem unberechtigten Nutzer getestet werden (Insecure Direct Object Reference Protection). Es muss zwingend ein 403 (Forbidden) oder 404 (Not Found) Statuscode erwartet werden.
- **API Resources (Leak Prevention):** In PHPUnit, do not just check HTTP status codes. Always assert the JSON response structure (`assertJsonStructure` or `assertJsonMissing`) to ensure no unintended fields (e.g., `password_hash`) are leaked.
- **Email & Link Integrity:**
  - Mocking emails is forbidden. Tests must query the local Mailpit API.
  - Tests MUST parse the HTML body of the email, extract generated action links (e.g., Magic Links), and confirm that navigating to these links resolves successfully (HTTP 200).
