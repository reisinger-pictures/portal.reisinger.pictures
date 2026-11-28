import { describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
    attachEaAssetDocument,
    bookEaAssetDepreciation,
    carryOverEaAssets,
    detachEaAssetDocument,
    switchEaAssetToLinear,
    useEaAssets,
    useEaDocuments,
    useEaReference,
    type EaAsset,
    type EaPlanYear,
} from '../../../../logic/useEa';
import { renderWithProviders } from '../../../../test-setup';
import EaAssetsPanel from '../EaAssetsPanel';

vi.mock('../../../../logic/useEa', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEa')>();
    return {
        ...actual,
        useEaAssets: vi.fn(),
        useEaReference: vi.fn(),
        useEaDocuments: vi.fn(),
        bookEaAssetDepreciation: vi.fn(),
        switchEaAssetToLinear: vi.fn(),
        carryOverEaAssets: vi.fn(),
        attachEaAssetDocument: vi.fn(),
        detachEaAssetDocument: vi.fn(),
    };
});

const plan = (overrides: Partial<EaPlanYear> = {}): EaPlanYear => ({
    year: 2026,
    method: 'linear',
    depreciation_cents: 20000,
    book_value_cents: 180000,
    private_use_percent: 0,
    private_use_cents: 0,
    business_use_cents: 20000,
    status: 'open',
    ea_entry_id: null,
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
    is_private_investment: false,
    years: [plan()],
    ...overrides,
});

function swrResult<R>(data: unknown): R {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as R;
}

function setAssets(...assets: EaAsset[]) {
    vi.mocked(useEaAssets).mockReturnValue(swrResult<ReturnType<typeof useEaAssets>>({assets}));
    vi.mocked(useEaReference).mockReturnValue(swrResult<ReturnType<typeof useEaReference>>({assets: {gwg_limit_cents: 100000}}));
    vi.mocked(useEaDocuments).mockReturnValue(swrResult<ReturnType<typeof useEaDocuments>>({documents: []}));
}

