import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
    createEaDocumentPositions,
    deleteEaDocumentPosition,
    setEaDocumentGroup,
    useEaAssetGroups,
    useEaDocuments,
    type EaAssetGroup,
    type EaDocument,
    type EaDocumentPosition,
} from '../../../../logic/useEa';
import { renderWithProviders } from '../../../../test-setup';
import EaDocumentsPanel from '../EaDocumentsPanel';

vi.mock('../../../../logic/useEa', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEa')>();
    return {
        ...actual,
        useEaDocuments: vi.fn(),
        useEaAssetGroups: vi.fn(),
        createEaDocumentPositions: vi.fn(),
        deleteEaDocumentPosition: vi.fn(),
        setEaDocumentGroup: vi.fn(),
    };
});

function swrResult<R>(data: unknown): R {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as R;
}

const document = (overrides: Partial<EaDocument> = {}): EaDocument => ({
    id: 42,
    year: 2026,
    month: 1,
    receipt_no: 'R-42',
    original_name: 'rechnung-42.pdf',
    mime_type: 'application/pdf',
    size_bytes: 2048,
    status: 'unsigned',
    sha256: 'hash',
    signed_at: null,
    signed_sha256: null,
    signed_storage_path: null,
    signed_size_bytes: null,
    linked_entry_ids: [],
    total_cents: 50000,
    allocated_cents: 30000,
    remaining_cents: 20000,
    positions: [],
    ...overrides,
});

const position = (overrides: Partial<EaDocumentPosition> = {}): EaDocumentPosition => ({
    id: 11,
    asset_id: 7,
    asset_name: 'Kamera',
    label: 'KameraBody',
    amount_cents: 20000,
    ordinal: 1,
    ...overrides,
});

const group = (overrides: Partial<EaAssetGroup> = {}): EaAssetGroup => ({
    id: 1,
    name: 'Kamera-Set',
    business_share: 100,
    scope: 'business',
    asset_ids: [7],
    assets: [{id: 7, name: 'Kamera', invoice_no: 'R-1', cost_cents: 200000}],
    years: [],
    ...overrides,
});

function setData(documents: EaDocument[], groups: EaAssetGroup[] = []) {
    vi.mocked(useEaDocuments).mockReturnValue(swrResult<ReturnType<typeof useEaDocuments>>({documents}));
    vi.mocked(useEaAssetGroups).mockReturnValue(swrResult<ReturnType<typeof useEaAssetGroups>>({groups}));
}

function documentRow() {
    return screen.getByRole('row', {name: /rechnung-42\.pdf/});
}

beforeEach(() => {
    vi.clearAllMocks();
});

