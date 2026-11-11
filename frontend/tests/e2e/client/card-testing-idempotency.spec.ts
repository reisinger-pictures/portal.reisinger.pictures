import {expect, test} from '@playwright/test';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {PRICING_STRATEGY_SETTINGS_LOCK, withGlobalSettingsLock} from '../helpers/GlobalSettingsLock';
import {MOLLIE_PROVIDER_SKIP_REASON, mollieCheckoutConfigured} from '../helpers/MollieE2E';
import {
    fillPaidCheckoutForm,
    openPaidCartCheckout,
    submitAndReadCheckout,
    type CheckoutAnswer,
} from '../helpers/CardTestingCheckoutFixture';

/**
 * The body `CheckoutIdempotencyService::resolveExistingOrder()` sends when a
 * browser key is reused with a different canonical fingerprint, quoted from
 * `backend/app/Services/CheckoutIdempotencyService.php`:
 *   return $this->conflict('Dieser Checkout-Versuch wurde bereits mit anderen Daten verwendet.');
 * and the flag that identifies it, from the same file:
 *   'idempotency_conflict' => true,
 */
const IDENTITY_CONFLICT_MESSAGE = 'Dieser Checkout-Versuch wurde bereits mit anderen Daten verwendet.';

interface CheckoutBody {
    order_id?: string;
    checkout_url?: string;
    idempotency_conflict?: boolean;
}

/**
 * The redirect lands on the provider's hosted checkout. The page is stubbed so
 * the navigation is deterministic and stays inside the test.
 */
const stubHostedCheckout = async (page: Parameters<typeof openPaidCartCheckout>[0]): Promise<void> => {
    await page.route('https://www.mollie.com/**', async route => {
        await route.fulfill({
            status: 200,
            contentType: 'text/html',
            body: '<!doctype html><html lang="de"><head><title>Mollie Test-Zahlungsseite</title></head><body><h1>Mollie Test-Zahlungsseite</h1></body></html>',
        });
    });
};

/**
 * Read the order id out of a checkout answer.
 *
 * The response body is what the application itself acts on — `ClientCartView`
 * branches on `payment_pending` / `requires_action` / `success` and stores
 * `response.order_id` as `pendingOrderId`. Reading it here asserts the same
 * thing the UI asserts, at the boundary the UI reads it from. Nothing here
 * looks at Stripe: no PaymentIntent id, no Radar state, no provider response.
 */
const readOrderId = async (answer: CheckoutAnswer): Promise<string> => {
    const body = await answer.response.json() as CheckoutBody;
    expect(body.order_id, `Checkout answer carried no order_id: ${JSON.stringify(body)}`).toBeTruthy();

    return String(body.order_id);
};

