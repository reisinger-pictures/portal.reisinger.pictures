import { Page, expect } from '@playwright/test';
import { SidebarHelper } from './SidebarHelper';
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

    async createAndOpenDeliveryGallery(name: string) {
        await this.sidebar.openNewGalleryModal();
        const form = new FormHelper(this.page, this.modal);
        await form.fillGalleryModal({ name, type: 'Delivery (Downloads)' });
        const res = await this.modal.submitModal('Speichern');
        if (res?.gallery?.id && this.sessionHelper) {
            this.sessionHelper.trackGallery(res.gallery.id);
        }

        const galLink = this.page.locator('main').locator('a').filter({ hasText: name }).first();
        
        await expect(async () => {
            if (!(await galLink.isVisible())) await this.page.reload();
            await expect(galLink).toBeVisible({ timeout: 2000 });
            await galLink.scrollIntoViewIfNeeded();
            await galLink.click();
        }).toPass({ timeout: 30000 });

        await expect(this.page.getByRole('heading', { name })).toBeVisible();
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
        
        await expect(this.page.locator('.toast')).toContainText('Status gespeichert');
        
        await teamModal.locator('button').filter({ hasText: '✕' }).click();
        await expect(teamModal).toBeHidden();
    }
}
