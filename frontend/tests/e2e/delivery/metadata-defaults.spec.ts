import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import path from 'path';

test.describe.serial('Smart Assistance & Metadata Defaults Workflow', () => {
    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = Date.now();
    const galleryName = `Smart Default Test ${uniqueId}`;

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
        await auth.login();
    });

    test('Gallery defaults are applied to newly uploaded photos via Smart Assist', async ({ page }) => {
        
        // Netzwerk Mocking für deterministische, schnelle Tests (Unabhängig von Meilisearch Sync)
        await page.route('**/api/search/locations?q=Linz&type=city', async route => {
            const json = [{ id: '1', type: 'city', name: 'Linz', state: 'Oberösterreich', country: 'Österreich', iso_country: 'AT' }];
            await route.fulfill({ json });
        });
        
        // Netzwerk mocking für country
        await page.route('**/api/search/locations?*type=country*', async route => {
            const json = [{ id: '2', type: 'country', name: 'Österreich', iso_country: 'AT' }];
            await route.fulfill({ json });
        });

        // 2. Neue Delivery Galerie erstellen
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');
        
        // 3. Metadaten Defaults aktivieren
        // Klick auf den Text-Span, um die Checkbox via <label> nativ zu toggeln (umgeht Playwright-Sichtbarkeitsprobleme)
        await modal.activeModal.locator('span.label-text').filter({ hasText: 'Standard-Metadaten beim Upload anwenden' }).click();

        // 4. Smart Assistance (Auto-Complete) testen
        // 4. Smart Assistance (Auto-Complete) testen
        // Wir warten bis das Metadaten-Formular (bzw. das Input) wirklich in den DOM injiziert wurde
        const cityInput = modal.activeModal.locator('.form-control').filter({ hasText: 'Stadt' }).locator('input[type="text"]');
        await cityInput.waitFor({ state: 'visible', timeout: 15000 });
        await expect(cityInput).toBeVisible();
        await cityInput.fill('Linz');

        // Dropdown Item anklicken
        const dropdownItem = modal.activeModal.locator('li').filter({ hasText: 'Linz' }).first();
        await expect(dropdownItem).toBeVisible();
        await dropdownItem.click();

        // Verifizieren, ob Bundesland, Land und ISO automatisch gefüllt wurden
        await expect(modal.activeModal.locator('.form-control').filter({ has: page.locator('.label-text', { hasText: 'Bundesland' }) }).locator('input[type="text"]')).toHaveValue('Oberösterreich');
        await expect(modal.activeModal.locator('.form-control').filter({ has: page.locator('.label-text', { hasText: /^Land$/ }) }).locator('input[type="text"]').first()).toHaveValue('Österreich');
        await expect(modal.activeModal.locator('.form-control').filter({ has: page.locator('.label-text', { hasText: 'ISO' }) }).locator('input[type="text"]').last()).toHaveValue('AT');

        // Noch einen Titel hinzufügen
        await modal.activeModal.locator('.form-control').filter({ hasText: 'Titel' }).locator('input[type="text"]').fill('Automatischer Titel');

        // Galerie speichern
        await modal.clickButton('Speichern');
        await expect(page.locator('main').locator('a').filter({ hasText: galleryName }).first()).toBeVisible({ timeout: 15000 });

        // 5. In die Galerie wechseln und Bild hochladen
        await page.locator('main').locator('a').filter({ hasText: galleryName }).first().click();
        const fileInput = page.locator('input[type="file"]');
        const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');
        await fileInput.setInputFiles(sampleImagePath);
        
        // Warten bis das Bild im DOM gerendert ist
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 20000 });
        await expect(page.locator('a.pswp-item img').first()).toHaveJSProperty('complete', true);
        expect(await page.locator('a.pswp-item img').first().evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);

        // 6. In die Detailansicht wechseln und Vererbung der Defaults prüfen
        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible();

        // Prüfen, ob das hochgeladene Bild die Defaults übernommen hat
        await expect(page.locator('.form-control').filter({ hasText: 'Titel' }).locator('input[type="text"]')).toHaveValue('Automatischer Titel');
        await expect(page.locator('.form-control').filter({ hasText: 'Stadt' }).locator('input[type="text"]')).toHaveValue('Linz');
        await expect(page.locator('.form-control').filter({ hasText: 'Bundesland' }).locator('input[type="text"]')).toHaveValue('Oberösterreich');
    });
});
