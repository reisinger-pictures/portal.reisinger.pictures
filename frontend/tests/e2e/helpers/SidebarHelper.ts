import { Page, expect } from '@playwright/test';

export class SidebarHelper {
    constructor(private page: Page) {}

    async navigateTo(menuText: string) {
        const menuBtn = this.page.locator('header button.btn-square').filter({ has: this.page.locator('.mdi--menu') }).first();
        const backdrop = this.page.locator('div.fixed.inset-0.z-40').first();
        
        if (await menuBtn.isVisible()) {
            // Ist das Menü ZU?
            if (await backdrop.count() === 0 || !(await backdrop.isVisible())) {
                await this.page.waitForTimeout(200); // Hydration Puffer
                await menuBtn.click();
                await backdrop.waitFor({ state: 'visible', timeout: 5000 });
                await this.page.waitForTimeout(350); // CSS-Animation abwarten
            }
        }
        
        // Da der AuthHelper jetzt garantiert, dass wir eingeloggt sind, 
        // ist der Link auch wirklich im DOM. Wir nutzen einen sauberen, nativen Klick!
        const link = this.page.locator('ul.menu').getByText(menuText).first();
        await link.waitFor({ state: 'visible', timeout: 5000 });
        await link.click();
        
        await this.page.waitForTimeout(400); 
    }

    async openNewGalleryModal() {
        await this.navigateTo('Galerien');
        const btn = this.page.getByRole('button', { name: 'Neue Galerie' });
        await expect(btn).toBeVisible({ timeout: 10000 });
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
