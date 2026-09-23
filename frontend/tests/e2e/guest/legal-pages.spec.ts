import { test, expect } from '@playwright/test';

test.describe('Rechtliche Seiten (AGB & Widerruf) (G)', () => {
    test('Guest can access /widerruf page', { tag: ['@feature:legal'] }, async ({ page }) => {
        await page.goto('/widerruf');

        await expect(page.getByRole('main').getByRole('heading', { name: 'Widerrufsbelehrung' })).toBeVisible({ timeout: 10000 });
        // Schlüssel-Inhalte: 14-tägige Frist + Erlöschen bei digitalen Inhalten
        await expect(page.locator('main')).toContainText('vierzehn Tagen');
        await expect(page.locator('main').getByRole('heading', { name: 'Erlöschen des Rücktrittsrechts bei digitalen Inhalten' })).toBeVisible();
    });

    test('Guest can access /license-terms page', { tag: ['@feature:legal'] }, async ({ page }) => {
        await page.goto('/license-terms');

        await expect(page.getByRole('main').getByRole('heading', { name: 'AGB & Lizenzbedingungen' })).toBeVisible({ timeout: 10000 });
        // Zentrale Rechtsgrundlage für digitale Inhalte (FAGG)
        await expect(page.locator('main')).toContainText('FAGG');
        await expect(page.locator('main')).toContainText('sofortiger Download');
    });

    test('Impressum page links to the Widerrufsbelehrung', { tag: ['@feature:legal'] }, async ({ page }) => {
        await page.goto('/impressum');

        await page.getByRole('main').getByRole('link', { name: 'Widerrufsbelehrung' }).click();
        await expect(page).toHaveURL(/\/widerruf$/);
        await expect(page.getByRole('main').getByRole('heading', { name: 'Widerrufsbelehrung' })).toBeVisible({ timeout: 10000 });
    });

    test('Datenschutzerklärung discloses card-testing defenses and processor roles', { tag: ['@feature:legal'] }, async ({ page }) => {
        await page.goto('/impressum');

        const main = page.getByRole('main');
        await main.getByRole('link', { name: 'Datenschutzerklärung' }).click();

        await expect(main.getByRole('heading', { name: 'Datenschutzerklärung' })).toBeVisible({ timeout: 10000 });
        await expect(main.getByRole('heading', { name: '1. IP-Adressen und technische Protokolle' })).toBeVisible();
        await expect(main.getByText(/Beim Checkout speichern wir/)).toBeVisible();
        await expect(main.getByText('Für den Checkout-Missbrauchsschutz werden aus der IP-Adresse abgeleitete Risikoschlüssel (IP-Risikoschlüssel) zur Überwachung und Limitierung von Versuchen verwendet. Diese Schlüssel dienen nicht als seitenübergreifendes Browser-Fingerprinting. Webserver-Logs können technisch bedingt IP-Adressen enthalten.', { exact: true })).toBeVisible();
        await expect(main.getByText(/Aufbewahrungsrichtlinie/)).toBeVisible();

        await expect(main.getByRole('heading', { name: '4. Zahlungsabwicklung und Betrugsprävention' })).toBeVisible();
        await expect(main.getByText(/Stripe Customer-ID/)).toBeVisible();
        await expect(main.getByText(/PaymentIntent-ID/)).toBeVisible();
        await expect(main.getByText(/Checkout-Fingerprint-Hash/)).toBeVisible();
        await expect(main.getByText(/PaymentIntent-Fehler-\/Decline-Telemetrie/)).toBeVisible();
        await expect(main.getByText(/Event-ID-Deduplizierung/)).toBeVisible();
        await expect(main.getByText(/Sitzungsspeicher/)).toBeVisible();
        await expect(main.getByText(/Zahlungsprozessor/)).toBeVisible();

        await expect(main.getByRole('heading', { name: '5. Cloudflare Turnstile' })).toBeVisible();
        await expect(main.getByText(/Sicherheitsprozessor/)).toBeVisible();
        await expect(main.getByText(/Action "checkout"/)).toBeVisible();
        await expect(main.getByText(/Hostname/)).toBeVisible();
        await expect(main.getByText(/Remote-IP/)).toBeVisible();

        await expect(main.getByRole('heading', { name: '6. Ihre Rechte' })).toBeVisible();
        await expect(main.getByRole('link', { name: 'Impressum' })).toBeVisible();
        await expect(main).not.toContainText('nach wenigen Tagen');
        await expect(main).not.toContainText('unserem berechtigten Interesse');
    });
});
