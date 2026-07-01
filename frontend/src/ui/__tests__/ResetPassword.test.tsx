import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import ResetPassword from '../ResetPassword';
import { apiMutate } from '../../api';

const mockSetSearchParams = vi.fn();
let mockSearchParams = new URLSearchParams('token=abc123&email=user@example.com');

vi.mock('react-router-dom', async () => {
    const actual = await vi.importActual<typeof import('react-router-dom')>('react-router-dom');
    return {
        ...actual,
        useNavigate: () => vi.fn(),
        useSearchParams: () => [mockSearchParams, mockSetSearchParams],
    };
});

vi.mock('../../api', () => ({
    apiMutate: vi.fn(),
}));

function renderResetPassword() {
    return render(
        <MemoryRouter>
            <ResetPassword />
        </MemoryRouter>,
    );
}

describe('ResetPassword', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        mockSearchParams = new URLSearchParams('token=abc123&email=user@example.com');
        vi.stubGlobal('fetch', vi.fn().mockResolvedValue({ ok: true }));
    });

    it('shows error when token or email is missing', () => {
        mockSearchParams = new URLSearchParams('');

        renderResetPassword();
        expect(screen.getByText('Ungültiger Link. Token oder E-Mail fehlen.')).toBeInTheDocument();
    });

    it('renders password reset form with email address', () => {
        renderResetPassword();
        expect(screen.getByText('Konto einrichten')).toBeInTheDocument();
        expect(screen.getByText('user@example.com')).toBeInTheDocument();
        expect(screen.getByText('Passwort speichern & Anmelden')).toBeInTheDocument();
    });

    it('shows validation error for short password', async () => {
        const user = userEvent.setup();
        const { container } = render(<MemoryRouter><ResetPassword /></MemoryRouter>);

        const passwordInput = container.querySelector('input[name="password"]')!;
        await user.type(passwordInput, 'short');
        await user.click(screen.getByText('Passwort speichern & Anmelden'));

        await waitFor(() => {
            expect(screen.getByText('Das Passwort muss mindestens 8 Zeichen lang sein.')).toBeInTheDocument();
        });
    });

    it('shows validation error for mismatched passwords', async () => {
        const user = userEvent.setup();
        const { container } = render(<MemoryRouter><ResetPassword /></MemoryRouter>);

        await user.type(container.querySelector('input[name="password"]')!, 'password123');
        await user.type(container.querySelector('input[name="passwordConfirm"]')!, 'different456');
        await user.click(screen.getByText('Passwort speichern & Anmelden'));

        await waitFor(() => {
            expect(screen.getByText('Die Passwörter stimmen nicht überein.')).toBeInTheDocument();
        });
    });

    it('calls API on submit and navigates on success', async () => {
        vi.mocked(apiMutate).mockResolvedValue(undefined);
        const user = userEvent.setup();
        const { container } = render(<MemoryRouter><ResetPassword /></MemoryRouter>);

        await user.type(container.querySelector('input[name="password"]')!, 'newpassword123');
        await user.type(container.querySelector('input[name="passwordConfirm"]')!, 'newpassword123');
        await user.click(screen.getByText('Passwort speichern & Anmelden'));

        await waitFor(() => {
            expect(apiMutate).toHaveBeenCalledWith('/api/auth/reset-password', 'POST', {
                email: 'user@example.com',
                token: 'abc123',
                password: 'newpassword123',
            });
        });
    });
});
