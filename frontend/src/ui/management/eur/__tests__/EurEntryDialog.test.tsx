import { describe, expect, it, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createEurEntry, type EurReference } from '../../../../logic/useEur';
import { renderWithProviders } from '../../../../test-setup';
import EurEntryDialog from '../EurEntryDialog';

vi.mock('../../../../logic/useEur', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEur')>();
    return {...actual, createEurEntry: vi.fn()};
});

const reference = {
    expense_kinds: [
        {type: 'Privatanteil', e1a: null, share_percent: 0},
        {type: 'Bürobedarf', e1a: '9230 - Bürobedarf', share_percent: 100},
    ],
    income_kinds: [],
} as unknown as EurReference;

function renderDialog() {
    return renderWithProviders(
        <EurEntryDialog year={2026} reference={reference} onClose={() => {}} onCreated={() => {}} />,
    );
}

describe('EurEntryDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(createEurEntry).mockResolvedValue(undefined);
    });

    it('takes the business share from the selected reference kind (EUE-033/048)', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.selectOptions(screen.getByLabelText('Art'), 'Privatanteil');
        expect(screen.getByLabelText('Betriebsanteil (%)')).toHaveValue(0);

        await user.type(screen.getByLabelText('Betrag (€)'), '50');
        await user.click(screen.getByRole('button', {name: 'Buchung speichern'}));

        expect(createEurEntry).toHaveBeenCalledWith(expect.objectContaining({
            business_share: 0,
            e1a_code: null,
        }));
    });

    it('rejects an invalid amount and keeps the save button disabled', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.selectOptions(screen.getByLabelText('Art'), 'Bürobedarf');
        await user.type(screen.getByLabelText('Betrag (€)'), 'abc');
        await user.tab();

        expect(screen.getByText('Bitte einen gültigen Betrag angeben')).toBeVisible();
        expect(screen.getByRole('button', {name: 'Buchung speichern'})).toBeDisabled();
        expect(createEurEntry).not.toHaveBeenCalled();
    });

    it('rejects a negative amount and a fractional-share value', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.selectOptions(screen.getByLabelText('Art'), 'Bürobedarf');
        await user.type(screen.getByLabelText('Betrag (€)'), '-5');
        const share = screen.getByLabelText('Betriebsanteil (%)');
        await user.clear(share);
        await user.type(share, '7');
        await user.tab();

        expect(screen.getByText('Bitte einen gültigen Betrag angeben')).toBeVisible();
        expect(screen.getByText('Betriebsanteil in 5-%-Schritten von 0 bis 100 angeben')).toBeVisible();
        expect(screen.getByRole('button', {name: 'Buchung speichern'})).toBeDisabled();
    });

    it('rejects a month outside 1..12', async () => {
        const user = userEvent.setup();
        renderDialog();

        const month = screen.getByLabelText('Monat');
        const invalid = document.createElement('option');
        invalid.value = '13';
        invalid.textContent = '13';
        month.append(invalid);
        await user.selectOptions(month, '13');
        await user.tab();

        expect(screen.getByText('Monat zwischen 1 und 12 wählen')).toBeVisible();
        expect(screen.getByRole('button', {name: 'Buchung speichern'})).toBeDisabled();
    });

    it('accepts a zero amount with a comma separator and rounds fractional cents', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.selectOptions(screen.getByLabelText('Art'), 'Bürobedarf');
        await user.type(screen.getByLabelText('Betrag (€)'), '0,00');
        await user.click(screen.getByRole('button', {name: 'Buchung speichern'}));

        expect(createEurEntry).toHaveBeenCalledWith(expect.objectContaining({
            amount_cents: 0,
            business_share: 100,
            e1a_code: '9230',
        }));
    });

    it('requires a kind and shows a German error when none is selected', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.type(screen.getByLabelText('Betrag (€)'), '10');
        const art = screen.getByLabelText('Art');
        art.focus();
        await user.tab();

        expect(screen.getByText('Bitte eine Art auswählen')).toBeVisible();
        expect(screen.getByRole('button', {name: 'Buchung speichern'})).toBeDisabled();
    });
});
