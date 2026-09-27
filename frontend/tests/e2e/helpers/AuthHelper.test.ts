import type { Page } from '@playwright/test';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { playwrightExpect, toBeHidden, toBeVisible, toBeAttached, toHaveCount, toHaveAttribute, toPass } = vi.hoisted(() => ({
    playwrightExpect: vi.fn(),
    toBeHidden: vi.fn(),
    toBeVisible: vi.fn(),
    toBeAttached: vi.fn(),
    toHaveCount: vi.fn(),
    toHaveAttribute: vi.fn(),
    toPass: vi.fn(),
}));

vi.mock('@playwright/test', () => ({
    expect: playwrightExpect,
}));

import { AuthHelper } from './AuthHelper';

type MockOf<T extends (...args: never[]) => unknown> = ReturnType<typeof vi.fn<T>>;

interface FakeLocator {
    name: string;
    first: MockOf<() => FakeLocator>;
    isVisible: MockOf<() => Promise<boolean>>;
    fill: MockOf<(value: string) => Promise<void>>;
    click: MockOf<() => Promise<void>>;
    getAttribute: MockOf<(name: string) => Promise<string | null>>;
    scrollIntoViewIfNeeded: MockOf<() => Promise<void>>;
}

function fakeLocator(name: string, visible = true): FakeLocator {
    const self: FakeLocator = {
        name,
        first: vi.fn<() => FakeLocator>(() => self),
        isVisible: vi.fn<() => Promise<boolean>>(async () => visible),
        fill: vi.fn<(value: string) => Promise<void>>(async () => undefined),
        click: vi.fn<() => Promise<void>>(async () => undefined),
        // `'false'` makes the drawer branch actually click the trigger before
        // re-reading `aria-expanded`, so a regression cannot hide behind an
        // already-open drawer.
        getAttribute: vi.fn<(name: string) => Promise<string | null>>(async () => 'false'),
        scrollIntoViewIfNeeded: vi.fn<() => Promise<void>>(async () => undefined),
    };
    return self;
}

interface FakeSidebar {
    name: string;
    getByRole: MockOf<(role: string, options?: { name?: string; exact?: boolean }) => FakeLocator>;
    getByLabel: MockOf<(text: string, options?: { exact?: boolean }) => FakeLocator>;
}

interface Harness {
    page: {
        goto: MockOf<(url: string) => Promise<void>>;
        getByTestId: MockOf<(id: string) => FakeLocator>;
        getByRole: MockOf<(role: string, options?: { name?: string }) => unknown>;
        locator: MockOf<(selector: string) => FakeLocator>;
        keyboard: { press: MockOf<(key: string) => Promise<void>> };
        context: MockOf<() => { clearCookies: MockOf<() => Promise<void>> }>;
        waitForResponse: MockOf<() => Promise<unknown>>;
    };
    appLoader: FakeLocator;
    appLoaderFallback: FakeLocator;
    main: FakeLocator;
    sidebar: FakeSidebar;
    menuButton: FakeLocator;
    emailInput: FakeLocator;
    passwordInput: FakeLocator;
    loginButton: FakeLocator;
    logoutButton: FakeLocator;
}

/**
 * Builds a page that only resolves the locators `AuthHelper` legitimately
 * needs, exactly like `SidebarHelper.test.ts`. Anything else throws. The
 * throw is the regression guard, not an accident of the fixture:
 *
 *  - `page.locator()` is never used by the current helper (it waits through
 *    role / testid / label locators), so any CSS selector reaching it means a
 *    removed selector came back. The messages name the regression and the
 *    correct hook instead of a generic mismatch.
 *  - `getByTestId()` only knows the two boot signals the helper is allowed to
 *    assert on: `app-loader` (rendered inside the lazy route) and
 *    `app-loader-fallback` (App.tsx SuspenseFallback). The ambiguous
 *    `.loading-spinner.loading-lg` class (43 sites in `src/`) cannot be reached
 *    through it, and neither can any id invented ad hoc to make a wait pass.
 *  - `getByRole()` / `getByLabel()` only resolve the roles and names the
 *    helper's own contract lists.
 */
