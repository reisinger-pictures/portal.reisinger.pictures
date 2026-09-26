import type { Page } from '@playwright/test';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { playwrightExpect, toHaveCount, toBeAttached, toBeVisible, toPass } = vi.hoisted(() => ({
    playwrightExpect: vi.fn(),
    toHaveCount: vi.fn(),
    toBeAttached: vi.fn(),
    toBeVisible: vi.fn(),
    toPass: vi.fn(),
}));

vi.mock('@playwright/test', () => ({
    expect: playwrightExpect,
}));

import { SidebarHelper } from './SidebarHelper';

type MockOf<T extends (...args: never[]) => unknown> = ReturnType<typeof vi.fn<T>>;

interface FakeLocator {
    name: string;
    first: MockOf<() => FakeLocator>;
    isVisible: MockOf<() => Promise<boolean>>;
    click: MockOf<() => Promise<void>>;
    // Kept only so the tests can prove the helper never uses the separate
    // scroll/attach steps that used to detach mid-click.
    scrollIntoViewIfNeeded: MockOf<() => Promise<void>>;
    waitFor: MockOf<() => Promise<void>>;
}

function fakeLocator(name: string, visible: boolean): FakeLocator {
    const self: FakeLocator = {
        name,
        first: vi.fn<() => FakeLocator>(() => self),
        isVisible: vi.fn<() => Promise<boolean>>(async () => visible),
        click: vi.fn<() => Promise<void>>(async () => undefined),
        scrollIntoViewIfNeeded: vi.fn<() => Promise<void>>(async () => undefined),
        waitFor: vi.fn<() => Promise<void>>(async () => undefined),
    };
    return self;
}

interface Harness {
    page: Page;
    modal: { name: string };
    shell: { name: string; getByRole: MockOf<(role: string, options?: { name?: string }) => FakeLocator> };
    menuButton: FakeLocator;
    backdrop: FakeLocator;
    link: FakeLocator;
}

/**
 * Builds a page whose drawer trigger is either visible (below the `md`
 * breakpoint) or absent from the accessibility tree (from `md` upwards, because
 * the button carries `md:hidden`). The `<aside>` landmark is present in both
 * cases, exactly as in the product.
 */
function createHarness(options: { triggerVisible: boolean; backdropVisible?: boolean }): Harness {
    const modal = { name: 'modal-open' };
    const backdrop = fakeLocator('backdrop', options.backdropVisible ?? false);
    const menuButton = fakeLocator('menu-btn', options.triggerVisible);
    const link = fakeLocator('link', true);
    const shell = {
        name: 'shell',
        getByRole: vi.fn<(role: string, options?: { name?: string }) => FakeLocator>((role, locatorOptions) => {
            if (role === 'link' && typeof locatorOptions?.name === 'string') return link;
            throw new Error(`Unexpected sidebar role: ${role} ${locatorOptions?.name ?? ''}`);
        }),
    };

    const page = {
        getByRole: vi.fn((role: string) => {
            if (role === 'complementary') return shell;
            if (role === 'button') return menuButton;
            throw new Error(`Unexpected page role: ${role}`);
        }),
        locator: vi.fn((selector: string) => {
            if (selector === '.modal-open') return modal;
            if (selector === 'div.fixed.inset-0') return backdrop;
            throw new Error(`Unexpected selector: ${selector}`);
        }),
    } as unknown as Page;

    return { page, modal, shell, menuButton, backdrop, link };
}

/** Every locator handed to an `expect()` wait, in call order. */
function awaitedLocators(): unknown[] {
    return playwrightExpect.mock.calls
        .map((call) => call[0] as unknown)
        .filter((locator) => typeof locator !== 'function');
}

function nameOf(locator: unknown): string {
    if (typeof locator === 'object' && locator !== null && 'name' in locator) {
        return String((locator as { name: string }).name);
    }
    return '<anonymous>';
}

