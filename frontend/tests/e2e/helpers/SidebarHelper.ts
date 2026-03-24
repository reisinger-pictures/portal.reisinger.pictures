import { Page, expect } from '@playwright/test';

export class SidebarHelper {
    constructor(private page: Page) {}

    async openMobileMenu() {
        // Sucht nach dem Hamburger-Button (sichtbar auf Mobile)
        const menuBtn = this.page.locator('button:has(.mdi--menu)').first();
        if (await menuBtn.isVisible()) {
            await menuBtn.click({ force: true });
            await this.page.waitForTimeout(400); // Kurz warten, bis die CSS Slide-In Animation beendet ist
        }
    }

    async navigateTo(menuText: string) {
        await this.openMobileMenu();
        await this.page.locator('ul.menu').getByText(menuText).click();
    }

    async openNewGalleryModal() {
        await this.openMobileMenu();
        // exact: true verhindert, dass "Meta-Galerie..." mit ausgewählt wird
        await this.page.getByRole('button', { name: 'Galerie...', exact: true }).click();
    }

    async openNewGroupModal() {
        await this.openMobileMenu();
        await this.page.getByRole('button', { name: 'Meta-Galerie...' }).click();
    }

    async assertNotVerticallyScrollable() {
        const sidebarMenu = this.page.locator('aside .overflow-y-auto').first();
        if (await sidebarMenu.isVisible()) {
            const isScrollable = await sidebarMenu.evaluate((el) => el.scrollHeight > el.clientHeight);
            // Wirft einen Fehler, falls die Sidebar unerwartet vertikal scrollt (z.B. durch Layout-Bugs)
            // Hinweis: Bei extrem vielen Galerien wird sie natürlich scrollen müssen, 
            // diese Methode ist für Standard-Tests mit wenigen Einträgen gedacht.
            if (isScrollable) {
                throw new Error('Sidebar ist vertikal scrollbar, obwohl sie es nicht sein sollte.');
            }
        }
    }
}
