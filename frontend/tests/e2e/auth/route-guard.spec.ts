import {test, expect} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';

// E2E-01 §3 (Berechtigungs-Grenzen / IDOR) + §5 (Accessibility-Stichprobe).
// Grounded in `src/App.tsx` (ProtectedRoute → Navigate "/") und `src/ui/ProtectedDashboard.tsx` (Rollen-Weiche).

// Geschützte Routen quer durch alle Roll-Tiers (Client / Photographer / Admin / Super-Admin).
const PROTECTED_ROUTES = [
    '/cart',
    '/orders',
    '/profile',
    '/galleries',
    '/settings',
    '/admin-orders',
    '/admin-products',
    '/my-payouts',
];

test.describe('Route Guards & IDOR Boundaries', () => {
    test.describe('Unauthenticated access is redirected to home', () => {
        for (const route of PROTECTED_ROUTES) {
            const tags = route === PROTECTED_ROUTES[0] ? ['@smoke', '@feature:auth'] : ['@regression', '@feature:auth'];
            test(`Unauthenticated user is redirected from ${route} to home`, { tags }, async ({page}) => {
                await page.context().clearCookies();
                await page.goto(route);

                // ProtectedRoute → <Navigate to="/" replace/>
                await expect(page).toHaveURL(/localhost:4321\/?$/);

                // Anonymous-Home-Oberfläche sichtbar (globale Suche im Header).
                await expect(page.locator('input[placeholder="Suche in allen Galerien..."]')).toBeVisible({timeout: 10000});
            });
        }
    });

    test.describe('Authenticated non-admin (IDOR boundary)', () => {
        let helper: E2ESessionHelper;
        let clientUser = {email: '', password: '', id: ''};

        test.beforeEach(async ({request}) => {
            helper = new E2ESessionHelper(request);
            clientUser = await helper.createIsolatedUser('client');
        });

        test.afterEach(async () => {
            if (helper) await helper.teardown();
        });

        test('Client opening an admin route directly sees client dashboard, not admin data', { tags: ['@smoke', '@feature:auth'] }, async ({page}) => {
            const auth = new AuthHelper(page);
            await auth.login(clientUser.email, clientUser.password);

            // Direkte URL auf eine Admin-Route (IDOR-Versuch).
            await page.goto('/admin-orders');

            // ProtectedDashboard-Rollen-Weiche: Client erhält ClientDashboard, nie ManagementDashboard.
            // → Admin-Bestelldaten werden weder gefetcht noch gerendert.
            await expect(page.locator('h1', {hasText: 'Bestellungen & Anfragen'})).toHaveCount(0);
            await expect(page.getByRole('heading', {name: /^Willkommen zurück/})).toBeVisible({timeout: 15000});
            await expect(page.getByText('Aktuell sind keine privaten Galerien für dich freigeschaltet.').first()).toBeVisible();
        });

        test('Login button has an accessible name (a11y spot-check, E2E-01 §5)', { tags: ['@smoke', '@feature:auth'] }, async ({page}) => {
            await page.context().clearCookies();
            await page.goto('/');

            const loginButton = page.getByRole('button', {name: 'Login'}).first();
            await expect(loginButton).toBeVisible({timeout: 10000});
            await expect(loginButton).toHaveAccessibleName('Login');
        });
    });
});