test.describe('Card-testing checkout identity', () => {
    // The retry contract needs several real checkout round-trips per test. The
    // sibling `turnstile-checkout.spec.ts` allows two retries for the same
    // reason (a real Mollie API call per test); local runs pass
    // `--retries=0` so a flake cannot hide behind a retry.
    test.describe.configure({retries: 2});

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

    test('a retry after returning from the provider reuses the pending payment instead of opening a second one', {
        tag: ['@regression', '@feature:client:checkout', '@feature:card-testing']
    }, async ({page}) => {
        test.skip(!mollieCheckoutConfigured(), MOLLIE_PROVIDER_SKIP_REASON);
        // The gallery carries no own `licensing_mode`, so the brand default decides
        // how it is priced (scope licence vs. volume tier). The brand-settings spec
        // writes that mode, so this spec takes the same lock and cannot observe a
        // foreign one.
        await withGlobalSettingsLock(PRICING_STRATEGY_SETTINGS_LOCK, async () => {
            await openPaidCartCheckout(page, helper, photographer, buyer);
        });
        await stubHostedCheckout(page);
        const main = page.getByRole('main');
        const checkoutButton = main.getByRole('button', {name: 'Zahlungspflichtig bestellen'});

        await fillPaidCheckoutForm(page);
        await expect(checkoutButton).toBeEnabled({timeout: 15000});
        const first = await submitAndReadCheckout(page, async () => {
            await checkoutButton.click();
        });
        expect(first.response.status(), await first.response.text()).toBe(200);
        const firstOrderId = await readOrderId(first);
        const firstCheckoutUrl = (await first.response.json() as CheckoutBody).checkout_url;
        expect(firstCheckoutUrl).toMatch(/^https:\/\/www\.mollie\.com\//);
        await expect(page).toHaveURL(/^https:\/\/www\.mollie\.com\//, {timeout: 15000});

        // Coming back is the ordinary way a customer returns to an unfinished
        // checkout, and it is the one interruption the browser cannot resume
        // from memory: the payment state lives on the provider's page, the cart
        // is in `localStorage` and the checkout identity is in
        // `sessionStorage`. Back on the cart both have to be found again, or the
        // retry becomes a second purchase.
        await page.goBack();
        await expect(main.getByRole('heading', {name: 'Dein Warenkorb'})).toBeVisible({timeout: 15000});
        await expect(main.getByTestId('cart-total')).toBeVisible({timeout: 15000});
        // The form comes back from the user profile, not from the failed
        // attempt, so it has to be retyped. Identical data, because the server
        // fingerprint hashes every billing field.
        await fillPaidCheckoutForm(page);
        await expect(checkoutButton).toBeEnabled({timeout: 15000});

        const replay = await submitAndReadCheckout(page, async () => {
            await checkoutButton.click();
        });
        expect(replay.response.status(), await replay.response.text()).toBe(200);
        const replayOrderId = await readOrderId(replay);

        // 1. One checkout identity across the interruption: the same opaque
        //    `Idempotency-Key` is replayed, so the server can resolve the
        //    request to the order it already owns.
        const firstKey = first.request.headers()['idempotency-key'];
        expect(firstKey).toBeTruthy();
        expect(replay.request.headers()['idempotency-key']).toBe(firstKey);

        // 2. …and therefore no second order. A distinct id here would mean the
        //    retry was treated as a new purchase, which on the immediate-online
        //    path is a second Mollie payment for one cart.
        expect(replayOrderId).toBe(firstOrderId);

        // 3. The resume reuses the persisted attempt, so the customer lands on
        //    the same hosted payment: no second provider payment, no empty cart,
        //    no error. A fresh link would be a second charge waiting to happen.
        const replayCheckoutUrl = (await replay.response.json() as CheckoutBody).checkout_url;
        expect(replayCheckoutUrl).toBe(firstCheckoutUrl);
        await expect(page).toHaveURL(/^https:\/\/www\.mollie\.com\//, {timeout: 15000});
    });

    test('a reused key with changed billing data surfaces the conflict and the next attempt gets a new key', {
        tag: ['@regression', '@feature:client:checkout', '@feature:card-testing']
    }, async ({page}) => {
        test.skip(!mollieCheckoutConfigured(), MOLLIE_PROVIDER_SKIP_REASON);
        const CHANGED_NAME = 'Card Testing E2E Geändert';

        // The gallery carries no own `licensing_mode`, so the brand default decides
        // how it is priced (scope licence vs. volume tier). The brand-settings spec
        // writes that mode, so this spec takes the same lock and cannot observe a
        // foreign one.
        await withGlobalSettingsLock(PRICING_STRATEGY_SETTINGS_LOCK, async () => {
            await openPaidCartCheckout(page, helper, photographer, buyer);
        });
        await stubHostedCheckout(page);
        const main = page.getByRole('main');
        const checkoutButton = main.getByRole('button', {name: 'Zahlungspflichtig bestellen'});

        await fillPaidCheckoutForm(page);
        await expect(checkoutButton).toBeEnabled({timeout: 15000});
        const first = await submitAndReadCheckout(page, async () => {
            await checkoutButton.click();
        });
        expect(first.response.status(), await first.response.text()).toBe(200);
        const firstOrderId = await readOrderId(first);
        const firstKey = first.request.headers()['idempotency-key'];
        expect(firstKey).toBeTruthy();

        // Same cart, same session, one billing field changed. The browser's
        // recovery key is tied to the cart marker only
        // (`createCheckoutCartMarker` hashes photoId/tier/useCaseId/modifierIds/
        // isQuote/notes), so the key is deliberately reused — while the server
        // additionally hashes the billing block into the canonical fingerprint
        // (`fingerprint()`: `'billing_name' => $request->input('billing_name'),`).
        // Same key, different fingerprint is the identity-mismatch case the
        // contract answers with `409`, never with a second provider payment.
        await page.goBack();
        await expect(main.getByRole('heading', {name: 'Dein Warenkorb'})).toBeVisible({timeout: 15000});
        await fillPaidCheckoutForm(page, CHANGED_NAME);
        await expect(main.getByLabel('Vor- & Nachname')).toHaveValue(CHANGED_NAME);
        await expect(checkoutButton).toBeEnabled({timeout: 15000});

        const conflict = await submitAndReadCheckout(page, async () => {
            await checkoutButton.click();
        });
        expect(conflict.response.status()).toBe(409);
        const conflictBody = await conflict.response.json() as CheckoutBody;
        expect(conflictBody.idempotency_conflict).toBe(true);
        // The conflicting attempt really did reuse the key; otherwise this test
        // would be asserting the wrong thing.
        expect(conflict.request.headers()['idempotency-key']).toBe(firstKey);
        // The mismatch created no order of its own.
        expect(conflictBody.order_id).toBeUndefined();

        // The honest error reaches the customer instead of a silent failure.
        await expect(page.getByRole('alert').filter({hasText: IDENTITY_CONFLICT_MESSAGE})).toBeVisible({timeout: 15000});

        // And the mismatch is not a dead end: the cart, the corrected data and
        // the submit button all survive it.
        await expect(main.getByTestId('cart-total')).toBeVisible();
        await expect(main.getByLabel('Vor- & Nachname')).toHaveValue(CHANGED_NAME);
        await expect(checkoutButton).toBeEnabled({timeout: 15000});
        await expect(main.getByRole('heading', {name: 'Sicherheitsprüfung'})).toHaveCount(0);

        // Retrying must not reproduce the conflict forever. The client treats a
        // `409 idempotency_conflict` as "this key is spent" and rotates it
        // (`ClientCartView`: `if (isIdempotencyConflict(error)) { rotateCheckoutRecovery(); }`),
        // so the next attempt carries a new identity — and, because the billing
        // data now matches it, a genuinely new order instead of a replay of the
        // rejected one.
        const recovery = await submitAndReadCheckout(page, async () => {
            await checkoutButton.click();
        });
        expect(recovery.response.status(), await recovery.response.text()).toBe(200);
        const recoveryOrderId = await readOrderId(recovery);
        const recoveryKey = recovery.request.headers()['idempotency-key'];
        expect(recoveryKey).toBeTruthy();
        expect(recoveryKey).not.toBe(firstKey);
        expect(recoveryOrderId).not.toBe(firstOrderId);
        await expect(page).toHaveURL(/^https:\/\/www\.mollie\.com\//, {timeout: 15000});
    });
});
