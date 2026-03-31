import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';

test.afterAll(async ({ request }) => {
    await E2EUserHelper.cleanupTrackedUsers(request);
});

test.describe.serial('PhotoSwipe in Selection Gallery', () => {
    let testUser = { email: '', password: '' };

    test.beforeAll(async ({ request }) => {
        testUser = await E2EUserHelper.createIsolatedUser(request, 'photographer');
    });

    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = () => Date.now() + Math.floor(Math.random() * 1000);
    const galleryName = `Selection Lightbox ${uniqueId()}`;
    let inviteLink = '';

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
    });

    test('Photographer creates selection gallery and uploads image', async ({ page }) => {
        await auth.login(testUser.email, testUser.password);

        // 1. Selection Galerie erstellen
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Auswahl (Ratings)');
        await modal.submitModal('Speichern');

        // Wir warten geduldig, bis SWR die Liste aktualisiert hat
        const link = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(link).toBeVisible({ timeout: 15000 });

        await link.click();

        // 2. Bild hochladen
        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        // 3. Invite Link für Gast generieren
        await page.getByRole('button', { name: 'Einladungslink...' }).click();
        await page.locator('text=Persönlicher Link (Einzelperson)').click();
        await modal.fillInputByLabel('Name des Gastes', 'Lightbox Tester');
        await modal.clickButton('Generieren');

        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible();
        inviteLink = await modal.activeModal.locator('input[readonly]').inputValue();
        await modal.clickButton('Schließen');
    });

    test('Client can interact with PhotoSwipe rating UI and use keyboard shortcuts', async ({ page }) => {
        // Sicherstellen, dass der Link aus dem vorherigen Test vorhanden ist (wichtig für --last-failed)
        test.skip(inviteLink === '', 'Test requires link from previous step');

        // 1. Als Gast die Galerie öffnen
        await page.goto(inviteLink);
        await page.getByRole('button', { name: 'Weiter als Lightbox Tester' }).click();
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();

        // 2. Lightbox öffnen
        await page.locator('a.pswp-item').first().click();

        // 3. Assert: Lightbox sichtbar und Portal-Anker vorhanden
        const lightbox = page.locator('.pswp');
        await expect(lightbox).toBeVisible();
        await expect(page.locator('#rating-portal-anchor')).toBeVisible();

        // 4. Rating via Button & Comment Input
        const ratingBar = page.locator('#rating-portal-anchor .rating');
        await expect(ratingBar).toBeVisible();

        const commentInput = page.locator('#rating-portal-anchor input[type="text"]');
        await commentInput.fill('Episches Bild im Fullscreen!');

        // Kommentar mit Enter bestätigen und auf API warten
        const commentResp = page.waitForResponse(res => res.url().includes('/rate') && res.request().method() === 'POST');
        await commentInput.press('Enter');
        await commentResp;

        // Mobile Fix: Kurz warten, bis die Bildschirmtastatur eingefahren und das Layout stabil ist
        await page.waitForTimeout(500);

        // 5 Sterne vergeben (triggert automatischen Wechsel zum nächsten Bild)
        const star5 = ratingBar.locator('input.mask-star-2').nth(4);
        await expect(star5).toBeVisible();
        const rateResponse = page.waitForResponse(res => res.url().includes('/rate') && res.request().method() === 'POST');
        await star5.click();
        await rateResponse;

        // 5. Lightbox schließen
        await expect(async () => {
            await page.locator('button.pswp__button--close').click();
            await expect(lightbox).toBeHidden();
        }).toPass();

        // 6. Verify im Grid
        await page.waitForTimeout(1000);
        const star5Grid = page.locator('.card-body input.mask-star-2').nth(4);
        await expect(star5Grid).toBeChecked();

        const gridComment = page.locator('input[placeholder="Kommentar..."]').first();
        await expect(gridComment).toHaveValue('Episches Bild im Fullscreen!');

        // 7. Rating via Keyboard Shortcut
        await page.locator('a.pswp-item').first().click();
        await expect(lightbox).toBeVisible();
        await expect(page.locator('#rating-portal-anchor')).toBeVisible();

        const rateResponse2 = page.waitForResponse(res => res.url().includes('/rate') && res.request().method() === 'POST');
        await page.keyboard.press('3');
        await rateResponse2;

        await expect(async () => {
            await page.locator('button.pswp__button--close').click();
            await expect(lightbox).toBeHidden();
        }).toPass();

        const star3 = page.locator('.card-body input.mask-star-2').nth(2);
        await expect(star3).toBeChecked();
    });
});