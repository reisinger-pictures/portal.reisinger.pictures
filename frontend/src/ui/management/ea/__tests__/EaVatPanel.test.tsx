import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { calculateEaVat, type EaVatResult } from '../../../../logic/useEa';
import { renderWithProviders } from '../../../../test-setup';
import EaVatPanel from '../EaVatPanel';

vi.mock('../../../../logic/useEa', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEa')>();
    return {...actual, calculateEaVat: vi.fn()};
});

const result: EaVatResult = {
    year: 2026,
    month: 1,
    kz: {
        '000': 100000,
        '016': null,
        '057': 0,
        '070': 0,
        '072': 0,
        '073': 0,
        '008': 0,
        '125': 0,
        zahllast: 1000,
    },
    erklaerung: [
        '1. Umsatzsteuervoranmeldung\nKennzahl 000: 1.000,00 €',
        '2. Umsatzsteuererklärung\nZahllast: 10,00 €',
    ],
};

describe('EaVatPanel declaration blocks', () => {
    it('renders both declaration blocks after a calculation', async () => {
        vi.mocked(calculateEaVat).mockResolvedValue(result);
        renderWithProviders(<EaVatPanel/>);

        await userEvent.click(screen.getByRole('button', {name: 'Kennzahlen berechnen'}));

        expect(screen.getByRole('heading', {name: 'Erklärungen'})).toBeVisible();
        expect(screen.getByRole('heading', {name: '1. Umsatzsteuervoranmeldung'})).toBeVisible();
        expect(screen.getByRole('heading', {name: '2. Umsatzsteuererklärung'})).toBeVisible();
        expect(screen.getByText(/Kennzahl 000: 1\.000,00 €/)).toBeVisible();
        expect(screen.getByText(/Zahllast: 10,00 €/)).toBeVisible();
    });

    it('renders structured declaration blocks with their server-supplied titles and text', async () => {
        vi.mocked(calculateEaVat).mockResolvedValue({
            ...result,
            erklaerung: {
                voranmeldung: {
                    key: 'voranmeldung',
                    title: '1. Umsatzsteuervoranmeldung',
                    text: 'Voranmeldungstext aus dem Regelkatalog',
                    zahllast_cents: 1000,
                },
                erklaerung: {
                    key: 'erklaerung',
                    title: '2. Umsatzsteuererklärung',
                    text: 'Erklärungstext aus dem Regelkatalog',
                    zahllast_cents: 1000,
                },
            },
        });
        renderWithProviders(<EaVatPanel/>);

        await userEvent.click(screen.getByRole('button', {name: 'Kennzahlen berechnen'}));

        expect(screen.getByRole('heading', {name: 'Erklärungen'})).toBeVisible();
        expect(screen.getByRole('heading', {name: '1. Umsatzsteuervoranmeldung'})).toBeVisible();
        expect(screen.getByRole('heading', {name: '2. Umsatzsteuererklärung'})).toBeVisible();
        expect(screen.getByText('Voranmeldungstext aus dem Regelkatalog')).toBeVisible();
        expect(screen.getByText('Erklärungstext aus dem Regelkatalog')).toBeVisible();
    });

    it('omits the declaration section when the response has no blocks', async () => {
        vi.mocked(calculateEaVat).mockResolvedValue({...result, erklaerung: []});
        renderWithProviders(<EaVatPanel/>);

        await userEvent.click(screen.getByRole('button', {name: 'Kennzahlen berechnen'}));

        expect(screen.queryByRole('heading', {name: 'Erklärungen'})).not.toBeInTheDocument();
    });
});
