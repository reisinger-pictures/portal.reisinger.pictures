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

        // Geduld: Warten bis das Element überhaupt am DOM angebunden und sichtbar ist, bevor wir scrollen
        await expect(image).toBeAttached({ timeout: 15000 });
        await expect(image).toBeVisible({ timeout: 15000 });

        // ✨ WICHTIG für Mobile: Polling-Block inkl. Scrollen (Robust gegen Layout-Shifts)
        await expect(async () => {
            // Kontinuierlich in den Viewport holen. Triggert Lazy-Loading auf natürliche Weise, 
            // selbst wenn das Layout zwischenzeitlich springt.
            await image.scrollIntoViewIfNeeded();
            
            // Prüfen, ob das Bild vom Browser nativ geladen und decodiert wurde
            const isLoaded = await image.evaluate((img: HTMLImageElement) => img.complete && img.naturalWidth > 0);
            expect(isLoaded, 'Bild ist noch nicht fertig geladen').toBeTruthy();
        }).toPass({ timeout: 15000 });
    }
}