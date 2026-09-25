import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {fireEvent, screen, waitFor} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import {renderWithProviders} from '../../test-setup';
import OrgInviteView from '../OrgInviteView';
import {useAuth} from '../../logic/useAuth';
import {apiMutate} from '../../api';

const {mockToken, mockNavigate, mockMutate} = vi.hoisted(() => ({
    mockToken: {value: 'org-token'},
    mockNavigate: vi.fn(),
    mockMutate: vi.fn(),
}));

vi.mock('react-router-dom', async () => {
    const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
    return {
        ...actual,
        useNavigate: () => mockNavigate,
        useParams: () => ({token: mockToken.value}),
    };
});

vi.mock('swr', () => ({
    useSWRConfig: () => ({mutate: mockMutate}),
}));

vi.mock('../../logic/useAuth', () => ({
    useAuth: vi.fn(),
}));

vi.mock('../../api', () => ({
    apiMutate: vi.fn(),
}));

vi.mock('../components/PageLayout', () => ({
    default: ({children}: {children: React.ReactNode}) => <div data-testid="page-layout">{children}</div>,
}));

function renderOrgInvite() {
    return renderWithProviders(
        <MemoryRouter>
            <OrgInviteView/>
        </MemoryRouter>,
    );
}

function deferredResponse() {
    let resolve: (response: Response) => void = () => undefined;
    const promise = new Promise<Response>((settle) => {
        resolve = settle;
    });
    return {promise, resolve};
}

describe('OrgInviteView invite lookup', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockToken.value = 'org-token';
        vi.mocked(useAuth).mockReturnValue({
            user: undefined,
            isLoading: false,
            isError: undefined,
            login: vi.fn(),
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });
        vi.mocked(apiMutate).mockResolvedValue(undefined);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('ignores an older organization lookup after the token changes', async () => {
        const oldLookup = deferredResponse();
        const newLookup = deferredResponse();
        const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
            void init;
            return input.toString().includes('old-token') ? oldLookup.promise : newLookup.promise;
        });
        vi.stubGlobal('fetch', fetchMock);

        mockToken.value = 'old-token';
        const view = renderOrgInvite();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        mockToken.value = 'new-token';
        view.rerender(
            <MemoryRouter>
                <OrgInviteView/>
            </MemoryRouter>,
        );
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));

        newLookup.resolve(new Response(JSON.stringify({
            org_name: 'Neue Organisation',
            email: 'new@example.com',
        }), {status: 200, headers: {'Content-Type': 'application/json'}}));
        await waitFor(() => expect(screen.getAllByText('Neue Organisation')).not.toHaveLength(0));

        oldLookup.resolve(new Response(JSON.stringify({
            org_name: 'Alte Organisation',
            email: 'old@example.com',
        }), {status: 200, headers: {'Content-Type': 'application/json'}}));
        await waitFor(() => expect(screen.queryByText('Alte Organisation')).not.toBeInTheDocument());

        expect(screen.getAllByText('Neue Organisation')).not.toHaveLength(0);
        expect(fetchMock.mock.calls[0][1]?.signal).toMatchObject({aborted: true});
        expect(fetchMock).not.toHaveBeenCalledWith('/api/auth/refresh', expect.anything());
    });

    it('does not attempt centralized refresh for a public organization lookup error', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(null, {status: 401}));
        vi.stubGlobal('fetch', fetchMock);

        renderOrgInvite();

        await waitFor(() => expect(screen.getByText('Dieser Einladungslink ist ungültig oder abgelaufen.')).toBeInTheDocument());
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).not.toHaveBeenCalledWith('/api/auth/refresh', expect.anything());
    });

    it('opens the privacy policy with opener isolation', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            org_name: 'Sicherheitsorganisation',
            email: 'member@example.com',
        }), {status: 200, headers: {'Content-Type': 'application/json'}}));
        vi.stubGlobal('fetch', fetchMock);

        renderOrgInvite();

        await waitFor(() => expect(screen.getByRole('button', {name: 'Beitreten & Fortfahren'})).toBeInTheDocument());
        fireEvent.click(screen.getByRole('button', {name: 'Beitreten & Fortfahren'}));

        const privacyLink = screen.getByRole('link', {name: 'Datenschutzerklärung'});
        expect(privacyLink).toHaveAttribute('target', '_blank');
        expect(privacyLink).toHaveAttribute('rel', 'noopener noreferrer');
    });
});
