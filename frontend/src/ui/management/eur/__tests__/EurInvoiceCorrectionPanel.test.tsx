import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createEurInvoiceCorrection } from '../../../../logic/useEur';
import { renderWithProviders } from '../../../../test-setup';
import EurInvoiceCorrectionPanel from '../EurInvoiceCorrectionPanel';

vi.mock('../../../../logic/useEur', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEur')>();
    return {...actual, createEurInvoiceCorrection: vi.fn()};
});

const onCreated = vi.fn();

function renderPanel() {
    return renderWithProviders(
        <EurInvoiceCorrectionPanel year={2026} month={5} onCreated={onCreated}/>,
    );
}

async function fillValidForm(user: ReturnType<typeof userEvent.setup>) {
    await user.type(screen.getByLabelText('Rechnungsnummer'), 'P-2026-0001-0001');
    await user.type(screen.getByLabelText('Rückzahlung (€)'), '70,00');
    await user.type(screen.getByLabelText('Grund'), 'Teilweise erstattet');
    const day = screen.getByLabelText('Tag');
    await user.clear(day);
    await user.type(day, '4');
}

describe('EurInvoiceCorrectionPanel', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(createEurInvoiceCorrection).mockResolvedValue(undefined);
    });

    it('renders the negative-income explanation and the date defaults from props', () => {
        renderPanel();

        expect(screen.getByRole('heading', {name: 'Rechnungskorrektur'})).toBeVisible();
        expect(screen.getByText(/negative Einnahme \(Erlösminderung\)/)).toBeVisible();
        expect(screen.getByLabelText('Jahr')).toHaveValue(2026);
        expect(screen.getByLabelText('Monat')).toHaveValue(5);
    });

    it('validates empty required fields without calling the API', async () => {
        const user = userEvent.setup();
        renderPanel();

        await user.click(screen.getByRole('button', {name: 'Rechnungskorrektur buchen'}));

        expect(screen.getByText('Rechnungsnummer angeben')).toBeVisible();
        expect(screen.getByText('Bitte einen gültigen Rückzahlungsbetrag über null angeben')).toBeVisible();
        expect(screen.getByText('Grund angeben')).toBeVisible();
        expect(createEurInvoiceCorrection).not.toHaveBeenCalled();
        expect(onCreated).not.toHaveBeenCalled();
    });

    it('submits a comma amount as whole cents and confirms the booking', async () => {
        const user = userEvent.setup();
        renderPanel();
        await fillValidForm(user);

        await user.click(screen.getByRole('button', {name: 'Rechnungskorrektur buchen'}));

        await waitFor(() => expect(createEurInvoiceCorrection).toHaveBeenCalledWith({
            invoice_number: 'P-2026-0001-0001',
            refund_cents: 7000,
            reason: 'Teilweise erstattet',
            year: 2026,
            month: 5,
            day: 4,
        }));
        expect(await screen.findByRole('status')).toHaveTextContent('Die Rechnungskorrektur wurde gebucht.');
        expect(onCreated).toHaveBeenCalledOnce();
    });

    it('shows the server reason when the invoice does not exist', async () => {
        const user = userEvent.setup();
        vi.mocked(createEurInvoiceCorrection).mockRejectedValue(new Error('Rechnung nicht gefunden.'));
        renderPanel();
        await fillValidForm(user);

        await user.click(screen.getByRole('button', {name: 'Rechnungskorrektur buchen'}));

        expect(await screen.findByRole('alert')).toHaveTextContent('Rechnung nicht gefunden.');
        expect(onCreated).not.toHaveBeenCalled();
    });
});
