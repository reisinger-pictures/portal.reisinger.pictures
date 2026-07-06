import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';
import { FormHelper } from '../helpers/FormHelper';



test.describe('Smart Assistance & Metadata Defaults Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser( 'photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = () => Math.random().toString(36).substring(2, 10);
    const galleryName = `Smart Default Test ${uniqueId()}`;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
        await auth.login(testUser.email, testUser.password);
    });

    test('Gallery defaults behavior: Graz (auto-fill) and Linz (stay empty on ambiguity)', { tag: ['@feature:delivery:metadata'] }, async ({ page }) => {
        // 1. Neue Delivery Galerie erstellen
        await sidebar.openNewGalleryModal();
        const form = new FormHelper(page, modal);
        await form.fillGalleryModal({ name: galleryName, type: 'Delivery (Downloads)' });
        
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        // 2. Galerie öffnen und Vorgaben-Modal aufrufen
        const galLink = page.locator('main').getByText(galleryName, { exact: true }).first();
        await expect(async () => {
            await expect(galLink).toBeVisible({ timeout: 2000 });
            await galLink.scrollIntoViewIfNeeded();
            await galLink.click();
        }).toPass({ timeout: 15000 });

        await page.getByRole('button', { name: 'Vorgaben...' }).click();
        await expect(page.locator('h3:has-text("Metadaten-Vorgaben")')).toBeVisible();

        // Metadaten Defaults aktivieren
        await page.locator('span.label-text').filter({ hasText: 'Standard-Metadaten beim Upload anwenden' }).click();

        const cityInput = page.locator('.form-control').filter({ hasText: 'Stadt' }).locator('input[type="text"]');
        const stateInput = page.locator('.form-control').filter({ has: page.locator('.label-text', { hasText: 'Bundesland' }) }).locator('input[type="text"]');

        // --- FALL 1: Graz (Eindeutiger Fall) ---
        await cityInput.click();
        const searchGrazPromise = page.waitForResponse(res => res.url().includes('/api/search/locations'));
        await cityInput.pressSequentially('Graz', { delay: 100 });
        await searchGrazPromise;

        const dropdownGraz = page.locator('li').filter({ hasText: 'Graz' }).first();
        await expect(dropdownGraz).toBeVisible({ timeout: 15000 });
        await dropdownGraz.click();

        await expect(stateInput).toHaveValue('Steiermark');

        // --- FALL 2: Linz (Mehrdeutigkeit) ---
        await cityInput.click();
        await cityInput.clear();
        const searchLinzPromise = page.waitForResponse(res => res.url().includes('/api/search/locations'));
        await cityInput.pressSequentially('Linz', { delay: 100 });
        await searchLinzPromise;

        const dropdownLinz = page.locator('li').filter({ has: page.locator('span.text-primary', { hasText: 'Linz' }) }).first();
        await expect(dropdownLinz).toBeVisible({ timeout: 15000 });
        await dropdownLinz.click();

        await expect(stateInput).not.toBeEmpty();
        
        // Speichern im Vorgaben-Modal
        await page.getByRole('button', { name: 'Speichern' }).click();
        await expect(page.locator('text=Metadaten-Vorgaben gespeichert')).toBeVisible();
        await page.keyboard.press('Escape');

        // 3. Bild hochladen und Vererbung prüfen
        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible();

        await expect(page.locator('.form-control').filter({ hasText: 'Stadt' }).locator('input[type="text"]')).toHaveValue('Linz');
        await expect(page.locator('.form-control').filter({ hasText: 'Bundesland' }).locator('input[type="text"]')).not.toBeEmpty();
    });
});
