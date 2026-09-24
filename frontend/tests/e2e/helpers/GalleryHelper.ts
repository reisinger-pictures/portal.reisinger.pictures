import { Page, expect } from '@playwright/test';
import { SidebarHelper } from './SidebarHelper';
import { ToastHelper } from './ToastHelper';
import { ModalHelper } from './ModalHelper';
import { FormHelper } from './FormHelper';
import { E2ESessionHelper } from './E2ESessionHelper';

export class GalleryHelper {
    private sidebar: SidebarHelper;
    private modal: ModalHelper;

    constructor(private page: Page, private sessionHelper?: E2ESessionHelper) {
        this.sidebar = new SidebarHelper(page);
        this.modal = new ModalHelper(page);
    }

    async createAndOpenDeliveryGallery(name: string, visibility?: string): Promise<string | undefined> {
        await this.sidebar.openNewGalleryModal();
        const form = new FormHelper(this.page, this.modal);
        await form.fillGalleryModal({ name, type: 'Delivery (Downloads)', visibility });
        const res = await this.modal.submitModal('Speichern', '/api/management/galleries');
        if (res?.gallery?.id && this.sessionHelper) {
            this.sessionHelper.trackGallery(res.gallery.id);
        }

        await this.page.reload();
        await this.page.waitForLoadState('networkidle');
        await this.openGallery(name);
        return res?.gallery?.id;
    }

    async openGallery(name: string): Promise<void> {
        const main = this.page.getByRole('main');
        // SWR can replace the structure tree while navigation is settling.
        // Locator.click() re-resolves the link after a render; element-handle
        // scrolling/evaluate clicks can race with that replacement.
        const galleryLink = main.getByRole('link', { name, exact: false }).first();

        await expect(galleryLink).toBeVisible({ timeout: 15000 });
        await galleryLink.click();
        await expect(main.getByRole('heading', { name, exact: false }).first()).toBeVisible({ timeout: 15000 });
    }

    async setPhotographerTeamAccess(status: 'Erben' | 'Offen' | 'Restriktiv') {
        const btn = this.page.getByRole('button', { name: 'Fotografen...' });
        await expect(btn).toBeVisible();
        await btn.click();
        
        const teamModal = this.page.locator('.modal-open').filter({ hasText: 'Fotografen-Team' });
        await expect(teamModal).toBeVisible();
        
        const select = teamModal.locator('select');
        const valueMap = { 'Erben': 'null', 'Offen': 'false', 'Restriktiv': 'true' };
        await select.selectOption(valueMap[status]);
        
        await new ToastHelper(this.page).expectToast('Status gespeichert');
        
        await teamModal.locator('button').filter({ hasText: '✕' }).click();
        await expect(teamModal).toBeHidden();
    }
}
