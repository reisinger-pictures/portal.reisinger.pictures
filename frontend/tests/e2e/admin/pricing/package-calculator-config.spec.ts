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
     *
     * ## Observed flake (2026-11-22)
     *
     * `Admin can set outdoor multiplier and verify it in the shooting calculator`
     * (this file, line 96) failed in CI push run 45063144821 with
     * `Gesamtbetrag: 319,00 €` instead of `229,00 €`, three times in a row, while
     * the pull_request run of the identical commit was green. 319,00 € is exactly
     * the total for `calc_outdoor_images_per_hour = 8` (the value left behind by
     * `manual-documents.spec.ts`, also the calculator's own default), not 20.
     *
     * Root cause: `CalculatorSettingsCard` resets the whole form from SWR when
     * `/api/settings/license-terms` arrives. If that answer lands *after* the test
     * filled the fields, it overwrites `Outdoor-Bilder` with 8 before the submit —
     * while `Stundensatz` still reads 80 because the previous test also used 80,
     * so the old post-save form assertion could not detect it. Waiting for the form
     * to be hydrated from the API before typing, and checking the effective values
     * on the same endpoint after saving, closes that window.
     *
     * ## Observed flake (2026-11-23)
     *
     * `Admin can configure package calculator settings` (this file) failed in
     * CI push run 45132699736, job 137551622438, `E2E (Playwright) — Desktop
     * (1/3)`, with `toHaveValue(95)` receiving `80` three times in a row; the
     * identical test was green in the prior run 45130935340. 80 is the hourly
     * rate left on the shared brand-scoped row by another test/worker. Unlike
     * the second test, this one filled the form before the initial
     * `/api/settings/license-terms` SWR answer had landed, so the card's
     * `reset(mapApiToForm(terms))` overwrote the typed 95 with the stale 80
     * before the submit. Both tests now wait for the terms *response* after
     * the in-lock reload (a field poll alone passes too early when the frozen
     * value equals the form default), and the first also checks the exact saved
     * values on the endpoint after saving.
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
            // The card resets every field from SWR when the terms answer arrives.
            // Filling before that reset lands makes the submit save the old values
            // — and 80 (the value left on the shared row) is exactly what the
            // failed run saw instead of 95. Read the frozen server state, reload
            // inside the lock so SWR hydrates from it, and wait for both the
            // network answer and the field that showed the stale value before
            // typing anything. reset() hydrates the whole form at once, so one
            // synchronized field is enough to make all three safe to fill.
            const before = await helper.getShootingCalculatorSettings();
            const termsLoaded = page.waitForResponse(
                response => response.url().includes('/api/settings/license-terms') && response.ok(),
            );
            await page.reload();
            await termsLoaded;
            await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();
            await expect.poll(
                () => field(cardBody, 'Stundensatz').inputValue(),
                { message: 'Das Formular muss die geladenen Einstellungen zeigen, bevor es bearbeitet wird.' },
            ).toBe(String(before.calc_hourly_rate / 100));

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

            // The form assertions only prove local state; the endpoint is what
            // the calculator consumes. 95 €/h = 9500 cents, 25 % surcharge =
            // factor 1.25 on the wire — the euro form unit is intentional.
            await expect.poll(
                () => helper.getShootingCalculatorSettings(),
                { message: 'Die gespeicherten Werte müssen am Kalkulator-Endpunkt ankommen.' },
            ).toMatchObject({
                calc_hourly_rate: 9500,
                calc_outdoor_images_per_hour: '12',
                calc_flatrate_multiplier: '1.25',
            });
        });
    });

    test('Admin can set outdoor multiplier and verify it in the shooting calculator', { tag: ['@feature:admin:calculator'] }, async ({ page }) => {
        const cardBody = await openCalculatorSettings(page);

        // The save and the calculator verification share one critical section:
        // they read the same shared row, so a concurrent writer between them
        // would be exactly the bug this guard exists for.
        await withCalculatorSettings(async () => {
            // The card resets every field from SWR when the terms answer arrives.
            // Typing before that reset lands makes the submit save the old values —
            // and a coincidentally identical hourly rate hides it. Wait for the
            // exact field this test changes to show the value read from the API.
            const before = await helper.getShootingCalculatorSettings();
            // SWR can still hold a value written by a previous test (the form
            // showed 12 while the endpoint already returned 8). Reload inside
            // the lock so the form hydrates from the frozen server state. Wait
            // for the terms *response* as well: when the frozen value equals
            // the form default (8), the field poll below passes before hydration
            // has actually landed, and a late reset then saves 8 instead of 20.
            const termsLoaded = page.waitForResponse(
                response => response.url().includes('/api/settings/license-terms') && response.ok(),
            );
            await page.reload();
            await termsLoaded;
            await expect(page.locator('h1:has-text("System-Einstellungen")')).toBeVisible();
            await expect.poll(
                () => field(cardBody, 'Outdoor-Bilder').inputValue(),
                { message: 'Das Formular muss die geladenen Einstellungen zeigen, bevor es bearbeitet wird.' },
            ).toBe(before.calc_outdoor_images_per_hour ?? '8');

            await field(cardBody, 'Grundpreis').fill('50');
            await field(cardBody, 'Stundensatz').fill('80');
            await field(cardBody, 'Outdoor-Bilder').fill('20');
            await field(cardBody, 'Bilder pro Stunde').fill('6');

            await cardBody.getByRole('button', { name: 'Einstellungen anwenden' }).click();
            await new ToastHelper(page).expectToast('Kalkulator-Einstellungen gespeichert');

            // Assert the effective value on the very endpoint the calculator
            // consumes. The form showing 80 only proves the local form state.
            await expect.poll(
                () => helper.getShootingCalculatorSettings(),
                { message: 'Die gespeicherten Werte müssen am Kalkulator-Endpunkt ankommen.' },
            ).toMatchObject({
                calc_base_price: 5000,
                calc_hourly_rate: 8000,
                calc_images_per_hour: '6',
                calc_outdoor_images_per_hour: '20',
            });
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
