import { Page, expect } from '@playwright/test';

export class SidebarHelper {
    constructor(private page: Page) {}

    async navigateTo(menuText: string) {
        // Anti-Flakiness: Sicherstellen, dass keine Fade-Out Animationen von Modals den Klick blockieren
        await expect(this.page.locator('.modal-open')).toHaveCount(0, { timeout: 5000 });

        const menuBtn = this.page.locator('header button').filter({ has: this.page.locator('.mdi--menu') }).first();
        const backdrop = this.page.locator('div.fixed.inset-0.z-40').first();

        if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
            await expect(async () => {
                if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
                    await menuBtn.click();
                }
                await expect(backdrop).toBeVisible({ timeout: 2000 });
            }).toPass({ timeout: 10000 });
            await this.page.waitForTimeout(400);
        }

        const link = this.page.locator('ul.menu').getByText(menuText, { exact: false }).first();
        await link.waitFor({ state: 'visible', timeout: 5000 });
        await link.scrollIntoViewIfNeeded();
        await link.click();
    }

    async openNewGalleryModal() {
        await this.navigateTo('Galerien & Ordner');
        const btn = this.page.getByRole('button', { name: 'Neue Galerie' });
        await expect(btn).toBeVisible();
        await btn.click();
    }

    async openNewGroupModal() {
        await this.navigateTo('Galerien & Ordner');
        await this.page.getByRole('button', { name: 'Neuer Ordner' }).click();
    }

    async assertNotVerticallyScrollable() {
        const sidebarMenu = this.page.locator('aside .overflow-y-auto').first();
        if (await sidebarMenu.isVisible()) {
            const isScrollable = await sidebarMenu.evaluate((el) => el.scrollHeight > el.clientHeight);
            if (isScrollable) {
                throw new Error('Sidebar ist vertikal scrollbar, obwohl sie es nicht sein sollte.');
            }
        }
    }
}
