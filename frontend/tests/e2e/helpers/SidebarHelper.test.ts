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

    it('waits for the mobile menu after a reload before clicking a semantic sidebar link', async () => {
        let appReady = false;
        const modal = {};
        const backdrop = {
            first: vi.fn().mockReturnThis(),
            isVisible: vi.fn().mockResolvedValue(false),
        };
        const menuButton = {
            first: vi.fn().mockReturnThis(),
            click: vi.fn().mockResolvedValue(undefined),
            isVisible: vi.fn().mockImplementation(async () => appReady),
        };
        const link = {
            first: vi.fn().mockReturnThis(),
            click: vi.fn().mockResolvedValue(undefined),
            scrollIntoViewIfNeeded: vi.fn().mockResolvedValue(undefined),
            waitFor: vi.fn().mockResolvedValue(undefined),
        };
        const sidebar = {
            getByRole: vi.fn().mockReturnValue(link),
        };
        const pageGetByRole = vi.fn((role: string) => {
            if (role === 'button') return menuButton;
            if (role === 'complementary') return sidebar;
            throw new Error(`Unexpected role: ${role}`);
        });
        const pageLocator = vi.fn((selector: string) => {
            if (selector === '.modal-open') return modal;
            if (selector === 'div.fixed.inset-0') return backdrop;
            throw new Error(`Unexpected selector: ${selector}`);
        });
        const page = {
            getByRole: pageGetByRole,
            locator: pageLocator,
        } as unknown as Page;
        toBeAttached.mockImplementation(async () => {
            appReady = true;
        });

        await new SidebarHelper(page).navigateTo('Mein Team');

        expect(pageLocator).toHaveBeenNthCalledWith(1, '.modal-open');
        expect(playwrightExpect).toHaveBeenNthCalledWith(1, modal);
        expect(toHaveCount).toHaveBeenCalledWith(0, { timeout: 5000 });
        expect(pageGetByRole).toHaveBeenNthCalledWith(1, 'button', { name: 'Menü öffnen' });
        expect(playwrightExpect).toHaveBeenNthCalledWith(2, menuButton);
        expect(toBeAttached).toHaveBeenCalledWith({ timeout: 10000 });
        expect(menuButton.click).toHaveBeenCalledTimes(1);
        expect(pageGetByRole).toHaveBeenNthCalledWith(2, 'complementary');
        expect(sidebar.getByRole).toHaveBeenCalledWith('link', { name: 'Mein Team', exact: false });
        expect(link.first).toHaveBeenCalledTimes(1);
        expect(playwrightExpect).toHaveBeenNthCalledWith(5, link);
        expect(toBeVisible).toHaveBeenCalledWith({ timeout: 10000 });
        expect(link.waitFor).not.toHaveBeenCalled();
        expect(link.scrollIntoViewIfNeeded).not.toHaveBeenCalled();
        expect(link.click).toHaveBeenCalledTimes(1);
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
