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
 * HOSTNAME-MOCK NOTE:
 * These tests use the page.addInitScript hostname-mock pattern from brand-isolation.spec.ts to
 * flip the FRONTEND brand (window.location.hostname -> 'all-the.rest'). This drives React-side
 * gating (sidebar visibility, route guards). It does NOT change the backend brand, which
 * BrandContextMiddleware derives from the request host (always localhost = B2B on the single dev
 * instance). Deep cross-brand gallery ACCESS (an ATR user blocked from a B2B-only gallery at the
 * data layer) therefore needs brand-bound fixtures + the 2-Vite setup — see the .fixme spec.
 * Here we assert the observable navigation/sidebar outcomes (URL redirects, absent links) per
 * AGENTS.md, never brittle CSS classes.
 */
test.describe('Gallery brand scoping (getAllowedGalleryIds)', () => {
    let helper: E2ESessionHelper;

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('client on ATR brand is redirected away from the B2B-only /tenants area', async ({ page }) => {
        // Frontend brand gating mirrors the backend scoping contract: a non-admin client must be
        // kept out of B2B-only areas. We assert the redirect outcome, not a CSS class.
        await page.addInitScript(() => {
            Object.defineProperty(window.location, 'hostname', {
                get: () => 'all-the.rest',
                configurable: true,
            });
        });

        const clientUser = await helper.createIsolatedUser('client');
        const auth = new AuthHelper(page);
        await auth.login(clientUser.email, clientUser.password);

        await page.goto('/tenants');

        // Client is bounced back to the dashboard/welcome landmark.
        await expect(page.getByRole('heading', { name: /^Willkommen zurück/ })).toBeVisible({ timeout: 15000 });
    });

    test('client on ATR brand does not see the B2B Mandanten entry in the sidebar', async ({ page }) => {
        // The sidebar only lists galleries/areas the user is allowed to reach. A client scoped to
        // ATR must not be offered the B2B tenants entry. Asserting link absence (count 0) inside
        // the aside landmark is stable against layout shifts.
        await page.addInitScript(() => {
            Object.defineProperty(window.location, 'hostname', {
                get: () => 'all-the.rest',
                configurable: true,
            });
        });

        const clientUser = await helper.createIsolatedUser('client');
        const auth = new AuthHelper(page);
        await auth.login(clientUser.email, clientUser.password);

        const sidebar = page.locator('aside');
        await expect(sidebar.getByText('Mandanten (B2B)')).toHaveCount(0);
    });

    test('cross-brand admin (super_admin) sees the management area regardless of mocked brand', async ({ page }) => {
        // A super_admin has brand = null (cross-brand) and must reach management from either host.
        await page.addInitScript(() => {
            Object.defineProperty(window.location, 'hostname', {
                get: () => 'all-the.rest',
                configurable: true,
            });
        });

        const adminUser = await helper.createIsolatedUser('super_admin');
        const auth = new AuthHelper(page);
        await auth.login(adminUser.email, adminUser.password);

        const sidebar = new SidebarHelper(page);
        await sidebar.navigateTo('Mandanten (B2B)');
        await expect(page.locator('h1:has-text("Mandanten")').first()).toBeVisible({ timeout: 10000 });
    });
});
