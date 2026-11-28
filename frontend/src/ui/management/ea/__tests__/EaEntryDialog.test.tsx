import { describe, expect, it, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { createEaEntry, type EaReference } from '../../../../logic/useEa';
import { renderWithProviders } from '../../../../test-setup';
import EaEntryDialog from '../EaEntryDialog';

vi.mock('../../../../logic/useEa', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEa')>();
    return {...actual, createEaEntry: vi.fn()};
});

const reference = {
    expense_kinds: [
        {type: 'Privatanteil', e1a: null, share_percent: 0},
        {type: 'Bürobedarf', e1a: '9230 - Bürobedarf', share_percent: 100},
    ],
    income_kinds: [],
    payment_methods: [
        {kind: 'Bankkonto', opening_balance_cents: null},
        {kind: 'Bar', opening_balance_cents: 10000},
    ],
    ea_projects: [{id: 7, name: 'Projekt A'}],
    vat_kinds: ['Reverse Charge 20 %', 'IG-Erwerb 20 %'],
} as unknown as EaReference;

function renderDialog() {
    return renderWithProviders(
        <EaEntryDialog year={2026} reference={reference} onClose={() => {}} onCreated={() => {}} />,
    );
}

describe('EaEntryDialog', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(createEaEntry).mockResolvedValue(undefined);
    });

    it('takes the business share from the selected reference kind (EUE-033/048)', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.selectOptions(screen.getByLabelText('Art'), 'Privatanteil');
        expect(screen.getByLabelText('Betriebsanteil (%)')).toHaveValue(0);

        await user.type(screen.getByLabelText('Betrag (€)'), '50');
        await user.click(screen.getByRole('button', {name: 'Buchung speichern'}));

        expect(createEaEntry).toHaveBeenCalledWith(expect.objectContaining({
            business_share: 0,
            e1a_code: null,
        }));
    });

    it('sends payment method and project id from the reference lists (EUE-005/006)', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.selectOptions(screen.getByLabelText('Art'), 'Bürobedarf');
        await user.type(screen.getByLabelText('Betrag (€)'), '50');
        await user.selectOptions(screen.getByLabelText('Zahlungsart'), 'Bar');
        await user.selectOptions(screen.getByLabelText('Projekt'), '7');
        await user.click(screen.getByRole('button', {name: 'Buchung speichern'}));

        expect(createEaEntry).toHaveBeenCalledWith(expect.objectContaining({
            payment_method: 'Bar',
            ea_project_id: 7,
        }));
    });

    it('sends null when no payment method or project is selected (EUE-005/006)', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.selectOptions(screen.getByLabelText('Art'), 'Bürobedarf');
        await user.type(screen.getByLabelText('Betrag (€)'), '50');
        await user.click(screen.getByRole('button', {name: 'Buchung speichern'}));

        expect(createEaEntry).toHaveBeenCalledWith(expect.objectContaining({
            payment_method: null,
            ea_project_id: null,
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
        expect(createEaEntry).not.toHaveBeenCalled();
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

        expect(createEaEntry).toHaveBeenCalledWith(expect.objectContaining({
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

    it('sendet die gewählte USt-Behandlung als vat_kind, sonst null (EUE-039/040)', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.selectOptions(screen.getByLabelText('Art'), 'Bürobedarf');
        await user.type(screen.getByLabelText('Betrag (€)'), '50');
        await user.selectOptions(screen.getByLabelText('USt-Behandlung'), 'IG-Erwerb 20 %');
        await user.click(screen.getByRole('button', {name: 'Buchung speichern'}));

        expect(createEaEntry).toHaveBeenCalledWith(expect.objectContaining({
            vat_kind: 'IG-Erwerb 20 %',
        }));
    });

    it('sendet vat_kind null ohne Auswahl (EUE-039/040)', async () => {
        const user = userEvent.setup();
        renderDialog();

        await user.selectOptions(screen.getByLabelText('Art'), 'Bürobedarf');
        await user.type(screen.getByLabelText('Betrag (€)'), '50');
        await user.click(screen.getByRole('button', {name: 'Buchung speichern'}));

        expect(createEaEntry).toHaveBeenCalledWith(expect.objectContaining({
            vat_kind: null,
        }));
    });

    it('sendet den Katalog-Typ unverändert als kind_type (EUE-029-Vertragskette)', async () => {
        // Echter Typ aus tables.json/ausgaben_arten — die Usage-Counts des
        // Referenz-Endpunkts werden genau über diesen Wert gematcht.
        const realCatalog = {
            ...reference,
            expense_kinds: [{type: 'Wareneinkäufe', e1a: '9100 - Waren', share_percent: 100}],
        } as unknown as EaReference;
        renderWithProviders(
            <EaEntryDialog year={2026} reference={realCatalog} onClose={() => {}} onCreated={() => {}} />,
        );
        const user = userEvent.setup();

        await user.selectOptions(screen.getByLabelText('Art'), 'Wareneinkäufe');
        await user.type(screen.getByLabelText('Betrag (€)'), '50');
        await user.click(screen.getByRole('button', {name: 'Buchung speichern'}));

        expect(createEaEntry).toHaveBeenCalledWith(expect.objectContaining({
            kind_type: 'Wareneinkäufe',
            e1a_code: '9100',
        }));
    });
});
