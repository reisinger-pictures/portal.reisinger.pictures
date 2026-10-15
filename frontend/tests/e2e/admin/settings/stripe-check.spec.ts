import { test, expect } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { SidebarHelper } from '../../helpers/SidebarHelper';

/**
 * Super-Admin „Stripe-Verbindungstest" (`POST /api/management/settings/stripe-check`).
 *
 * Was diese Spec NICHT behauptet, und warum:
 * Die Prüfung `stripe_webhook_endpoint` fragt Stripe, ob ein Webhook-Endpunkt
 * existiert, der auf `BrandRegistry::frontendUrl()/api/webhooks/stripe` zeigt.
 * Gemessen am 2026-10-15 gibt es im Test-Modus **keinen** Endpunkt:
 *
 *   curl -sS -H "Authorization: Bearer $STRIPE_SECRET" \
 *     'https://api.stripe.com/v1/webhook_endpoints?limit=100'   →  "data": []
 *
 * Der Tunnel legt einen Endpunkt nur ad hoc an, der CI-Job hat keinen. Diese
 * Prüfung ist hier also zwangsläufig `fail` — und das ist der gemessene Ist-Zustand,
 * kein Defekt. Deshalb assertiert diese Spec sie ausdrücklich **nicht**; ihr
 * Verhalten je Zweig (fehlend / disabled / Events unvollständig) ist in
 * `backend/tests/Feature/StripeKeyCheckControllerTest.php` gepinnt, wo ein
 * gefakter Client jeden Zweig deterministisch erzeugen kann.
 *
 * Die vier übrigen Prüfungen sind im E2E-Lauf deterministisch: Sie hängen an Env
 * und Key-Modus (Test-Keys + `APP_ENV=local` → Soll-Modus `test`, `livemode=false`,
 * Test-Konto vollständig aktiviert) — `local_key_mode` zusätzlich an einem
 * nicht-leeren `STRIPE_WEBHOOK_SECRET`, das `scripts/e2e-up.sh` als Dummy setzt
 * (`E2E_STRIPE_WEBHOOK_SECRET`; `backend/.env` lässt den Wert bewusst leer). Genau
 * die werden geprüft.
 */

/** Die vier Prüfungen, deren Ergebnis die E2E-Umgebung festlegt. */
const DETERMINISTIC_OK_KEYS = [
    'local_key_mode',
    'frontend_bundle_mode',
    'stripe_balance',
    'stripe_account',
] as const;

test.describe('Super Admin Stripe-Verbindungstest', () => {
    let helper: E2ESessionHelper;
    let testUser = { email: '', password: '' };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        testUser = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('Super-Admin prüft die Stripe-Verbindung; Prüfungen laufen nur auf Klick', { tag: ['@smoke', '@feature:settings'] }, async ({ page }) => {
        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(testUser.email, testUser.password);
        await sidebar.navigateTo('Einstellungen');

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'System-Einstellungen', exact: true })).toBeVisible();

        const card = main.getByTestId('stripe-check-card');
        await expect(card).toBeVisible();
        const button = card.getByTestId('stripe-check-button');
        await expect(button).toBeVisible();

        // Vertrag: der Selbsttest läuft NUR auf Klick. Kein Stripe-Aufruf beim
        // Seitenaufbau — vor dem Klick darf keine einzige Befundzeile existieren.
        await expect(main.getByTestId('stripe-check-result')).toHaveCount(0);

        await button.click();

        // Immer alle fünf Befunde — eine verschwundene oder hinzugekommene
        // Prüfung ist ein Vertragsbruch und muss hier auffallen.
        // Über `main` gescopt, nicht über die Karte: wo die Zeilen im Baum
        // hängen, ist Sache der Komponente und keine Zusicherung dieses Tests.
        const results = main.getByTestId('stripe-check-result');
        await expect(results).toHaveCount(5);

        const resultFor = (key: string) => main.locator(`[data-testid="stripe-check-result"][data-key="${key}"]`);

        for (const key of DETERMINISTIC_OK_KEYS) {
            await expect(resultFor(key), `Prüfung "${key}" muss in der E2E-Umgebung ok sein`).toHaveAttribute('data-status', 'ok');
        }

        // `stripe_webhook_endpoint` wird bewusst nicht assertiert — siehe Docblock oben.
        await expect(resultFor('stripe_webhook_endpoint')).toHaveCount(1);
    });

    test('Normaler Admin sieht den Stripe-Verbindungstest nicht', { tag: ['@feature:settings'] }, async ({ page, request }) => {
        const adminHelper = new E2ESessionHelper(request);
        const adminUser = await adminHelper.createIsolatedUser('admin');

        const auth = new AuthHelper(page);
        const sidebar = new SidebarHelper(page);

        await auth.login(adminUser.email, adminUser.password);
        await sidebar.navigateTo('Einstellungen');

        const main = page.getByRole('main');
        await expect(main.getByRole('heading', { name: 'System-Einstellungen', exact: true })).toBeVisible();

        await expect(main.getByTestId('stripe-check-card')).toHaveCount(0);
        await expect(main.getByTestId('stripe-check-button')).toHaveCount(0);

        await adminHelper.teardown();
    });
});
