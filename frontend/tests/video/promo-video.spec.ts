// Demo-video category — a deliberately paced UI walkthrough.
//
// See ./README.md and ./harness.ts for the category contract. Each spec owns
// its own STORY; the shared harness only handles the unrecorded login, the
// recording, the "the image really loaded" check and the publish-on-success
// contract. A new promo with a different flow is a new spec file.
//
// Story: a photographer sets up a gallery with metadata defaults (Linz),
// uploads two real photos. The second photo is edited wrongly and restored via
// the metadata history. At the end both photos are opened in the detail view:
// the first one proves that the upload defaults — including the correct author
// — were applied, the second one shows the restored metadata.
import { expect, test, type Page } from '@playwright/test';
import path from 'node:path';
import { E2ESessionHelper } from '../e2e/helpers/E2ESessionHelper';
import { GalleryHelper } from '../e2e/helpers/GalleryHelper';
import { FIXTURES_DIR, beat, expectRealImage, record, type DemoRecording } from './harness';

const IMAGE_DEFAULT = '21_LASK-Reisinger_09.jpg';
const IMAGE_EDITED = '21_LASK-Reisinger_10.jpg';
const AUTHOR = 'E2E photographer';

async function uploadImage(page: Page, filename: string): Promise<void> {
    const fileInput = page.locator('input[type="file"].file-input').first();
    await fileInput.evaluate(el => {
        (el as HTMLInputElement).value = '';
    });
    const upload = page.waitForResponse(
        response => response.url().includes('/api/management/upload') && response.request().method() === 'POST',
        { timeout: 20000 },
    );
    await fileInput.setInputFiles(path.join(FIXTURES_DIR, filename));
    const response = await upload;
    expect(response.ok(), `Upload von ${filename} fehlgeschlagen: ${response.status()}`).toBeTruthy();
    await expect(page.locator('.toast').filter({ hasText: /hochgeladen/i }).first()).toBeVisible({ timeout: 10000 });
}

async function backToGallery(page: Page): Promise<void> {
    await page.locator('main button:has(span.mdi--arrow-left)').click();
    await expect(page.locator('button[title="Details & Metadaten"]').first()).toBeVisible({ timeout: 15000 });
}

