import { Page } from '@playwright/test';

export class SidebarHelper {
    constructor(private page: Page) {}

    async navigateTo(menuText: string) {
        await this.page.locator('ul.menu').getByText(menuText).click();
    }

    async openNewGalleryModal() {
        // exact: true verhindert, dass "Meta-Galerie..." mit ausgewählt wird
        await this.page.getByRole('button', { name: 'Galerie...', exact: true }).click();
    }

    async openNewGroupModal() {
        await this.page.getByRole('button', { name: 'Meta-Galerie...' }).click();
    }
}
