import { Page, expect } from '@playwright/test';

export class SidebarHelper {
    constructor(private page: Page) {}

    async navigateTo(menuText: string) {
        // Anti-Flakiness: Sicherstellen, dass keine Fade-Out Animationen von Modals den Klick blockieren
        await expect(this.page.locator('.modal-open')).toHaveCount(0, { timeout: 5000 });
        
        // Prüfe ob der Link bereits sichtbar ist (Desktop Sidebar oder geöffneter Mobile Drawer)
        const link = this.page.locator('ul.menu').getByText(menuText).first();
        if (await link.isVisible().catch(() => false)) {
            await link.scrollIntoViewIfNeeded();
            await link.click();
            return;
        }

        // Mobile: Hamburger-Menü öffnen
        const menuBtn = this.page.locator('header button.btn-square').filter({ has: this.page.locator('.mdi--menu') }).first();
        if (await menuBtn.isVisible()) {
            await menuBtn.click();
            // Warte kurz auf die Slide-In Animation
            await this.page.waitForTimeout(600);
        }
        
        await link.waitFor({ state: 'visible', timeout: 5000 });
        await link.scrollIntoViewIfNeeded();
        await link.click();
        
    }

    async openNewGalleryModal() {
        await this.navigateTo('Galerien');
        const btn = this.page.getByRole('button', { name: 'Neue Galerie' });
        await expect(btn).toBeVisible();
        await btn.click();
    }

    async openNewGroupModal() {
        await this.navigateTo('Galerien');
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
