import {APIRequestContext, expect, Page, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';
import {ModalHelper} from '../helpers/ModalHelper';
import {UploadHelper} from '../helpers/UploadHelper';
import {FormHelper} from '../helpers/FormHelper';

test.describe('Download Triggers UI & Flatrate Restrictions', () => {
    let helper: E2ESessionHelper;
    let photogUser = {email: '', password: '', id: ''};
    let clientUser = {email: '', password: '', id: ''};

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);
        photogUser = await helper.createIsolatedUser('photographer');
        clientUser = await helper.createIsolatedUser('client');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    // --- Zentrale Setup-Funktion für alle isolierten Tests ---
    async function setupGalleryAndAssign(page: Page, request: APIRequestContext, flatrateLevel: 'print' | 'original') {
        const adminToken = helper.getAdminToken();

        // Kunden auf das gewünschte Flatrate-Level hochstufen
        // role_ids und brand nicht mitsenden — createIsolatedUser hat das bereits gesetzt
        const putRes = await request.put(`/api/management/users/${clientUser.id}`, {
            data: {
                gallery_group_ids: [],
                gallery_ids: [],
                can_edit_metadata: false,
                flatrate_level: flatrateLevel
            },
            headers: {'Cookie': adminToken, 'Accept': 'application/json'}
        });
        if (!putRes.ok()) {
            console.error('PUT /api/management/users failed:', putRes.status(), await putRes.text());
        }
        expect(putRes.ok(), `Failed to update user flatrate_level to ${flatrateLevel}`).toBeTruthy();

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);

        const galleryName = `Flatrate Test ${Math.random().toString(36).substring(2, 10)}`;

        await auth.login(photogUser.email, photogUser.password);

        await sidebar.openNewGalleryModal();
        await form.fillGalleryModal({
            name: galleryName,
            type: 'Delivery (Downloads)',
            visibility: 'Privat (Nur mit Link / Passwort)',
            freeDownload: false
        });

        const resData = await modal.submitModal('Speichern');
        const galleryId = resData?.gallery?.id;
        if (galleryId) helper.trackGallery(galleryId);

        const link = page.locator('main').locator('a').filter({hasText: galleryName}).first();
        await expect(link).toBeVisible({timeout: 15000});
        await link.click();

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 10000 });

        await request.post(`/api/management/galleries/${galleryId}/sync-access`, {
            data: {user_id: clientUser.id, action: 'attach'},
            headers: {'Cookie': adminToken, 'Accept': 'application/json'}
        });

        await auth.logout();
        return galleryName;
    }

    test('Test 1: ZIP Download dropdown restricts options and downloads correctly', { tags: ['@feature:delivery:download'] }, async ({page, request}) => {
        const galleryName = await setupGalleryAndAssign(page, request, 'print');
        const auth = new AuthHelper(page);

        await auth.login(clientUser.email, clientUser.password);

        // Verify flatrate_level is applied in the frontend
        await expect(async () => {
            const userData = await page.evaluate(() =>
                fetch('/api/auth/me', { headers: { 'Accept': 'application/json' } }).then(r => r.json())
            );
            expect(userData.flatrate_level).toBe('print');
        }).toPass({ timeout: 10000 });

        await page.locator('main').locator('.card').filter({hasText: galleryName}).first().click();
        
        // Requirement: Sicherstellen, dass das Bild für den Kunden korrekt geladen und angezeigt wird
        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible({timeout: 15000});
        await image.scrollIntoViewIfNeeded();

        const zipDropdownBtn = page.getByRole('button', { name: /Alle herunterladen/ });
        await expect(zipDropdownBtn).toBeVisible({timeout: 10000});
        await zipDropdownBtn.click();

        // ASSERTION: Darf nur Web und Print sehen
        await expect(page.getByRole('link', {name: 'WEB Format'})).toBeVisible();
        await expect(page.getByRole('link', {name: 'PRINT Format'})).toBeVisible();
        await expect(page.getByRole('link', {name: 'ORIGINAL Format'})).toBeHidden();

        const zipLink = page.getByRole('link', {name: 'PRINT Format'});

        // target="_blank" entfernen, damit das Playwright Download-Event nativ fängt
        await zipLink.evaluate(node => node.removeAttribute('target'));

        const [download] = await Promise.all([
            page.waitForEvent('download', {timeout: 30000}),
            zipLink.click()
        ]);

        expect(download.suggestedFilename()).toMatch(/\.zip$/i);
    });

    test('Test 2: Normal client does not see upgrade options and cart button', { tags: ['@feature:delivery:download'] }, async ({page, request}) => {
        const galleryName = await setupGalleryAndAssign(page, request, 'print');
        const auth = new AuthHelper(page);

        await auth.login(clientUser.email, clientUser.password);
        await page.locator('main').locator('.card').filter({hasText: galleryName}).first().click();

        // Requirement: Sicherstellen, dass das Bild für den Kunden korrekt geladen und angezeigt wird
        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible({timeout: 15000});
        await image.scrollIntoViewIfNeeded();

        await page.getByRole('button', {name: 'Bild öffnen'}).first().click();
        await expect(page.locator('h4:has-text("Lizenz wählen")')).toBeVisible({timeout: 10000});

        // Werbung/Kampagne erfordert Original -> Der Kunde hat aber nur Print
        // Da es ein normaler Kunde ist, darf er keine Upsells kaufen, daher ist die Option versteckt.
        await expect(page.locator('label').filter({hasText: 'Werbung / Kampagne'})).toBeHidden({timeout: 10000});

        const cartButton = page.getByRole('button', {name: /In den Warenkorb/i});
        await expect(cartButton).toBeHidden();
    });

    test('Test 3: Single Download executes successfully when covered by flatrate', { tags: ['@feature:delivery:download'] }, async ({page, request}) => {
        const galleryName = await setupGalleryAndAssign(page, request, 'original');
        const auth = new AuthHelper(page);

        await auth.login(clientUser.email, clientUser.password);

        // Verify flatrate_level is applied in the frontend
        await expect(async () => {
            const userData = await page.evaluate(() =>
                fetch('/api/auth/me', { headers: { 'Accept': 'application/json' } }).then(r => r.json())
            );
            expect(userData.flatrate_level).toBe('original');
        }).toPass({ timeout: 10000 });

        await page.locator('main').locator('.card').filter({hasText: galleryName}).first().click();

        // Requirement: Sicherstellen, dass das Bild für den Kunden korrekt geladen und angezeigt wird
        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible({timeout: 15000});
        await image.scrollIntoViewIfNeeded();

        await page.getByRole('button', {name: 'Bild öffnen'}).first().click();
        await expect(page.locator('h4:has-text("Lizenz wählen")')).toBeVisible({timeout: 10000});

        const werbungLabel = page.getByText(/Werbung.*Kampagne|Kampagne.*Werbung/).first();
        await expect(werbungLabel).toBeVisible({timeout: 10000});
        await werbungLabel.click();

        const downloadLink = page.getByRole('link', {name: 'Download', exact: true}).first();
        await expect(downloadLink).toBeVisible();

        // target="_blank" entfernen, damit der Download zuverlässig gefangen wird
        await downloadLink.evaluate(node => node.removeAttribute('target'));

        const [download] = await Promise.all([
            page.waitForEvent('download', {timeout: 30000}),
            downloadLink.click()
        ]);

        expect(download.suggestedFilename()).toMatch(/\.jpg$/i);
    });

    test('Test 4: Admin and Photographer see the Admin Download button in PhotoDetailView', { tags: ['@feature:delivery:download'] }, async ({page, request}) => {
        const galleryName = await setupGalleryAndAssign(page, request, 'original');
        const auth = new AuthHelper(page);

        // Login als Fotograf
        await auth.login(photogUser.email, photogUser.password);
        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Galerien');
        await page.locator('main').locator('a').filter({hasText: galleryName}).first().click();

        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible({timeout: 15000});

        await page.locator('button[title="Details & Metadaten"]').first().click();
        await expect(page.locator('h4:has-text("IPTC Metadaten")')).toBeVisible();

        const adminDownloadBtn = page.getByRole('link', { name: 'Admin Download' });
        await expect(adminDownloadBtn).toBeVisible();

        // target="_blank" entfernen, um den Download im gleichen Tab zu catchen
        await adminDownloadBtn.evaluate(node => node.removeAttribute('target'));

        const [download] = await Promise.all([
            page.waitForEvent('download', {timeout: 30000}),
            adminDownloadBtn.click()
        ]);

        expect(download.suggestedFilename()).toMatch(/\.jpg$/i);
    });

});
