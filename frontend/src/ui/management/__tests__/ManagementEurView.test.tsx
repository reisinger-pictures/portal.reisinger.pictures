import { describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { useBrand } from '../../../logic/useBrand';
import { useBillingDetails } from '../../../logic/useLicenseTerms';
import { renderWithProviders } from '../../../test-setup';
import ManagementEurView from '../ManagementEurView';

vi.mock('../../../logic/useBrand', () => ({
    useBrand: vi.fn(),
}));

vi.mock('../../../logic/useLicenseTerms', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../logic/useLicenseTerms')>();
    return {...actual, useBillingDetails: vi.fn()};
});

const billingDetails = {
    bank_holder: 'Musterfoto GmbH',
    bank_iban: 'AT11 2222 3333 4444 5555',
    bank_bic: '',
    company_street: 'Musterstraße 1',
    company_zip: '4020',
    company_city: 'Linz',
    company_country: 'Österreich',
    company_email: 'office@example.at',
    steuernummer: '12/345/67890',
    finanzamt: 'Finanzamt Linz',
    uid: 'ATU12345678',
    rechtsform: 'GmbH',
};

function renderView() {
    return renderWithProviders(
        <MemoryRouter initialEntries={['/eur']}>
            <main>
                <ManagementEurView/>
            </main>
        </MemoryRouter>,
    );
}

describe('ManagementEurView', () => {
    it('shows the current brand, the company data from settings and useful EÜR facts, with links back', () => {
        vi.mocked(useBrand).mockReturnValue({
            brand: 'test-brand',
            config: null,
            logoSrc: '/brands/test-brand/android-chrome-192x192.png',
            svgUrl: '/brands/test-brand/safari-pinned-tab.svg',
            portalName: 'Test-Portal',
            impressumUrl: null,
            features: {},
            theme: { light: 'rp-light', dark: 'rp-dark' },
            primaryColor: '#1E5631',
            secondaryColor: '#A4B494',
        });
        vi.mocked(useBillingDetails).mockReturnValue({
            billingDetails,
            isLoading: false,
            updateBillingDetails: vi.fn(),
        });

        renderView();

        const main = within(screen.getByRole('main'));

        expect(main.getByRole('heading', { name: 'Buchhaltung (EÜR)', level: 1 })).toBeVisible();
        expect(main.getByText(/Ihre Buchhaltung für Test-Portal/)).toBeVisible();

        expect(main.getByText('Musterfoto GmbH')).toBeVisible();
        expect(main.getByText('GmbH')).toBeVisible();
        expect(main.getByText('Musterstraße 1, 4020 Linz, Österreich')).toBeVisible();
        expect(main.getByText('office@example.at')).toBeVisible();
        expect(main.getByText('12/345/67890')).toBeVisible();
        expect(main.getByText('Finanzamt Linz')).toBeVisible();
        expect(main.getByText('ATU12345678')).toBeVisible();
        expect(main.getByText('AT11 2222 3333 4444 5555')).toBeVisible();

        expect(main.getByText(/unter 1.000 €/)).toBeVisible();
        expect(main.getByText(/0 % \(privat\) bis 100 % \(betrieblich\)/)).toBeVisible();

        expect(main.getByRole('link', { name: 'Unternehmensdaten bearbeiten' })).toHaveAttribute('href', '/settings');
        expect(main.getByRole('link', { name: 'Zurück zum Portal' })).toHaveAttribute('href', '/');
    });

    it('shows a placeholder instead of technical or empty internals when settings are missing', () => {
        vi.mocked(useBrand).mockReturnValue({
            brand: 'test-brand',
            config: null,
            logoSrc: '/brands/test-brand/android-chrome-192x192.png',
            svgUrl: '/brands/test-brand/safari-pinned-tab.svg',
            portalName: 'Test-Portal',
            impressumUrl: null,
            features: {},
            theme: { light: 'rp-light', dark: 'rp-dark' },
            primaryColor: '#1E5631',
            secondaryColor: '#A4B494',
        });
        vi.mocked(useBillingDetails).mockReturnValue({
            billingDetails: undefined,
            isLoading: false,
            updateBillingDetails: vi.fn(),
        });

        renderView();

        const main = within(screen.getByRole('main'));
        expect(main.getAllByText('—').length).toBeGreaterThan(0);
        expect(main.queryByText(/Paritätsvektoren/)).not.toBeInTheDocument();
        expect(main.queryByText(/Cent/)).not.toBeInTheDocument();
    });
});
