import type { Page } from '@playwright/test';
import { beforeEach, describe, expect, it, vi } from 'vitest';

const { playwrightExpect, toBeVisible } = vi.hoisted(() => ({
    playwrightExpect: vi.fn(),
    toBeVisible: vi.fn(),
}));

vi.mock('@playwright/test', () => ({
    expect: playwrightExpect,
}));

vi.mock('./SidebarHelper', () => ({
    SidebarHelper: vi.fn(),
}));

vi.mock('./ModalHelper', () => ({
    ModalHelper: vi.fn(),
}));

vi.mock('./FormHelper', () => ({
    FormHelper: vi.fn(),
}));

vi.mock('./ToastHelper', () => ({
    ToastHelper: vi.fn(),
}));

vi.mock('./E2ESessionHelper', () => ({
    E2ESessionHelper: vi.fn(),
}));

import { GalleryHelper } from './GalleryHelper';

describe('GalleryHelper.openGallery', () => {
    beforeEach(() => {
        playwrightExpect.mockReset();
        toBeVisible.mockReset();
        playwrightExpect.mockReturnValue({ toBeVisible });
    });

    it('uses a scoped semantic link and a re-resolving click for gallery navigation', async () => {
        const galleryName = 'Communication Gallery';
        const click = vi.fn().mockResolvedValue(undefined);
        const galleryLink = {
            click,
            first: vi.fn().mockReturnThis(),
        };
        const heading = {
            first: vi.fn().mockReturnThis(),
        };
        const mainGetByRole = vi.fn((role: string) => {
            if (role === 'link') return galleryLink;
            if (role === 'heading') return heading;
            throw new Error(`Unexpected role: ${role}`);
        });
        const pageGetByRole = vi.fn().mockReturnValue({ getByRole: mainGetByRole });
        const helper = new GalleryHelper({ getByRole: pageGetByRole } as unknown as Page);

        await helper.openGallery(galleryName);

        expect(pageGetByRole).toHaveBeenCalledWith('main');
        expect(mainGetByRole).toHaveBeenNthCalledWith(1, 'link', { name: galleryName, exact: false });
        expect(galleryLink.first).toHaveBeenCalledTimes(1);
        expect(click).toHaveBeenCalledTimes(1);
        expect(mainGetByRole).toHaveBeenNthCalledWith(2, 'heading', { name: galleryName, exact: false });
        expect(heading.first).toHaveBeenCalledTimes(1);
        expect(playwrightExpect.mock.calls[0][0]).toBe(galleryLink);
        expect(playwrightExpect.mock.calls[1][0]).toBe(heading);
        expect(toBeVisible).toHaveBeenNthCalledWith(1, { timeout: 15000 });
        expect(toBeVisible).toHaveBeenNthCalledWith(2, { timeout: 15000 });
    });
});
