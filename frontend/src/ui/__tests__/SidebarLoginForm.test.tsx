import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import SidebarLoginForm from '../components/SidebarLoginForm';
import { useAuth } from '../../logic/useAuth';

vi.mock('../../logic/useAuth', () => ({
    useAuth: vi.fn(),
}));

function renderForm() {
    return render(<SidebarLoginForm />);
}

describe('SidebarLoginForm', () => {
    const mockLogin = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useAuth).mockReturnValue({
            user: undefined,
            isLoading: false,
            isError: undefined,
            login: mockLogin,
            register: vi.fn(),
            logout: vi.fn(),
            mutate: vi.fn(),
        });
    });

    it('renders login form with email and password fields', () => {
        renderForm();
        expect(screen.getByPlaceholderText('E-Mail Adresse')).toBeInTheDocument();
        expect(screen.getByPlaceholderText('Passwort')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Login' })).toBeInTheDocument();
    });

    it('shows validation error for empty email', async () => {
        const user = userEvent.setup();
        renderForm();

        await user.click(screen.getByRole('button', { name: 'Login' }));

        await waitFor(() => {
            expect(screen.getByText('Ungültige E-Mail-Adresse')).toBeInTheDocument();
        });
        expect(mockLogin).not.toHaveBeenCalled();
    });

    it('shows validation error for empty password', async () => {
        const user = userEvent.setup();
        renderForm();

        await user.type(screen.getByPlaceholderText('E-Mail Adresse'), 'test@example.com');
        await user.click(screen.getByRole('button', { name: 'Login' }));

        await waitFor(() => {
            expect(screen.getByPlaceholderText('Passwort')).toHaveClass('input-error');
        });
        expect(mockLogin).not.toHaveBeenCalled();
    });

    it('calls login API on submit with valid data', async () => {
        mockLogin.mockResolvedValue(undefined);
        const user = userEvent.setup();
        renderForm();

        await user.type(screen.getByPlaceholderText('E-Mail Adresse'), 'test@example.com');
        await user.type(screen.getByPlaceholderText('Passwort'), 'secret123');
        await user.click(screen.getByRole('button', { name: 'Login' }));

        await waitFor(() => {
            expect(mockLogin).toHaveBeenCalledWith('test@example.com', 'secret123');
        });
    });

    it('shows loading state during submission', async () => {
        mockLogin.mockImplementation(() => new Promise(() => {}));
        const user = userEvent.setup();
        renderForm();

        await user.type(screen.getByPlaceholderText('E-Mail Adresse'), 'test@example.com');
        await user.type(screen.getByPlaceholderText('Passwort'), 'secret123');
        await user.click(screen.getByRole('button', { name: 'Login' }));

        await waitFor(() => {
            expect(screen.getByRole('button')).toBeDisabled();
        });
    });

    it('shows error message on failed login', async () => {
        mockLogin.mockRejectedValue(new Error('Invalid credentials'));
        const user = userEvent.setup();
        renderForm();

        await user.type(screen.getByPlaceholderText('E-Mail Adresse'), 'test@example.com');
        await user.type(screen.getByPlaceholderText('Passwort'), 'secret123');
        await user.click(screen.getByRole('button', { name: 'Login' }));

        await waitFor(() => {
            expect(screen.getByText('Login fehlgeschlagen.')).toBeInTheDocument();
        });
    });
});
