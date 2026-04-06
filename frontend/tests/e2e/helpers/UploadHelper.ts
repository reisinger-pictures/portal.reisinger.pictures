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
        
        // Nutzt den neuen NetworkHelper
        const uploadPromise = this.network.waitForUpload();
        
        await fileInput.setInputFiles(sampleImagePath);
        await uploadPromise;

        // Warten, bis das Bild im DOM gerendert wurde (geduldige Asserts)
        const image = this.page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible();
        await expect(async () => {
            expect(await image.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
        }).toPass();
    }
}