describe('EaAssetsPanel (#34)', () => {
    it('shows the depreciation directly and books an open year', async () => {
        const user = userEvent.setup();
        setAssets(asset());
        vi.mocked(bookEaAssetDepreciation).mockResolvedValue({
            entry: {id: 9, receipt_no: 'AV-1-2026', business_amount_cents: 20000},
            already_booked: false,
        });

        renderWithProviders(<EaAssetsPanel year={2026}/>);

        const table = screen.getByRole('table');
        // AfA and betrieblicher Anteil both render the booked 200,00 €; the
        // acquisition column shows 2.000,00 € (cost is stored in cents).
        expect(within(table).getAllByText(/200,00/)).toHaveLength(2);
        expect(within(table).getByText(/2\.000,00/)).toBeVisible();
        expect(within(table).getByText(/10,00/)).toBeVisible();
        expect(within(table).getByText(/^0,00/)).toBeVisible();
        expect(within(table).getByText('Offen')).toBeVisible();

        await user.click(within(table).getByRole('button', {name: 'AfA buchen'}));

        expect(bookEaAssetDepreciation).toHaveBeenCalledWith(1, 2026);
        expect(screen.getByRole('status')).toHaveTextContent(/AV-1-2026/);
    });

    it('filters private investments (P5.4)', async () => {
        const user = userEvent.setup();
        setAssets(
            asset({id: 1, name: 'Privat-Notebook', is_private_investment: true}),
            asset({id: 2, name: 'Büro-PC', is_private_investment: false}),
        );

        renderWithProviders(<EaAssetsPanel year={2026}/>);

        expect(screen.getByText('Privat-Notebook')).toBeVisible();
        expect(screen.getByText('Büro-PC')).toBeVisible();

        await user.click(screen.getByRole('button', {name: 'Privateinlagen'}));

        expect(screen.getByText('Privat-Notebook')).toBeVisible();
        expect(screen.queryByText('Büro-PC')).not.toBeInTheDocument();
    });

    it('hides the switch after a permanent move to linear (P5.3)', () => {
        setAssets(asset({
            method: 'degressiv',
            degressive_rate: 3000,
            switched_to_linear_at: '2026-06-01T00:00:00Z',
        }));

        renderWithProviders(<EaAssetsPanel year={2026}/>);

        expect(screen.getByText('Linear (fest)')).toBeVisible();
        expect(screen.queryByRole('button', {name: 'Zu linear wechseln'})).not.toBeInTheDocument();
    });

    it('shows the EUE-055 withdrawal status for a mixed private share', () => {
        // 75 % business / 25 % private is backend-producible (50–100 in
        // 5-% steps) and triggers EUE-055 for any private share above zero.
        const mixed = {
            business_share: 75,
            years: [plan({
                private_use_percent: 25,
                private_use_cents: 5000,
                business_use_cents: 15000,
            })],
        };

        setAssets(asset(mixed));
        const {unmount} = renderWithProviders(<EaAssetsPanel year={2026}/>);
        expect(screen.getByText('Entnahme fehlt')).toBeVisible();
        unmount();

        setAssets(asset({...mixed, entnahme: {done: true, entry_id: 7, amount_cents: 50000}}));
        renderWithProviders(<EaAssetsPanel year={2026}/>);
        expect(screen.getByText('Entnahme erledigt')).toBeVisible();
        expect(screen.queryByText('Entnahme fehlt')).not.toBeInTheDocument();
    });

    it('requires a reason for the one-time switch to linear (P5.3)', async () => {
        const user = userEvent.setup();
        setAssets(asset({method: 'degressiv', degressive_rate: 3000}));
        vi.mocked(switchEaAssetToLinear).mockResolvedValue({asset: asset()});

        renderWithProviders(<EaAssetsPanel year={2026}/>);

        await user.click(screen.getByRole('button', {name: 'Zu linear wechseln'}));
        const input = screen.getByRole('textbox');
        await user.type(input, 'Wirtschaftlichkeitsprüfung 2026');
        await user.click(screen.getByRole('button', {name: 'Bestätigen'}));

        expect(switchEaAssetToLinear).toHaveBeenCalledWith(1, 2026, 'Wirtschaftlichkeitsprüfung 2026');
    });

    it('zeigt die AV-Hinweise aus dem gepinnten Regeltext (EUE-053/058)', () => {
        setAssets(asset({warnings: [
            {code: 'EUE-053', text: '⚠ Ausgaben-Art muss zu einer AfA-Kennzahl zugeordnet sein'},
            {code: 'EUE-058', text: '⚠ Jahr liegt in der Zukunft'},
        ]}));

        renderWithProviders(<EaAssetsPanel year={2026}/>);

        const table = screen.getByRole('table');
        expect(within(table).getByText('⚠ Ausgaben-Art muss zu einer AfA-Kennzahl zugeordnet sein')).toBeVisible();
        expect(within(table).getByText('⚠ Jahr liegt in der Zukunft')).toBeVisible();
    });

    it('zeigt die nicht blockierende Wechsel-Erinnerung (DEG-013)', () => {
        setAssets(asset({
            method: 'degressiv',
            degressive_rate: 3000,
            switch_hint: {
                year: 2027,
                linear_cents: 84000,
                degressive_cents: 50400,
                advantage_cents: 33600,
            },
        }));

        renderWithProviders(<EaAssetsPanel year={2026}/>);

        const table = screen.getByRole('table');
        expect(within(table).getByText(/Wechsel zur linearen AfA ab Jahr 2027 empfohlen/)).toBeVisible();
        expect(within(table).getByText(/336,00/)).toBeVisible();
        // Der Hinweis ist ausdrücklich kein Fehler: die Buchung bleibt möglich.
        expect(within(table).getByRole('button', {name: 'AfA buchen'})).toBeEnabled();
    });

    it('zeigt die DEG-Ausschlussmerkmale und die gespeicherte Ausnahme (DEG-006/007/008)', () => {
        setAssets(asset({
            is_used: true,
            is_immaterial: true,
            immaterial_exception: 'digitalisation',
            special_depreciation: true,
            is_electric_vehicle: true,
        }));

        renderWithProviders(<EaAssetsPanel year={2026}/>);

        const table = screen.getByRole('table');
        expect(within(table).getByText('Gebraucht')).toBeVisible();
        expect(within(table).getByText('Immateriell')).toBeVisible();
        expect(within(table).getByText(/Ausnahme: Digitalisierung/)).toBeVisible();
        expect(within(table).getByText('Sonder-AfA')).toBeVisible();
        expect(within(table).getByText('Elektroauto')).toBeVisible();
    });

    it('verwendet die GWG-Grenze aus dem Katalog, nicht den Offline-Fallback (EUE-051)', () => {
        setAssets(
            asset({id: 1, name: 'Unter Grenze', cost_cents: 140000}),
            asset({id: 2, name: 'Über Grenze', cost_cents: 160000}),
        );
        // Abweichender Katalogwert (150.000 ct) beweist, dass die API-Quelle
        // und nicht die hart codierte 100.000 ct herangezogen wird.
        vi.mocked(useEaReference).mockReturnValue(swrResult<ReturnType<typeof useEaReference>>({
            assets: {gwg_limit_cents: 150000},
        }));

        renderWithProviders(<EaAssetsPanel year={2026}/>);

        const below = screen.getByText('Unter Grenze').closest('tr');
        const above = screen.getByText('Über Grenze').closest('tr');
        expect(below).not.toBeNull();
        expect(above).not.toBeNull();
        expect(within(below as HTMLElement).getByText('GWG')).toBeVisible();
        expect(within(above as HTMLElement).queryByText('GWG')).not.toBeInTheDocument();
    });

    it('zeigt den gepinnten EUE-051-Hinweis wörtlich nur unter der GWG-Grenze (EUE-051)', () => {
        const hint = '• Unter GWG-Grenze (1000€) - evtl. sofort im A-Blatt erfassen';
        setAssets(
            asset({id: 1, name: 'GWG-Gut', cost_cents: 80000}),
            asset({id: 2, name: 'Normal-Gut', cost_cents: 120000}),
        );

        renderWithProviders(<EaAssetsPanel year={2026}/>);

        const low = screen.getByText('GWG-Gut').closest('tr');
        const high = screen.getByText('Normal-Gut').closest('tr');
        expect(low).not.toBeNull();
        expect(high).not.toBeNull();
        expect(within(low as HTMLElement).getByText(hint)).toBeVisible();
        expect(within(high as HTMLElement).queryByText(hint)).not.toBeInTheDocument();
        // Der Hinweis bleibt nicht blockierend: die AfA ist weiter buchbar.
        expect(within(low as HTMLElement).getByRole('button', {name: 'AfA buchen'})).toBeEnabled();
    });

    it('zeigt GWG nur im Erwerbsjahr und nie für Privatanlagen (D-93)', () => {
        const hint = '• Unter GWG-Grenze (1000€) - evtl. sofort im A-Blatt erfassen';
        setAssets(
            asset({id: 1, name: 'Privat-GWG', cost_cents: 80000, business_share: 30, scope: 'private'}),
            asset({id: 2, name: 'Betrieb-GWG-Späterjahr', cost_cents: 80000, purchase_date: '2025-01-15'}),
            asset({id: 3, name: 'Betrieb-GWG-Erwerbsjahr', cost_cents: 80000}),
        );

        renderWithProviders(<EaAssetsPanel year={2026}/>);

        const rowOf = (name: string): HTMLElement => {
            const row = screen.getByText(name).closest('tr');
            expect(row).not.toBeNull();
            return row as HTMLElement;
        };
        const privateRow = rowOf('Privat-GWG');
        const laterRow = rowOf('Betrieb-GWG-Späterjahr');
        const acquisitionRow = rowOf('Betrieb-GWG-Erwerbsjahr');

        for (const row of [privateRow, laterRow]) {
            expect(within(row).queryByText('GWG')).not.toBeInTheDocument();
            expect(within(row).queryByText(hint)).not.toBeInTheDocument();
        }
        // Der gepinnte Hinweistext bleibt im Erwerbsjahr wörtlich erhalten.
        expect(within(acquisitionRow).getByText('GWG')).toBeVisible();
        expect(within(acquisitionRow).getByText(hint)).toBeVisible();
    });

    it('filtert die beiden Verzeichnisse über scope, mit Fallback für Legacy-Zeilen (D-85)', async () => {
        const user = userEvent.setup();
        setAssets(
            asset({id: 1, name: 'Betrieb-Kamera', scope: 'business'}),
            // scope ist authoritative, auch wenn das alte Flag fehlt.
            asset({id: 2, name: 'Privat-Notebook', scope: 'private', is_private_investment: false}),
            // Legacy-Zeile ohne scope: das alte Flag entscheidet.
            asset({id: 3, name: 'Legacy-Privat', scope: undefined, is_private_investment: true}),
        );

        renderWithProviders(<EaAssetsPanel year={2026}/>);
        const panel = screen.getByTestId('ea-assets-panel');

        await user.click(within(panel).getByRole('button', {name: 'Betriebsanlagen'}));
        expect(within(panel).getByText('Betrieb-Kamera')).toBeVisible();
        expect(within(panel).queryByText('Privat-Notebook')).not.toBeInTheDocument();
        expect(within(panel).queryByText('Legacy-Privat')).not.toBeInTheDocument();

        await user.click(within(panel).getByRole('button', {name: 'Privateinlagen'}));
        expect(within(panel).getByText('Privat-Notebook')).toBeVisible();
        expect(within(panel).getByText('Legacy-Privat')).toBeVisible();
        expect(within(panel).queryByText('Betrieb-Kamera')).not.toBeInTheDocument();
    });

    it('erstellt einen Eigenbeleg ohne Journalbuchung (D-85)', async () => {
        const user = userEvent.setup();
        setAssets(asset({
            scope: 'private',
            is_private_investment: true,
            business_share: 30,
            years: [plan({scope: 'private', private_use_percent: 70, depreciation_cents: 20000})],
        }));
        vi.mocked(bookEaAssetDepreciation).mockResolvedValue({
            eigenbeleg: {
                year: 2026,
                eigenbeleg_no: 'EB-1-2026',
                theoretical_depreciation_cents: 20000,
                usage_percent: 30,
                eigenbeleg_cents: 6000,
                eigenbeleg_created_at: '2026-11-23T00:00:00Z',
            },
            already_created: false,
        });

        renderWithProviders(<EaAssetsPanel year={2026}/>);
        const panel = screen.getByTestId('ea-assets-panel');

        await user.click(within(panel).getByRole('button', {name: 'Eigenbeleg erstellen'}));

        expect(bookEaAssetDepreciation).toHaveBeenCalledWith(1, 2026);
        const status = within(panel).getByRole('status');
        expect(status).toHaveTextContent(/EB-1-2026/);
        expect(status).toHaveTextContent(/60,00/);
        // Der Eigenbeleg ist reine Dokumentation: kein Aufwand, keine Buchung.
        expect(status).not.toHaveTextContent(/gebucht|Buchung|Journal/i);
    });

    it('übernimmt das Jahr idempotent (D-85)', async () => {
        const user = userEvent.setup();
        setAssets(asset());
        vi.mocked(carryOverEaAssets)
            .mockResolvedValueOnce({year: 2026, created: 2})
            .mockResolvedValueOnce({year: 2026, created: 0});

        renderWithProviders(<EaAssetsPanel year={2026}/>);
        const panel = screen.getByTestId('ea-assets-panel');
        const button = within(panel).getByRole('button', {name: 'Jahr übernehmen'});

        await user.click(button);
        expect(carryOverEaAssets).toHaveBeenCalledWith(2026);
        expect(within(panel).getByRole('status')).toHaveTextContent(/2 Anlagen ergänzt/);

        await user.click(button);
        expect(carryOverEaAssets).toHaveBeenCalledTimes(2);
        expect(within(panel).getByRole('status')).toHaveTextContent(/bereits vollständig übernommen/);
    });

    it('hängt einen Beleg aus dem Jahresverzeichnis an (D-85)', async () => {
        const user = userEvent.setup();
        setAssets(asset({documents: []}));
        vi.mocked(useEaDocuments).mockReturnValue(swrResult<ReturnType<typeof useEaDocuments>>({
            documents: [{
                id: 7,
                year: 2026,
                month: null,
                receipt_no: 'R-7',
                original_name: 'rechnung.pdf',
                mime_type: 'application/pdf',
                size_bytes: 12,
                status: 'signed',
                sha256: 'abc',
                signed_at: null,
                signed_sha256: null,
                signed_storage_path: null,
                signed_size_bytes: null,
            }],
        }));
        vi.mocked(attachEaAssetDocument).mockResolvedValue({
            document: {id: 7, receipt_no: 'R-7', original_name: 'rechnung.pdf', sha256: 'abc'},
            already_linked: false,
        });

        renderWithProviders(<EaAssetsPanel year={2026}/>);
        const panel = screen.getByTestId('ea-assets-panel');

        await user.selectOptions(within(panel).getByLabelText('Beleg'), '7');
        await user.click(within(panel).getByRole('button', {name: 'Beleg anhängen'}));

        expect(attachEaAssetDocument).toHaveBeenCalledWith(1, 7);
        expect(within(panel).getByRole('status')).toHaveTextContent(/rechnung.pdf/);
        expect(panel).not.toHaveTextContent('storage_path');
    });

    it('löst einen verknüpften Beleg, ohne den Beleg selbst zu löschen (D-85)', async () => {
        const user = userEvent.setup();
        setAssets(asset({
            documents: [{id: 7, receipt_no: 'R-7', original_name: 'rechnung.pdf', sha256: 'abc'}],
        }));
        vi.mocked(detachEaAssetDocument).mockResolvedValue(undefined);

        renderWithProviders(<EaAssetsPanel year={2026}/>);
        const panel = screen.getByTestId('ea-assets-panel');

        expect(within(panel).getByText('rechnung.pdf')).toBeVisible();
        // Nur Metadaten — der Speicherpfad verlässt die API nie.
        expect(panel).not.toHaveTextContent('storage_path');

        await user.click(within(panel).getByRole('button', {name: 'Lösen'}));

        expect(detachEaAssetDocument).toHaveBeenCalledWith(1, 7);
        expect(within(panel).getByRole('status')).toHaveTextContent(/rechnung.pdf/);
    });
});
