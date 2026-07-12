import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, fireEvent, act } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import ContractSignView from '../ContractSignView';
import { fetchSignContract } from '../../logic/useContractJoin';

// DOMPurify needs a real DOM; the jsdom environment is set globally via vitest config.

// --------------------------------------------------------------------------
// Mocks
// --------------------------------------------------------------------------

vi.mock('react-router-dom', () => ({
    useParams: () => ({ token: 'test-token-123' }),
    useNavigate: () => vi.fn(),
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

const version1Data = {
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

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

describe('ContractSignView stale detection', () => {
    beforeEach(() => {
        vi.clearAllMocks();
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

        const { useContractHeartbeat } = await import('../../logic/useContractHeartbeat');
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

        const { useContractHeartbeat } = await import('../../logic/useContractHeartbeat');
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
});
