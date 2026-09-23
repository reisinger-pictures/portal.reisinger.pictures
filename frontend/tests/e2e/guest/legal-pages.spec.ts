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
        const ipRetentionParagraph = main.locator('p').filter({
            hasText: 'Die Aufbewahrung der IP-bezogenen Bestell- und Sicherheitsdaten'
        });
        await expect(ipRetentionParagraph).toHaveText(
            'Die Aufbewahrung der IP-bezogenen Bestell- und Sicherheitsdaten richtet sich je nach Datenkategorie nach der geltenden Aufbewahrungsrichtlinie sowie den einschlägigen rechtlichen, steuerlichen und abrechnungsbezogenen Pflichten. Die IP-Risikoschlüssel laufen mit dem jeweiligen konfigurierten Limitierungsfenster ab. Die konkrete Frist und die zugrunde liegende Zuordnung teilt der Betreiber auf Anfrage mit.',
        );

        await expect(main.getByRole('heading', { name: '4. Zahlungsabwicklung und Betrugsprävention' })).toBeVisible();

        const paymentIdentifiersParagraph = main.locator('p').filter({
            hasText: 'Zur eindeutigen Zuordnung speichern wir die Stripe Customer-ID'
        });
        await expect(paymentIdentifiersParagraph).toBeVisible();
        await expect(paymentIdentifiersParagraph).toContainText('Stripe Customer-ID');
        await expect(paymentIdentifiersParagraph).toContainText('PaymentIntent-ID');
        await expect(paymentIdentifiersParagraph).toContainText('Checkout-Fingerprint-Hash');

        const paymentTelemetryParagraph = main.locator('p').filter({
            hasText: 'Bei fehlgeschlagenen Zahlungen verarbeiten wir begrenzte PaymentIntent-Fehler-/Decline-Telemetrie'
        });
        await expect(paymentTelemetryParagraph).toBeVisible();
        await expect(paymentTelemetryParagraph).toContainText('PaymentIntent-Fehler-/Decline-Telemetrie');
        await expect(paymentTelemetryParagraph).toContainText('Event-ID-Deduplizierung');

        const checkoutSessionParagraph = main.locator('p').filter({
            hasText: 'Für die Wiederaufnahme eines Checkout-Vorgangs speichert Ihr Browser'
        });
        await expect(checkoutSessionParagraph).toBeVisible();
        await expect(checkoutSessionParagraph).toContainText('Sitzungsspeicher');

        const paymentProcessorParagraph = main.locator('p').filter({
            hasText: 'Stripe ist der eingesetzte Zahlungsdienstleister und Zahlungsprozessor'
        });
        await expect(paymentProcessorParagraph).toBeVisible();
        await expect(paymentProcessorParagraph).toContainText('Zahlungsprozessor');

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
