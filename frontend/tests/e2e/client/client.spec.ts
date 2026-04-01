import { test, expect } from '@playwright/test';
import { NetworkHelper } from '../helpers/NetworkHelper';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';

// 🧹 Clean-Up Hook: Räumt alle isoliert erstellten Galerien auf, bevor der E2E-User gelöscht wird.
test.afterAll(async ({ request }) => {
    await E2EUserHelper.cleanupE2EData(request);
    await E2EUserHelper.cleanupTrackedUsers(request);
});

test.describe.serial('Client Selection Workflow', () => {
    let testUser = { email: '', password: '' };

    test.beforeAll(async ({ request }) => {
        testUser = await E2EUserHelper.createIsolatedUser(request, 'photographer');
    });

    test('End-to-End Selection Flow: Create, Invite, Redeem, DAU-Protect, Rate and Filter', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const upload = new UploadHelper(page);
        const network = new NetworkHelper(page);

        const uniqueId = Math.random().toString(36).substring(2, 10);
        const galleryName = `E2E Selection ${uniqueId}`;

        // --- PHASE 1: FOTOGRAF BEREITET VOR ---
        await auth.login(testUser.email, testUser.password);
        
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Auswahl (Ratings)');
        await modal.submitModal('Speichern');
        
        const galLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(galLink).toBeVisible({ timeout: 15000 });
        await galLink.scrollIntoViewIfNeeded();
        await galLink.click();
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();

        await upload.uploadSampleImage();

        await page.getByRole('button', { name: 'Einladungslink...' }).click();
        await page.locator('text=Persönlicher Link (Einzelperson)').click();
        await modal.fillInputByLabel('Name des Gastes', 'Test Client');
        await modal.clickButton('Generieren');

        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible();
        const inviteLink = await modal.activeModal.locator('input[readonly]').inputValue();
        await modal.clickButton('Schließen');

        // Fotograf loggt sich aus
        await auth.logout();

        // --- PHASE 2: GAST LÖST EIN UND BEWERTET ---
        expect(inviteLink).not.toBe('');
        await page.goto(inviteLink);
        
        await expect(page.locator('h2:has-text("Willkommen zur Fotoauswahl")')).toBeVisible();
        await page.getByRole('button', { name: 'Weiter als Test Client' }).click();

        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();
        await expect(page.locator('p:has-text("Wähle deine Favoriten aus.")')).toBeVisible();

        // DAU Protection prüfen (Rechtsklick & Drag)
        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible();
        await expect(image).toHaveJSProperty('complete', true);
        expect(await image.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);

        const isPrevented = await image.evaluate((el) => {
            const event = new MouseEvent('contextmenu', { bubbles: true, cancelable: true });
            el.dispatchEvent(event);
            return event.defaultPrevented;
        });
        expect(isPrevented).toBe(true);
        await expect(image).toHaveAttribute('draggable', 'false');

        // --- PHASE 3: GAST FILTERT UND NUTZT PHOTOSWIPE ---
        const ratePromise = network.waitForRating();
        await page.locator('input.mask-star-2').nth(4).click();
        await ratePromise;

        // Filter: "Neu (Unbewertet)"
        await page.getByRole('button', { name: 'Neu', exact: true }).click();
        await expect(page.locator('a.pswp-item')).toHaveCount(0);

        // Filter: "Meine Favoriten"
        await page.getByRole('button', { name: 'Favoriten', exact: true }).click();
        await expect(page.locator('a.pswp-item')).toHaveCount(1);

        // PhotoSwipe aufrufen
        await page.locator('a.pswp-item').first().click();
        await expect(page.locator('.pswp')).toBeVisible();
        
        await expect(async () => {
            await page.locator('button.pswp__button--close').click();
            await expect(page.locator('.pswp')).toBeHidden();
        }).toPass();
    });
});
