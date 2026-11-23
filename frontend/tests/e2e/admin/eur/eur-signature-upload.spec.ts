import { test, expect, type Page, type Locator } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

/**
 * FRONTEND-ONLY E2E coverage for the "unsigned PDF → external signing →
 * re-upload with content binding" flow. The backend contract is implemented in
 * parallel; these tests exercise the UI as specified there:
 *   POST /api/management/eur/documents/corrections            → 201 {document}
 *   POST /api/management/eur/documents/{id}/signed            → 200 / 422
 *   GET  /api/management/eur/documents/{id}/signed/download   → PDF bytes
 *
 * Navigation is SPA-only (sidebar + tabs). All views are scoped to <main>
 * (owner decision D-10). This spec was written by inspection: Playwright cannot
 * run on the development host, so its gates belong to CI.
 */
test.describe('EAR Belege — externe Signatur mit Inhaltsprüfung', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    async function openBelege(page: Page) {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(superAdmin.email, superAdmin.password);
        await sidebar.navigateTo('EA Rechnung');
        const main = page.getByRole('main');
        await page.getByRole('tab', { name: 'Belege', exact: true }).click();
        await expect(main.getByRole('heading', { name: 'Belege', exact: true })).toBeVisible();
        return main;
    }

    async function createCorrection(page: Page, main: Locator) {
        await expect(main.getByText('Korrektur-PDF', { exact: true })).toBeVisible();
        await expect(main.getByText(/Inhaltsprüfung/)).toBeVisible();
        await expect(main).toContainText('keine qualifizierte elektronische Signatur');

        const created = page.waitForResponse((response) =>
            response.request().method() === 'POST'
            && response.url().includes('/api/management/eur/documents/corrections'));
        await main.getByRole('button', { name: 'Korrektur-PDF erstellen' }).click();
        const response = await created;
        expect(response.status()).toBe(201);
        const body = await response.json() as { document: { id: number; status: string } };
        expect(body.document.status).toBe('unsigned');
        await expect(main.getByRole('link', { name: 'Unsigniertes Korrektur-PDF herunterladen' }))
            .toHaveAttribute('href', `/api/management/eur/documents/${body.document.id}/download`);
        return body.document.id;
    }

    test('Korrektur-PDF erstellen, extern signieren und signiertes PDF hochladen', { tag: ['@feature:eur-signature'] }, async ({ page }) => {
        const main = await openBelege(page);
        const documentId = await createCorrection(page, main);

        const row = main.getByRole('row').filter({ hasText: 'unsigned' }).first();
        const signedInput = row.getByLabel('Signiertes PDF hochladen');
        await expect(row.getByText('Signiertes PDF hochladen', { exact: true })).toBeVisible();

        // D-84: the upload now requires a real CMS/PAdES signature over the
        // portal's bytes. A test-only backend route (local/testing only) signs
        // the exact downloaded PDF with a throwaway self-signed certificate;
        // no production endpoint can produce such a signature.
        // ProtectApiMutations guards every API POST: the browser context has
        // the JWT cookie, so the request needs a same-origin Origin and the
        // decoded XSRF-TOKEN header, exactly like the SPA's apiMutate helper.
        const csrf = (await page.context().cookies()).find((cookie) => cookie.name === 'XSRF-TOKEN');
        const signedResponse = await page.request.post(`/api/test/eur/sign-document/${documentId}`, {
            headers: {
                Origin: 'http://localhost:4321',
                ...(csrf ? {'X-XSRF-TOKEN': decodeURIComponent(csrf.value)} : {}),
            },
        });
        expect(signedResponse.status()).toBe(200);
        const signed = await signedResponse.body();

        const uploaded = page.waitForResponse((response) =>
            response.request().method() === 'POST'
            && response.url().includes(`/api/management/eur/documents/${documentId}/signed`));
        await signedInput.setInputFiles({
            name: 'signiert.pdf',
            mimeType: 'application/pdf',
            buffer: signed,
        });
        expect((await uploaded).status()).toBe(200);

        const link = main.getByRole('link', { name: 'Signiertes PDF herunterladen' });
        await expect(link).toBeVisible();
        await expect(link).toHaveAttribute('href', `/api/management/eur/documents/${documentId}/signed/download`);
    });

    test('fehlgeschlagene Inhaltsprüfung zeigt Fehler und nächsten Schritt', { tag: ['@feature:eur-signature'] }, async ({ page }) => {
        const main = await openBelege(page);
        const documentId = await createCorrection(page, main);

        const row = main.getByRole('row').filter({ hasText: 'unsigned' }).first();
        const rejected = page.waitForResponse((response) =>
            response.request().method() === 'POST'
            && response.url().includes(`/api/management/eur/documents/${documentId}/signed`));
        await row.getByLabel('Signiertes PDF hochladen').setInputFiles({
            name: 'falsch-signiert.pdf',
            mimeType: 'application/pdf',
            buffer: Buffer.from('%PDF-1.4\n fremder Inhalt\n%%EOF\n', 'utf-8'),
        });
        const response = await rejected;
        expect(response.status()).toBe(422);
        const body = await response.json() as { error?: unknown; action?: unknown };
        expect(typeof body.error).toBe('string');
        expect(typeof body.action).toBe('string');

        const alert = row.getByRole('alert');
        await expect(alert).toBeVisible();
        await expect(alert).toContainText(String(body.error));
        await expect(alert).toContainText(String(body.action));
        await expect(row.getByRole('link', { name: 'Signiertes PDF herunterladen' })).toHaveCount(0);
    });
});