function createHarness(options: { loginFormVisible: boolean; menuTriggerVisible: boolean }): Harness {
    const appLoader = fakeLocator('app-loader');
    const appLoaderFallback = fakeLocator('app-loader-fallback');
    const main = fakeLocator('main');
    const emailInput = fakeLocator('login-email', options.loginFormVisible);
    const passwordInput = fakeLocator('login-password');
    const loginButton = fakeLocator('login-button');
    const logoutButton = fakeLocator('logout-button');
    const menuButton = fakeLocator('menu-btn', options.menuTriggerVisible);

    const sidebar: FakeSidebar = {
        name: 'sidebar',
        getByRole: vi.fn((role: string, options?: { name?: string; exact?: boolean }) => {
            if (role === 'textbox' && options?.name === 'E-Mail Adresse') return emailInput;
            if (role === 'button' && options?.name === 'Login' && options.exact === true) return loginButton;
            if (role === 'button' && options?.name === 'Abmelden') return logoutButton;
            throw new Error(`Unexpected sidebar role in AuthHelper: ${role} ${options?.name ?? ''}`);
        }),
        getByLabel: vi.fn((text: string, options?: { exact?: boolean }) => {
            if (text === 'Passwort' && options?.exact === true) return passwordInput;
            throw new Error(`Unexpected sidebar label in AuthHelper: ${text}`);
        }),
    };

    const page = {
        goto: vi.fn<(url: string) => Promise<void>>(async () => undefined),
        getByTestId: vi.fn((id: string) => {
            if (id === 'app-loader') return appLoader;
            if (id === 'app-loader-fallback') return appLoaderFallback;
            throw new Error(
                `AuthHelper resolved an unexpected test id: getByTestId(${JSON.stringify(id)}). ` +
                'The only boot signals are data-testid="app-loader" (rendered inside the lazy route) ' +
                'and data-testid="app-loader-fallback" (App.tsx SuspenseFallback). An id outside that ' +
                'pair cannot express "the boot is finished" — and note that waiting on app-loader ' +
                'ALONE can never fail, because it is absent while the route chunk is still in flight.',
            );
        }),
        getByRole: vi.fn((role: string, options?: { name?: string }) => {
            if (role === 'main') return main;
            if (role === 'complementary') return sidebar;
            if (role === 'button' && options?.name === 'Menü öffnen') return menuButton;
            throw new Error(`Unexpected page role in AuthHelper: ${role} ${options?.name ?? ''}`);
        }),
        locator: vi.fn((selector: string): FakeLocator => {
            if (selector.includes('inset-0')) {
                throw new Error(
                    `AuthHelper reintroduced the removed scrim selector: page.locator(${JSON.stringify(selector)}). ` +
                    'div.fixed.inset-0 no longer exists — it was removed from DashboardLayout.tsx and ' +
                    'ClientDashboard.tsx because it is unreachable at every viewport width. ' +
                    'Read drawer state from aria-expanded on the "Menü öffnen" trigger instead.',
                );
            }
            if (selector.includes('loading-spinner') && selector.includes('loading-lg')) {
                throw new Error(
                    `AuthHelper bound to the ambiguous global spinner: page.locator(${JSON.stringify(selector)}). ` +
                    '.loading-spinner.loading-lg appears 43 times in src/ (App.tsx SuspenseFallback, SearchView, …) ' +
                    'and .first() binds to a non-app-loader spinner. Use getByTestId("app-loader") instead.',
                );
            }
            throw new Error(`AuthHelper resolved an unexpected CSS selector: ${JSON.stringify(selector)}`);
        }),
        keyboard: { press: vi.fn<(key: string) => Promise<void>>(async () => undefined) },
        context: vi.fn(() => ({ clearCookies: vi.fn<() => Promise<void>>(async () => undefined) })),
        waitForResponse: vi.fn<() => Promise<unknown>>(async () => ({})),
    };

    return {
        page,
        appLoader,
        appLoaderFallback,
        main,
        sidebar,
        menuButton,
        emailInput,
        passwordInput,
        loginButton,
        logoutButton,
    };
}

/** Every locator handed to an `expect()` wait, in call order. */
function awaitedLocators(): unknown[] {
    return playwrightExpect.mock.calls
        .map((call) => call[0] as unknown)
        .filter((locator) => typeof locator !== 'function');
}

