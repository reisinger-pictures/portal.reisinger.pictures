import {afterEach, beforeEach, describe, expect, it, vi} from 'vitest';
import {fireEvent, screen, waitFor} from '@testing-library/react';
import {MemoryRouter, Route, Routes, useLocation, useNavigate} from 'react-router-dom';
import {renderWithProviders} from '../../test-setup';
import InviteView from '../InviteView';
import {useAuth, type AuthMeUser} from '../../logic/useAuth';

vi.mock('../components/PageLayout', () => ({
    default: ({children}: {children: React.ReactNode}) => <main>{children}</main>,
}));

const inviteToken = 'invite-token';
const galleryPath = '/galleries/registered-gallery';

const registeredUser: AuthMeUser = {
    id: 'photographer-1',
    guest_id: null,
    name: 'Registered Photographer',
    email: 'photographer@example.com',
    billing_name: null,
    billing_company: null,
    billing_street: null,
    billing_zip: null,
    billing_city: null,
    brand: 'rp',
    is_cross_brand: false,
    is_super_admin: false,
    is_admin: false,
    is_photographer: true,
    is_org_admin: false,
    is_power_user: false,
    is_pending: false,
    can_edit_metadata: false,
    can_purchase_upgrades: false,
    roles: ['Fotograf'],
    transient_galleries: [],
    transient_meta_galleries: [],
    photographer_gallery_groups: [],
};

const guestUser: AuthMeUser = {
    ...registeredUser,
    id: 'guest-1',
    guest_id: 'guest-1',
    name: 'Gast Bewerter',
    email: 'guest@example.com',
    is_photographer: false,
};

type Session = 'anonymous' | 'guest' | 'registered';

function jsonResponse(body: unknown, status = 200): Response {
    return new Response(JSON.stringify(body), {
        status,
        headers: {'Content-Type': 'application/json'},
    });
}

function AuthControls() {
    const {user, login} = useAuth();
    const navigate = useNavigate();

    return (
        <>
            <span data-testid="auth-identity">{user?.guest_id ?? (user ? 'registered' : 'anonymous')}</span>
            <button type="button" onClick={() => {
                void login('photographer@example.com', 'SecurePassword123!');
            }}>login</button>
            <button type="button" onClick={() => navigate('/')}>home</button>
            <button type="button" onClick={() => navigate(`/invite/${inviteToken}`)}>invite</button>
        </>
    );
}

function LocationProbe() {
    const location = useLocation();
    return <span data-testid="location">{location.pathname}</span>;
}

function App() {
    return (
        <MemoryRouter initialEntries={[`/invite/${inviteToken}`]}>
            <AuthControls/>
            <LocationProbe/>
            <Routes>
                <Route path="/invite/:token" element={<InviteView/>}/>
                <Route path="/galleries/:slug" element={<div data-testid="gallery-route"/>}/>
                <Route path="/" element={<div data-testid="home-route"/>}/>
            </Routes>
        </MemoryRouter>
    );
}

describe('InviteView auth transition integration', () => {
    let session: Session;
    let redeemCalls: number;
    let fetchCalls: string[];

    beforeEach(() => {
        session = 'anonymous';
        redeemCalls = 0;
        fetchCalls = [];
        vi.stubGlobal('fetch', vi.fn(async (input: RequestInfo | URL) => {
            const url = typeof input === 'string' ? input : input.toString();
            fetchCalls.push(url);
            if (url === '/api/auth/me') {
                if (session === 'anonymous') return jsonResponse({error: 'Unauthenticated.'}, 401);
                return jsonResponse(session === 'guest' ? guestUser : registeredUser);
            }
            if (url === '/api/auth/refresh') {
                return session === 'anonymous' ? jsonResponse({error: 'invalid'}, 401) : jsonResponse({success: true});
            }
            if (url === '/api/auth/login') {
                session = 'registered';
                return jsonResponse({success: true});
            }
            if (url === '/api/auth/logout') {
                session = 'anonymous';
                return jsonResponse({message: 'Successfully logged out'});
            }
            if (url === `/api/invites/${inviteToken}`) {
                return jsonResponse({gallery_name: 'Registered Gallery', requires_password: false, invite_name: ''});
            }
            if (url === '/api/invites/redeem') {
                redeemCalls += 1;
                if (session === 'anonymous') session = 'guest';
                return jsonResponse({full_path: galleryPath.slice(1)});
            }
            throw new Error(`Unexpected fetch: ${url}`);
        }));
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('redeems the same invite after a guest is cleared and a registered user logs in', async () => {
        const clearGuestSession = () => {
            session = 'anonymous';
        };

        renderWithProviders(<App/>);

        await waitFor(() => expect(screen.getByText('Willkommen zur Fotoauswahl')).toBeInTheDocument());
        fireEvent.change(screen.getByPlaceholderText('z.B. Maria Muster'), {target: {value: 'Gast Bewerter'}});
        fireEvent.change(screen.getByPlaceholderText('maria@beispiel.de'), {target: {value: 'guest@example.com'}});
        fireEvent.click(screen.getByRole('checkbox'));
        fireEvent.click(screen.getByRole('button', {name: 'Galerie öffnen'}));

        await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(galleryPath));
        expect(screen.getByTestId('auth-identity')).toHaveTextContent('guest-1');

        clearGuestSession();
        fireEvent.click(screen.getByRole('button', {name: 'home'}));
        await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent('/'));
        fireEvent.click(screen.getByRole('button', {name: 'login'}));
        await waitFor(() => expect(screen.getByTestId('auth-identity')).toHaveTextContent('registered'));
        fireEvent.click(screen.getByRole('button', {name: 'invite'}));
        await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(`/invite/${inviteToken}`));
        await waitFor(() => expect(fetchCalls.filter(url => url === `/api/invites/${inviteToken}`)).toHaveLength(2), {timeout: 5000});
        await waitFor(() => expect(redeemCalls).toBe(2), {timeout: 5000});
        await waitFor(() => expect(screen.getByTestId('location')).toHaveTextContent(galleryPath), {timeout: 5000});
        expect(redeemCalls).toBe(2);
    }, 15000);
});
