import { describe, expect, it, vi } from 'vitest';
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

describe('EurEntryDialog', () => {
    it('takes the business share from the selected reference kind (EUE-033/048)', async () => {
        const user = userEvent.setup();
        vi.mocked(createEurEntry).mockResolvedValue({} as never);
        renderWithProviders(
            <EurEntryDialog year={2026} reference={reference} onClose={() => {}} onCreated={() => {}} />,
        );

        await user.selectOptions(screen.getByLabelText('Art'), 'Privatanteil');
        expect(screen.getByLabelText('Betriebsanteil (%)')).toHaveValue(0);

        await user.type(screen.getByLabelText('Betrag (€)'), '50');
        await user.click(screen.getByRole('button', {name: 'Buchung speichern'}));

        expect(createEurEntry).toHaveBeenCalledWith(expect.objectContaining({
            business_share: 0,
            e1a_code: null,
        }));
    });
});
