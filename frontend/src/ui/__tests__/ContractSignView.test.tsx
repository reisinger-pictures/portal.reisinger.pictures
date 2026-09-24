import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent, act } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import ContractSignView from '../ContractSignView';
import { fetchSignContract, sendPageExit, submitSign, type SignContractResponse } from '../../logic/useContractJoin';
import { useContractHeartbeat } from '../../logic/useContractHeartbeat';

// DOMPurify needs a real DOM; the jsdom environment is set globally via vitest config.

// --------------------------------------------------------------------------
// Mocks
// --------------------------------------------------------------------------

const routerState = vi.hoisted(() => ({
    token: 'test-token-123',
    navigate: vi.fn(),
}));

vi.mock('react-router-dom', () => ({
    useParams: () => ({ token: routerState.token }),
    useNavigate: () => routerState.navigate,
}));

vi.mock('../../logic/useContractJoin', () => ({
    fetchSignContract: vi.fn(),
    submitSign: vi.fn(),
    sendPageExit: vi.fn(),
}));

vi.mock('../../logic/useContractHeartbeat', () => ({
    useContractHeartbeat: vi.fn(),
}));

vi.mock('../components/PageLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div data-testid="page-layout">{children}</div>,
}));

vi.mock('../components/ErrorMessage', () => ({
    default: ({ message }: { message: string }) => <div data-testid="error-message">{message}</div>,
}));

// --------------------------------------------------------------------------
// Test data
// --------------------------------------------------------------------------

const version1Data: SignContractResponse = {
    contract: {
        id: 'contract-1',
        terms_html: '<p>Version 1</p>',
        items: [],
        discounts: [],
        billing_details: null,
        available_roles: ['Model'],
        content_version: 0,
    },
    signer: {
        id: 'signer-1',
        name: 'Test User',
        email: 'test@example.com',
        roles: ['Model'],
        status: 'joined',
    },
};

const version2Data: SignContractResponse = {
    contract: {
        id: 'contract-2',
        terms_html: '<p>Version 2</p>',
        items: [],
        discounts: [],
        billing_details: null,
        available_roles: ['Fotograf'],
        content_version: 1,
    },
    signer: {
        id: 'signer-2',
        name: 'Second User',
        email: 'second@example.com',
        roles: ['Fotograf'],
        status: 'joined',
    },
};

const version3Data: SignContractResponse = {
    contract: {
        id: 'contract-3',
        terms_html: '<p>Version 3</p>',
        items: [],
        discounts: [],
        billing_details: null,
        available_roles: ['Model'],
        content_version: 2,
    },
    signer: {
        id: 'signer-3',
        name: 'Third User',
        email: 'third@example.com',
        roles: ['Model'],
        status: 'joined',
    },
};

