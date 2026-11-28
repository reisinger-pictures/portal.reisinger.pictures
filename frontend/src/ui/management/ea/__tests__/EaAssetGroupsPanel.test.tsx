import { describe, expect, it, vi, beforeEach } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
    assignEaAssetToGroup,
    createEaAssetGroup,
    deleteEaAssetGroup,
    revalidateEa,
    unassignEaAssetFromGroup,
    updateEaAssetGroup,
    useEaAssetGroups,
    useEaAssets,
    type EaAsset,
    type EaAssetGroup,
} from '../../../../logic/useEa';
import { renderWithProviders } from '../../../../test-setup';
import EaAssetGroupsPanel from '../EaAssetGroupsPanel';

vi.mock('../../../../logic/useEa', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEa')>();
    return {
        ...actual,
        useEaAssetGroups: vi.fn(),
        useEaAssets: vi.fn(),
        createEaAssetGroup: vi.fn(),
        updateEaAssetGroup: vi.fn(),
        assignEaAssetToGroup: vi.fn(),
        unassignEaAssetFromGroup: vi.fn(),
        deleteEaAssetGroup: vi.fn(),
        revalidateEa: vi.fn(),
    };
});

const group = (overrides: Partial<EaAssetGroup> = {}): EaAssetGroup => ({
    id: 1,
    name: 'Kamera-Set',
    business_share: 100,
    scope: 'business',
    asset_ids: [1],
    assets: [{id: 1, name: 'Kamera', invoice_no: 'R-1', cost_cents: 200000}],
    years: [],
    ...overrides,
});

const asset = (overrides: Partial<EaAsset> = {}): EaAsset => ({
    id: 1,
    name: 'Kamera',
    invoice_no: 'R-1',
    supplier: 'Händler',
    purchase_date: '2026-01-15',
    cost_cents: 200000,
    useful_life_years: 10,
    business_share: 100,
    method: 'linear',
    degressive_rate: null,
    status: 'active',
    years: [],
    ...overrides,
});

function swrResult<R>(data: unknown): R {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as R;
}

function setData(groups: EaAssetGroup[], assets: EaAsset[] = []) {
    vi.mocked(useEaAssetGroups).mockReturnValue(swrResult<ReturnType<typeof useEaAssetGroups>>({groups}));
    vi.mocked(useEaAssets).mockReturnValue(swrResult<ReturnType<typeof useEaAssets>>({assets}));
}

