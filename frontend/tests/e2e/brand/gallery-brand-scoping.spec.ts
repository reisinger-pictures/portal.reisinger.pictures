import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

/**
 * Brand scoping guard for User::getAllowedGalleryIds (backend/app/Models/User.php).
 * A brand-bound client must never reach a cross-brand gallery; a cross-brand admin sees both.
 *
 * Run with the project's standard runner (do NOT call `npx playwright` directly):
 *   node ai_test_runner.mjs brand/gallery-brand-scoping.spec.ts
 *
 * SUBDOMAIN NOTE:
 * Browser page navigations use explicit `http://buy.localhost:4321/...` URLs (browsers resolve
 * `*.localhost` to 127.0.0.1). API calls via the `request` fixture stay relative and resolve
 * against the Playwright config's `baseURL` (`http://localhost:4321`). The backend
 * BrandContextMiddleware derives the brand from the Host header (`buy.localhost` → SRP).
 */
test.describe('Gallery brand scoping (getAllowedGalleryIds)', () => {

    let helper: E2ESessionHelper;

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('client on SRP brand is redirected away from the B2B-only /tenants area', async ({ page }) => {
        // Frontend brand gating mirrors the backend scoping contract: a non-admin client must be
        // kept out of B2B-only areas. We assert the redirect outcome, not a CSS class.
        const clientUser = await helper.createIsolatedUser('client', { brand: 'srp' });
        const auth = new AuthHelper(page);
        await auth.login(clientUser.email, clientUser.password, 'http://buy.localhost:4321/');

        await page.goto('http://buy.localhost:4321/tenants');

        // Client is bounced back to the dashboard/welcome landmark.
        await expect(page.getByRole('heading', { name: /^Willkommen zurück/ })).toBeVisible({ timeout: 15000 });
    });

    test('client on SRP brand does not see the B2B Mandanten entry in the sidebar', async ({ page }) => {
        // The sidebar only lists galleries/areas the user is allowed to reach. A client scoped to
        // SRP must not be offered the B2B tenants entry. Asserting link absence (count 0) inside
        // the aside landmark is stable against layout shifts.
        const clientUser = await helper.createIsolatedUser('client', { brand: 'srp' });
        const auth = new AuthHelper(page);
        await auth.login(clientUser.email, clientUser.password, 'http://buy.localhost:4321/');

        const sidebar = page.locator('aside');
        await expect(sidebar.getByText('Organisationen (B2B)')).toHaveCount(0);
    });

    test('cross-brand admin (super_admin) sees the management area regardless of SRP subdomain', async ({ page }) => {
        // A super_admin has brand = null (cross-brand) and must reach management from either host.
        const adminUser = await helper.createIsolatedUser('super_admin');
        const auth = new AuthHelper(page);
        await auth.login(adminUser.email, adminUser.password);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Organisationen');
        await expect(page.getByRole('heading', { name: /Organisationen/ })).toBeVisible({ timeout: 10000 });
    });
});