describe('AuthHelper regression guards', () => {
    beforeEach(() => {
        playwrightExpect.mockReset();
        toBeHidden.mockReset();
        toBeVisible.mockReset();
        toBeAttached.mockReset();
        toHaveCount.mockReset();
        toHaveAttribute.mockReset();
        toPass.mockReset();
        toPass.mockImplementation(async () => {
            const actual = playwrightExpect.mock.calls.at(-1)?.[0];
            if (typeof actual !== 'function') throw new Error('toPass callback was not provided');
            return (actual as () => Promise<void>)();
        });
        playwrightExpect.mockReturnValue({ toBeHidden, toBeVisible, toBeAttached, toHaveCount, toHaveAttribute, toPass });
    });

    it('logs in by asserting BOTH boot loaders absent, then asserts the session via the form, never a scrim', async () => {
        const { page, appLoader, appLoaderFallback, main, menuButton, emailInput } = createHarness({
            loginFormVisible: true,
            menuTriggerVisible: true,
        });

        await new AuthHelper(page as unknown as Page).login();

        // Order is boot order: the Suspense fallback is the loader that is on
        // screen while the ProtectedDashboard chunk is in flight, so its wait
        // has to come first for the first budget to mean anything.
        expect(awaitedLocators()).toEqual([appLoaderFallback, appLoader, main, menuButton, emailInput]);
        expect(page.getByTestId).toHaveBeenCalledTimes(2);
        expect(page.getByTestId).toHaveBeenNthCalledWith(1, 'app-loader-fallback');
        expect(page.getByTestId).toHaveBeenNthCalledWith(2, 'app-loader');
        // The only bootstrap waits are the two testids — no `.loading-spinner.loading-lg`
        // and no `div.fixed.inset-0` may be resolved here.
        expect(page.locator).not.toHaveBeenCalled();
        // Absence, not `toBeHidden`: `toBeHidden` on an element that does not
        // exist passes instantly, which is precisely how the old guard became
        // unfailable. `toHaveCount(0)` is the assertion that can observe the
        // loader while it is up and therefore can fail.
        expect(toHaveCount).toHaveBeenCalledTimes(2);
        expect(toHaveCount).toHaveBeenNthCalledWith(1, 0, { timeout: 15000 });
        expect(toHaveCount).toHaveBeenNthCalledWith(2, 0, { timeout: 15000 });
        // The loader waits must not swallow the `main` budget.
        expect(toBeVisible).toHaveBeenCalledWith({ timeout: 15000 });
        // The drawer is opened because its trigger reports yes, then state is read
        // from `aria-expanded` — not from a backdrop overlay.
        expect(page.getByRole).toHaveBeenCalledWith('button', { name: 'Menü öffnen' });
        expect(menuButton.getAttribute).toHaveBeenCalledWith('aria-expanded');
        expect(menuButton.click).toHaveBeenCalledTimes(1);
        expect(toBeHidden).toHaveBeenCalledWith({ timeout: 15000 });
    });

    it('logs out through the app-loader testid, then asserts the guest form and no Abmelden button', async () => {
        const { page, appLoader, emailInput, logoutButton } = createHarness({
            loginFormVisible: false,
            menuTriggerVisible: false,
        });

        await new AuthHelper(page as unknown as Page).logout();

        expect(awaitedLocators()).toEqual([appLoader, emailInput, logoutButton]);
        expect(page.getByTestId).toHaveBeenCalledTimes(1);
        expect(page.getByTestId).toHaveBeenCalledWith('app-loader');
        // The `toBeHidden` on the testid is the only wait on a loading signal;
        // the two session assertions are what actually mean "logged out".
        expect(page.locator).not.toHaveBeenCalled();
        expect(toBeHidden).toHaveBeenCalledWith({ timeout: 5000 });
        expect(toBeAttached).toHaveBeenCalledWith({ timeout: 5000 });
        expect(toHaveCount).toHaveBeenCalledWith(0);
        expect(logoutButton.name).toBe('logout-button');
    });
});

/**
 * Anti-vacuity for the guard above.
 *
 * The defect this suite is shipped against is a *guard that cannot fail*, so the
 * guard against that defect must itself be proven live: a closed door that no
 * longer throws is indistinguishable from a closed door nobody looks at. Each
 * case below asserts both halves of "still fails" — that it throws at all, and
 * that the message names the regression and the correct hook rather than being a
 * generic matcher error.
 */
describe('AuthHelper guard is live, not vacuous', () => {
    const harness = () => createHarness({ loginFormVisible: false, menuTriggerVisible: false }).page;

    it('still fails on a reintroduced scrim selector, naming the regression', () => {
        const page = harness();
        expect(() => page.locator('div.fixed.inset-0')).toThrow(/reintroduced the removed scrim selector/);
        expect(() => page.locator('div.fixed.inset-0')).toThrow(/aria-expanded/);
    });

    it('still fails on a reintroduced bare .first() on the ambiguous spinner, naming the regression', () => {
        const page = harness();
        expect(() => page.locator('.loading-spinner.loading-lg')).toThrow(/ambiguous global spinner/);
        // The message must point at the handle that is unambiguous, or the guard
        // is only half a guard.
        expect(() => page.locator('.loading-spinner.loading-lg')).toThrow(/getByTestId/);
    });

    it('accepts exactly the two boot test ids and rejects any third, naming both', () => {
        const page = harness();
        // Accepted: the correct form of the assertion.
        expect(page.getByTestId('app-loader').name).toBe('app-loader');
        expect(page.getByTestId('app-loader-fallback').name).toBe('app-loader-fallback');
        // Rejected: an id invented to make some wait pass.
        expect(() => page.getByTestId('app-loader-suspense')).toThrow(/unexpected test id/);
        expect(() => page.getByTestId('app-loader-suspense')).toThrow(/app-loader-fallback/);
    });

    it('still fails on any other CSS selector instead of silently resolving', () => {
        const page = harness();
        expect(() => page.locator('main')).toThrow(/unexpected CSS selector/);
    });
});
