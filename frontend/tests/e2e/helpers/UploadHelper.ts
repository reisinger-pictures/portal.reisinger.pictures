import { Page, expect } from '@playwright/test';
import path from 'path';

export class UploadHelper {
    constructor(private page: Page) {}

    async uploadSampleImage() {
        const fileInput = this.page.locator('input[type="file"]');
        const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');
        await fileInput.setInputFiles(sampleImagePath);

        // Warten, bis das Bild im DOM gerendert wurde (geduldige Asserts)
        const image = this.page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible({ timeout: 20000 });
        await expect(image).toHaveJSProperty('complete', true);
        await expect(async () => {
            expect(await image.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
        }).toPass({ timeout: 15000 });
    }
}