function createDeferred<T>() {
    let resolve!: (value: T | PromiseLike<T>) => void;
    const promise = new Promise<T>((resolvePromise) => {
        resolve = resolvePromise;
    });
    return { promise, resolve };
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

describe('ContractSignView stale detection', () => {
    beforeEach(() => {
        routerState.token = 'test-token-123';
        vi.mocked(fetchSignContract).mockReset();
        vi.mocked(sendPageExit).mockReset();
        vi.mocked(submitSign).mockReset();
        vi.mocked(useContractHeartbeat).mockReset();
        routerState.navigate.mockReset();
    });

    it('renders contract content on load', async () => {
        vi.mocked(fetchSignContract).mockResolvedValueOnce(version1Data);

        renderWithProviders(<ContractSignView />);

        await waitFor(() => {
            expect(screen.getByText('Version 1')).toBeInTheDocument();
        });

        expect(screen.getByText('Test User')).toBeInTheDocument();
        expect(screen.getByText('test@example.com')).toBeInTheDocument();
    });

    it('shows stale warning when heartbeat triggers isStale', async () => {
        vi.mocked(fetchSignContract).mockResolvedValueOnce(version1Data);

        renderWithProviders(<ContractSignView />);

        await waitFor(() => {
            expect(screen.getByText('Version 1')).toBeInTheDocument();
        });

        const heartbeatHook = vi.mocked(useContractHeartbeat);
        const onStale = heartbeatHook.mock.calls[0][3];

        act(() => { onStale(); });

        expect(screen.getByText('Vertrag wurde geändert')).toBeInTheDocument();
    });

    it('disables sign button when stale', async () => {
        vi.mocked(fetchSignContract).mockResolvedValueOnce(version1Data);

        renderWithProviders(<ContractSignView />);

        await waitFor(() => {
            expect(screen.getByText('Version 1')).toBeInTheDocument();
        });

        const checkbox = screen.getByRole('checkbox');
        fireEvent.click(checkbox);
        expect(checkbox).toBeChecked();

        const signButton = screen.getByRole('button', { name: 'Vertrag verbindlich abschließen' });
        expect(signButton).toBeEnabled();

        const heartbeatHook = vi.mocked(useContractHeartbeat);
        const onStale = heartbeatHook.mock.calls[0][3];

        act(() => { onStale(); });

        expect(screen.getByRole('button', { name: 'Vertrag verbindlich abschließen' })).toBeDisabled();
    });

    it('sanitizes XSS payloads from terms_html before rendering (C5 regression)', async () => {
        // terms_html contains a malicious <script> tag + a safe paragraph.
        // The script must be stripped before it reaches the DOM.
        const xssData = {
            contract: {
                id: 'contract-xss',
                terms_html: '<script>alert("xss")</script><p>safe content</p>',
                items: [],
                discounts: [],
                billing_details: null,
                available_roles: ['Model'],
                content_version: 0,
            },
            signer: {
                id: 'signer-1',
                name: 'Test User',
                email: 'test@example.com',
                roles: ['Model'],
                status: 'joined',
            },
        };
        vi.mocked(fetchSignContract).mockResolvedValueOnce(xssData);

        const { container } = renderWithProviders(<ContractSignView />);

        await waitFor(() => {
            expect(screen.getByText('safe content')).toBeInTheDocument();
        });

        // Safe paragraph rendered inside the .editor-content container
        const editorContent = container.querySelector('.editor-content');
        expect(editorContent?.textContent).toContain('safe content');
        expect(editorContent?.querySelector('script')).toBeNull();
        // No script anywhere in the rendered terms
        expect(container.innerHTML).not.toContain('alert("xss")');
    });

    it('applies percentage discounts when computing the grand total (backend snapshot semantics)', async () => {
        const dataWithDiscounts = {
            contract: {
                id: 'contract-discounts',
                terms_html: '<p>Version 1</p>',
                items: [
                    { type: 'item', description: 'Fotos', notes: '', qty: 2, price: 5000, row_total: 10000 },
                ],
                discounts: [
                    // 10% => stored as basis points (percent × 100)
                    { type: 'discount_percent', description: '10% Rabatt', notes: '', price: 1000 },
                    { type: 'discount_fixed', description: 'Bonus', notes: '', price: 500 },
                ],
                billing_details: null,
                available_roles: ['Model'],
                content_version: 0,
            },
            signer: {
                id: 'signer-1',
                name: 'Test User',
                email: 'test@example.com',
                roles: ['Model'],
                status: 'joined',
            },
        };
        vi.mocked(fetchSignContract).mockResolvedValueOnce(dataWithDiscounts);

        renderWithProviders(<ContractSignView />);

        await waitFor(() => {
            expect(screen.getByText('Gesamtbetrag')).toBeInTheDocument();
        });

        // 10000 - round(10000 × 1000 / 10000) = 9000; 9000 - 500 = 8500
        expect(screen.getByText('85,00 €')).toBeInTheDocument();
    });

    it('resets consent, stale, error, signed and data state for a new personal token', async () => {
        const thirdTokenFetch = createDeferred<SignContractResponse>();
        vi.mocked(fetchSignContract).mockImplementation((token) => {
            if (token === 'token-1') return Promise.resolve(version1Data);
            if (token === 'token-2') return Promise.resolve(version2Data);
            return thirdTokenFetch.promise;
        });
        vi.mocked(submitSign)
            .mockRejectedValueOnce(new Error('Token 1 signing failed'))
            .mockResolvedValueOnce({ success: true, message: 'Token 2 signed' });

        routerState.token = 'token-1';
        const { rerender } = renderWithProviders(<ContractSignView />);
        await waitFor(() => expect(screen.getByText('Version 1')).toBeInTheDocument());

        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(screen.getByRole('button', { name: 'Vertrag verbindlich abschließen' }));
        await waitFor(() => expect(screen.getByText('Token 1 signing failed')).toBeInTheDocument());

        const onStale = vi.mocked(useContractHeartbeat).mock.calls.at(-1)?.[3];
        if (!onStale) throw new Error('Heartbeat callback was not registered');
        act(() => { onStale(); });
        expect(screen.getByText('Vertrag wurde geändert')).toBeInTheDocument();

        routerState.token = 'token-2';
        rerender(<ContractSignView />);

        await waitFor(() => expect(screen.getByText('Version 2')).toBeInTheDocument());
        expect(screen.getByText('Second User')).toBeInTheDocument();
        expect(screen.queryByText('Token 1 signing failed')).not.toBeInTheDocument();
        expect(screen.queryByText('Vertrag wurde geändert')).not.toBeInTheDocument();
        expect(screen.getByRole('checkbox')).not.toBeChecked();

        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(screen.getByRole('button', { name: 'Vertrag verbindlich abschließen' }));
        await waitFor(() => expect(screen.getByText('Vertrag unterschrieben!')).toBeInTheDocument());

        routerState.token = 'token-3';
        rerender(<ContractSignView />);

        expect(screen.queryByText('Vertrag unterschrieben!')).not.toBeInTheDocument();
        expect(screen.queryByText('Second User')).not.toBeInTheDocument();
        expect(screen.getByTestId('page-layout').querySelector('.loading-spinner.loading-lg')).toBeInTheDocument();

        act(() => { thirdTokenFetch.resolve(version3Data); });
        await waitFor(() => expect(screen.getByText('Version 3')).toBeInTheDocument());

        expect(screen.getByText('Third User')).toBeInTheDocument();
        expect(screen.getByRole('checkbox')).not.toBeChecked();
        expect(screen.getByRole('button', { name: 'Vertrag verbindlich abschließen' })).toBeDisabled();
    });
});
