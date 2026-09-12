import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('Cart Persistence', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '', id: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('client');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    // User-Flow statt localStorage-Injektion (frontend/AGENTS.md, "localStorage Injection"):
    // Der Warenkorb wird API-basiert über den realen Admin-Quote-Link befüllt (derselbe
    // Code-Pfad wie im Quote-Restore-Flow, kein hardcodierter `rp_cart_*`-Storage-Key).
    // Danach wird die Seite vollständig neu geladen und die Re-Hydrierung aus dem
    // user-scoped Persistenz-Key geprüft. Die Zod-Validierung korrupter localStorage-
    // Inhalte ist auf Unit-Ebene abgedeckt (src/logic/__tests__/cartLogic.test.ts).
    test('Cart items persist across a full page reload', { tag: ['@feature:client:cart'] }, async ({ page, request }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);
        await auth.login(testUser.email, testUser.password);

        // API-Seeding des Warenkorbs über den realen Admin-Quote-Link.
        const quoteRes = await request.post('/api/management/orders/quote-link', {
            data: { photo_ids: ['mocked-photo-1', 'mocked-photo-2'], custom_price: 150000 },
            headers: { 'Cookie': helper.getAdminToken(), 'Accept': 'application/json' }
        });
        expect(quoteRes.ok()).toBeTruthy();
        const quoteData = await quoteRes.json();
        const quoteToken = quoteData.link.split('quote_token=')[1];

        // SPA-Navigation zum Warenkorb, Quote-Token per History-API setzen (Real-Flow).
        await sidebar.navigateTo('Warenkorb');
        await page.evaluate((t) => {
            const url = new URL(window.location.href);
            url.searchParams.set('quote_token', t);
            window.history.pushState({}, '', url.toString());
            window.dispatchEvent(new PopStateEvent('popstate'));
        }, quoteToken);

        await expect(page.locator('.toast')).toContainText('Angebot aus Link wiederhergestellt.');
        await expect(page.getByRole('button', { name: 'Entfernen' })).toHaveCount(2);
        await expect(page.locator('.text-3xl.font-mono.text-primary')).toHaveText('1500.00 €');

        // Persistenz: vollständiger Reload — Items müssen aus dem user-scoped
        // Cart-Key re-hydriert werden (der quote_token ist zu diesem Zeitpunkt
        // bereits aus der URL entfernt, also kein erneutes API-Seeding).
        await page.reload();
        await expect(page.locator('h1:has-text("Dein Warenkorb")')).toBeVisible();
        await expect(page.getByRole('button', { name: 'Entfernen' })).toHaveCount(2);
        await expect(page.locator('.text-3xl.font-mono.text-primary')).toHaveText('1500.00 €');
    });
});
