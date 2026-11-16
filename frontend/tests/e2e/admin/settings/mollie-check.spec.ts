import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';
import { mollieConfiguredApiKey } from '../../helpers/MollieE2E';

/**
 * Super-Admin „Mollie-Verbindungstest" (`POST /api/management/settings/mollie-check`).
 *
 * New online payments go through Mollie's hosted checkout (owner decision
 * D-63); the card answers the question an operator actually has: *can this
 * installation take a payment right now, and in which mode?*
 *
 * Was diese Spec NICHT behauptet, und warum:
 * Fünf Befunde sind immer vorhanden, aber ihr Status hängt an der Umgebung.
 * `webhook_url` und `readiness` sind in der E2E-Umgebung bewusst `fail`:
 * `backend/.env.e2e` lässt `MOLLIE_WEBHOOK_URL` leer (kein öffentlicher Tunnel,
 * die vier provider-abhängigen Specs skippen deshalb), und ohne HTTPS-Webhook
 * verweigert der Server die Zahlungsanlage mit `503`. `account` ist eine
 * echte, authentifizierte Provider-Abfrage und damit netzabhängig. Diese Spec
 * assertiert deshalb nur, was jede Umgebung garantiert: die Karte existiert
 * für den Super-Admin und fehlt für einen normalen Admin, nichts läuft vor dem
 * Klick, alle fünf Befunde erscheinen in der dokumentierten Reihenfolge mit
 * nicht-leerer Meldung und einem Status aus `ok|warn|fail`, und der Schlüssel
 * taucht nirgends auf der Seite auf.
 *
 * Die Zweige jedes einzelnen Befunds (fehlender Schlüssel, HTTP-Webhook,
 * denylistete Methode, Provider-401, gesunde Konfiguration) sind in
 * `backend/tests/Feature/MolliePaymentCheckControllerTest.php` gepinnt, wo ein
 * gefakter Mollie-Client jeden Zweig deterministisch erzeugt.
 */

/** Die fünf Befunde in der vertraglich festen Reihenfolge. */
const FINDING_KEYS = [
    'key_mode',
    'webhook_url',
    'methods',
    'account',
    'readiness',
] as const;

const ALLOWED_STATUSES = ['ok', 'warn', 'fail'];

test.describe('Super Admin Mollie-Verbindungstest', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super-Admin prüft die Mollie-Verbindung; Prüfungen laufen nur auf Klick', { tag: ['@smoke', '@feature:settings'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Einstellungen');

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'System-Einstellungen', exact: true })).toBeVisible();

        const card = main.getByTestId('mollie-check-card');
        await expect(card).toBeVisible();
        await expect(card.getByRole('heading', { name: 'Mollie-Verbindungstest', exact: true })).toBeVisible();

        const button = card.getByTestId('mollie-check-button');
        await expect(button).toBeVisible();

        // Vertrag: der Selbsttest läuft NUR auf Klick. Vor dem Klick darf keine
        // einzige Befundzeile existieren — auch keine Provider-Abfrage.
        await expect(main.getByTestId('mollie-check-result')).toHaveCount(0);

        await button.click();

        // Immer alle fünf Befunde in fester Reihenfolge. Über `main` gescopt,
        // nicht über die Karte: wo die Zeilen im Baum hängen, ist Sache der
        // Komponente und keine Zusicherung dieses Tests.
        const results = main.getByTestId('mollie-check-result');
        await expect(results).toHaveCount(FINDING_KEYS.length);

        for (const [index, key] of FINDING_KEYS.entries()) {
            const row = results.nth(index);
            await expect(row, `Befund ${index + 1} muss "${key}" sein`).toHaveAttribute('data-key', key);

            const status = await row.getAttribute('data-status');
            expect(ALLOWED_STATUSES, `Status von "${key}" muss ok|warn|fail sein`).toContain(status);

            const message = (await row.innerText()).trim();
            expect(message.length, `Befund "${key}" braucht eine Meldung`).toBeGreaterThan(0);
        }

        // Ein Schlüssel ist serverseitig; er darf niemals in der gerenderten
        // Seite (geschweige denn in einer Befundmeldung) auftauchen.
        const apiKey = mollieConfiguredApiKey();
        if (apiKey !== '') {
            expect(await page.content()).not.toContain(apiKey);
        }
    });

    test('Normaler Admin sieht den Mollie-Verbindungstest nicht', { tag: ['@feature:settings'] }, async ({ page, request }) => {
        const adminHelper = new E2ESessionHelper(request);
        const adminUser = await adminHelper.createIsolatedUser('admin');

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Einstellungen');

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'System-Einstellungen', exact: true })).toBeVisible();

        await expect(main.getByTestId('mollie-check-card')).toHaveCount(0);
        await expect(main.getByTestId('mollie-check-button')).toHaveCount(0);

        await adminHelper.teardown();
    });
});
