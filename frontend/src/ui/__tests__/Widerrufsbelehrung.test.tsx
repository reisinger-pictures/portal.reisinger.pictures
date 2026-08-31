import { describe, it, expect, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import { MemoryRouter } from 'react-router-dom';
import Widerrufsbelehrung from '../Widerrufsbelehrung';

vi.mock('../components/PageLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <div data-testid="page-layout">{children}</div>
    ),
}));

describe('Widerrufsbelehrung', () => {
    it('renders the withdrawal policy with headline', () => {
        renderWithProviders(
            <MemoryRouter>
                <Widerrufsbelehrung />
            </MemoryRouter>,
        );

        expect(
            screen.getByRole('heading', { name: 'Widerrufsbelehrung' }),
        ).toBeInTheDocument();
    });

    it('states the 14-day withdrawal period, the model form and the digital-content expiry', () => {
        renderWithProviders(
            <MemoryRouter>
                <Widerrufsbelehrung />
            </MemoryRouter>,
        );

        expect(screen.getAllByText(/vierzehn Tagen/i).length).toBeGreaterThan(0);
        expect(screen.getAllByText(/Muster-Widerrufsformular/i).length).toBeGreaterThan(0);
        expect(
            screen.getAllByText(/Erlöschen des Rücktrittsrechts bei digitalen Inhalten/i).length,
        ).toBeGreaterThan(0);
        expect(screen.getByText(/sofortiger Download/i)).toBeInTheDocument();
    });
});