describe('EaDocumentsPanel Positionen (#59)', () => {
    it('listet Positionen und fasst Belegbetrag, verteilt und offen zusammen', () => {
        setData([document({positions: [
            position(),
            position({id: 12, asset_id: 8, asset_name: 'Objektiv', label: null, amount_cents: 10000, ordinal: 2}),
        ]})]);

        renderWithProviders(<EaDocumentsPanel year={2026} month={1}/>);
        const target = documentRow();
        const list = within(target).getByTestId('ea-document-position-list-42');

        expect(within(list).getByText('KameraBody')).toBeVisible();
        expect(within(list).getByText('Objektiv')).toBeVisible();
        expect(within(list).getByText(/200,00/)).toBeVisible();
        expect(within(target).getByText(/Belegbetrag:.*verteilt:.*offen:/)).toBeVisible();
        expect(within(target).getByText(/500,00/)).toBeVisible();
        expect(within(target).getByText(/300,00/)).toBeVisible();
    });

    it('splittet einen Beleg in zwei Positionen', async () => {
        const user = userEvent.setup();
        setData([document({positions: []})]);
        vi.mocked(createEaDocumentPositions).mockResolvedValue({
            document: {
                id: 42,
                receipt_no: 'R-42',
                original_name: 'rechnung-42.pdf',
                total_cents: 30000,
                allocated_cents: 30000,
                remaining_cents: 0,
            },
        });

        renderWithProviders(<EaDocumentsPanel year={2026} month={1}/>);
        const target = documentRow();

        await user.click(within(target).getByRole('button', {name: 'Position hinzufügen'}));
        const names = within(target).getAllByRole('textbox', {name: 'Name'});
        await user.type(names[0], 'Kamera');
        await user.type(names[1], 'Objektiv');
        const amounts = within(target).getAllByRole('spinbutton', {name: 'Betrag in Cent'});
        await user.type(amounts[0], '20000');
        await user.type(amounts[1], '10000');
        const shares = within(target).getAllByRole('combobox', {name: 'Betriebsanteil'});
        await user.selectOptions(shares[0], '100');
        await user.selectOptions(shares[1], '75');
        const years = within(target).getAllByRole('spinbutton', {name: 'Nutzungsdauer in Jahren'});
        await user.type(years[0], '10');
        await user.type(years[1], '5');
        const labels = within(target).getAllByRole('textbox', {name: 'Bezeichnung'});
        await user.type(labels[0], 'Body');
        await user.type(labels[1], 'Optik');
        await user.type(within(target).getByRole('spinbutton', {name: 'Belegbetrag in Cent'}), '30000');
        await user.click(within(target).getByRole('button', {name: 'Beleg aufteilen'}));

        expect(createEaDocumentPositions).toHaveBeenCalledWith(42, {
            total_cents: 30000,
            positions: [
                {name: 'Kamera', amount_cents: 20000, business_share: 100, useful_life_years: 10, label: 'Body', method: 'linear'},
                {name: 'Objektiv', amount_cents: 10000, business_share: 75, useful_life_years: 5, label: 'Optik', method: 'linear'},
            ],
        });
        expect(within(target).getByRole('status')).toHaveTextContent(/2 Positionen/);
    });

    it('zeigt eine Überbuchung als Fehler im Status an', async () => {
        const user = userEvent.setup();
        setData([document({positions: []})]);
        vi.mocked(createEaDocumentPositions).mockRejectedValue(Object.assign(new Error('422'), {
            status: 422,
            info: {error: 'Die Positionen übersteigen den Belegbetrag.'},
        }));

        renderWithProviders(<EaDocumentsPanel year={2026} month={1}/>);
        const target = documentRow();

        await user.type(within(target).getByRole('textbox', {name: 'Name'}), 'Kamera');
        await user.type(within(target).getByRole('spinbutton', {name: 'Betrag in Cent'}), '60000');
        await user.type(within(target).getByRole('spinbutton', {name: 'Nutzungsdauer in Jahren'}), '10');
        await user.click(within(target).getByRole('button', {name: 'Beleg aufteilen'}));

        expect(await within(target).findByRole('status'))
            .toHaveTextContent('Die Positionen übersteigen den Belegbetrag.');
    });

    it('entfernt eine Position, ohne den Beleg selbst zu löschen', async () => {
        const user = userEvent.setup();
        setData([document({positions: [position()]})]);
        vi.mocked(deleteEaDocumentPosition).mockResolvedValue(undefined);

        renderWithProviders(<EaDocumentsPanel year={2026} month={1}/>);
        const target = documentRow();

        await user.click(within(target).getByRole('button', {name: 'Position entfernen'}));

        expect(deleteEaDocumentPosition).toHaveBeenCalledWith(11);
        expect(within(target).getByRole('status')).toHaveTextContent(/Kamera/);
    });

    it('ordnet einen Beleg einer Gruppe zu und löst ihn wieder', async () => {
        const user = userEvent.setup();
        setData([document()], [group()]);
        vi.mocked(setEaDocumentGroup).mockResolvedValue({group: group()});

        renderWithProviders(<EaDocumentsPanel year={2026} month={1}/>);
        const target = documentRow();

        await user.selectOptions(within(target).getByRole('combobox', {name: 'Beleg einer Gruppe zuordnen'}), '1');
        await user.click(within(target).getByRole('button', {name: 'Zuordnen'}));

        expect(setEaDocumentGroup).toHaveBeenCalledWith(42, 1);
        expect(within(target).getByRole('status')).toHaveTextContent(/zugeordnet/);

        await user.click(within(target).getByRole('button', {name: 'Gruppe lösen'}));

        expect(setEaDocumentGroup).toHaveBeenLastCalledWith(42, null);
    });

    it('deaktiviert die Aufteilung bei bereits vorhandenen Positionen', () => {
        setData([document({positions: [position()]})]);

        renderWithProviders(<EaDocumentsPanel year={2026} month={1}/>);
        const target = documentRow();

        expect(within(target).getByRole('button', {name: 'Beleg aufteilen'})).toBeDisabled();
    });
});
