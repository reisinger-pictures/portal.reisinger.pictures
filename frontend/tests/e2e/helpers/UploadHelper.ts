import { Page, expect } from '@playwright/test';
import path from 'path';
import { NetworkHelper } from './NetworkHelper';
import { LightboxHelper } from './LightboxHelper';

export class UploadHelper {
    private network: NetworkHelper;

    constructor(private page: Page) {
        this.network = new NetworkHelper(page);
    }

    async uploadSampleImage() {
        const fileInput = this.page.locator('input[type="file"].file-input').first();
        const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');

        // Nutzt den NetworkHelper, um auf das Ende des Upload-Requests zu warten
        const uploadPromise = this.network.waitForUpload();

        await fileInput.evaluate(el => { (el as HTMLInputElement).value = ''; });
        await fileInput.setInputFiles(sampleImagePath);

        // `waitForUpload` throws when the request is never answered, so `res` is
        // always a real Response by the time it is read: "never answered" and
        // "answered with an error" cannot both arrive here. The previous version
        // still asked `if (res)` and kept an else arm that fabricated a timeout
        // message for a response that never existed — unreachable, and a claim
        // this code can no longer make. On a timeout the enriched `TimeoutError`
        // from NetworkHelper propagates out of this method untouched instead,
        // naming the endpoint, the method and the budget.
        const res = await uploadPromise;

        let errorBody = '';
        if (!res.ok()) {
            errorBody = await res.text();
            console.error('\n--- UPLOAD ERROR RESPONSE BODY ---');
            console.error(errorBody);
            console.error('----------------------------------\n');
        }

        expect(res.ok(), `Upload API request failed with status ${res.status()}. Details: ${errorBody}`).toBeTruthy();

        // Warten, bis das Frontend den Upload-Prozess registriert hat
        const toast = this.page.locator('.toast').filter({ hasText: /hochgeladen/i }).first();
        await expect(toast).toBeVisible({ timeout: 10000 });

        // Warten, bis das Bild im DOM gerendert wurde (geduldige Asserts)
        const image = new LightboxHelper(this.page).image;

        // Geduld: Warten bis das Element überhaupt am DOM angebunden und sichtbar ist, bevor wir scrollen
        await expect(image).toBeAttached({ timeout: 15000 });
        await expect(image).toBeVisible({ timeout: 15000 });

        // Scroll in view to trigger lazy loading, but don't strictly poll naturalWidth as it flakes on headless mobile viewports.
        await image.scrollIntoViewIfNeeded();
        await expect(image).toBeVisible({ timeout: 15000 });
    }
}