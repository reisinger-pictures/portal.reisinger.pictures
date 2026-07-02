import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Quote Cart Restore Workflow', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('client');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Navigating with quote_token fetches data, populates cart, and cleans URL', async ({ page, request }) => {
        const auth = new AuthHelper(page);
        await auth.login(testUser.email, testUser.password);

        // Reale Token-Generierung via Admin API (Real-Flow)
        const quoteRes = await request.post('/api/management/orders/quote-link', {
            data: { photo_ids: ['mocked-photo-1', 'mocked-photo-2'], custom_price: 150000 },
            headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
        });
        const quoteData = await quoteRes.json();
        const quoteToken = quoteData.link.split('quote_token=')[1];

        // Simuliere den Klick auf den Link: SPA-navigiere zum Warenkorb, dann token per URL setzen
        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Warenkorb');
        await page.evaluate((token) => {
            const url = new URL(window.location.href);
            url.searchParams.set('quote_token', token);
            window.history.pushState({}, '', url.toString());
            window.dispatchEvent(new PopStateEvent('popstate'));
        }, quoteToken);

        // UI-First Assertions
        const toast = page.locator('.toast');
        await expect(toast).toBeVisible();
        await expect(toast).toContainText('Angebot aus Link wiederhergestellt.');

        // Prüfe, ob das UI das Item anzeigt
        await expect(page.getByRole('button', { name: 'Entfernen' })).toHaveCount(2);
        
        // Da isQuote: true gesetzt sein muss, erwarten wir --- €
        // Da isQuote: false gesetzt ist, wird der Preis angezeigt

        // Gesamtsumme muss 0.00 € sein
        const totalAmount = page.locator('.text-3xl.font-mono.text-primary');
        await expect(totalAmount).toHaveText('1500.00 €');

        // Prüfe ob die URL von UX-Gründen bereinigt wurde (ohne Token)
        await expect(page).toHaveURL(/.*\/cart$/);
    });
});
