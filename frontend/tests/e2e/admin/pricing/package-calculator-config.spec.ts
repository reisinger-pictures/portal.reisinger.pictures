import { test, expect, type Locator, type Page } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';
import { ToastHelper } from '../../helpers/ToastHelper';
import { withGlobalSettingsLock, SHOOTING_CALCULATOR_SETTINGS_LOCK } from '../../helpers/GlobalSettingsLock';

test.describe('Package Calculator Configuration (G2)', () => {
    let helper: E2ESessionHelper;
    let superAdmin = { email: '', password: '' };

    /**
     * The calculator settings are global, brand-scoped state, not per-user, and
     * the portal has a single brand — so every worker of a run reads and writes
     * the very same `settings` row. Saving them therefore races any other spec
     * that opens the calculator, and the assertion on the other side then sees a
     * different price. That is an order dependency, and a serial run hides it
     * completely.
     *
     * This spec sits on both sides of that race: it writes the settings *and*
     * reads them back. So each test runs its complete save-then-assert sequence
     * inside one exclusive critical section, and restores the values it found on
     * the way out — a run that ends here leaves the shared row as it started.
     */
    const withCalculatorSettings = async (body: () => Promise<void>) => {
        await withGlobalSettingsLock(SHOOTING_CALCULATOR_SETTINGS_LOCK, async () => {
            const settingsBefore = await helper.getShootingCalculatorSettings();
            try {
                await body();
            } finally {
                await helper.setShootingCalculatorSettings(settingsBefore);
            }
        });
    };

    /**
     * Log in and open the calculator settings card, returning its body.
     *
     * This deliberately stays *outside* the lock: waiting for the lock is charged
     * to the test's own timeout budget, so the critical section only covers the
     * shared-state access — the save and everything that reads it back. Nothing
     * asserted below depends on when this page happened to load.
     *
     * The values themselves are written through the settings form, because the
     * form is the subject of this spec; an API write would stop testing it.
     */
    const openCalculatorSettings = async (page: Page): Promise<Locator> => {
        await new AuthHelper(page).login(superAdmin.email, superAdmin.password);
        await new SidebarHelper(page).navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();

        const card = page.locator('main h2:has-text("Paket-Rechner Konfiguration")').first();
        await expect(card).toBeVisible();
        return card.locator('..').locator('..');
    };

    const field = (cardBody: Locator, label: string) =>
        cardBody.locator('.form-control').filter({ hasText: label }).locator('input[type="number"]');

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        superAdmin = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Admin can configure package calculator settings', { tag: ['@feature:admin:calculator'] }, async ({ page }) => {
        const cardBody = await openCalculatorSettings(page);

        // Every value below is typed into the *form*, and the form edits euros:
        // `mapApiToForm` divides the served cents by 100 and `mapFormToApi`
        // multiplies by 100 again. So 95 here is 95 €/h = 9500 cents on the wire,
        // and that is what the card is being asked to do — these assertions are
        // not euro values the API should have rejected. The API-side unit is
        // pinned separately in `manual-documents.spec.ts`, which writes through
        // `E2ESessionHelper` and therefore cannot speak euros at all.
        await withCalculatorSettings(async () => {
            // Saved and read back inside the same critical section, so what the
            // form shows is what this test just wrote — no other worker can have
            // replaced the value in between. It asserts the save/read round trip.
            await field(cardBody, 'Stundensatz').fill('95');
            await field(cardBody, 'Outdoor-Bilder').fill('12');
            await field(cardBody, 'Reportage-Aufschlag').fill('25');

            await cardBody.getByRole('button', { name: 'Einstellungen anwenden' }).click();
            await new ToastHelper(page).expectToast('Kalkulator-Einstellungen gespeichert');

            await expect(field(cardBody, 'Stundensatz')).toHaveValue('95');
            await expect(field(cardBody, 'Outdoor-Bilder')).toHaveValue('12');
            await expect(field(cardBody, 'Reportage-Aufschlag')).toHaveValue('25');
        });
    });

    test('Admin can set outdoor multiplier and verify it in the shooting calculator', { tag: ['@feature:admin:calculator'] }, async ({ page }) => {
        const cardBody = await openCalculatorSettings(page);

        // The save and the calculator verification share one critical section:
        // they read the same shared row, so a concurrent writer between them
        // would be exactly the bug this guard exists for.
        await withCalculatorSettings(async () => {
            await field(cardBody, 'Grundpreis').fill('50');
            await field(cardBody, 'Stundensatz').fill('80');
            await field(cardBody, 'Outdoor-Bilder').fill('20');
            await field(cardBody, 'Bilder pro Stunde').fill('6');

            await cardBody.getByRole('button', { name: 'Einstellungen anwenden' }).click();
            await new ToastHelper(page).expectToast('Kalkulator-Einstellungen gespeichert');

            // Wait for the form to reflect the saved hourly rate (ensures SWR cache is fresh)
            await expect(field(cardBody, 'Stundensatz')).toHaveValue('80');
            // Navigate to manual offer page and open calculator
            await new SidebarHelper(page).navigateTo('Manuelles Angebot');

            await page.locator('button:has-text("Paket-Kalkulator")').click();
            const calcModal = page.locator('.modal-open');
            await expect(calcModal).toBeVisible();

            // Enter values: 90 min, 15 images
            await calcModal.locator('.form-control', { hasText: 'Dauer (Min.)' }).locator('input').fill('90');
            await calcModal.locator('.form-control', { hasText: 'Inkl. Bilder' }).locator('input').fill('15');

            // Activate outdoor
            await calcModal.locator('label').filter({ hasText: 'Outdoor-Shooting' }).locator('input[type="checkbox"]').check();

            // Calculate & add
            await calcModal.getByRole('button', { name: 'Berechnen & Hinzufügen' }).click();
            await expect(calcModal).toBeHidden();

            // Verify result. The calculator computes in cents since the
            // 2026-09-28 cents decision, and the modal converts to euros for the
            // invoice line, so the assertion below is the same price as before
            // the unit change, computed on a different scale:
            // 5000 + 12000 + ((8000/20)*15 = 6000) = 23000 → psych 22900 = 229,00 €.
            const itemTitleInput = page.locator('.form-control').filter({ hasText: 'Titel / Name' }).locator('input').first();
            await expect(itemTitleInput).toHaveValue('Individuelles Shooting-Paket', { timeout: 10000 });

            await expect(page.locator('.text-2xl.font-bold').filter({ hasText: 'Gesamtbetrag' })).toContainText('229,00 €');
        });
    });
});