describe('SidebarHelper.navigateTo', () => {
    beforeEach(() => {
        playwrightExpect.mockReset();
        toHaveCount.mockReset();
        toBeAttached.mockReset();
        toBeVisible.mockReset();
        toPass.mockReset();
        toPass.mockImplementation(async () => {
            const actual = playwrightExpect.mock.calls.at(-1)?.[0];
            if (typeof actual !== 'function') throw new Error('toPass callback was not provided');
            return (actual as () => Promise<void>)();
        });
        playwrightExpect.mockReturnValue({ toHaveCount, toBeAttached, toBeVisible, toPass });
    });

    /** The locator the most recent `expect()` was called with. */
    function currentTarget(): unknown {
        return playwrightExpect.mock.calls.at(-1)?.[0] as unknown;
    }

    /**
     * Mirrors Playwright: `toBeAttached()` on a locator that matches nothing
     * retries until it times out. Only the navigation landmark is waitable
     * here, so re-introducing an unconditional `toBeAttached()` on the
     * `md:hidden` drawer trigger fails these tests.
     */
    function onlyShellIsAwaitable(shell: unknown) {
        toBeAttached.mockImplementation(async () => {
            const target = currentTarget();
            if (target !== shell) {
                throw new Error(`expect(locator).toBeAttached() failed: element(s) not found (${nameOf(target)})`);
            }
        });
    }

    it('waits for the navigation shell, then opens the mobile drawer before clicking the link', async () => {
        const { page, modal, shell, menuButton, backdrop, link } = createHarness({ triggerVisible: true });
        onlyShellIsAwaitable(shell);

        await new SidebarHelper(page).navigateTo('Mein Team');

        expect(awaitedLocators()).toEqual([modal, shell, backdrop, link]);
        expect(toHaveCount).toHaveBeenCalledWith(0, { timeout: 5000 });
        expect(toBeAttached).toHaveBeenCalledTimes(1);
        expect(toBeAttached).toHaveBeenCalledWith({ timeout: 10000 });
        expect(toBeAttached.mock.invocationCallOrder[0]).toBeLessThan(menuButton.isVisible.mock.invocationCallOrder[0]);
        expect(menuButton.click).toHaveBeenCalledTimes(1);
        expect(shell.getByRole).toHaveBeenCalledWith('link', { name: 'Mein Team', exact: false });
        expect(link.first).toHaveBeenCalledTimes(1);
        expect(toBeVisible).toHaveBeenCalledWith({ timeout: 10000 });
        expect(link.click).toHaveBeenCalledTimes(1);
        expect(link.waitFor).not.toHaveBeenCalled();
        expect(link.scrollIntoViewIfNeeded).not.toHaveBeenCalled();
    });

    it('does not re-click the trigger when the drawer is already open', async () => {
        const { page, shell, menuButton, link } = createHarness({ triggerVisible: true, backdropVisible: true });
        onlyShellIsAwaitable(shell);

        await new SidebarHelper(page).navigateTo('Verträge');

        expect(toBeAttached).toHaveBeenCalledTimes(1);
        expect(awaitedLocators()).toContain(shell);
        expect(menuButton.click).not.toHaveBeenCalled();
        expect(link.click).toHaveBeenCalledTimes(1);
    });

    it('navigates on desktop, where the md:hidden drawer trigger is absent from the accessibility tree', async () => {
        const { page, modal, shell, menuButton, link } = createHarness({ triggerVisible: false });
        onlyShellIsAwaitable(shell);

        await new SidebarHelper(page).navigateTo('Verträge');

        // The regression guard: an unconditional wait on the trigger times out on
        // Desktop Chrome and took out every Desktop shard.
        expect(awaitedLocators()).not.toContain(menuButton);
        expect(awaitedLocators()).toEqual([modal, shell, link]);
        expect(toBeAttached).toHaveBeenCalledTimes(1);
        expect(menuButton.click).not.toHaveBeenCalled();
        expect(shell.getByRole).toHaveBeenCalledWith('link', { name: 'Verträge', exact: false });
        expect(link.click).toHaveBeenCalledTimes(1);
        expect(link.waitFor).not.toHaveBeenCalled();
        expect(link.scrollIntoViewIfNeeded).not.toHaveBeenCalled();
    });

    it('uses the client discovery link for public gallery navigation', async () => {
        const link = {
            first: vi.fn().mockReturnThis(),
            click: vi.fn().mockResolvedValue(undefined),
        };
        const sidebar = {
            getByRole: vi.fn((role: string, options?: {name?: string}) => {
                if (role === 'link' && options?.name === 'Suche & Entdecken') return link;
                throw new Error(`Unexpected sidebar link: ${options?.name ?? role}`);
            }),
        };
        const menuButton = {
            first: vi.fn().mockReturnThis(),
            isVisible: vi.fn().mockResolvedValue(false),
        };
        const backdrop = {
            first: vi.fn().mockReturnThis(),
            isVisible: vi.fn().mockResolvedValue(false),
        };
        const page = {
            getByRole: vi.fn((role: string) => {
                if (role === 'button') return menuButton;
                if (role === 'complementary') return sidebar;
                throw new Error(`Unexpected page role: ${role}`);
            }),
            locator: vi.fn((selector: string) => {
                if (selector === '.modal-open') return {};
                if (selector === 'div.fixed.inset-0') return backdrop;
                throw new Error(`Unexpected selector: ${selector}`);
            }),
        } as unknown as Page;

        await new SidebarHelper(page).navigateToClientGalleries();

        expect(sidebar.getByRole).toHaveBeenCalledWith('link', { name: 'Suche & Entdecken', exact: false });
        expect(link.click).toHaveBeenCalledTimes(1);
    });
});
