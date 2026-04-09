import { Page, expect } from '@playwright/test';
import path from 'path';
import { NetworkHelper } from './NetworkHelper';

export class UploadHelper {
    private network: NetworkHelper;

    constructor(private page: Page) {
        this.network = new NetworkHelper(page);
    }

    async uploadSampleImage() {
        const fileInput = this.page.locator('input[type="file"]');
        const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');

        // Nutzt den NetworkHelper, um auf das Ende des Upload-Requests zu warten
        const uploadPromise = this.network.waitForUpload();

        await fileInput.setInputFiles(sampleImagePath);
        await uploadPromise;

        // Warten, bis das Bild im DOM gerendert wurde (geduldige Asserts)
        const image = this.page.locator('a.pswp-item img').first();

        // ✨ WICHTIG für Mobile: In den Viewport scrollen, um Lazy Loading zu triggern
        await image.scrollIntoViewIfNeeded();

        await expect(image).toBeVisible({ timeout: 15000 });
        await expect(image).toHaveJSProperty('complete', true, { timeout: 15000 });

        // Validierung der tatsächlichen Bilddaten (Width > 0)
        await expect(async () => {
            expect(await image.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
        }).toPass({ timeout: 15000 });
    }
}