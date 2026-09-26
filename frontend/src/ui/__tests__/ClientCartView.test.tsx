import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import ClientCartView from '../client/ClientCartView';
import { useCart } from '../../logic/CartContext';
import type {CartContextType, VolumeLicensingResult} from '../../logic/CartContext';
import { useAuth } from '../../logic/useAuth';
import { apiMutate, type ApiError } from '../../api';
import { checkoutSessionStorageKey } from '../../logic/checkoutSession';

// --------------------------------------------------------------------------
// Mocks — external modules
// --------------------------------------------------------------------------

const {mockShowToast, mockSearchParams, mockFormState, mockReset} = vi.hoisted(() => ({
    mockShowToast: vi.fn(),
    mockSearchParams: {value: ''},
    mockFormState: {isDirty: false},
    mockReset: vi.fn(),
}));

// Keep real router components, mock useNavigate and useSearchParams
vi.mock('react-router-dom', async () => {
    const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
    return {
        ...actual,
        useNavigate: () => vi.fn(),
        useSearchParams: () => [new URLSearchParams(mockSearchParams.value), vi.fn()],
    };
});

vi.mock('../../logic/stripe', () => ({
    stripePromise: Promise.resolve({}),
}));

vi.mock('@stripe/react-stripe-js', () => ({
    Elements: ({ children }: { children: React.ReactNode }) => (
        <div data-testid="stripe-elements">{children}</div>
    ),
}));

vi.mock('../../api', () => ({
    apiMutate: vi.fn(),
}));

// --------------------------------------------------------------------------
// Mocks — custom hooks
// --------------------------------------------------------------------------

vi.mock('../../logic/CartContext', () => ({
    useCart: vi.fn(),
}));

vi.mock('../components/UIContext', () => ({
    useUI: vi.fn(() => ({
        showToast: mockShowToast,
    })),
}));

vi.mock('../../logic/useAuth', () => ({
    useAuth: vi.fn(),
}));

vi.mock('../../logic/useLicensingMode', () => ({
    useLicensingMode: () => 'scope_licensing',
}));

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(() => ({
        isPowerUser: false,
        isAdmin: false,
    })),
}));

// --------------------------------------------------------------------------
// Mocks — sub-components
// --------------------------------------------------------------------------

vi.mock('../components/PageLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <div data-testid="page-layout">{children}</div>
    ),
}));

vi.mock('../client/components/StripeCheckoutForm', () => ({
    StripeCheckoutForm: ({onSuccess}: {onSuccess: (confirmed: boolean) => void}) => (
        <div data-testid="stripe-checkout-form">
            <button type="button" onClick={() => onSuccess(true)}>Stripe-Zahlung bestätigen</button>
            <button type="button" onClick={() => onSuccess(false)}>Stripe-Verarbeitung melden</button>
        </div>
    ),
}));

vi.mock('../client/components/TurnstileWidget', () => ({
    TurnstileWidget: ({
        onSuccess,
        onExpire,
        onError
    }: {
        onSuccess: (token: string) => void;
        onExpire: () => void;
        onError: () => void;
    }) => (
        <div role="group" aria-label="Sicherheitsprüfung">
            <button type="button" onClick={() => onSuccess('turnstile-token')}>Sicherheitsprüfung abschließen</button>
            <button type="button" onClick={onExpire}>Sicherheitsprüfung ablaufen lassen</button>
            <button type="button" onClick={onError}>Sicherheitsprüfung fehler auslösen</button>
        </div>
    ),
}));

vi.mock('../client/components/CartItemList', () => ({
    CartItemList: () => <div data-testid="cart-item-list" />,
}));

// --------------------------------------------------------------------------
// Mocks — react-hook-form (useForm needs a real-enough mock to wire
//          handleSubmit back to the component's onCheckout callback)
// --------------------------------------------------------------------------

vi.mock('react-hook-form', () => ({
    useForm: vi.fn(() => ({
        register: vi.fn((name: string) => ({
            name,
            onChange: vi.fn(),
            onBlur: vi.fn(),
            ref: vi.fn(),
        })),
        handleSubmit: vi.fn(
            (onValid: (data: Record<string, unknown>) => void) =>
                (e?: Event) => {
                    if (e?.preventDefault) e.preventDefault();
                    return onValid({
                        billing_name: 'Test User',
                        billing_company: '',
                        billing_street: 'Test St 1',
                        billing_zip: '12345',
                        billing_city: 'Vienna',
                        quote_message: '',
                        agb_accepted: true as const,
                        withdrawal_waived: true,
                    });
                },
        ),
        reset: mockReset,
        setError: vi.fn(),
        formState: { errors: {}, isSubmitting: false, isDirty: mockFormState.isDirty },
    })),
}));

// --------------------------------------------------------------------------
// Test data
// --------------------------------------------------------------------------

const mockUser = {
    id: 'u1',
    guest_id: null,
    name: 'Test User',
    email: 'user@example.com',
    billing_name: 'Test User',
    billing_company: '',
    billing_street: '',
    billing_zip: '',
    billing_city: '',
    brand: null,
    is_cross_brand: false,
    is_super_admin: false,
    is_admin: false,
    is_photographer: false,
    is_org_admin: false,
    is_power_user: false,
    is_pending: false,
    can_edit_metadata: false,
    can_purchase_upgrades: false,
    roles: [],
    transient_galleries: [],
    transient_meta_galleries: [],
    photographer_gallery_groups: [],
};

const mockCartItems = [
    {
        photoId: 'p1',
        tier: 'web' as const,
        price: 1500,
        filename: 'test.jpg',
    },
    {
        photoId: 'p2',
        tier: 'print' as const,
        price: 2500,
    },
];

function setupDefaultMocks() {
    vi.mocked(useAuth).mockReturnValue({
        user: mockUser,
        isLoading: false,
        isError: undefined,
        login: vi.fn(),
        register: vi.fn(),
        logout: vi.fn(),
        mutate: vi.fn(),
    });
    vi.mocked(useCart).mockReturnValue({
        items: [],
        quoteToken: null,
        setQuoteToken: vi.fn(),
        removeFromCart: vi.fn(),
        totalAmount: 0,
        clearCart: vi.fn(),
        addToCart: vi.fn(),
        itemCount: 0,
    });
}