test('Demo-Video: Linz-Vorgaben, zwei Uploads, korrekter Autor und Metadaten-Wiederherstellung', { tag: ['@demo-video', '@feature:promo-video'] }, async ({ browser, request }, testInfo) => {
    const helper = new E2ESessionHelper(request);
    const photographer = await helper.createIsolatedUser('photographer');
    let recording: DemoRecording | undefined;
    let published = false;

    try {
        // Recording starts with the session already authenticated — no white
        // boot/login screen in the video.
        recording = await record(browser, testInfo, photographer.email, photographer.password);
        const page = recording.page;
        await beat(page);

        // --- 1. Galerie anlegen ------------------------------------------------
        const gallery = new GalleryHelper(page, helper);
        const galleryName = `Linz Derby ${Date.now()}`;
        await gallery.createAndOpenDeliveryGallery(galleryName);
        await beat(page, 1800);

        // --- 2. Metadaten-Vorgaben ---------------------------------------------
        await page.getByRole('button', { name: 'Vorgaben...' }).click();
        const defaults = page.getByRole('dialog', { name: 'Metadaten-Vorgaben' });
        await expect(defaults).toBeVisible();

        await defaults
            .locator('.form-control')
            .filter({ hasText: 'Standard-Metadaten beim Upload anwenden' })
            .locator('input[type="checkbox"]')
            .check();
        await beat(page);

        const correctTitle = 'Fußball-Derby in Linz';
        const description = 'Derby-Abend im Linzer Stadion, Oberösterreich.';
        await defaults.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input')
            .pressSequentially(correctTitle, { delay: 160 });
        await defaults.locator('.form-control').filter({ hasText: 'Beschreibung' }).locator('textarea')
            .pressSequentially(description, { delay: 25 });
        await defaults.getByPlaceholder('Schlagworte eingeben (Komma/Enter)...').fill('Fußball');
        await defaults.getByPlaceholder('Schlagworte eingeben (Komma/Enter)...').press('Enter');
        await beat(page, 1800);

        // Dynamic location lookup — type slowly, then let the suggestion list
        // (including the postal code) stay on screen long enough to be read.
        const city = defaults.getByRole('combobox', { name: 'Stadt / Ort' });
        await city.click();
        await city.pressSequentially('Lin', { delay: 500 });
        const linzOption = defaults.getByRole('option', { name: /Linz/ });
        await expect(linzOption).toBeVisible();
        await expect(linzOption).toContainText('4020');
        await beat(page, 2600);
        await linzOption.click();

        await expect(defaults.locator('.form-control').filter({ hasText: 'Bundesland' }).locator('input'))
            .toHaveValue('Oberösterreich');
        await expect(defaults.getByRole('combobox', { name: 'Land' })).toHaveValue('Österreich');
        await expect(defaults.locator('input[maxlength="2"]')).toHaveValue('AT');
        await beat(page, 2200); // show the autofilled country/state/ISO values

        await defaults.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.locator('.toast')).toContainText('Metadaten-Vorgaben gespeichert');
        await beat(page, 1800);

        // --- 3. Beide Bilder hochladen — echte Bilder müssen erscheinen --------
        await uploadImage(page, IMAGE_DEFAULT);
        await beat(page, 1600);
        await uploadImage(page, IMAGE_EDITED);
        await beat(page, 1600);

        const items = page.locator('a.pswp-item');
        await expect(items).toHaveCount(2, { timeout: 15000 });
        await expectRealImage(page, items.nth(0).locator('img'), 'erstes hochgeladenes Bild');
        await expectRealImage(page, items.nth(1).locator('img'), 'zweites hochgeladenes Bild');
        await beat(page, 3000);

        // --- 4. Zweites Bild: falsche Metadaten und Wiederherstellung ---------
        await page.locator('button[title="Details & Metadaten"]').nth(1).click();
        const detailImage = page.locator(`img[alt="${correctTitle}"]`).first();
        await expectRealImage(page, detailImage, 'Bild in der Einzelansicht');
        await beat(page, 2600);

        const photoTitle = page.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input');
        await expect(photoTitle).toHaveValue(correctTitle);
        await photoTitle.scrollIntoViewIfNeeded();
        await beat(page, 1800); // show the correct value before changing it

        const wrongTitle = 'FALSCH: Bayern München';
        await photoTitle.fill('');
        await photoTitle.pressSequentially(wrongTitle, { delay: 220 });
        await beat(page, 2600); // hold on the wrong value so the change is readable

        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.locator('.toast')).toContainText('Metadaten gespeichert');
        await expect(photoTitle).toHaveValue(wrongTitle);
        await beat(page, 2200);

        await page.getByRole('button', { name: 'Historie' }).click();
        const history = page.getByRole('dialog', { name: 'Änderungshistorie (Vor-Zustände)' });
        await expect(history).toBeVisible();
        await expect(history).toContainText(correctTitle);
        await beat(page, 6000); // first-time viewer can read the version table

        await history.getByRole('button', { name: 'Wiederherstellen' }).first().click();
        const confirm = page.locator('.modal-global');
        await expect(confirm).toBeVisible();
        await beat(page, 1800);
        await confirm.getByRole('button', { name: 'Wiederherstellen' }).click();

        await expect(page.locator('.toast')).toContainText('Metadaten wiederhergestellt');
        await expect(photoTitle).toHaveValue(correctTitle);
        await beat(page, 2600);
        await backToGallery(page);

        // --- 5. Am Ende: beide Bilder in den Details zeigen --------------------
        // Erstes Bild: Upload-Defaults inkl. korrektem Autor.
        await page.locator('button[title="Details & Metadaten"]').nth(0).click();
        await expectRealImage(page, page.locator(`img[alt="${correctTitle}"]`).first(), 'Detailbild 1');
        await beat(page, 2600);
        await expect(page.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input'))
            .toHaveValue(correctTitle);
        await expect(page.locator('div.form-control').filter({ hasText: 'Beschreibung' }).locator('textarea'))
            .toHaveValue(description);
        await expect(page.getByRole('combobox', { name: 'Stadt / Ort' })).toHaveValue('Linz');
        await expect(page.locator('.form-control').filter({ hasText: 'Urheber / Copyright' }).locator('input'))
            .toHaveValue(AUTHOR);
        await page.locator('.form-control').filter({ hasText: 'Urheber / Copyright' }).scrollIntoViewIfNeeded();
        await beat(page, 3200); // hold on the author field
        await backToGallery(page);

        // Zweites Bild: das wiederhergestellte Metadata.
        await page.locator('button[title="Details & Metadaten"]').nth(1).click();
        await expectRealImage(page, page.locator(`img[alt="${correctTitle}"]`).first(), 'Detailbild 2');
        await expect(page.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input'))
            .toHaveValue(correctTitle);
        await beat(page, 3200);

        // --- Publish only on success -------------------------------------------
        await recording.finish(true);
        published = true;
    } finally {
        if (recording && !published) {
            await recording.finish(false);
        }
        await helper.teardown();
    }
});
