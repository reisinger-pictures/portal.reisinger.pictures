import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {screen, waitFor} from '@testing-library/react';
import {MemoryRouter} from 'react-router-dom';
import {renderWithProviders} from '../../test-setup';
import InviteView from '../InviteView';
import {useAuth} from '../../logic/useAuth';
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

describe('InviteView invite lookup', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockToken.value = 'invite-token';
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

    it('ignores an older lookup after the invite token changes', async () => {
        const oldLookup = deferredResponse();
        const newLookup = deferredResponse();
        const fetchMock = vi.fn((input: RequestInfo | URL, _init?: RequestInit) =>
            input.toString().includes('old-token') ? oldLookup.promise : newLookup.promise
        );
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