describe('EaAssetGroupsPanel (#57)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(revalidateEa).mockResolvedValue(undefined);
    });

    it('legt eine Gruppe mit Namen und Betriebsanteil an', async () => {
        const user = userEvent.setup();
        setData([]);
        vi.mocked(createEaAssetGroup).mockResolvedValue({group: group({business_share: 75})});

        renderWithProviders(<EaAssetGroupsPanel/>);
        const panel = screen.getByTestId('ea-asset-groups-panel');

        await user.type(within(panel).getByRole('textbox', {name: 'Name der Gruppe'}), 'Kamera-Set');
        await user.selectOptions(within(panel).getByRole('combobox', {name: 'Betriebsanteil'}), '75');
        await user.click(within(panel).getByRole('button', {name: 'Gruppe anlegen'}));

        expect(createEaAssetGroup).toHaveBeenCalledWith({name: 'Kamera-Set', business_share: 75});
        expect(revalidateEa).toHaveBeenCalled();
        expect(within(panel).getByRole('status')).toHaveTextContent(/Kamera-Set/);
    });

    it('verlangt einen Namen und einen gültigen Betriebsanteil', async () => {
        const user = userEvent.setup();
        setData([]);

        renderWithProviders(<EaAssetGroupsPanel/>);
        const panel = screen.getByTestId('ea-asset-groups-panel');

        expect(within(panel).getByRole('textbox', {name: 'Name der Gruppe'})).toBeRequired();
        expect(within(panel).getByRole('combobox', {name: 'Betriebsanteil'})).toBeRequired();
        await user.click(within(panel).getByRole('button', {name: 'Gruppe anlegen'}));
        expect(createEaAssetGroup).not.toHaveBeenCalled();
    });

    it('bietet nurgruppefreie Anlagegüter zur Zuordnung an', async () => {
        const user = userEvent.setup();
        setData(
            [group()],
            [
                asset({id: 2, name: 'Notebook', ea_asset_group_id: null}),
                asset({id: 3, name: 'Tablet', ea_asset_group_id: 9}),
            ],
        );
        vi.mocked(assignEaAssetToGroup).mockResolvedValue({group: group()});

        renderWithProviders(<EaAssetGroupsPanel/>);
        const panel = screen.getByTestId('ea-asset-groups-panel');
        const select = within(panel).getByRole('combobox', {name: 'Anlagegut zuordnen'});

        expect(within(select).getByRole('option', {name: 'Notebook'})).toBeInTheDocument();
        expect(within(select).queryByRole('option', {name: 'Tablet'})).not.toBeInTheDocument();
        expect(within(select).queryByRole('option', {name: 'Kamera'})).not.toBeInTheDocument();

        await user.selectOptions(select, '2');
        await user.click(within(panel).getByRole('button', {name: 'Zuordnen'}));

        expect(assignEaAssetToGroup).toHaveBeenCalledWith(1, 2);
        expect(revalidateEa).toHaveBeenCalled();
        expect(within(panel).getByRole('status')).toHaveTextContent(/zugeordnet/);
    });

    it('löst ein Mitglied aus der Gruppe', async () => {
        const user = userEvent.setup();
        setData([group()]);
        vi.mocked(unassignEaAssetFromGroup).mockResolvedValue({group: group({asset_ids: [], assets: []})});

        renderWithProviders(<EaAssetGroupsPanel/>);
        const panel = screen.getByTestId('ea-asset-groups-panel');

        expect(within(panel).getByText('Kamera')).toBeVisible();
        await user.click(within(panel).getByRole('button', {name: 'Entfernen'}));

        expect(unassignEaAssetFromGroup).toHaveBeenCalledWith(1, 1);
        expect(revalidateEa).toHaveBeenCalled();
        expect(within(panel).getByRole('status')).toHaveTextContent(/einzeln abgerechnet/);
    });

    it('ändert den Betriebsanteil einer Gruppe per PUT', async () => {
        const user = userEvent.setup();
        setData([group()]);
        vi.mocked(updateEaAssetGroup).mockResolvedValue({group: group({business_share: 75})});

        renderWithProviders(<EaAssetGroupsPanel/>);
        const panel = screen.getByTestId('ea-asset-groups-panel');

        await user.selectOptions(within(panel).getByRole('combobox', {name: 'Betriebsanteil ändern'}), '75');

        expect(updateEaAssetGroup).toHaveBeenCalledWith(1, {name: 'Kamera-Set', business_share: 75});
        expect(revalidateEa).toHaveBeenCalled();
        expect(within(panel).getByRole('status')).toHaveTextContent(/75 %/);
    });

    it('löscht eine Gruppe', async () => {
        const user = userEvent.setup();
        setData([group()]);
        vi.mocked(deleteEaAssetGroup).mockResolvedValue({success: true});

        renderWithProviders(<EaAssetGroupsPanel/>);
        const panel = screen.getByTestId('ea-asset-groups-panel');

        await user.click(within(panel).getByRole('button', {name: 'Gruppe löschen'}));

        expect(deleteEaAssetGroup).toHaveBeenCalledWith(1);
        expect(revalidateEa).toHaveBeenCalled();
        expect(within(panel).getByRole('status')).toHaveTextContent(/gelöscht/);
    });

    it('zeigt beide Scope-Badges und die Eigenbeleg-Jahre privater Gruppen', () => {
        setData([
            group({id: 1, name: 'Betriebsgruppe', business_share: 100, scope: 'business'}),
            group({
                id: 2,
                name: 'Privatgruppe',
                business_share: 30,
                scope: 'private',
                asset_ids: [2],
                assets: [{id: 2, name: 'Privat-Notebook', invoice_no: 'R-2', cost_cents: 100000}],
                years: [{
                    year: 2026,
                    eigenbeleg_no: 'EB-2-2026',
                    eigenbeleg_cents: 3000,
                    usage_percent: 30,
                }],
            }),
        ]);

        renderWithProviders(<EaAssetGroupsPanel/>);
        const business = screen.getByTestId('ea-asset-group-1');
        const privateGroup = screen.getByTestId('ea-asset-group-2');

        expect(within(business).getByText('Betrieblich')).toBeVisible();
        expect(within(business).queryByText('Privat')).not.toBeInTheDocument();
        expect(within(privateGroup).getByText('Privat')).toBeVisible();
        expect(within(privateGroup).queryByText('Betrieblich')).not.toBeInTheDocument();
        expect(within(privateGroup).getByText(/EB-2-2026/)).toBeVisible();
        expect(within(privateGroup).getByText(/30,00/)).toBeVisible();
        expect(within(business).queryByTestId('ea-asset-group-years-1')).not.toBeInTheDocument();
        expect(within(privateGroup).getByTestId('ea-asset-group-years-2')).toBeVisible();
    });

    it('zeigt die verknüpften Belege als eine Abrechnungseinheit (#59)', () => {
        setData([group({
            document_ids: [3, 4],
            documents: [
                {id: 3, receipt_no: 'R-3', original_name: 'beleg-3.pdf'},
                {id: 4, receipt_no: null, original_name: 'beleg-4.pdf'},
            ],
        })]);

        renderWithProviders(<EaAssetGroupsPanel/>);
        const card = screen.getByTestId('ea-asset-group-1');
        const receipts = within(card).getByTestId('ea-asset-group-documents-1');

        expect(within(receipts).getByText('2 Belege')).toBeVisible();
        expect(within(receipts).getByText('R-3')).toBeVisible();
        expect(within(receipts).getByText('beleg-4.pdf')).toBeVisible();
        expect(within(receipts).getByText(/eine Abrechnungseinheit/)).toBeVisible();
        expect(within(card).getByText('1 Anlagegüter')).toBeVisible();
    });

    it('zeigt bei älteren Gruppen ohne Belegfeld null Belege an', () => {
        setData([group()]);

        renderWithProviders(<EaAssetGroupsPanel/>);
        const receipts = within(screen.getByTestId('ea-asset-group-1'))
            .getByTestId('ea-asset-group-documents-1');

        expect(within(receipts).getByText('0 Belege')).toBeVisible();
        expect(within(receipts).getByText('Keine Belege zugeordnet.')).toBeVisible();
    });
});
