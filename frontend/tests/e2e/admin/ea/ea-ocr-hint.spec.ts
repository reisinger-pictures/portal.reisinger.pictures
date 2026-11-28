import { test, expect, type Page, type Locator } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

/**
 * #54 — E2E-Abdeckung für den OCR-Hinweis (D-82). Der Test nutzt bewusst das
 * ein PDF eines Korrektur-Belegs: der echte Endpunkt liefert dafür
 * `supported: false` mit einem Grund, den die UI in DERSELBEN Zeile anzeigt.
 *
 * Flaky-Historie (siehe #54, CI 45221502609 / 45227149316 / 45229695573 /
 * 45231144898): ein has-Locator auf <main> statt auf der Zeile fand den
 * Status-Alert nicht; `.first()`-Zeilenlocators liefen nach dem SWR-Refetch
 * ins Leere. Die Zeile wird deshalb über den stabilen `Öffnen`-Link
 * (href enthält die Dokument-ID) als `ancestor::tr` bestimmt, wie in der
 * bewährten Signatur-Spec. Playwright läuft hostbedingt nur in CI (RAM-Policy).
 */
test.describe('EAR Belege — OCR-Hinweis (#54)', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    async function openBelege(page: Page): Promise<Locator> {
        await new AuthHelper(page).login(superAdmin.email, superAdmin.password);
        await new SidebarHelper(page).navigateTo('EA Rechnung');
        const main = page.getByRole('main');
        await page.getByRole('tab', { name: 'Belege', exact: true }).click();
        await expect(main.getByRole('heading', { name: 'Belege', exact: true })).toBeVisible();
        return main;
    }

    function documentRow(main: Locator, documentId: number): Locator {
        return main
            .locator(`a[href="/api/management/ea/documents/${documentId}/download"]`)
            .locator('xpath=ancestor::tr');
    }

    test('OCR-Hinweis zeigt die echte Antwort in der Belegzeile', { tag: ['@feature:ea-signature'] }, async ({ page }) => {
        const main = await openBelege(page);

        const created = page.waitForResponse((response) =>
            response.request().method() === 'POST'
            && response.url().endsWith('/api/management/ea/documents/corrections'));
        await main.getByRole('button', { name: 'Korrektur-PDF erstellen' }).click();
        const createResponse = await created;
        expect(createResponse.status()).toBe(201);
        const body = await createResponse.json() as { document: { id: number } };
        const documentId = body.document.id;

        const row = documentRow(main, documentId);
        await expect(row).toHaveCount(1);

        const ocr = page.waitForResponse((response) =>
            response.request().method() === 'POST'
            && response.url().endsWith(`/api/management/ea/documents/${documentId}/ocr`));
        await row.getByRole('button', { name: 'OCR-Hinweise' }).click();
        const ocrResponse = await ocr;
        expect(ocrResponse.status()).toBe(200);
        const ocrBody = await ocrResponse.json() as {
            ocr: { supported: boolean; reason?: string };
        };
        expect(ocrBody.ocr.supported).toBe(false);
        expect(ocrBody.ocr.reason).toContain('OCR ist nur für Bildbelege');

        // Der Hinweis steht in DERSELBEN Zeile — nicht in einem globalen Alert.
        await expect(row).toContainText(ocrBody.ocr.reason ?? '');
    });
});
