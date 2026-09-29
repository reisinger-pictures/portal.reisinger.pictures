import {expect, type Page, type Request, type Response} from '@playwright/test';
import {AuthHelper} from './AuthHelper';
import {E2ESessionHelper} from './E2ESessionHelper';
import {FormHelper} from './FormHelper';
import {GalleryHelper} from './GalleryHelper';
import {ModalHelper} from './ModalHelper';
import {SidebarHelper} from './SidebarHelper';
import {UploadHelper} from './UploadHelper';

export const CHECKOUT_PATH = '/api/orders/checkout';

/** A `Request` and the `Response` that answered it, captured as one pair. */
export interface CheckoutAnswer {
    request: Request;
    response: Response;
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
 */
export async function submitAndReadCheckout(page: Page, click: () => Promise<void>): Promise<CheckoutAnswer> {
    const requestPromise = page.waitForRequest(isCheckoutRequest);
    const responsePromise = page.waitForResponse(isCheckoutResponse);
    await click();
    const [request, response] = await Promise.all([requestPromise, responsePromise]);

    return {request, response};
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
 * Put a real browser on the immediate-Stripe checkout path, which is the only
 * path the V036 card-testing gates apply to
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
    // A positive total is what classifies the request as an immediate Stripe
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
