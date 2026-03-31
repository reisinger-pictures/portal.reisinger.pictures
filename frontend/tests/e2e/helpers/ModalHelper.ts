import { Page, Locator, expect } from '@playwright/test';

export class ModalHelper {
    constructor(private page: Page) {}

    get activeModal(): Locator {
        // DaisyUI hält Modals oft im DOM, wir fokussieren uns strikt auf das aktuell geöffnete.
        return this.page.locator('.modal-open');
    }

    async fillInputByLabel(labelText: string, value: string) {
        await this.activeModal.locator('.form-control').filter({ hasText: labelText }).locator('input').fill(value);
    }

    async selectByLabel(labelText: string, optionLabel: string) {
        await this.activeModal.locator('.form-control').filter({ hasText: labelText }).locator('select').selectOption({ label: optionLabel });
    }

    async clickButton(buttonText: string) {
        const btn = this.activeModal.getByRole('button', { name: buttonText });
        await btn.scrollIntoViewIfNeeded();
        await btn.click();
    }
    
    async closeModal() {
        await this.activeModal.locator('button').filter({ hasText: '✕' }).click();
    }

    async submitModal(buttonText: string = 'Speichern') {
        const savePromise = this.page.waitForResponse(res =>
            res.url().includes('/api/management/') &&
            ['POST', 'PUT'].includes(res.request().method())
        );
        await this.clickButton(buttonText);
        await savePromise;
        await expect(this.activeModal).toBeHidden({ timeout: 15000 });
    }
}
