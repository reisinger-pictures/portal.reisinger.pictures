import {APIRequestContext, expect, Page, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {SidebarHelper} from '../helpers/SidebarHelper';
import {ModalHelper} from '../helpers/ModalHelper';
import {UploadHelper} from '../helpers/UploadHelper';
import {FormHelper} from '../helpers/FormHelper';
import {Role} from '../../../src/logic/useUsers';

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
        const rolesRes = await request.get('/api/management/roles', {headers: {'Cookie': adminToken}});
        const roles = await rolesRes.json();
        const clientRoleId = roles.find((r: Role) => r.name === 'client').id;

        // Kunden auf das gewünschte Flatrate-Level hochstufen
        await request.put(`/api/management/users/${clientUser.id}`, {
            data: {
                role_ids: [clientRoleId],
                gallery_group_ids: [],
                gallery_ids: [],
                can_edit_metadata: false,
                flatrate_level: flatrateLevel
            },
            headers: {'Cookie': adminToken, 'Accept': 'application/json'}
        });

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
        await page.waitForTimeout(1000); // Backend I/O abwarten

        await request.post(`/api/management/galleries/${galleryId}/sync-access`, {
            data: {user_id: clientUser.id, action: 'attach'},
            headers: {'Cookie': adminToken, 'Accept': 'application/json'}
        });

        await auth.logout();
        return galleryName;
    }

    test('Test 1: ZIP Download dropdown restricts options and downloads correctly', async ({page, request}) => {
        const galleryName = await setupGalleryAndAssign(page, request, 'print');
        const auth = new AuthHelper(page);

        await auth.login(clientUser.email, clientUser.password);
        await page.locator('main').getByText(galleryName).first().click();
        
        // Requirement: Sicherstellen, dass das Bild für den Kunden korrekt geladen und angezeigt wird
        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible({timeout: 15000});
        await image.scrollIntoViewIfNeeded();

        const zipDropdownBtn = page.locator('div[role="button"]').filter({hasText: 'Alle herunterladen (.zip)'});
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

    test('Test 2: Single Download UI correctly shows Cart button for restricted resolutions', async ({
                                                                                                         page,
                                                                                                         request
                                                                                                     }) => {
        const galleryName = await setupGalleryAndAssign(page, request, 'print');
        const auth = new AuthHelper(page);

        await auth.login(clientUser.email, clientUser.password);
        await page.locator('main').getByText(galleryName).first().click();

        // Requirement: Sicherstellen, dass das Bild für den Kunden korrekt geladen und angezeigt wird
        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible({timeout: 15000});
        await image.scrollIntoViewIfNeeded();

        await page.getByRole('button', {name: 'Bild öffnen'}).first().click();
        await expect(page.locator('h4:has-text("Lizenz wählen")')).toBeVisible();

        // Werbung/Kampagne erfordert Original -> Der Kunde hat aber nur Print
        await page.locator('label').filter({hasText: 'Werbung / Kampagne'}).click();

        const cartButton = page.getByRole('button', {name: /In den Warenkorb/i});
        await expect(cartButton).toBeVisible();

        const downloadLink = page.getByRole('link', {name: 'Download', exact: true});
        await expect(downloadLink).toBeHidden();
    });

    test('Test 3: Single Download executes successfully when covered by flatrate', async ({page, request}) => {
        const galleryName = await setupGalleryAndAssign(page, request, 'original');
        const auth = new AuthHelper(page);

        await auth.login(clientUser.email, clientUser.password);
        await page.locator('main').getByText(galleryName).first().click();

        // Requirement: Sicherstellen, dass das Bild für den Kunden korrekt geladen und angezeigt wird
        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible({timeout: 15000});
        await image.scrollIntoViewIfNeeded();

        await page.getByRole('button', {name: 'Bild öffnen'}).first().click();
        await expect(page.locator('h4:has-text("Lizenz wählen")')).toBeVisible();

        await page.locator('label').filter({hasText: 'Werbung / Kampagne'}).click();

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
});
