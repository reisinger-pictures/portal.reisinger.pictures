import { test, expect, type Page } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { browserAuthHeadersFromPage } from '../../helpers/BrowserAuthHeaders';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

/**
 * D-93 / EUE-051: the GWG hint is shown only for a business asset in its
 * acquisition year. Both low-cost assets are created via the API (the SPA has
 * no asset dialog) before the panel mounts, because SWR does not revalidate on
 * focus. Navigation is SPA-only and all locators are scoped to <main> and the
 * `ea-assets-panel` testid (D-10). Playwright cannot run on the development
 * host (RAM policy), so CI is the gate for this spec.
 */
test.describe('EAR · GWG nur im Erwerbsjahr (D-93)', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    const year = 2026;
    const hint = '• Unter GWG-Grenze (1000€) - evtl. sofort im A-Blatt erfassen';

    async function login(page: Page): Promise<Record<string, string>> {
        await new AuthHelper(page).login(superAdmin.email, superAdmin.password);
        return {...await browserAuthHeadersFromPage(page), 'Accept': 'application/json'};
    }

    async function createLowCostAsset(
        page: Page,
        headers: Record<string, string>,
        name: string,
        businessShare: number,
    ): Promise<void> {
        const response = await page.request.post('/api/management/ea/assets', {
            headers,
            data: {
                name,
                invoice_no: `R-${name}`,
                supplier: 'Lieferant',
                purchase_date: `${year}-01-15`,
                cost_cents: 80000,
                useful_life_years: 3,
                business_share: businessShare,
                method: 'linear',
            },
        });
        expect(response.status(), await response.text()).toBe(201);
    }

    test('GWG-Badge und Hinweis nur beim Betriebsgut im Erwerbsjahr', { tag: ['@feature:ea-gwg-rule'] }, async ({ page }) => {
        const headers = await login(page);
        const suffix = Date.now();
        const privateName = `D93-Privat-${suffix}`;
        const businessName = `D93-Betrieb-${suffix}`;

        await createLowCostAsset(page, headers, privateName, 30);
        await createLowCostAsset(page, headers, businessName, 100);

        await new SidebarHelper(page).navigateTo('EA Rechnung');
        await page.getByRole('tab', { name: 'Anlagen', exact: true }).click();
        const panel = page.getByRole('main').getByTestId('ea-assets-panel');
        await expect(panel.getByRole('heading', { name: 'Anlagen und Abschreibung' })).toBeVisible();

        const privateRow = panel.getByRole('row').filter({ hasText: privateName });
        const businessRow = panel.getByRole('row').filter({ hasText: businessName });
        await expect(privateRow).toBeVisible();
        await expect(businessRow).toBeVisible();

        // Privatanlagen sind immer linear: kein GWG-Badge, kein GWG-Hinweis.
        await expect(privateRow.getByText('GWG', { exact: true })).toHaveCount(0);
        await expect(privateRow.getByTestId('ea-gwg-hint')).toHaveCount(0);
        await expect(privateRow.getByText('Privat', { exact: true })).toBeVisible();

        // Betriebsgut im Erwerbsjahr unter der Grenze: gepinnter Hinweis wörtlich.
        await expect(businessRow.getByText('GWG', { exact: true })).toBeVisible();
        await expect(businessRow.getByTestId('ea-gwg-hint')).toHaveText(hint);
    });
});
