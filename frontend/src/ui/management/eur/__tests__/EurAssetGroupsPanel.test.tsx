import { describe, expect, it, vi, beforeEach } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
    assignEurAssetToGroup,
    createEurAssetGroup,
    deleteEurAssetGroup,
    revalidateEur,
    unassignEurAssetFromGroup,
    updateEurAssetGroup,
    useEurAssetGroups,
    useEurAssets,
    type EurAsset,
    type EurAssetGroup,
} from '../../../../logic/useEur';
import { renderWithProviders } from '../../../../test-setup';
import EurAssetGroupsPanel from '../EurAssetGroupsPanel';

vi.mock('../../../../logic/useEur', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEur')>();
    return {
        ...actual,
        useEurAssetGroups: vi.fn(),
        useEurAssets: vi.fn(),
        createEurAssetGroup: vi.fn(),
        updateEurAssetGroup: vi.fn(),
        assignEurAssetToGroup: vi.fn(),
        unassignEurAssetFromGroup: vi.fn(),
        deleteEurAssetGroup: vi.fn(),
        revalidateEur: vi.fn(),
    };
});

const group = (overrides: Partial<EurAssetGroup> = {}): EurAssetGroup => ({
    id: 1,
    name: 'Kamera-Set',
    business_share: 100,
    scope: 'business',
    asset_ids: [1],
    assets: [{id: 1, name: 'Kamera', invoice_no: 'R-1', cost_cents: 200000}],
    years: [],
    ...overrides,
});

