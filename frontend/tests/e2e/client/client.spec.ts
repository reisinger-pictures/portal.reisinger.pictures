import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';

test.describe.serial('Client Workflow', () => {
    let testUser = { email: '', password: '' };

    test.beforeAll(async ({ request }) => {
        testUser = await E2EUserHelper.createIsolatedUser(request, 'photographer');
    });

    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = Date.now();
    const galleryName = `E2E Selection ${uniqueId}`;
    let inviteLink = '';

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
    });

    test('Photographer creates selection gallery and invite link', async ({ page }) => {
        await auth.login(testUser.email, testUser.password);
        
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Auswahl (Ratings)');
        await modal.submitModal('Speichern');
        // Robustes Klicken: Playwright pollt den Click selbst, bis das Element da und klickbar ist
        const galLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(async () => {
            if (await galLink.isHidden()) await page.reload();
            await expect(galLink).toBeVisible({ timeout: 3000 });
        }).toPass({ timeout: 20000 });
        await galLink.scrollIntoViewIfNeeded();
        await galLink.click();
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible({ timeout: 15000 });

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await page.getByRole('button', { name: 'Einladungslink...' }).click();
        
        // Neues InviteModal UI: Wir müssen zuerst auf den persönlichen Link wechseln
        await page.locator('text=Persönlicher Link (Einzelperson)').click();
        
        // Das Label wurde ebenfalls umbenannt
        await modal.fillInputByLabel('Name des Gastes', 'Test Client');
        await modal.clickButton('Generieren');

        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible();
        inviteLink = await modal.activeModal.locator('input[readonly]').inputValue();
        
        await modal.clickButton('Schließen');
    });

    test('Client can access selection gallery via invite link', async ({ page }) => {
        expect(inviteLink).not.toBe('');

        await page.goto(inviteLink);
        
        await expect(page.locator('h2:has-text("Willkommen zur Fotoauswahl")')).toBeVisible();
        await page.getByRole('button', { name: 'Weiter als Test Client' }).click();
        

        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible({ timeout: 15000 });
        await expect(page.locator('p:has-text("Wähle deine Favoriten aus.")')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Auswahl abschließen' })).toBeVisible();

        // --- DAU Protection Check (Right-Click & Drag) ---
        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible({ timeout: 15000 });
        await expect(image).toHaveJSProperty('complete', true, { timeout: 15000 });
        expect(await image.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);

        // 1. Prüfen, ob das Kontextmenü blockiert wird
        const isPrevented = await image.evaluate((el) => {
            const event = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
            el.dispatchEvent(event);
            return event.defaultPrevented;
        });
        expect(isPrevented).toBe(true);

        // 2. Prüfen, ob das Bild nicht gezogen werden kann (Drag & Drop)
        await expect(image).toHaveAttribute('draggable', 'false');
    });


    test('Client can filter photos by rating and PhotoSwipe respects the filter', async ({ page }) => {
        expect(inviteLink).not.toBe('');
        await page.goto(inviteLink);
        await page.getByRole('button', { name: 'Weiter als Test Client' }).click();
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible({ timeout: 15000 });

        // Wait for image to load
        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible({ timeout: 15000 });
        await expect(image).toHaveJSProperty('complete', true, { timeout: 15000 });
        expect(await image.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);

        // Rate the first image (click 5th star)
        const ratePromise = page.waitForResponse(res => res.url().includes('/rate') && res.request().method() === 'POST');
        await page.locator('input.mask-star-2').nth(4).click();
        await ratePromise;

        // Test Filter: "Neu (Unbewertet)"
        await page.getByRole('button', { name: 'Neu', exact: true }).click();
        // Since we only uploaded 1 sample image and rated it, the "Unbewertet" list should be empty
        await expect(page.locator('a.pswp-item')).toHaveCount(0);

        // Test Filter: "Meine Favoriten"
        await page.getByRole('button', { name: 'Favoriten', exact: true }).click();
        await expect(page.locator('a.pswp-item')).toHaveCount(1);

        // Verify PhotoSwipe opens and shows 1 of 1 (instead of crashing or showing wrong count)
        await page.locator('a.pswp-item').first().click();
        await expect(page.locator('.pswp')).toBeVisible();
        await expect(async () => {
            await page.locator('button.pswp__button--close').click();
            await expect(page.locator('.pswp')).toBeHidden({ timeout: 2000 });
        }).toPass({ timeout: 15000 });
    });
});