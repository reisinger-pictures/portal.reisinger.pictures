import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { FormHelper } from '../helpers/FormHelper';
import { UploadHelper } from '../helpers/UploadHelper';

test.describe('AI Batch Edit Feature', () => {
    let helper: E2ESessionHelper;
    let photogUser = { email: '', password: '', id: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        photogUser = await helper.createIsolatedUser('photographer');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Admin user cannot see the AI Batch Edit button', async ({ page, request }) => {
        let adminUser = await helper.createIsolatedUser('admin');
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);

        await auth.login(photogUser.email, photogUser.password);
        await sidebar.openNewGalleryModal();
        const galleryName = `Admin Security ${Math.random().toString(36).substring(2, 10)}`;
        await form.fillGalleryModal({ name: galleryName, type: 'Delivery (Downloads)' });
        const resData = await modal.submitModal('Speichern');
        const galId = resData.gallery.id;
        if (galId) helper.trackGallery(galId);

        // Explicitly assign Admin to gallery so they can access the management view
        const validAdminToken = helper.getAdminToken();
        const rolesRes = await request.get('/api/management/roles', { headers: { 'Cookie': validAdminToken } });
        const rolesData = await rolesRes.json();
        const adminRoleId = rolesData.find((r: any) => r.name === 'admin').id;
        
        await request.put(`/api/management/users/${adminUser.id}`, {
            data: { role_ids: [adminRoleId], gallery_ids: [galId], gallery_group_ids: [], can_edit_metadata: false },
            headers: { 'Cookie': validAdminToken }
        });
        
        const galleryUrl = `/${resData.gallery.full_path}`;
        await auth.logout();

        // Login as Admin
        await auth.login(adminUser.email, adminUser.password);
        await page.goto(galleryUrl);
        await expect(page.locator('h1', { hasText: galleryName })).toBeVisible();
        
        // Assert Admin is in Management View
        await expect(page.locator('button[role="tab"]').filter({ hasText: 'Verwaltung' })).toBeVisible();
        
        // Assert Button is hidden
        await expect(page.getByRole('button', { name: 'KI Batch-Edit' })).toBeHidden();
    });

    test('Photographer cannot see AI Batch Edit on Selection galleries', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);

        await auth.login(photogUser.email, photogUser.password);
        await sidebar.openNewGalleryModal();
        const galleryName = `Selection Security ${Math.random().toString(36).substring(2, 10)}`;
        await form.fillGalleryModal({ name: galleryName, type: 'Auswahl (Ratings)' });
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        await page.goto(`/${resData.gallery.full_path}`);
        await expect(page.locator('h1', { hasText: galleryName })).toBeVisible();
        
        // Selection galleries do not support AI Batch Edit
        await expect(page.getByRole('button', { name: 'KI Batch-Edit' })).toBeHidden();
    });

    test('Photographer can use AI Batch Edit with mocked LM Studio', async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        const modal = new ModalHelper(page);
        const form = new FormHelper(page, modal);
        const upload = new UploadHelper(page);

        // Mock LM Studio /v1/models
        await page.route('http://127.0.0.1:1234/v1/models', async route => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({ data: [{ id: 'mocked-vision-model' }] })
            });
        });

        // Mock LM Studio /v1/chat/completions
        await page.route('http://127.0.0.1:1234/v1/chat/completions', async route => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify({
                    choices: [{
                        message: {
                            content: '{"title": "Mocked Title", "description": "Mocked Description", "keywords": "mock, ai", "detected_city": "Linz"}'
                        }
                    }]
                })
            });
        });

        // Mock Meilisearch location fallback
        await page.route('**/api/search/locations?type=city&q=Linz', async route => {
            await route.fulfill({
                status: 200,
                contentType: 'application/json',
                body: JSON.stringify([{ name: 'Linz', state: 'Oberösterreich', country: 'Österreich', iso_country: 'AT' }])
            });
        });

        await auth.login(photogUser.email, photogUser.password);
        await sidebar.openNewGalleryModal();
        const galleryName = `AI Test ${Math.random().toString(36).substring(2, 10)}`;
        await form.fillGalleryModal({ name: galleryName, type: 'Delivery (Downloads)' });
        const resData = await modal.submitModal('Speichern');
        if (resData?.gallery?.id) helper.trackGallery(resData.gallery.id);

        await page.goto(`/${resData.gallery.full_path}`);
        await upload.uploadSampleImage();

        const btn = page.getByRole('button', { name: 'KI Batch-Edit' });
        await expect(btn).toBeVisible();
        await btn.click();

        const batchModal = page.locator('.modal-open').filter({ hasText: 'KI Batch-Edit' });
        await expect(batchModal).toBeVisible();
        await expect(batchModal.locator('.badge-success')).toContainText('mocked-vision-model');

        // Click generate
        await batchModal.getByRole('button', { name: 'KI Generieren' }).first().click();

        // Check if inputs are filled with mocked data
        await expect(batchModal.locator('input[placeholder="Titel"]').first()).toHaveValue('Mocked Title');
        await expect(batchModal.locator('textarea[placeholder="Beschreibung"]').first()).toHaveValue('Mocked Description');

        // Save
        await batchModal.getByRole('button', { name: 'Speichern' }).first().click();
        await expect(page.locator('.toast')).toContainText('Gespeichert!');
    });
});
