import {expect, test, type Route} from '@playwright/test';
import {E2ESessionHelper} from '../helpers/E2ESessionHelper';
import {
    CHECKOUT_BILLING,
    CHECKOUT_PATH,
    fillPaidCheckoutForm,
    openPaidCartCheckout,
    submitAndReadCheckout,
} from '../helpers/CardTestingCheckoutFixture';

/**
 * The body `CheckoutRiskService::rejectQuota()` sends, quoted from
 * `backend/app/Services/CheckoutRiskService.php`:
 *   'error' => 'Checkout ist vorübergehend ausgelastet. Bitte versuche es später erneut.',
 *
 * The value is a contract, not decoration. The client renders
 * `error.message` from the response body (`frontend/src/api.ts`,
 * `handleApiError()`: `if (typeof parsed.error === 'string' …) errorMsg = parsed.error;`
 * and `ClientCartView` onCheckout's catch arm:
 * `showToast('error', error instanceof Error ? error.message : …)`), so a
 * localised, generic or empty replacement here is what the customer would read.
 * "Checkout is only busy" and "something broke" are different decisions for the
 * person holding the credit card, and only the first one is true.
 */
const QUOTA_BUSY_MESSAGE = 'Checkout ist vorübergehend ausgelastet. Bitte versuche es später erneut.';

/** `Retry-After` in seconds, mirroring the header `rejectQuota()` always sends. */
const QUOTA_RETRY_AFTER_SECONDS = '3600';

test.describe('Card-testing checkout quota denial', () => {
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

    test('a quota-denied checkout shows the honest busy message and the retry lands on the same order', {
        tag: ['@regression', '@feature:client:checkout', '@feature:card-testing']
    }, async ({page}) => {
        await openPaidCartCheckout(page, helper, photographer, buyer);
        const main = page.getByRole('main');
        const checkoutButton = main.getByRole('button', {name: 'Zahlungspflichtig bestellen'});

        // --- Attempt 1: a real checkout, so there is a pending order to keep --
        //
        // Starting from a real order is what makes step 4 mean something: a
        // denial answered before any order exists could not be distinguished
        // from a denial that silently threw one away.
        await fillPaidCheckoutForm(page);
        await expect(checkoutButton).toBeEnabled({timeout: 15000});
        const first = await submitAndReadCheckout(page, async () => {
            await checkoutButton.click();
        });
        expect(first.response.status(), await first.response.text()).toBe(200);
        const firstBody = await first.response.json() as {order_id?: string};
        expect(firstBody.order_id).toBeTruthy();
        await expect(main.getByRole('heading', {name: 'Zahlung abschließen'})).toBeVisible({timeout: 15000});

        // --- Attempt 2: the dedicated checkout quota denies it ----------------
        //
        // The denial is answered at the browser boundary, and that is a measured
        // limit of this environment, not a shortcut: `scripts/e2e-up.sh` raises
        // the three checkout buckets to 1000/hour for the isolated E2E backend
        // (`readonly E2E_CHECKOUT_LIMIT=1000`, written to
        // `CHECKOUT_THROTTLE_USER_PER_HOUR` / `_IP_PER_HOUR` / `_IP_PER_DAY`),
        // so the enforced threshold cannot be reached from a test — and lowering
        // it is backend config, outside this task's scope. What is captured here
        // is therefore the *client* half: how the browser treats the documented
        // denial. The enforcement itself (the counter, the `>` comparison, the
        // 429) is pinned by `backend/tests/Feature/CheckoutRateLimitTest.php`.
        //
        // Only `Retry-After` is sent. The runbook's discriminator for the two
        // 429 sources on this endpoint is the `X-RateLimit-Limit` header
        // (`features/security/card-testing-runbook.md` §S3: present ⇒ route
        // middleware, absent ⇒ checkout quota), and `rejectQuota()` sends no
        // such header — so the mock must not invent one.
        const deniedIdempotencyKeys: string[] = [];
        const denyWithQuota = async (route: Route) => {
            if (route.request().method() !== 'POST') {
                await route.fallback();
                return;
            }
            deniedIdempotencyKeys.push(route.request().headers()['idempotency-key'] ?? '');
            await route.fulfill({
                status: 429,
                contentType: 'application/json',
                headers: {'Retry-After': QUOTA_RETRY_AFTER_SECONDS},
                body: JSON.stringify({error: QUOTA_BUSY_MESSAGE}),
            });
        };
        await page.route(`**${CHECKOUT_PATH}`, denyWithQuota);

        await page.reload();
        await expect(main.getByRole('heading', {name: 'Dein Warenkorb'})).toBeVisible({timeout: 15000});
        await fillPaidCheckoutForm(page);
        await expect(checkoutButton).toBeEnabled({timeout: 15000});
        const denied = await submitAndReadCheckout(page, async () => {
            await checkoutButton.click();
        });
        expect(denied.response.status()).toBe(429);
        expect(denied.response.headers()['retry-after']).toBe(QUOTA_RETRY_AFTER_SECONDS);

        // 1. The honest message reaches the customer. A toast filtered by that
        //    exact text is the assertion: a generic fallback, an empty toast or
        //    a silent failure all fail it.
        await expect(page.getByRole('alert').filter({hasText: QUOTA_BUSY_MESSAGE})).toBeVisible({timeout: 15000});

        // 2. No silent failure: the denial is not a dead end. The customer is
        //    still on the cart, the cart and the typed billing data survived, and
        //    the submit button is usable again.
        await expect(page).toHaveURL(/\/cart$/);
        await expect(main.getByTestId('cart-total')).toBeVisible();
        await expect(main.getByLabel('Vor- & Nachname')).toHaveValue(CHECKOUT_BILLING.name);
        await expect(checkoutButton).toBeEnabled({timeout: 15000});

        // 3. A quota denial is not a risk challenge. The quota answers 429 with a
        //    generic message and no token; rendering the challenge here would
        //    both be wrong and would not make the checkout succeed.
        await expect(main.getByRole('heading', {name: 'Sicherheitsprüfung'})).toHaveCount(0);

        // --- Attempt 3: the real server again, same browser session ----------
        await page.unroute(`**${CHECKOUT_PATH}`, denyWithQuota);
        const retry = await submitAndReadCheckout(page, async () => {
            await checkoutButton.click();
        });
        expect(retry.response.status(), await retry.response.text()).toBe(200);
        const retryBody = await retry.response.json() as {order_id?: string};

        // 4. A denied attempt costs the customer nothing. The 429 is a
        //    recoverable answer, so the browser keeps its checkout identity
        //    (`checkoutSession.ts` only rotates the key on a `409`
        //    `idempotency_conflict`, and `ClientCartView` clears it only on a
        //    terminal success) and the server resolves the retry back to the
        //    order it already has. A second `order_id` here would mean the
        //    denial pushed the customer into a second order — and, on the
        //    immediate path, a second PaymentIntent.
        expect(deniedIdempotencyKeys).toHaveLength(1);
        expect(retry.request.headers()['idempotency-key']).toBe(deniedIdempotencyKeys[0]);
        expect(retryBody.order_id).toBe(firstBody.order_id);
        await expect(main.getByRole('heading', {name: 'Zahlung abschließen'})).toBeVisible({timeout: 15000});
    });
});
