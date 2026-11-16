import {expect, type Page, type Request, type Response, type Route} from '@playwright/test';
import {AuthHelper} from './AuthHelper';
import {E2ESessionHelper} from './E2ESessionHelper';
import {FormHelper} from './FormHelper';
import {GalleryHelper} from './GalleryHelper';
import {ModalHelper} from './ModalHelper';
import {SidebarHelper} from './SidebarHelper';
import {UploadHelper} from './UploadHelper';

export const CHECKOUT_PATH = '/api/orders/checkout';

/** The stub document that stands in for Mollie's hosted checkout page. */
const HOSTED_CHECKOUT_STUB =
    '<!doctype html><html lang="de"><head><title>Mollie Test-Zahlungsseite</title></head><body><h1>Mollie Test-Zahlungsseite</h1></body></html>';

/**
 * Answer the hosted-checkout navigation with a local stub page, so the redirect
 * stays inside the test and the `toHaveURL` assertion is deterministic.
 *
 * The stub is deliberately not the source of the checkout answer: see
 * `submitAndReadCheckout()` for how the body is captured.
 */
export async function stubHostedCheckout(page: Page): Promise<void> {
    await page.route('https://www.mollie.com/**', async route => {
        await route.fulfill({status: 200, contentType: 'text/html', body: HOSTED_CHECKOUT_STUB});
    });
}

/** A `Request`, the `Response` that answered it, and its already-read body. */
export interface CheckoutAnswer {
    request: Request;
    response: Response;
    /**
     * The response body as text.
     *
     * A successful checkout navigates the top-level document to the hosted
     * provider page, and Playwright then refuses to serve the body of the
     * response that caused the navigation ("No resource with given identifier
     * found"). The body is therefore captured at the network layer, before the
     * browser can leave the page — see `submitAndReadCheckout()`.
     */
    body: string;
}

export const isCheckoutRequest = (candidate: Request): boolean =>
    new URL(candidate.url()).pathname === CHECKOUT_PATH && candidate.method() === 'POST';

export const isCheckoutResponse = (candidate: Response): boolean =>
    new URL(candidate.url()).pathname === CHECKOUT_PATH && candidate.request().method() === 'POST';

/**
 * Click something that submits the checkout form and return that submission.
 *
 * Request and response are awaited together, so the `Idempotency-Key` header
 * under assertion is provably the one belonging to the status under assertion —
 * the two are separate round-trips to a shared endpoint and pairing them by
 * order alone would be a guess.
 *
 * The body is captured through a transparent pass-through: the route fetches
 * the real request, reads the real answer body, and hands that same answer back
 * to the browser with `route.fulfill({response})`. It is not a mock — status,
 * headers and body are the server's — it only reads the body before the
 * navigation the answer triggers can destroy it.
 */
export async function submitAndReadCheckout(page: Page, click: () => Promise<void>): Promise<CheckoutAnswer> {
    let captured: string | null = null;
    const record = async (route: Route): Promise<void> => {
        const answer = await route.fetch();
        captured = await answer.text();
        await route.fulfill({response: answer});
    };
    await page.route(`**${CHECKOUT_PATH}`, record);

    try {
        const requestPromise = page.waitForRequest(isCheckoutRequest);
        const responsePromise = page.waitForResponse(isCheckoutResponse);
        await click();
        const response = await responsePromise;
        const request = await requestPromise;

        if (captured === null) {
            throw new Error(`The checkout answer with status ${response.status()} was not recorded.`);
        }

        return {request, response, body: captured};
    } finally {
        await page.unroute(`**${CHECKOUT_PATH}`, record);
    }
}

/**
 * The billing data every card-testing checkout submits.
 *
 * It is a constant and not a per-test value on purpose: the server-side
 * checkout fingerprint hashes `billing_name`, `billing_company`,
 * `billing_street`, `billing_zip` and `billing_city`
 * (`backend/app/Services/CheckoutIdempotencyService.php`, `fingerprint()`:
 * `'billing_name' => $request->input('billing_name'),` … `'billing_city' => …`).
 * A test that retypes the form after a remount therefore has to retype it
 * *identically* to land on the idempotent replay instead of the `409`
 * conflict. The single place that data is written is this constant.
 */
export const CHECKOUT_BILLING = {
    name: 'Card Testing E2E',
    street: 'Teststraße 42',
    zip: '1010',
    city: 'Wien',
} as const;

export interface IsolatedTestUser {
    email: string;
    password: string;
    id: string;
}

export interface PaidCartFixture {
    galleryName: string;
}

/**
 * Put a real browser on the immediate online-checkout path (Mollie), which is
 * the only path the V036 card-testing gates apply to
 * (`features/security/card-testing-protection.md` §11).
 *
 * The setup is the one `turnstile-checkout.spec.ts` already proves end to end:
 * photographer creates a public delivery gallery through the real modal, one
 * photo is uploaded, the buyer puts that photo in the cart and the cart total is
 * positive. Everything below is the real user flow — no storage injection, no
 * `page.goto` — because the behaviours these specs pin (which `Idempotency-Key`
 * the browser reuses, which order the server resolves it to) are properties of
 * that flow, not of a hand-built state.
 */
export async function openPaidCartCheckout(
    page: Page,
    helper: E2ESessionHelper,
    photographer: IsolatedTestUser,
    buyer: IsolatedTestUser,
): Promise<PaidCartFixture> {
    const auth = new AuthHelper(page);
    const sidebar = new SidebarHelper(page);
    const galleryName = `Card Testing ${crypto.randomUUID().slice(0, 8)}`;

    await auth.login(photographer.email, photographer.password);
    const galleryHelper = new GalleryHelper(page, helper);
    await galleryHelper.createAndOpenDeliveryGallery(galleryName, 'Öffentlich (Für alle sichtbar)');
    await new UploadHelper(page).uploadSampleImage();

    await auth.logout();
    await auth.login(buyer.email, buyer.password);

    const main = page.getByRole('main');
    await main.getByText(galleryName, {exact: true}).first().click();
    await main.getByRole('button', {name: 'Bild öffnen'}).first().click();
    await main.getByRole('button', {name: 'In den Warenkorb'}).click();
    await sidebar.navigateTo('Warenkorb');

    await expect(main.getByRole('heading', {name: 'Dein Warenkorb'})).toBeVisible({timeout: 15000});
    // A positive total is what classifies the request as an immediate online
    // checkout. Assert it here so a later 403/422 is not read as a card-testing
    // gate that fired.
    await expect(main.getByTestId('cart-total')).toBeVisible({timeout: 15000});

    return {galleryName};
}

/**
 * Fill the billing form with the shared fixture data.
 *
 * `billingName` is a parameter because the identity-mismatch capture has to
 * submit a *different* name on the same cart, and the fingerprint consequence of
 * that single change is the whole point of that test.
 */
export async function fillPaidCheckoutForm(page: Page, billingName: string = CHECKOUT_BILLING.name): Promise<void> {
    await new FormHelper(page, new ModalHelper(page)).fillCheckoutForm({
        name: billingName,
        street: CHECKOUT_BILLING.street,
        zip: CHECKOUT_BILLING.zip,
        city: CHECKOUT_BILLING.city,
        acceptAgb: true,
        waiveWithdrawal: true,
    });
}
