import { Page, expect } from '@playwright/test';
import { SidebarHelper } from './SidebarHelper';
import { ModalHelper } from './ModalHelper';
import { FormHelper } from './FormHelper';

export class GalleryHelper {
    private sidebar: SidebarHelper;
    private modal: ModalHelper;

    constructor(private page: Page, private sessionHelper?: any) {
        this.sidebar = new SidebarHelper(page);
        this.modal = new ModalHelper(page);
    }

    async createAndOpenDeliveryGallery(name: string) {
        await this.sidebar.openNewGalleryModal();
        const form = new FormHelper(this.page, this.modal);
        await form.fillGalleryModal({ name, type: 'Delivery (Downloads)' });
        const res = await this.modal.submitModal('Speichern');
        if (res?.gallery?.id && this.sessionHelper) {
            this.sessionHelper.trackGallery(res.gallery.id);
        }

        const galLink = this.page.locator('main').getByText(name, { exact: true }).first();
        
        // SWR Cache & DOM Race-Condition Protection (besonders für Mobile/Parallel Execution)
        await expect(async () => {
            await expect(galLink).toBeVisible({ timeout: 2000 });
            await galLink.scrollIntoViewIfNeeded();
            // Wenn der Klick fehlschlägt, weil das Element detached wurde (SWR Update), wirft es einen Fehler und der Block wird von toPass() neu versucht.
            await galLink.click();
        }).toPass({ timeout: 15000 });

        await expect(this.page.getByRole('heading', { name })).toBeVisible();
    }
}
