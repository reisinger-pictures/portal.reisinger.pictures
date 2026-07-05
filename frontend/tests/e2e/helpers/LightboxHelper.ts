import { Page, expect, Locator } from '@playwright/test';

export class LightboxHelper {
    private page: Page;

    constructor(page: Page) {
        this.page = page;
    }

    get imageLink(): Locator {
        return this.page.locator('a.pswp-item').first();
    }

    get image(): Locator {
        return this.page.locator('a.pswp-item img').first();
    }

    get lightbox(): Locator {
        return this.page.locator('.pswp');
    }

    get closeButton(): Locator {
        return this.page.locator('button.pswp__button--close');
    }

    async waitForImageVisible(timeout = 15000) {
        await expect(this.image).toBeAttached({ timeout });
        await expect(this.image).toBeVisible({ timeout });
        await this.image.scrollIntoViewIfNeeded();
        await expect(this.image).toBeVisible({ timeout });
    }

    async clickToOpen() {
        await this.imageLink.click();
        await expect(this.lightbox).toBeVisible();
    }

    async expectTitle(title: string) {
        await expect(this.imageLink).toHaveAttribute('data-title', title);
    }

    async expectCaptionVisible(text: string) {
        await expect(this.lightbox.locator(`text=${text}`)).toBeVisible();
    }

    async expectCopyrightVisible() {
        await expect(this.lightbox.locator('.pswp__custom-caption small')).toContainText('©');
    }

    async close(options?: { timeout?: number }) {
        const timeout = options?.timeout ?? 5000;
        await expect(this.closeButton).toBeVisible({ timeout });
        await expect(async () => {
            await this.closeButton.click();
            await expect(this.lightbox).toBeHidden();
        }).toPass({ timeout: 15000 });
    }

    async expectNotPresent() {
        await expect(this.page.locator('a.pswp-item')).toHaveCount(0);
    }

    async expectCount(count: number) {
        await expect(this.page.locator('a.pswp-item')).toHaveCount(count);
    }
}