function setCartWithItems() {
    const cart = {
        items: mockCartItems,
        quoteToken: null,
        setQuoteToken: vi.fn(),
        removeFromCart: vi.fn(),
        totalAmount: 4000,
        clearCart: vi.fn(),
        addToCart: vi.fn(),
        itemCount: 2,
    };
    vi.mocked(useCart).mockReturnValue(cart);
    return cart;
}

const mixedScopeItems: CartContextType['items'] = [
    {
        photoId: 'mixed-photo-a',
        tier: 'original',
        price: 1500,
        galleryId: 'gallery-a',
        galleryGroupId: 'group-a',
    },
    {
        photoId: 'mixed-photo-b',
        tier: 'original',
        price: 2500,
        galleryId: 'gallery-b',
        galleryGroupId: 'group-b',
    },
];

const mixedScopeTiers = [
    {minQuantity: 0, priceCents: 2500},
    {minQuantity: 2, priceCents: 2000},
];

const mixedVolumeLicensing: VolumeLicensingResult = {
    tierIndex: 1,
    isMaxTier: true,
    pricePerItemCents: 2000,
    totalCents: 4000,
    nextTierCount: 0,
    nextTierLabel: '',
    tiers: mixedScopeTiers,
    isVolumePricing: true,
    isLoading: false,
    groups: [
        {
            key: 'preset-a',
            licensingMode: 'volume_licensing',
            presetId: 'preset-a',
            presetName: 'Preset A',
            items: [mixedScopeItems[0]],
            itemIds: ['mixed-photo-a'],
            totalCents: 1500,
            pricePerItemCents: 1500,
            tiers: mixedScopeTiers,
            tierIndex: 0,
            isMaxTier: false,
            nextTierCount: 0,
            nextTierLabel: '',
            isVolumePricing: true,
            itemPriceCents: {'mixed-photo-a': 1500},
        },
        {
            key: 'preset-b',
            licensingMode: 'volume_licensing',
            presetId: 'preset-b',
            presetName: 'Preset B',
            items: [mixedScopeItems[1]],
            itemIds: ['mixed-photo-b'],
            totalCents: 2500,
            pricePerItemCents: 2500,
            tiers: mixedScopeTiers,
            tierIndex: 0,
            isMaxTier: false,
            nextTierCount: 0,
            nextTierLabel: '',
            isVolumePricing: true,
            itemPriceCents: {'mixed-photo-b': 2500},
        },
    ],
    groupedTotalCents: 4000,
    volumeSubtotalCents: 4000,
    volumeItemPrices: {
        'mixed-photo-a': 1500,
        'mixed-photo-b': 2500,
    },
};

function setMixedScopeCart(): CartContextType {
    const cart: CartContextType = {
        items: mixedScopeItems,
        quoteToken: null,
        setQuoteToken: vi.fn(),
        removeFromCart: vi.fn(),
        totalAmount: 4000,
        clearCart: vi.fn(),
        addToCart: vi.fn(),
        itemCount: mixedScopeItems.length,
        volumeLicensing: mixedVolumeLicensing,
    };
    vi.mocked(useCart).mockReturnValue(cart);
    return cart;
}

const createApiError = (status: number, info: Record<string, unknown>): ApiError => {
    const error = new Error(`HTTP ${status}`) as ApiError;
    error.status = status;
    error.info = info;
    return error;
};

const idempotencyKeyForCall = (index: number): string | undefined => {
    const options = vi.mocked(apiMutate).mock.calls[index][3];
    return options?.headers?.['Idempotency-Key'];
};

function renderCartView() {
    return renderWithProviders(
        <MemoryRouter>
            <ClientCartView />
        </MemoryRouter>,
    );
}

// --------------------------------------------------------------------------
// Suite
// --------------------------------------------------------------------------

