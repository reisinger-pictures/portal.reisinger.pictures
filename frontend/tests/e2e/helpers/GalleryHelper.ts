import { Page, expect } from '@playwright/test';
import { SidebarHelper } from './SidebarHelper';
import { ModalHelper } from './ModalHelper';

export class GalleryHelper {
    private sidebar: SidebarHelper;
    private modal: ModalHelper;

    constructor(private page: Page) {
        this.sidebar = new SidebarHelper(page);
        this.modal = new ModalHelper(page);
    }

    async createAndOpenDeliveryGallery(name: string) {
        await this.sidebar.openNewGalleryModal();
        await this.modal.fillInputByLabel('Name der Galerie', name);
        await this.modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');
        await this.modal.submitModal('Speichern');

        const galLink = this.page.locator('main').getByText(name, { exact: true });
        await expect(galLink).toBeVisible({ timeout: 15000 });

        await galLink.click();

        await expect(this.page.getByRole('heading', { name })).toBeVisible({ timeout: 10000 });
    }
}
