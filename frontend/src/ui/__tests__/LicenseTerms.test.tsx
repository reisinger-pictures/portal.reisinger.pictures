import { describe, it, expect, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import { MemoryRouter } from 'react-router-dom';
import LicenseTerms from '../LicenseTerms';

vi.mock('../components/PageLayout', () => ({
    default: ({ children }: { children: React.ReactNode }) => (
        <div data-testid="page-layout">{children}</div>
    ),
}));

describe('LicenseTerms', () => {
    it('renders the AGB & license terms page with headline', () => {
        renderWithProviders(
            <MemoryRouter>
                <LicenseTerms />
            </MemoryRouter>,
        );

        expect(
            screen.getByRole('heading', { name: 'AGB & Lizenzbedingungen' }),
        ).toBeInTheDocument();
    });

    it('explains the early expiry of the right of withdrawal for digital content', () => {
        renderWithProviders(
            <MemoryRouter>
                <LicenseTerms />
            </MemoryRouter>,
        );

        // Central paragraph on legal basis for digital content (FAGG)
        expect(screen.getByText(/FAGG/i)).toBeInTheDocument();
        expect(
            screen.getAllByText(/Erlöschen des Rücktrittsrechts bei digitalen Inhalten/i).length,
        ).toBeGreaterThan(0);
        expect(screen.getAllByText(/Widerrufsbelehrung/i).length).toBeGreaterThan(0);
    });
});