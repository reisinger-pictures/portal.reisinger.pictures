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
        const galLink = this.page.locator('main').locator('a').filter({ hasText: name }).first();
        const galAny = this.page.locator('main').getByText(name).first();
        await expect(async () => {
            if (await galLink.isVisible().catch(() => false)) {
                await galLink.scrollIntoViewIfNeeded();
                await galLink.evaluate(el => (el as HTMLElement).click());
            } else {
                await expect(galAny).toBeVisible({ timeout: 2000 });
                await galAny.scrollIntoViewIfNeeded();
                await galAny.evaluate(el => (el as HTMLElement).click());
            }
        }).toPass({ timeout: 15000 });

        await expect(this.page.getByRole('heading', { name })).toBeVisible();
        return res?.gallery?.id;
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
