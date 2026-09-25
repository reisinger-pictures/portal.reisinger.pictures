import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {screen, waitFor} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import {renderWithProviders} from '../../test-setup';
import InviteView from '../InviteView';
import {useAuth, type AuthMeUser} from '../../logic/useAuth';
import {apiMutate} from '../../api';

const {mockToken, mockNavigate, mockMutate} = vi.hoisted(() => ({
    mockToken: {value: 'invite-token'},
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

function renderInvite() {
    return renderWithProviders(
        <MemoryRouter>
            <InviteView/>
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

function authState(user: AuthMeUser | undefined) {
    return {
        user,
        isLoading: false,
        isError: undefined,
        login: vi.fn(),
        register: vi.fn(),
        logout: vi.fn(),
        mutate: vi.fn(),
    };
}

describe('InviteView invite lookup', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockNavigate.mockReset();
        mockMutate.mockReset();
        mockToken.value = 'invite-token';
        vi.mocked(useAuth).mockReturnValue(authState(undefined));
        vi.mocked(apiMutate).mockResolvedValue(undefined);
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('does not auto-redeem a public invite for a transient guest', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            gallery_name: 'Gast Galerie',
            requires_password: false,
            invite_name: '',
        }), {status: 200, headers: {'Content-Type': 'application/json'}}));
        vi.stubGlobal('fetch', fetchMock);
        vi.mocked(useAuth).mockReturnValue(authState(undefined));

        const view = renderInvite();
        await waitFor(() => expect(screen.getByText('Gast Galerie')).toBeInTheDocument());

        // A successful anonymous redemption changes /api/auth/me to a guest,
        // but that must not trigger a second automatic redemption.
        vi.mocked(useAuth).mockReturnValue(authState({
            id: 'guest-1',
            guest_id: 'guest-1',
        } as AuthMeUser));
        view.rerender(
            <MemoryRouter>
                <InviteView/>
            </MemoryRouter>,
        );
        await waitFor(() => expect(apiMutate).not.toHaveBeenCalled());
    });

    it('navigates after a registered user redemption even when auth revalidation rerenders the view', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            gallery_name: 'Registrierte Galerie',
            requires_password: false,
            invite_name: '',
        }), {status: 200, headers: {'Content-Type': 'application/json'}}));
        vi.stubGlobal('fetch', fetchMock);

        const registeredUser = {
            id: 'photographer-1',
            guest_id: null,
        } as AuthMeUser;
        vi.mocked(useAuth).mockReturnValue(authState(registeredUser));
        vi.mocked(apiMutate).mockResolvedValue({full_path: 'galleries/registered-gallery'});

        let resolveMutate: () => void = () => undefined;
        vi.mocked(mockMutate).mockImplementation(() => new Promise<void>((resolve) => {
            resolveMutate = resolve;
        }));

        const view = renderInvite();
        await waitFor(() => expect(apiMutate).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(mockMutate).toHaveBeenCalledTimes(1));
        expect(mockMutate).toHaveBeenCalledWith('/api/auth/me', undefined, {revalidate: true});
        await waitFor(() => expect(mockNavigate).toHaveBeenCalledWith(
            '/galleries/registered-gallery',
            {replace: true},
        ));

        // SWR revalidation returns a fresh object for the same identity. The
        // primitive identity dependencies must not cancel a successful redeem.
        vi.mocked(useAuth).mockReturnValue(authState({...registeredUser} as AuthMeUser));
        view.rerender(
            <MemoryRouter>
                <InviteView/>
            </MemoryRouter>,
        );
        resolveMutate();
    });

    it('ignores an older lookup after the invite token changes', async () => {
        const oldLookup = deferredResponse();
        const newLookup = deferredResponse();
        const fetchMock = vi.fn((input: RequestInfo | URL, init?: RequestInit) => {
            if (init?.signal?.aborted) {
                return Promise.reject(init.signal.reason ?? new DOMException('Aborted', 'AbortError'));
            }
            return input.toString().includes('old-token') ? oldLookup.promise : newLookup.promise;
        });
        vi.stubGlobal('fetch', fetchMock);

        mockToken.value = 'old-token';
        const view = renderInvite();
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(1));

        mockToken.value = 'new-token';
        view.rerender(
            <MemoryRouter>
                <InviteView/>
            </MemoryRouter>,
        );
        await waitFor(() => expect(fetchMock).toHaveBeenCalledTimes(2));

        newLookup.resolve(new Response(JSON.stringify({
            gallery_name: 'Neue Galerie',
            requires_password: false,
            invite_name: 'Neuer Gast',
        }), {status: 200, headers: {'Content-Type': 'application/json'}}));
        await waitFor(() => expect(screen.getByText('Neue Galerie')).toBeInTheDocument());

        oldLookup.resolve(new Response(JSON.stringify({
            gallery_name: 'Alte Galerie',
            requires_password: true,
            invite_name: 'Alter Gast',
        }), {status: 200, headers: {'Content-Type': 'application/json'}}));
        await waitFor(() => expect(screen.queryByText('Alte Galerie')).not.toBeInTheDocument());

        expect(screen.getByText('Neue Galerie')).toBeInTheDocument();
        expect(fetchMock.mock.calls[0][1]?.signal).toMatchObject({aborted: true});
        expect(fetchMock).not.toHaveBeenCalledWith('/api/auth/refresh', expect.anything());
    });

    it('keeps public lookup failures local without attempting auth refresh', async () => {
        const fetchMock = vi.fn().mockResolvedValue(new Response(JSON.stringify({
            error: 'ungültig',
        }), {status: 401, headers: {'Content-Type': 'application/json'}}));
        vi.stubGlobal('fetch', fetchMock);

        renderInvite();

        await waitFor(() => expect(screen.getByText('Dieser Einladungslink ist ungültig oder abgelaufen.')).toBeInTheDocument());
        expect(fetchMock).toHaveBeenCalledTimes(1);
        expect(fetchMock).not.toHaveBeenCalledWith('/api/auth/refresh', expect.anything());
    });
});
