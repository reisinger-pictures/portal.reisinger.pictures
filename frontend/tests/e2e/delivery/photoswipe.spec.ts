import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';

test.afterAll(async ({ request }) => {
  await E2EUserHelper.cleanupTrackedUsers(request);
});


test.describe.serial('PhotoSwipe & Lightbox UI', () => {
    let testUser = { email: '', password: '' };

    test.beforeAll(async ({ request }) => {
        testUser = await E2EUserHelper.createIsolatedUser(request, 'photographer');
    });

    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = () => Date.now() + Math.floor(Math.random() * 1000);
    const galleryName = `Lightbox Test ${uniqueId()}`;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
    });

    test('Lightbox opens and displays custom IPTC captions', async ({ page }) => {
        await auth.login(testUser.email, testUser.password);

        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.submitModal('Speichern');
        
        const galLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await expect(galLink).toBeVisible({ timeout: 15000 });
        await galLink.click();

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible();

        const titleInput = page.locator('div.form-control').filter({ hasText: 'Titel' }).locator('input');
        await titleInput.fill('Episches Testbild');

        const descInput = page.locator('div.form-control').filter({ hasText: 'Beschreibung' }).locator('textarea');
        await descInput.fill('Dies ist eine fantastische Beschreibung für die Lightbox-Ansicht.');

        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.getByRole('button', { name: 'Speichern' })).toBeEnabled();

        await page.locator('button.btn-ghost:has(.mdi--arrow-left)').click();
        
        // SWR revalidiert on focus. In Headless-Tests erzwingen wir dies via Reload.
        await page.reload();
        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible();
        await expect(image).toHaveJSProperty('complete', true);
        await expect(async () => { 
            expect(await image.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0); 
        }).toPass();

        await expect(page.locator('a.pswp-item').first()).toHaveAttribute('data-title', 'Episches Testbild');

        await page.locator('a.pswp-item').first().click();

        const lightbox = page.locator('.pswp');
        await expect(lightbox).toBeVisible();

        await expect(lightbox.locator('text=Episches Testbild')).toBeVisible();
        await expect(lightbox.locator('text=Dies ist eine fantastische Beschreibung für die Lightbox-Ansicht.')).toBeVisible();
        await expect(lightbox.locator('.pswp__custom-caption small')).toContainText('©');

        await expect(async () => {
            await page.locator('button.pswp__button--close').click();
            await expect(lightbox).toBeHidden();
        }).toPass();
    });
});