describe('ClientCartView', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockSearchParams.value = '';
        mockFormState.isDirty = false;
        sessionStorage.clear();
        vi.stubEnv('VITE_TURNSTILE_SITE_KEY', 'site-key-for-tests');
        setupDefaultMocks();
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.unstubAllEnvs();
    });

    // ------------------------------------------------------------------
    // Empty cart
    // ------------------------------------------------------------------

    it('renders empty cart state', () => {
        renderCartView();

        expect(screen.getByText('Dein Warenkorb ist leer.')).toBeInTheDocument();

        // "Back to home" link must be present
        expect(
            screen.getByRole('link', { name: /zurück zur startseite/i }),
        ).toBeInTheDocument();

        // No item list or checkout form should appear
        expect(screen.queryByTestId('cart-item-list')).not.toBeInTheDocument();
        expect(screen.queryByTestId('stripe-elements')).not.toBeInTheDocument();
        expect(screen.queryByText('Rechnungsadresse')).not.toBeInTheDocument();
    });

    // ------------------------------------------------------------------
    // With items
    // ------------------------------------------------------------------

    it('renders item list and checkout form when items are present', () => {
        vi.mocked(useCart).mockReturnValue({
            items: mockCartItems,
            quoteToken: null,
            setQuoteToken: vi.fn(),
            removeFromCart: vi.fn(),
            totalAmount: 4000,
            clearCart: vi.fn(),
            addToCart: vi.fn(),
            itemCount: 2,
        });
        renderCartView();

        expect(screen.getByTestId('cart-item-list')).toBeInTheDocument();
        expect(screen.getByText('Rechnungsadresse')).toBeInTheDocument();

        // The submit button should be enabled
        expect(
            screen.getByRole('button', { name: /zahlungspflichtig bestellen/i }),
        ).toBeEnabled();

        // Empty-cart message must be gone
        expect(screen.queryByText('Dein Warenkorb ist leer.')).not.toBeInTheDocument();
    });

    it('associates checkout controls with programmatic labels and required attributes', () => {
        setCartWithItems();
        renderCartView();

        expect(screen.getByLabelText('Vor- & Nachname')).toHaveAttribute('required');
        expect(screen.getByLabelText('Firma')).toBeInTheDocument();
        expect(screen.getByLabelText('Straße & Hausnummer')).toHaveAttribute('required');
        expect(screen.getByLabelText('PLZ')).toHaveAttribute('required');
        expect(screen.getByLabelText('Ort')).toHaveAttribute('required');
        expect(screen.getByLabelText('Land')).toBeDisabled();
        expect(screen.getByRole('radio', {name: 'Kreditkarte (Stripe)'})).toHaveAttribute('required');
        expect(screen.getByRole('checkbox', {name: /allgemeinen geschäftsbedingungen/i})).toHaveAttribute('required');
        expect(screen.getByRole('checkbox', {name: /widerrufsrecht/i})).toHaveAttribute('required');
    });

    it('preserves dirty billing and consent fields during auth revalidation', () => {
        const view = renderCartView();
        expect(mockReset).toHaveBeenCalledTimes(1);

        mockFormState.isDirty = true;
        vi.mocked(useAuth).mockReturnValue({
            user: {...mockUser},
            isLoading: false,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });

        view.rerender(
            <MemoryRouter>
                <ClientCartView />
            </MemoryRouter>,
        );

        expect(mockReset).toHaveBeenCalledTimes(1);
    });

    it('ignores a stale quote decode response after the URL token changes', async () => {
        const cart = setCartWithItems();
        let resolveOld: (response: Response) => void = () => undefined;
        let resolveNew: (response: Response) => void = () => undefined;
        const oldResponse = new Promise<Response>((resolve) => {
            resolveOld = resolve;
        });
        const newResponse = new Promise<Response>((resolve) => {
            resolveNew = resolve;
        });
        const fetchMock = vi.fn((input: RequestInfo | URL, _init?: RequestInit) =>
            input.toString().includes('old-token') ? oldResponse : newResponse
        );
        vi.stubGlobal('fetch', fetchMock);

        mockSearchParams.value = 'quote_token=old-token';
        const view = renderCartView();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        mockSearchParams.value = 'quote_token=new-token';
        view.rerender(
            <MemoryRouter>
                <ClientCartView />
            </MemoryRouter>,
        );
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));

        resolveNew(new Response(JSON.stringify({photos: ['new-photo'], price: 1200}), {
            status: 200,
            headers: {'Content-Type': 'application/json'}
        }));
        await waitFor(() => expect(cart.setQuoteToken).toHaveBeenCalledWith('new-token'));

        resolveOld(new Response(JSON.stringify({photos: ['old-photo'], price: 900}), {
            status: 200,
            headers: {'Content-Type': 'application/json'}
        }));
        await waitFor(() => expect(cart.clearCart).toHaveBeenCalledTimes(1));

        expect(cart.setQuoteToken).toHaveBeenCalledTimes(1);
        expect(cart.addToCart).toHaveBeenCalledWith(expect.objectContaining({photoId: 'new-photo'}));
        expect(cart.addToCart).not.toHaveBeenCalledWith(expect.objectContaining({photoId: 'old-photo'}));
        expect(fetchMock.mock.calls[0][1]?.signal).toMatchObject({aborted: true});
    });

    // ------------------------------------------------------------------
    // Loading state (user null)
    // ------------------------------------------------------------------

    it('shows a loading alert when the user object is null', () => {
        vi.mocked(useAuth).mockReturnValue({
            user: undefined,
            isLoading: true,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });
        renderCartView();

        expect(screen.getByText('Lade Rechnungsdaten...')).toBeInTheDocument();
        expect(screen.getByText('Dein Warenkorb ist leer.')).toBeInTheDocument();
    });

    // ------------------------------------------------------------------
    // Stripe Elements (clientSecret flow)
    // ------------------------------------------------------------------

    it('shows Stripe Elements after checkout returns a client secret', async () => {
        const user = userEvent.setup();

        // Provide cart items so the submit button is enabled
        vi.mocked(useCart).mockReturnValue({
            items: mockCartItems,
            quoteToken: null,
            setQuoteToken: vi.fn(),
            removeFromCart: vi.fn(),
            totalAmount: 4000,
            clearCart: vi.fn(),
            addToCart: vi.fn(),
            itemCount: 2,
        });

        // Checkout endpoint returns requires_action → triggers clientSecret
        vi.mocked(apiMutate).mockResolvedValue({
            requires_action: true,
            client_secret: 'cs_test_live_123',
            order_id: 'ord_abc',
            success: false,
        });

        renderCartView();

        // Click the submit button to run the onCheckout callback
        await user.click(
            screen.getByRole('button', { name: /zahlungspflichtig bestellen/i }),
        );

        // After the async checkout handler runs, the component should
        // swap the billing form for Stripe Elements
        await waitFor(() => {
            expect(screen.getByTestId('stripe-elements')).toBeInTheDocument();
        });
        expect(screen.getByTestId('stripe-checkout-form')).toBeInTheDocument();

        // The billing-address form must be replaced
        expect(screen.queryByText('Rechnungsadresse')).not.toBeInTheDocument();
    });

    it('keeps recovery non-destructive and reuses its key for a remotely succeeded payment', async () => {
        const user = userEvent.setup();
        const cart = setCartWithItems();

        vi.mocked(apiMutate).mockResolvedValue({
            success: true,
            payment_pending: true,
            requires_action: false,
            client_secret: 'cs_stale_remote_succeeded',
            order_id: 'ord_pending_webhook',
            status: 'pending_payment',
            poll_url: '/api/orders/ord_pending_webhook',
        });

        renderCartView();
        await user.click(screen.getByRole('button', {name: /zahlungspflichtig bestellen/i}));

        expect(await screen.findByRole('status')).toHaveTextContent('Die Zahlung ist noch nicht als bezahlt bestätigt');
        expect(mockShowToast).toHaveBeenCalledWith(
            'info',
            'Die Zahlung wird derzeit geprüft. Bitte später erneut prüfen.',
        );
        expect(screen.queryByTestId('stripe-checkout-form')).not.toBeInTheDocument();
        expect(cart.clearCart).not.toHaveBeenCalled();

        const originalKey = idempotencyKeyForCall(0);
        expect(originalKey).toBeDefined();
        expect(sessionStorage.getItem(checkoutSessionStorageKey(mockUser.id))).toContain(originalKey);

        await user.click(screen.getByRole('button', {name: 'Zahlung erneut prüfen'}));

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(2));
        expect(idempotencyKeyForCall(1)).toBe(originalKey);
        expect(sessionStorage.getItem(checkoutSessionStorageKey(mockUser.id))).toContain(originalKey);
        expect(screen.getByRole('status')).toHaveTextContent('Die Zahlung ist noch nicht als bezahlt bestätigt');
        expect(screen.queryByTestId('stripe-checkout-form')).not.toBeInTheDocument();
        expect(cart.clearCart).not.toHaveBeenCalled();
    });

    it('clears persisted checkout recovery after confirmed Stripe payment', async () => {
        const user = userEvent.setup();
        const cart = setCartWithItems();
        vi.mocked(apiMutate).mockResolvedValue({
            requires_action: true,
            client_secret: 'cs_test_paid',
            order_id: 'ord_paid',
            success: true
        });

        renderCartView();
        await user.click(screen.getByRole('button', {name: /zahlungspflichtig bestellen/i}));
        await screen.findByTestId('stripe-checkout-form');
        expect(sessionStorage.getItem(checkoutSessionStorageKey(mockUser.id))).toContain(idempotencyKeyForCall(0));

        await user.click(screen.getByRole('button', {name: 'Stripe-Zahlung bestätigen'}));

        expect(sessionStorage.getItem(checkoutSessionStorageKey(mockUser.id))).toBeNull();
        expect(cart.clearCart).toHaveBeenCalledTimes(1);
    });

    it('retains cart and idempotency recovery when the local paid state is delayed', async () => {
        const user = userEvent.setup();
        const cart = setCartWithItems();
        vi.mocked(apiMutate).mockResolvedValue({
            requires_action: true,
            client_secret: 'cs_test_delayed_webhook',
            order_id: 'ord_delayed_webhook',
            success: true
        });

        renderCartView();
        await user.click(screen.getByRole('button', {name: /zahlungspflichtig bestellen/i}));
        await screen.findByTestId('stripe-checkout-form');
        const originalKey = idempotencyKeyForCall(0);
        expect(sessionStorage.getItem(checkoutSessionStorageKey(mockUser.id))).toContain(originalKey);

        await user.click(screen.getByRole('button', {name: 'Stripe-Verarbeitung melden'}));

        expect(screen.getByRole('status')).toHaveTextContent('noch nicht als bezahlt bestätigt');
        expect(sessionStorage.getItem(checkoutSessionStorageKey(mockUser.id))).toContain(originalKey);
        expect(cart.clearCart).not.toHaveBeenCalled();
        expect(document.querySelector('input[name="billing_name"]')).toBeDisabled();

        const recoveryButton = screen.getByRole('button', {name: 'Zahlung erneut prüfen'});
        await user.click(recoveryButton);

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(2));
        expect(idempotencyKeyForCall(1)).toBe(originalKey);
        expect(screen.getByTestId('stripe-checkout-form')).toBeInTheDocument();
        expect(cart.clearCart).not.toHaveBeenCalled();
    });

    // ------------------------------------------------------------------
    // form controls (checkbox area)
    // ------------------------------------------------------------------

    it('renders AGB checkbox and withdrawal waiver', () => {
        vi.mocked(useCart).mockReturnValue({
            items: mockCartItems,
            quoteToken: null,
            setQuoteToken: vi.fn(),
            removeFromCart: vi.fn(),
            totalAmount: 4000,
            clearCart: vi.fn(),
            addToCart: vi.fn(),
            itemCount: 2,
        });
        renderCartView();

        expect(
            screen.getByText(/allgemeinen geschäftsbedingungen/i),
        ).toBeInTheDocument();
        expect(
            screen.getByText(/widerrufsrecht/i),
        ).toBeInTheDocument();
    });

    // ------------------------------------------------------------------
    // Coupon → checkout payload (regression: state was duplicated across
    // ClientCartView and CouponInput, so the payload always sent null)
    // ------------------------------------------------------------------

    it('includes an applied coupon code in the checkout payload', async () => {
        const user = userEvent.setup();

        vi.mocked(useCart).mockReturnValue({
            items: mockCartItems,
            quoteToken: null,
            setQuoteToken: vi.fn(),
            removeFromCart: vi.fn(),
            totalAmount: 4000,
            clearCart: vi.fn(),
            addToCart: vi.fn(),
            itemCount: 2,
        });

        vi.mocked(apiMutate)
            .mockResolvedValueOnce({
                valid: true,
                coupon: { code: 'SAVE10', type: 'fixed', value: 1000 },
                discount_cents: 1000,
            })
            .mockResolvedValueOnce({
                success: true,
                invoice_number: 'INV-1',
            });

        renderCartView();

        await user.type(screen.getByLabelText('Rabattcode'), 'SAVE10');
        await user.click(screen.getByRole('button', { name: 'Anwenden' }));

        await waitFor(() => {
            expect(screen.getByText('SAVE10')).toBeInTheDocument();
        });

        await user.click(
            screen.getByRole('button', { name: /zahlungspflichtig bestellen/i }),
        );

        await waitFor(() => {
            expect(apiMutate).toHaveBeenCalledTimes(2);
        });

        const payload = vi.mocked(apiMutate).mock.calls[1][2] as Record<string, unknown>;
        expect(payload.coupon_code).toBe('SAVE10');
    });

    it('sends every mixed-cart gallery and group id during coupon validation', async () => {
        const user = userEvent.setup();
        setMixedScopeCart();
        vi.mocked(apiMutate).mockResolvedValueOnce({
            valid: true,
            coupon: {code: 'MIXED10', type: 'fixed', value: 10},
            discount_cents: 1000,
        });

        renderCartView();
        await user.type(screen.getByLabelText('Rabattcode'), 'MIXED10');
        await user.click(screen.getByRole('button', {name: 'Anwenden'}));

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(1));
        const validationPayload = vi.mocked(apiMutate).mock.calls[0][2] as Record<string, unknown>;
        expect(validationPayload).toEqual({
            code: 'MIXED10',
            gallery_id: ['gallery-a', 'gallery-b'],
            meta_gallery_id: ['group-a', 'group-b'],
        });
    });

    it('resets an applied coupon when a non-eligible cart item is added', async () => {
        const user = userEvent.setup();
        const cart = setMixedScopeCart();
        const firstPricingGroup = mixedVolumeLicensing.groups?.[0];
        if (!firstPricingGroup) throw new Error('Mixed-volume fixture is missing its first pricing group.');
        const scopeOnlyItem: CartContextType['items'][number] = {
            photoId: 'scope-only-photo',
            tier: 'original',
            price: 500,
            galleryId: 'scope-gallery',
            galleryGroupId: 'scope-group',
        };
        const scopePricingGroup = {
            ...firstPricingGroup,
            key: 'scope-group',
            presetId: '',
            presetName: null,
            items: [scopeOnlyItem],
            itemIds: [scopeOnlyItem.photoId],
            totalCents: 500,
            pricePerItemCents: null,
            isVolumePricing: false,
            itemPriceCents: {},
        };
        vi.mocked(apiMutate).mockResolvedValueOnce({
            valid: true,
            coupon: {code: 'SAVE10', type: 'fixed', value: 10},
            discount_cents: 1000,
        });

        const view = renderCartView();
        await user.type(screen.getByLabelText('Rabattcode'), 'SAVE10');
        await user.click(screen.getByRole('button', {name: 'Anwenden'}));
        expect(await screen.findByText('SAVE10')).toBeInTheDocument();

        vi.mocked(useCart).mockReturnValue({
            ...cart,
            items: [...cart.items, scopeOnlyItem],
            itemCount: cart.itemCount + 1,
            totalAmount: 4500,
            volumeLicensing: {
                ...mixedVolumeLicensing,
                groups: [...(mixedVolumeLicensing.groups ?? []), scopePricingGroup],
                groupedTotalCents: 4500,
            },
        });
        view.rerender(
            <MemoryRouter>
                <ClientCartView />
            </MemoryRouter>,
        );

        expect(screen.queryByText('SAVE10')).not.toBeInTheDocument();
        expect(screen.getByLabelText('Rabattcode')).toHaveValue('');
    });

    it('removes a displayed coupon and omits it from checkout after eligibility changes', async () => {
        const user = userEvent.setup();
        const cart = setMixedScopeCart();
        vi.mocked(apiMutate)
            .mockResolvedValueOnce({
                valid: true,
                coupon: {code: 'SAVE10', type: 'fixed', value: 10},
                discount_cents: 1000,
            })
            .mockResolvedValueOnce({success: true, invoice_number: 'INV-NO-STALE-COUPON'});

        const view = renderCartView();
        await user.type(screen.getByLabelText('Rabattcode'), 'SAVE10');
        await user.click(screen.getByRole('button', {name: 'Anwenden'}));
        expect(await screen.findByText('SAVE10')).toBeInTheDocument();

        vi.mocked(useCart).mockReturnValue({
            ...cart,
            volumeLicensing: {
                ...mixedVolumeLicensing,
                isVolumePricing: false,
                volumeSubtotalCents: undefined,
                groups: mixedVolumeLicensing.groups?.map(group => ({
                    ...group,
                    isVolumePricing: false,
                })),
            },
        });
        view.rerender(
            <MemoryRouter>
                <ClientCartView />
            </MemoryRouter>,
        );

        expect(screen.queryByText('SAVE10')).not.toBeInTheDocument();
        expect(screen.queryByTestId('coupon-input')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', {name: /zahlungspflichtig bestellen/i}));

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(2));
        const checkoutPayload = vi.mocked(apiMutate).mock.calls[1][2] as Record<string, unknown>;
        expect(checkoutPayload.coupon_code).toBeNull();
    });

    it('clears an applied coupon when a non-eligible cart item changes', async () => {
        const user = userEvent.setup();
        const cart = setMixedScopeCart();
        vi.mocked(apiMutate).mockResolvedValueOnce({
            valid: true,
            coupon: {code: 'SAVE10', type: 'fixed', value: 10},
            discount_cents: 1000,
        });

        const view = renderCartView();
        await user.type(screen.getByLabelText('Rabattcode'), 'SAVE10');
        await user.click(screen.getByRole('button', {name: 'Anwenden'}));
        expect(await screen.findByText('SAVE10')).toBeInTheDocument();

        vi.mocked(useCart).mockReturnValue({
            ...cart,
            items: [
                ...cart.items,
                {
                    photoId: 'scope-photo',
                    tier: 'original',
                    price: 500,
                    galleryId: 'scope-gallery',
                    galleryGroupId: 'scope-group',
                },
            ],
        });
        view.rerender(
            <MemoryRouter>
                <ClientCartView />
            </MemoryRouter>,
        );

        expect(screen.queryByText('SAVE10')).not.toBeInTheDocument();
    });

    it('hides coupon entry for a scope-only cart', () => {
        vi.mocked(useCart).mockReturnValue({
            items: mockCartItems,
            quoteToken: null,
            setQuoteToken: vi.fn(),
            removeFromCart: vi.fn(),
            totalAmount: 4000,
            clearCart: vi.fn(),
            addToCart: vi.fn(),
            itemCount: 2,
            volumeLicensing: {
                isVolumePricing: false,
                tierIndex: 0,
                isMaxTier: false,
                pricePerItemCents: 3000,
                totalCents: 0,
                nextTierCount: 0,
                nextTierLabel: '',
                tiers: [],
                isLoading: false,
            },
        });

        renderCartView();

        expect(screen.queryByTestId('coupon-input')).not.toBeInTheDocument();
    });

    it('uses a full discount for free checkout without opening Stripe Elements', async () => {
        const user = userEvent.setup();
        vi.mocked(useCart).mockReturnValue({
            items: mockCartItems,
            quoteToken: null,
            setQuoteToken: vi.fn(),
            removeFromCart: vi.fn(),
            totalAmount: 4000,
            clearCart: vi.fn(),
            addToCart: vi.fn(),
            itemCount: 2,
            volumeLicensing: {
                isVolumePricing: true,
                isLoading: false,
                tierIndex: 0,
                isMaxTier: true,
                pricePerItemCents: 2000,
                totalCents: 4000,
                nextTierCount: 0,
                nextTierLabel: '',
                tiers: [{minQuantity: 0, priceCents: 2000}],
                volumeSubtotalCents: 4000,
                volumeItemPrices: {p1: 2000, p2: 2000},
            },
        });
        vi.mocked(apiMutate)
            .mockResolvedValueOnce({
                valid: true,
                coupon: {code: 'FREE100', type: 'percentage', value: 100},
                discount_cents: 1000,
            })
            .mockResolvedValueOnce({success: true, invoice_number: 'INV-FREE'});

        renderCartView();
        await user.type(screen.getByLabelText('Rabattcode'), 'FREE100');
        await user.click(screen.getByRole('button', {name: 'Anwenden'}));
        await waitFor(() => expect(screen.getByRole('button', {name: 'Kostenlos bestellen'})).toBeInTheDocument());

        await user.click(screen.getByRole('button', {name: 'Kostenlos bestellen'}));
        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(2));
        const payload = vi.mocked(apiMutate).mock.calls[1][2] as Record<string, unknown>;
        expect(payload.coupon_code).toBe('FREE100');
        expect(screen.queryByTestId('stripe-checkout-form')).not.toBeInTheDocument();
    });

    it('does not offer or submit a coupon for a signed quote cart', async () => {
        const user = userEvent.setup();
        vi.mocked(useCart).mockReturnValue({
            ...setCartWithItems(),
            quoteToken: 'header.payload.signature',
            volumeLicensing: {
                isVolumePricing: true,
                isLoading: false,
                tierIndex: 0,
                isMaxTier: true,
                pricePerItemCents: 2000,
                totalCents: 4000,
                nextTierCount: 0,
                nextTierLabel: '',
                tiers: [{minQuantity: 0, priceCents: 2000}],
                volumeSubtotalCents: 4000,
                volumeItemPrices: {p1: 2000, p2: 2000},
            },
        });
        vi.mocked(apiMutate).mockResolvedValue({success: true, invoice_number: 'INV-QUOTE-NO-COUPON'});

        renderCartView();
        expect(screen.queryByTestId('coupon-input')).not.toBeInTheDocument();
        await user.click(screen.getByRole('button', {name: /zahlungspflichtig bestellen/i}));

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(1));
        const payload = vi.mocked(apiMutate).mock.calls[0][2] as Record<string, unknown>;
        expect(payload.quote_token).toBe('header.payload.signature');
        expect(payload.coupon_code).toBeNull();
    });

    it('sends the persisted quote token with the restored offer prices', async () => {
        const user = userEvent.setup();
        const cart = setCartWithItems();
        vi.mocked(useCart).mockReturnValue({
            ...cart,
            quoteToken: 'header.payload.signature',
        });
        vi.mocked(apiMutate).mockResolvedValue({success: true, invoice_number: 'INV-QUOTE'});

        renderCartView();
        await user.click(screen.getByRole('button', {name: /zahlungspflichtig bestellen/i}));

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(1));
        const payload = vi.mocked(apiMutate).mock.calls[0][2] as Record<string, unknown>;
        expect(payload.quote_token).toBe('header.payload.signature');
        expect(payload.items).toEqual(mockCartItems);
    });

    it.each([0, 429, 500])('keeps the checkout idempotency key after recoverable status %s', async (status) => {
        const user = userEvent.setup();
        setCartWithItems();
        vi.mocked(apiMutate)
            .mockRejectedValueOnce(createApiError(status, {}))
            .mockResolvedValueOnce({
                requires_action: true,
                client_secret: 'cs_test_retry',
                order_id: 'ord_retry',
                success: true
            });

        renderCartView();
        const checkoutButton = screen.getByRole('button', {name: /zahlungspflichtig bestellen/i});
        await user.click(checkoutButton);
        await user.click(checkoutButton);

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(2));
        expect(idempotencyKeyForCall(0)).toMatch(/^[0-9a-f-]{36}$/i);
        expect(idempotencyKeyForCall(1)).toBe(idempotencyKeyForCall(0));
    });

    it('replays the same browser key for a settled-free checkout after a transient failure', async () => {
        const user = userEvent.setup();
        const cart = setCartWithItems();
        vi.mocked(useCart).mockReturnValue({
            ...cart,
            volumeLicensing: {
                isVolumePricing: true,
                isLoading: false,
                tierIndex: 0,
                isMaxTier: true,
                pricePerItemCents: 2000,
                totalCents: 4000,
                nextTierCount: 0,
                nextTierLabel: '',
                tiers: [{minQuantity: 0, priceCents: 2000}],
                volumeSubtotalCents: 4000,
                volumeItemPrices: {p1: 2000, p2: 2000},
            },
        });
        vi.mocked(apiMutate)
            .mockResolvedValueOnce({
                valid: true,
                coupon: {code: 'FREE100', type: 'percentage', value: 100},
                discount_cents: 4000,
            })
            .mockRejectedValueOnce(createApiError(503, {}))
            .mockResolvedValueOnce({success: true, invoice_number: 'INV-FREE-RETRY'});

        renderCartView();
        await user.type(screen.getByLabelText('Rabattcode'), 'FREE100');
        await user.click(screen.getByRole('button', {name: 'Anwenden'}));
        await screen.findByRole('button', {name: 'Kostenlos bestellen'});
        const checkoutButton = screen.getByRole('button', {name: 'Kostenlos bestellen'});
        await user.click(checkoutButton);
        await user.click(checkoutButton);

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(3));
        expect(idempotencyKeyForCall(1)).toBe(idempotencyKeyForCall(2));
        expect(idempotencyKeyForCall(1)).toMatch(/^[0-9a-f-]{36}$/i);
    });

    it('replays the same browser key for a reactive quote after a transient failure', async () => {
        const user = userEvent.setup();
        vi.mocked(useCart).mockReturnValue({
            items: [{...mockCartItems[0], isQuote: true, price: 0}],
            quoteToken: null,
            setQuoteToken: vi.fn(),
            removeFromCart: vi.fn(),
            totalAmount: 0,
            clearCart: vi.fn(),
            addToCart: vi.fn(),
            itemCount: 1,
        });
        vi.mocked(apiMutate)
            .mockRejectedValueOnce(createApiError(503, {}))
            .mockResolvedValueOnce({success: true, invoice_number: 'INV-QUOTE-RETRY'});

        renderCartView();
        const checkoutButton = screen.getByRole('button', {name: /unverbindlich anfragen/i});
        await user.click(checkoutButton);
        await user.click(checkoutButton);

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(2));
        expect(idempotencyKeyForCall(0)).toBe(idempotencyKeyForCall(1));
    });

    it('replays the same browser key for invoice checkout after a transient failure', async () => {
        const user = userEvent.setup();
        vi.mocked(useAuth).mockReturnValue({
            user: {...mockUser, roles: ['client']},
            isLoading: false,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });
        setCartWithItems();
        vi.mocked(apiMutate)
            .mockRejectedValueOnce(createApiError(503, {}))
            .mockResolvedValueOnce({success: true, invoice_number: 'INV-INVOICE-RETRY'});

        renderCartView();
        await user.click(screen.getByRole('radio', {name: 'Kauf auf Rechnung'}));
        const checkoutButton = screen.getByRole('button', {name: /zahlungspflichtig bestellen/i});
        await user.click(checkoutButton);
        await user.click(checkoutButton);

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(2));
        expect(idempotencyKeyForCall(0)).toBe(idempotencyKeyForCall(1));
    });

    it('generates a new recovery key when an invoice checkout loses browser storage', async () => {
        const user = userEvent.setup();
        vi.mocked(useAuth).mockReturnValue({
            user: {...mockUser, roles: ['client']},
            isLoading: false,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });
        setCartWithItems();
        vi.mocked(apiMutate)
            .mockRejectedValueOnce(createApiError(503, {}))
            .mockResolvedValueOnce({success: true, invoice_number: 'INV-NO-KEY'});

        renderCartView();
        await user.click(screen.getByRole('radio', {name: 'Kauf auf Rechnung'}));
        const checkoutButton = screen.getByRole('button', {name: /zahlungspflichtig bestellen/i});
        await user.click(checkoutButton);
        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(1));
        const firstKey = idempotencyKeyForCall(0);

        // Simulate a browser/session-storage loss between retries. The
        // non-immediate backend contract has no fingerprint fallback, so the
        // client must not pretend that the lost key can replay the old order.
        sessionStorage.removeItem(checkoutSessionStorageKey(mockUser.id));
        await user.click(checkoutButton);

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(2));
        const secondKey = idempotencyKeyForCall(1);
        expect(firstKey).toMatch(/^[0-9a-f-]{36}$/i);
        expect(secondKey).toMatch(/^[0-9a-f-]{36}$/i);
        expect(secondKey).not.toBe(firstKey);
    });

    it('recovers the same idempotency key after a CartView remount', async () => {
        const user = userEvent.setup();
        setCartWithItems();
        vi.mocked(apiMutate)
            .mockRejectedValueOnce(createApiError(500, {}))
            .mockResolvedValueOnce({
                requires_action: true,
                client_secret: 'cs_test_remount_recovery',
                order_id: 'ord_remount_recovery',
                success: true
            });

        const firstRender = renderCartView();
        await user.click(screen.getByRole('button', {name: /zahlungspflichtig bestellen/i}));
        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(1));
        firstRender.unmount();

        renderCartView();
        await user.click(screen.getByRole('button', {name: /zahlungspflichtig bestellen/i}));
        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(2));

        expect(idempotencyKeyForCall(1)).toBe(idempotencyKeyForCall(0));
        expect(sessionStorage.getItem(checkoutSessionStorageKey(mockUser.id))).toContain(idempotencyKeyForCall(0));
    });

    it('isolates persisted idempotency keys when the authenticated user changes', async () => {
        const user = userEvent.setup();
        setCartWithItems();
        vi.mocked(apiMutate)
            .mockRejectedValueOnce(createApiError(500, {}))
            .mockRejectedValueOnce(createApiError(500, {}));
        const firstRender = renderCartView();

        await user.click(screen.getByRole('button', {name: /zahlungspflichtig bestellen/i}));
        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(1));
        firstRender.unmount();

        vi.mocked(useAuth).mockReturnValue({
            user: {...mockUser, id: 'u2', name: 'Second User'},
            isLoading: false,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });
        renderCartView();
        await user.click(screen.getByRole('button', {name: /zahlungspflichtig bestellen/i}));
        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(2));

        expect(idempotencyKeyForCall(1)).not.toBe(idempotencyKeyForCall(0));
        expect(sessionStorage.getItem(checkoutSessionStorageKey('u1'))).toContain(idempotencyKeyForCall(0));
        expect(sessionStorage.getItem(checkoutSessionStorageKey('u2'))).toContain(idempotencyKeyForCall(1));
    });

    it('starts a new idempotency attempt after an idempotency conflict', async () => {
        const user = userEvent.setup();
        setCartWithItems();
        vi.mocked(apiMutate)
            .mockRejectedValueOnce(createApiError(409, {idempotency_conflict: true}))
            .mockResolvedValueOnce({success: true, invoice_number: 'INV-CONFLICT-RETRY'});

        renderCartView();
        const checkoutButton = screen.getByRole('button', {name: /zahlungspflichtig bestellen/i});
        await user.click(checkoutButton);
        await user.click(checkoutButton);

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(2));
        expect(idempotencyKeyForCall(1)).toBeDefined();
        expect(idempotencyKeyForCall(1)).not.toBe(idempotencyKeyForCall(0));
    });

    it('renders Turnstile only when required and sends its token on the next submit', async () => {
        const user = userEvent.setup();
        setCartWithItems();
        vi.mocked(apiMutate)
            .mockRejectedValueOnce(createApiError(403, {turnstile_required: true}))
            .mockResolvedValueOnce({
                requires_action: true,
                client_secret: 'cs_test_turnstile',
                order_id: 'ord_turnstile',
                success: true
            });

        renderCartView();
        expect(screen.queryByRole('group', {name: 'Sicherheitsprüfung'})).not.toBeInTheDocument();

        const checkoutButton = screen.getByRole('button', {name: /zahlungspflichtig bestellen/i});
        await user.click(checkoutButton);

        await waitFor(() => {
            expect(screen.getByRole('group', {name: 'Sicherheitsprüfung'})).toBeInTheDocument();
        });
        expect(checkoutButton).toBeDisabled();
        expect(mockShowToast).toHaveBeenCalledWith(
            'info',
            'Bitte bestätige die Sicherheitsprüfung und versuche es erneut.'
        );

        await user.click(screen.getByRole('button', {name: 'Sicherheitsprüfung abschließen'}));
        expect(checkoutButton).toBeEnabled();
        await user.click(checkoutButton);

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(2));
        const secondPayload = vi.mocked(apiMutate).mock.calls[1][2] as Record<string, unknown>;
        expect(secondPayload.turnstile_token).toBe('turnstile-token');
        expect(idempotencyKeyForCall(1)).toBe(idempotencyKeyForCall(0));
    });

    it('clears a pending Turnstile challenge when switching to invoice checkout', async () => {
        const user = userEvent.setup();
        vi.mocked(useAuth).mockReturnValue({
            user: {...mockUser, roles: ['client']},
            isLoading: false,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });
        setCartWithItems();
        vi.mocked(apiMutate)
            .mockRejectedValueOnce(createApiError(403, {turnstile_required: true}))
            .mockResolvedValueOnce({success: true, invoice_number: 'INV-INVOICE'});

        renderCartView();
        const checkoutButton = screen.getByRole('button', {name: /zahlungspflichtig bestellen/i});
        await user.click(checkoutButton);
        await screen.findByRole('group', {name: 'Sicherheitsprüfung'});

        await user.click(screen.getByRole('radio', {name: 'Kauf auf Rechnung'}));
        expect(screen.queryByRole('group', {name: 'Sicherheitsprüfung'})).not.toBeInTheDocument();
        expect(checkoutButton).toBeEnabled();
        await user.click(checkoutButton);

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(2));
        const invoicePayload = vi.mocked(apiMutate).mock.calls[1][2] as Record<string, unknown>;
        expect(invoicePayload.payment_method).toBe('invoice');
        expect(invoicePayload.turnstile_token).toBeUndefined();
        expect(sessionStorage.getItem(checkoutSessionStorageKey(mockUser.id))).toBeNull();
    });

    it('does not render Turnstile for quote checkout paths', async () => {
        const user = userEvent.setup();
        vi.mocked(useCart).mockReturnValue({
            items: [{...mockCartItems[0], isQuote: true, price: 0}],
            quoteToken: null,
            setQuoteToken: vi.fn(),
            removeFromCart: vi.fn(),
            totalAmount: 0,
            clearCart: vi.fn(),
            addToCart: vi.fn(),
            itemCount: 1,
        });
        vi.mocked(apiMutate).mockRejectedValueOnce(createApiError(403, {turnstile_required: true}));

        renderCartView();
        await user.click(screen.getByRole('button', {name: /unverbindlich anfragen/i}));

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(1));
        expect(screen.queryByRole('group', {name: 'Sicherheitsprüfung'})).not.toBeInTheDocument();
    });

    it('clears and recreates Turnstile after a failed request consumes its one-time token', async () => {
        const user = userEvent.setup();
        setCartWithItems();
        vi.mocked(apiMutate)
            .mockRejectedValueOnce(createApiError(403, {turnstile_required: true}))
            .mockRejectedValueOnce(createApiError(500, {}))
            .mockResolvedValueOnce({
                requires_action: true,
                client_secret: 'cs_test_new_turnstile',
                order_id: 'ord_new_turnstile',
                success: true
            });

        renderCartView();
        const checkoutButton = screen.getByRole('button', {name: /zahlungspflichtig bestellen/i});
        await user.click(checkoutButton);
        await user.click(await screen.findByRole('button', {name: 'Sicherheitsprüfung abschließen'}));
        await user.click(checkoutButton);

        await waitFor(() => expect(checkoutButton).toBeDisabled());
        expect(screen.getByRole('group', {name: 'Sicherheitsprüfung'})).toBeInTheDocument();

        await user.click(screen.getByRole('button', {name: 'Sicherheitsprüfung abschließen'}));
        await user.click(checkoutButton);

        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(3));
        const secondPayload = vi.mocked(apiMutate).mock.calls[1][2] as Record<string, unknown>;
        const thirdPayload = vi.mocked(apiMutate).mock.calls[2][2] as Record<string, unknown>;
        expect(secondPayload.turnstile_token).toBe('turnstile-token');
        expect(thirdPayload.turnstile_token).toBe('turnstile-token');
        expect(idempotencyKeyForCall(1)).toBe(idempotencyKeyForCall(0));
        expect(idempotencyKeyForCall(2)).toBe(idempotencyKeyForCall(0));
    });

    it('resets the idempotency key after a terminal non-Stripe success', async () => {
        const user = userEvent.setup();
        setCartWithItems();
        vi.mocked(apiMutate)
            .mockResolvedValueOnce({success: true, invoice_number: 'INV-1'})
            .mockResolvedValueOnce({
                requires_action: true,
                client_secret: 'cs_test_new_attempt',
                order_id: 'ord_new_attempt',
                success: true
            });

        renderCartView();
        const checkoutButton = screen.getByRole('button', {name: /zahlungspflichtig bestellen/i});
        await user.click(checkoutButton);
        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(1));
        expect(sessionStorage.getItem(checkoutSessionStorageKey(mockUser.id))).toBeNull();

        await user.click(checkoutButton);
        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(2));
        expect(idempotencyKeyForCall(1)).toBeDefined();
        expect(idempotencyKeyForCall(1)).not.toBe(idempotencyKeyForCall(0));
    });

    it('fails closed without a retry loop when Turnstile is required but the site key is absent', async () => {
        const user = userEvent.setup();
        vi.stubEnv('VITE_TURNSTILE_SITE_KEY', '   ');
        setCartWithItems();
        vi.mocked(apiMutate).mockRejectedValueOnce(createApiError(403, {turnstile_required: true}));

        renderCartView();
        const checkoutButton = screen.getByRole('button', {name: /zahlungspflichtig bestellen/i});
        await user.click(checkoutButton);

        await waitFor(() => {
            expect(screen.getByRole('alert')).toHaveTextContent('Die Sicherheitsprüfung ist nicht konfiguriert.');
        });
        expect(screen.queryByRole('group', {name: 'Sicherheitsprüfung'})).not.toBeInTheDocument();
        expect(checkoutButton).toBeDisabled();
        expect(apiMutate).toHaveBeenCalledTimes(1);
        expect(mockShowToast).toHaveBeenCalledWith(
            'error',
            'Die Sicherheitsprüfung ist nicht konfiguriert. Bitte wende dich an den Support.'
        );
    });
});
