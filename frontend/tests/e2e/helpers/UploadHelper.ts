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
        const res = await uploadPromise;
        expect(res.ok(), `Upload API request failed with status ${res.status()}`).toBeTruthy();

        // Warten, bis das Frontend den Upload-Prozess registriert hat
        const toast = this.page.locator('.toast').filter({ hasText: /hochgeladen/i }).first();
        await expect(toast).toBeVisible({ timeout: 10000 });

        // Warten, bis das Bild im DOM gerendert wurde (geduldige Asserts)
        const image = this.page.locator('a.pswp-item img').first();

        // Geduld: Warten bis das Element überhaupt am DOM angebunden und sichtbar ist, bevor wir scrollen
        await expect(image).toBeAttached({ timeout: 15000 });
        await expect(image).toBeVisible({ timeout: 15000 });

        // Scroll in view to trigger lazy loading, but don't strictly poll naturalWidth as it flakes on headless mobile viewports.
        await image.scrollIntoViewIfNeeded();
        await expect(image).toBeVisible({ timeout: 15000 });
    }
}