const asset = (overrides: Partial<EurAsset> = {}): EurAsset => ({
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

function setData(groups: EurAssetGroup[], assets: EurAsset[] = []) {
    vi.mocked(useEurAssetGroups).mockReturnValue(swrResult<ReturnType<typeof useEurAssetGroups>>({groups}));
    vi.mocked(useEurAssets).mockReturnValue(swrResult<ReturnType<typeof useEurAssets>>({assets}));
}

describe('EurAssetGroupsPanel (#57)', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(revalidateEur).mockResolvedValue(undefined);
    });

    it('legt eine Gruppe mit Namen und Betriebsanteil an', async () => {
        const user = userEvent.setup();
        setData([]);
        vi.mocked(createEurAssetGroup).mockResolvedValue({group: group({business_share: 75})});

        renderWithProviders(<EurAssetGroupsPanel/>);
        const panel = screen.getByTestId('eur-asset-groups-panel');

        await user.type(within(panel).getByRole('textbox', {name: 'Name der Gruppe'}), 'Kamera-Set');
        await user.selectOptions(within(panel).getByRole('combobox', {name: 'Betriebsanteil'}), '75');
        await user.click(within(panel).getByRole('button', {name: 'Gruppe anlegen'}));

        expect(createEurAssetGroup).toHaveBeenCalledWith({name: 'Kamera-Set', business_share: 75});
        expect(revalidateEur).toHaveBeenCalled();
        expect(within(panel).getByRole('status')).toHaveTextContent(/Kamera-Set/);
    });

    it('verlangt einen Namen und einen gültigen Betriebsanteil', async () => {
        const user = userEvent.setup();
        setData([]);

        renderWithProviders(<EurAssetGroupsPanel/>);
        const panel = screen.getByTestId('eur-asset-groups-panel');

        expect(within(panel).getByRole('textbox', {name: 'Name der Gruppe'})).toBeRequired();
        expect(within(panel).getByRole('combobox', {name: 'Betriebsanteil'})).toBeRequired();
        await user.click(within(panel).getByRole('button', {name: 'Gruppe anlegen'}));
        expect(createEurAssetGroup).not.toHaveBeenCalled();
    });

    it('bietet nurgruppefreie Anlagegüter zur Zuordnung an', async () => {
        const user = userEvent.setup();
        setData(
            [group()],
            [
                asset({id: 2, name: 'Notebook', eur_asset_group_id: null}),
                asset({id: 3, name: 'Tablet', eur_asset_group_id: 9}),
            ],
        );
        vi.mocked(assignEurAssetToGroup).mockResolvedValue({group: group()});

        renderWithProviders(<EurAssetGroupsPanel/>);
        const panel = screen.getByTestId('eur-asset-groups-panel');
        const select = within(panel).getByRole('combobox', {name: 'Anlagegut zuordnen'});

        expect(within(select).getByRole('option', {name: 'Notebook'})).toBeInTheDocument();
        expect(within(select).queryByRole('option', {name: 'Tablet'})).not.toBeInTheDocument();
        expect(within(select).queryByRole('option', {name: 'Kamera'})).not.toBeInTheDocument();

        await user.selectOptions(select, '2');
        await user.click(within(panel).getByRole('button', {name: 'Zuordnen'}));

        expect(assignEurAssetToGroup).toHaveBeenCalledWith(1, 2);
        expect(revalidateEur).toHaveBeenCalled();
        expect(within(panel).getByRole('status')).toHaveTextContent(/zugeordnet/);
    });

    it('löst ein Mitglied aus der Gruppe', async () => {
        const user = userEvent.setup();
        setData([group()]);
        vi.mocked(unassignEurAssetFromGroup).mockResolvedValue({group: group({asset_ids: [], assets: []})});

        renderWithProviders(<EurAssetGroupsPanel/>);
        const panel = screen.getByTestId('eur-asset-groups-panel');

        expect(within(panel).getByText('Kamera')).toBeVisible();
        await user.click(within(panel).getByRole('button', {name: 'Entfernen'}));

        expect(unassignEurAssetFromGroup).toHaveBeenCalledWith(1, 1);
        expect(revalidateEur).toHaveBeenCalled();
        expect(within(panel).getByRole('status')).toHaveTextContent(/einzeln abgerechnet/);
    });

    it('ändert den Betriebsanteil einer Gruppe per PUT', async () => {
        const user = userEvent.setup();
        setData([group()]);
        vi.mocked(updateEurAssetGroup).mockResolvedValue({group: group({business_share: 75})});

        renderWithProviders(<EurAssetGroupsPanel/>);
        const panel = screen.getByTestId('eur-asset-groups-panel');

        await user.selectOptions(within(panel).getByRole('combobox', {name: 'Betriebsanteil ändern'}), '75');

        expect(updateEurAssetGroup).toHaveBeenCalledWith(1, {name: 'Kamera-Set', business_share: 75});
        expect(revalidateEur).toHaveBeenCalled();
        expect(within(panel).getByRole('status')).toHaveTextContent(/75 %/);
    });

    it('löscht eine Gruppe', async () => {
        const user = userEvent.setup();
        setData([group()]);
        vi.mocked(deleteEurAssetGroup).mockResolvedValue({success: true});

        renderWithProviders(<EurAssetGroupsPanel/>);
        const panel = screen.getByTestId('eur-asset-groups-panel');

        await user.click(within(panel).getByRole('button', {name: 'Gruppe löschen'}));

        expect(deleteEurAssetGroup).toHaveBeenCalledWith(1);
        expect(revalidateEur).toHaveBeenCalled();
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

        renderWithProviders(<EurAssetGroupsPanel/>);
        const business = screen.getByTestId('eur-asset-group-1');
        const privateGroup = screen.getByTestId('eur-asset-group-2');

        expect(within(business).getByText('Betrieblich')).toBeVisible();
        expect(within(business).queryByText('Privat')).not.toBeInTheDocument();
        expect(within(privateGroup).getByText('Privat')).toBeVisible();
        expect(within(privateGroup).queryByText('Betrieblich')).not.toBeInTheDocument();
        expect(within(privateGroup).getByText(/EB-2-2026/)).toBeVisible();
        expect(within(privateGroup).getByText(/30,00/)).toBeVisible();
        expect(within(business).queryByTestId('eur-asset-group-years-1')).not.toBeInTheDocument();
        expect(within(privateGroup).getByTestId('eur-asset-group-years-2')).toBeVisible();
    });
});
