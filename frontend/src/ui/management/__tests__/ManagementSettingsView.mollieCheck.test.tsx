import { beforeEach, describe, expect, it, vi } from 'vitest';
import { fireEvent, screen, waitFor, within } from '@testing-library/react';
import { renderWithProviders } from '../../../test-setup';

vi.mock('swr', () => ({
    default: () => ({ data: undefined }),
}));

vi.mock('../../../logic/useLicenseTerms', () => ({
    useBillingDetails: () => ({
        billingDetails: {
            bank_holder: 'Studio',
            company_street: 'Straße 1',
            company_zip: '4020',
            company_city: 'Linz',
            bank_iban: 'AT00 0000 0000 0000 0000',
        },
        isLoading: false,
    }),
}));

vi.mock('../../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../../components/UIContext', () => ({
    useUI: () => ({ showToast: vi.fn() }),
}));

vi.mock('../../../api', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../api')>()),
    apiMutate: vi.fn(),
}));

vi.mock('../components/WatermarkSettingsCard', () => ({ default: () => null }));
vi.mock('../components/PricingSettingsTabs', () => ({ default: () => null }));
vi.mock('../components/BillingDetailsCard', () => ({ default: () => null }));
vi.mock('../components/BrandSettingsCard', () => ({ default: () => null }));
vi.mock('../components/CalculatorSettingsCard', () => ({ default: () => null }));

import { apiMutate, type MollieCheckFinding } from '../../../api';
import { usePermissions } from '../../../logic/usePermissions';
import ManagementSettingsView from '../ManagementSettingsView';

const ALL_OK: MollieCheckFinding[] = [
    { key: 'key_mode', status: 'ok', message: 'MOLLIE_KEY ist gesetzt und läuft im test-Modus.' },
    { key: 'webhook_url', status: 'ok', message: 'MOLLIE_WEBHOOK_URL ist gesetzt und HTTPS: https://portal.example.test/api/webhooks/mollie' },
    { key: 'methods', status: 'ok', message: 'Zugelassene Methoden: paybybank, eps, creditcard (Standard: paybybank).' },
    { key: 'account', status: 'ok', message: 'Profil pfl_1 ist erreichbar und verifiziert; der Schlüssel funktioniert im test-Modus.' },
    { key: 'readiness', status: 'ok', message: 'Zahlungen können angelegt werden (canCreatePayment() = true).' },
];

const superAdmin = {
    isStaff: true,
    isAdmin: true,
    isSuperAdmin: true,
    isPhotographer: false,
    isOrgAdmin: false,
    canEditMetadata: true,
    isPowerUser: true,
    canAccessB2BFeatures: true,
    canAccessProjectsBoard: true,
    canAccessProductionBoard: true,
    showOrgsSection: true,
    showCRM: true,
    showInvoicing: true,
    showPayouts: true,
};

describe('ManagementSettingsView — Mollie-Verbindungstest', () => {
    beforeEach(() => {
        vi.mocked(apiMutate).mockReset();
        vi.mocked(usePermissions).mockReturnValue(superAdmin);
    });

    it('zeigt die Karte samt Button und noch keinen Befund', () => {
        renderWithProviders(<ManagementSettingsView />);

        const card = screen.getByTestId('mollie-check-card');
        expect(within(card).getByRole('heading', { name: 'Mollie-Verbindungstest' })).toBeInTheDocument();
        expect(within(card).getByTestId('mollie-check-button')).toBeInTheDocument();
        expect(screen.queryAllByTestId('mollie-check-result')).toHaveLength(0);
    });

    it('ruft den Endpunkt nur auf Klick auf und rendert alle fünf Befunde in fester Reihenfolge', async () => {
        vi.mocked(apiMutate).mockResolvedValue({ checks: ALL_OK });
        renderWithProviders(<ManagementSettingsView />);

        expect(apiMutate).not.toHaveBeenCalled();

        fireEvent.click(screen.getByTestId('mollie-check-button'));

        await waitFor(() => {
            expect(screen.getAllByTestId('mollie-check-result')).toHaveLength(5);
        });
        expect(apiMutate).toHaveBeenCalledTimes(1);
        expect(apiMutate).toHaveBeenCalledWith('/api/management/settings/mollie-check', 'POST', {});

        const rows = screen.getAllByTestId('mollie-check-result');
        expect(rows.map(row => row.getAttribute('data-key'))).toEqual([
            'key_mode',
            'webhook_url',
            'methods',
            'account',
            'readiness',
        ]);
        for (const row of rows) {
            expect(row).toHaveAttribute('data-status', 'ok');
        }
    });

    it('rendert einen fehlgeschlagenen Befund mit Status und Meldung', async () => {
        vi.mocked(apiMutate).mockResolvedValue({
            checks: [
                ALL_OK[0],
                { key: 'webhook_url', status: 'fail', message: 'MOLLIE_WEBHOOK_URL ist nicht gesetzt. Die Zahlungsanlage ist deaktiviert (503).' },
                ALL_OK[2],
                ALL_OK[3],
                { key: 'readiness', status: 'fail', message: 'Zahlungen können derzeit nicht angelegt werden (canCreatePayment() = false).' },
            ],
        });
        renderWithProviders(<ManagementSettingsView />);

        fireEvent.click(screen.getByTestId('mollie-check-button'));

        await waitFor(() => {
            expect(screen.getAllByTestId('mollie-check-result')).toHaveLength(5);
        });

        const row = screen.getByText(/MOLLIE_WEBHOOK_URL ist nicht gesetzt/).closest('li');
        expect(row).toHaveAttribute('data-key', 'webhook_url');
        expect(row).toHaveAttribute('data-status', 'fail');
        expect(screen.getByText(/canCreatePayment\(\) = false/)).toBeInTheDocument();
    });

    it('zeigt die Karte einem Nicht-Super-Admin nicht', () => {
        vi.mocked(usePermissions).mockReturnValue({ ...superAdmin, isSuperAdmin: false, isAdmin: false });

        renderWithProviders(<ManagementSettingsView />);

        expect(screen.queryByTestId('mollie-check-card')).not.toBeInTheDocument();
        expect(screen.queryByTestId('mollie-check-button')).not.toBeInTheDocument();
    });
});
