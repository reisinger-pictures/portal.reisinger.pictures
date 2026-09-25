import { Page, expect } from '@playwright/test';

export class SidebarHelper {
    constructor(private page: Page) {}

    async navigateTo(menuText: string) {
        // Anti-Flakiness: Sicherstellen, dass keine Fade-Out Animationen von Modals den Klick blockieren
        await expect(this.page.locator('.modal-open')).toHaveCount(0, { timeout: 5000 });

        const menuBtn = this.page.getByRole('button', { name: 'Menü öffnen' }).first();
        const backdrop = this.page.locator('div.fixed.inset-0').first();

        // After a reload the app loader can briefly replace the dashboard. Wait
        // for its mobile menu before deciding that no drawer click is needed.
        await expect(menuBtn).toBeAttached({ timeout: 10000 });

        if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
            await expect(async () => {
                if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
                    await menuBtn.click();
                }
                await expect(backdrop).toBeVisible({ timeout: 2000 });
            }).toPass({ timeout: 10000 });
        }

        const link = this.page.getByRole('complementary')
            .getByRole('link', { name: menuText, exact: false })
            .first();
        // click() performs its own actionability and scrolling checks.
        await expect(link).toBeVisible({ timeout: 10000 });
        await link.click();
    }

    async navigateToClientGalleries() {
        // Client users reach public galleries through discovery, not the staff-only gallery manager.
        await this.navigateTo('Suche & Entdecken');
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
