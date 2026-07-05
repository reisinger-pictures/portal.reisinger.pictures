import { Page, expect } from '@playwright/test';

export class ToastHelper {
    constructor(private page: Page) {}

    private get toastItems() {
        return this.page.locator('.toast .alert');
    }

    async expectToast(text: string | RegExp, timeout = 10000) {
        const toast = this.toastItems.filter({ hasText: text }).first();
        await expect(toast).toBeVisible({ timeout });
    }

    async expectNoToast(text: string | RegExp, timeout = 3000) {
        const toast = this.toastItems.filter({ hasText: text }).first();
        await expect(toast).toBeHidden({ timeout });
    }

    async dismissToast(text?: string | RegExp) {
        const toast = text
            ? this.toastItems.filter({ hasText: text }).first()
            : this.toastItems.first();
        await expect(toast).toBeVisible({ timeout: 10000 });
        await toast.locator('button').click();
        await expect(toast).toBeHidden({ timeout: 3000 });
    }
}
