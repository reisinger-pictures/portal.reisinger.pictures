import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import ClientCartView from '../client/ClientCartView';
import { useCart } from '../../logic/CartContext';
import { useAuth } from '../../logic/useAuth';
import { apiMutate } from '../../api';

// --------------------------------------------------------------------------
// Mocks — external modules
// --------------------------------------------------------------------------

// Keep real router components, mock useNavigate and useSearchParams
vi.mock('react-router-dom', async () => {
    const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
    return {
        ...actual,
        useNavigate: () => vi.fn(),
        useSearchParams: () => [new URLSearchParams(), vi.fn()],
    };
});

vi.mock('@stripe/stripe-js', () => ({
    loadStripe: vi.fn(() => Promise.resolve({} as import('@stripe/stripe-js').Stripe)),
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
        showToast: vi.fn(),
    })),
}));

vi.mock('../../logic/useAuth', () => ({
    useAuth: vi.fn(),
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
    StripeCheckoutForm: () => <div data-testid="stripe-checkout-form" />,
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
        reset: vi.fn(),
        setError: vi.fn(),
        formState: { errors: {}, isSubmitting: false },
    })),
}));

// --------------------------------------------------------------------------
// Test data
// --------------------------------------------------------------------------

const mockUser = {
    id: 'u1',
    name: 'Test User',
    email: 'user@example.com',
    billing_name: 'Test User',
    billing_company: '',
    billing_street: '',
    billing_zip: '',
    billing_city: '',
    is_super_admin: false,
    is_admin: false,
    is_photographer: false,
    is_pending: false,
    can_edit_metadata: false,
    roles: [],
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
        removeFromCart: vi.fn(),
        totalAmount: 0,
        clearCart: vi.fn(),
        addToCart: vi.fn(),
        itemCount: 0,
    });
}

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
        setupDefaultMocks();
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

    // ------------------------------------------------------------------
    // form controls (checkbox area)
    // ------------------------------------------------------------------

    it('renders AGB checkbox and withdrawal waiver', () => {
        vi.mocked(useCart).mockReturnValue({
            items: mockCartItems,
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
});
