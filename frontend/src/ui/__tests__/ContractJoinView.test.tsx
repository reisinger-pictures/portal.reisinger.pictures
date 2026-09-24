import { describe, it, expect, vi, beforeEach } from 'vitest';
import { act, fireEvent, screen, waitFor } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import ContractJoinView from '../ContractJoinView';
import { fetchJoinContract, submitJoin, type JoinContractResponse, type JoinResult } from '../../logic/useContractJoin';

const routerState = vi.hoisted(() => ({
    token: 'join-token-1',
    navigate: vi.fn(),
}));

vi.mock('react-router-dom', () => ({
    useParams: () => ({ token: routerState.token }),
    useNavigate: () => routerState.navigate,
}));

vi.mock('../../logic/useContractJoin', () => ({
    fetchJoinContract: vi.fn(),
    submitJoin: vi.fn(),
}));

vi.mock('../components/PageLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => <div data-testid="page-layout">{children}</div>,
}));

vi.mock('../components/ErrorMessage', () => ({
    default: ({ message }: { message: string }) => <div data-testid="error-message">{message}</div>,
}));

const firstContract: JoinContractResponse = {
    contract_id: 'contract-1',
    status: 'open',
    available_roles: ['Model'],
    allow_multiple_roles: false,
    terms_html: '<p>Erster Vertrag</p>',
};

const secondContract: JoinContractResponse = {
    contract_id: 'contract-2',
    status: 'open',
    available_roles: ['Fotograf'],
    allow_multiple_roles: false,
    terms_html: '<p>Zweiter Vertrag</p>',
};

function createDeferred<T>() {
    let resolve!: (value: T | PromiseLike<T>) => void;
    const promise = new Promise<T>((resolvePromise) => {
        resolve = resolvePromise;
    });
    return { promise, resolve };
}

describe('ContractJoinView token state', () => {
    beforeEach(() => {
        routerState.token = 'join-token-1';
        vi.mocked(fetchJoinContract).mockReset();
        vi.mocked(submitJoin).mockReset();
        routerState.navigate.mockReset();
    });

    it('resets identity, role, consent, error and data state for a new join token', async () => {
        const secondTokenFetch = createDeferred<JoinContractResponse>();
        vi.mocked(fetchJoinContract).mockImplementation((token) => {
            return token === 'join-token-1'
                ? Promise.resolve(firstContract)
                : secondTokenFetch.promise;
        });
        vi.mocked(submitJoin).mockRejectedValueOnce(new Error('Token 1 join failed'));

        const { rerender } = renderWithProviders(<ContractJoinView />);
        await waitFor(() => expect(screen.getByRole('button', { name: 'Model' })).toBeInTheDocument());

        fireEvent.change(screen.getByPlaceholderText('z.B. Maria Muster'), { target: { value: 'First User' } });
        fireEvent.change(screen.getByPlaceholderText('maria@beispiel.de'), { target: { value: 'first@example.com' } });
        fireEvent.click(screen.getByRole('button', { name: 'Model' }));
        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(screen.getByRole('button', { name: 'Vertraulich ansehen & unterschreiben' }));

        await waitFor(() => expect(screen.getByText('Token 1 join failed')).toBeInTheDocument());
        expect(submitJoin).toHaveBeenCalledWith('join-token-1', 'First User', 'first@example.com', ['Model']);

        routerState.token = 'join-token-2';
        rerender(<ContractJoinView />);

        expect(screen.queryByText('Token 1 join failed')).not.toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Model' })).not.toBeInTheDocument();
        expect(screen.getByTestId('page-layout').querySelector('.loading-spinner.loading-lg')).toBeInTheDocument();

        act(() => { secondTokenFetch.resolve(secondContract); });
        await waitFor(() => expect(screen.getByRole('button', { name: 'Fotograf' })).toBeInTheDocument());

        expect(screen.getByPlaceholderText('z.B. Maria Muster')).toHaveValue('');
        expect(screen.getByPlaceholderText('maria@beispiel.de')).toHaveValue('');
        expect(screen.getByRole('checkbox')).not.toBeChecked();
        expect(screen.getByRole('button', { name: 'Vertraulich ansehen & unterschreiben' })).toBeDisabled();
        expect(screen.queryByText('Token 1 join failed')).not.toBeInTheDocument();
    });

    it('ignores a join result that resolves after the route token changed', async () => {
        const submission = createDeferred<JoinResult>();
        vi.mocked(fetchJoinContract).mockImplementation((token) => {
            return Promise.resolve(token === 'join-token-1' ? firstContract : secondContract);
        });
        vi.mocked(submitJoin).mockReturnValue(submission.promise);

        const { rerender } = renderWithProviders(<ContractJoinView />);
        await waitFor(() => expect(screen.getByRole('button', { name: 'Model' })).toBeInTheDocument());

        fireEvent.change(screen.getByPlaceholderText('z.B. Maria Muster'), { target: { value: 'First User' } });
        fireEvent.change(screen.getByPlaceholderText('maria@beispiel.de'), { target: { value: 'first@example.com' } });
        fireEvent.click(screen.getByRole('button', { name: 'Model' }));
        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(screen.getByRole('button', { name: 'Vertraulich ansehen & unterschreiben' }));
        expect(submitJoin).toHaveBeenCalledOnce();

        routerState.token = 'join-token-2';
        rerender(<ContractJoinView />);
        await waitFor(() => expect(screen.getByRole('button', { name: 'Fotograf' })).toBeInTheDocument());

        await act(async () => {
            submission.resolve({ personal_token: 'old-personal-token', name: 'First User', roles: ['Model'] });
            await submission.promise;
        });

        expect(routerState.navigate).not.toHaveBeenCalled();
        expect(screen.getByRole('button', { name: 'Fotograf' })).toBeInTheDocument();
    });
});
