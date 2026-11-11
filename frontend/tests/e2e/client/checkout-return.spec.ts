import {expect, test} from '@playwright/test';
import {AuthHelper} from '../helpers/AuthHelper';
import {
    CHECKOUT_PATH,
    fillPaidCheckoutForm,
    isCheckoutResponse,
    openPaidCartCheckout,
} from '../helpers/CardTestingCheckoutFixture';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {PRICING_STRATEGY_SETTINGS_LOCK, withGlobalSettingsLock} from '../helpers/GlobalSettingsLock';

/**
 * The hosted-checkout return page, against the real backend.
 *
 * No API is mocked here: `page.goto` is the documented exception for a
 * return-URL simulation, and everything the page shows comes from the real
 * owner-scoped `GET /api/orders/{id}/payment-status`. What is proven is the
 * route wiring, the German states, and — most importantly — that an order the
 * viewer does not own can never be reported as paid. The paid and pending
 * branches themselves are covered by `CheckoutReturnView.test.tsx` and the
 * backend suites; the redirect to Mollie needs a configured test account and is
 * listed in `features/infrastructure/30-mollie-payments.md` ("Verification
 * boundary").
 */
test.describe('Hosted checkout return', () => {
    let helper: E2ESessionHelper;
    let photographer = {email: '', password: '', id: ''};
    let buyer = {email: '', password: '', id: ''};

    test.beforeEach(async ({request}) => {
        helper = new E2ESessionHelper(request);
        photographer = await helper.createIsolatedUser('photographer');
        buyer = await helper.createIsolatedUser('power_user');
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('asks for the order reference when the return URL carries none', {
        tag: ['@regression', '@feature:client:checkout'],
    }, async ({page}) => {
        await new AuthHelper(page).login(buyer.email, buyer.password);
        await page.goto('/checkout/return');

        await expect(page.getByRole('main').getByRole('alert').filter({hasText: 'fehlt die Bestellreferenz'}))
            .toBeVisible({timeout: 15000});
        await expect(page.getByTestId('payment-return-paid')).toHaveCount(0);
    });

    test('reports an unknown order as unreadable instead of paid', {
        tag: ['@regression', '@feature:client:checkout'],
    }, async ({page}) => {
        await new AuthHelper(page).login(buyer.email, buyer.password);
        await page.goto(`/checkout/return?order_id=${crypto.randomUUID()}`);

        await expect(page.getByTestId('payment-return-error')).toBeVisible({timeout: 15000});
        await expect(page.getByTestId('payment-return-error'))
            .toContainText('Der Zahlungsstatus konnte nicht geladen werden');
        await expect(page.getByTestId('payment-return-paid')).toHaveCount(0);
        await expect(page.getByRole('button', {name: 'Erneut prüfen'})).toBeVisible();
    });

    test('never reports another customer order as paid', {
        tag: ['@regression', '@feature:client:checkout'],
    }, async ({page}) => {
        // A real order of the first customer, created through the real cart. An
        // invoice order is used because it needs no provider account: the point
        // of this test is the authorization boundary of the return page, not the
        // payment.
        await withGlobalSettingsLock(PRICING_STRATEGY_SETTINGS_LOCK, async () => {
            await openPaidCartCheckout(page, helper, photographer, buyer);
        });
        await fillPaidCheckoutForm(page);
        await page.locator('input[name="payment_method"][value="invoice"]').click();

        const answered = page.waitForResponse(isCheckoutResponse);
        await page.getByRole('button', {name: 'Zahlungspflichtig bestellen'}).click();
        const checkoutResponse = await answered;
        expect(checkoutResponse.status(), await checkoutResponse.text()).toBe(200);
        expect(new URL(checkoutResponse.url()).pathname).toBe(CHECKOUT_PATH);
        const orderId = (await checkoutResponse.json() as {order_id?: string}).order_id;
        expect(orderId).toBeTruthy();

        const stranger = await helper.createIsolatedUser('power_user');
        const auth = new AuthHelper(page);
        await auth.logout();
        await auth.login(stranger.email, stranger.password);
        await page.goto(`/checkout/return?order_id=${orderId}`);

        // The endpoint answers 404 for an order the viewer does not own, so the
        // page can only report that the status is unavailable — never the order
        // status of somebody else.
        await expect(page.getByTestId('payment-return-error')).toBeVisible({timeout: 15000});
        await expect(page.getByTestId('payment-return-paid')).toHaveCount(0);
        await expect(page.getByTestId('payment-return-ambiguous')).toHaveCount(0);
        await expect(page.getByTestId('payment-return-not-completed')).toHaveCount(0);
    });
});
