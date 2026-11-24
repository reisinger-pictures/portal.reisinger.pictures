import { describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
    attachEurAssetDocument,
    bookEurAssetDepreciation,
    carryOverEurAssets,
    detachEurAssetDocument,
    switchEurAssetToLinear,
    useEurAssets,
    useEurDocuments,
    useEurReference,
    type EurAsset,
    type EurPlanYear,
} from '../../../../logic/useEur';
import { renderWithProviders } from '../../../../test-setup';
import EurAssetsPanel from '../EurAssetsPanel';

vi.mock('../../../../logic/useEur', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEur')>();
    return {
        ...actual,
        useEurAssets: vi.fn(),
        useEurReference: vi.fn(),
        useEurDocuments: vi.fn(),
        bookEurAssetDepreciation: vi.fn(),
        switchEurAssetToLinear: vi.fn(),
        carryOverEurAssets: vi.fn(),
        attachEurAssetDocument: vi.fn(),
        detachEurAssetDocument: vi.fn(),
    };
});

const plan = (overrides: Partial<EurPlanYear> = {}): EurPlanYear => ({
    year: 2026,
    method: 'linear',
    depreciation_cents: 20000,
    book_value_cents: 180000,
    private_use_percent: 0,
    private_use_cents: 0,
    business_use_cents: 20000,
    status: 'open',
    eur_entry_id: null,
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
    is_private_investment: false,
    years: [plan()],
    ...overrides,
});

function swrResult<R>(data: unknown): R {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as R;
}

function setAssets(...assets: EurAsset[]) {
    vi.mocked(useEurAssets).mockReturnValue(swrResult<ReturnType<typeof useEurAssets>>({assets}));
    vi.mocked(useEurReference).mockReturnValue(swrResult<ReturnType<typeof useEurReference>>({assets: {gwg_limit_cents: 100000}}));
    vi.mocked(useEurDocuments).mockReturnValue(swrResult<ReturnType<typeof useEurDocuments>>({documents: []}));
}

describe('EurAssetsPanel (#34)', () => {
    it('shows the depreciation directly and books an open year', async () => {
        const user = userEvent.setup();
        setAssets(asset());
        vi.mocked(bookEurAssetDepreciation).mockResolvedValue({
            entry: {id: 9, receipt_no: 'AV-1-2026', business_amount_cents: 20000},
            already_booked: false,
        });

        renderWithProviders(<EurAssetsPanel year={2026}/>);

        const table = screen.getByRole('table');
        // AfA and betrieblicher Anteil both render the booked 200,00 €; the
        // acquisition column shows 2.000,00 € (cost is stored in cents).
        expect(within(table).getAllByText(/200,00/)).toHaveLength(2);
        expect(within(table).getByText(/2\.000,00/)).toBeVisible();
        expect(within(table).getByText(/10,00/)).toBeVisible();
        expect(within(table).getByText(/^0,00/)).toBeVisible();
        expect(within(table).getByText('Offen')).toBeVisible();

        await user.click(within(table).getByRole('button', {name: 'AfA buchen'}));

        expect(bookEurAssetDepreciation).toHaveBeenCalledWith(1, 2026);
        expect(screen.getByRole('status')).toHaveTextContent(/AV-1-2026/);
    });

    it('filters private investments (P5.4)', async () => {
        const user = userEvent.setup();
        setAssets(
            asset({id: 1, name: 'Privat-Notebook', is_private_investment: true}),
            asset({id: 2, name: 'Büro-PC', is_private_investment: false}),
        );

        renderWithProviders(<EurAssetsPanel year={2026}/>);

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

        renderWithProviders(<EurAssetsPanel year={2026}/>);

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
        const {unmount} = renderWithProviders(<EurAssetsPanel year={2026}/>);
        expect(screen.getByText('Entnahme fehlt')).toBeVisible();
        unmount();

        setAssets(asset({...mixed, entnahme: {done: true, entry_id: 7, amount_cents: 50000}}));
        renderWithProviders(<EurAssetsPanel year={2026}/>);
        expect(screen.getByText('Entnahme erledigt')).toBeVisible();
        expect(screen.queryByText('Entnahme fehlt')).not.toBeInTheDocument();
    });

    it('requires a reason for the one-time switch to linear (P5.3)', async () => {
        const user = userEvent.setup();
        setAssets(asset({method: 'degressiv', degressive_rate: 3000}));
        vi.mocked(switchEurAssetToLinear).mockResolvedValue({asset: asset()});

        renderWithProviders(<EurAssetsPanel year={2026}/>);

        await user.click(screen.getByRole('button', {name: 'Zu linear wechseln'}));
        const input = screen.getByRole('textbox');
        await user.type(input, 'Wirtschaftlichkeitsprüfung 2026');
        await user.click(screen.getByRole('button', {name: 'Bestätigen'}));

        expect(switchEurAssetToLinear).toHaveBeenCalledWith(1, 2026, 'Wirtschaftlichkeitsprüfung 2026');
    });

    it('zeigt die AV-Hinweise aus dem gepinnten Regeltext (EUE-053/058)', () => {
        setAssets(asset({warnings: [
            {code: 'EUE-053', text: '⚠ Ausgaben-Art muss zu einer AfA-Kennzahl zugeordnet sein'},
            {code: 'EUE-058', text: '⚠ Jahr liegt in der Zukunft'},
        ]}));

        renderWithProviders(<EurAssetsPanel year={2026}/>);

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

        renderWithProviders(<EurAssetsPanel year={2026}/>);

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

        renderWithProviders(<EurAssetsPanel year={2026}/>);

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
        vi.mocked(useEurReference).mockReturnValue(swrResult<ReturnType<typeof useEurReference>>({
            assets: {gwg_limit_cents: 150000},
        }));

        renderWithProviders(<EurAssetsPanel year={2026}/>);

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

        renderWithProviders(<EurAssetsPanel year={2026}/>);

        const low = screen.getByText('GWG-Gut').closest('tr');
        const high = screen.getByText('Normal-Gut').closest('tr');
        expect(low).not.toBeNull();
        expect(high).not.toBeNull();
        expect(within(low as HTMLElement).getByText(hint)).toBeVisible();
        expect(within(high as HTMLElement).queryByText(hint)).not.toBeInTheDocument();
        // Der Hinweis bleibt nicht blockierend: die AfA ist weiter buchbar.
        expect(within(low as HTMLElement).getByRole('button', {name: 'AfA buchen'})).toBeEnabled();
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

        renderWithProviders(<EurAssetsPanel year={2026}/>);
        const panel = screen.getByTestId('eur-assets-panel');

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
        vi.mocked(bookEurAssetDepreciation).mockResolvedValue({
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

        renderWithProviders(<EurAssetsPanel year={2026}/>);
        const panel = screen.getByTestId('eur-assets-panel');

        await user.click(within(panel).getByRole('button', {name: 'Eigenbeleg erstellen'}));

        expect(bookEurAssetDepreciation).toHaveBeenCalledWith(1, 2026);
        const status = within(panel).getByRole('status');
        expect(status).toHaveTextContent(/EB-1-2026/);
        expect(status).toHaveTextContent(/60,00/);
        // Der Eigenbeleg ist reine Dokumentation: kein Aufwand, keine Buchung.
        expect(status).not.toHaveTextContent(/gebucht|Buchung|Journal/i);
    });

    it('übernimmt das Jahr idempotent (D-85)', async () => {
        const user = userEvent.setup();
        setAssets(asset());
        vi.mocked(carryOverEurAssets)
            .mockResolvedValueOnce({year: 2026, created: 2})
            .mockResolvedValueOnce({year: 2026, created: 0});

        renderWithProviders(<EurAssetsPanel year={2026}/>);
        const panel = screen.getByTestId('eur-assets-panel');
        const button = within(panel).getByRole('button', {name: 'Jahr übernehmen'});

        await user.click(button);
        expect(carryOverEurAssets).toHaveBeenCalledWith(2026);
        expect(within(panel).getByRole('status')).toHaveTextContent(/2 Anlagen ergänzt/);

        await user.click(button);
        expect(carryOverEurAssets).toHaveBeenCalledTimes(2);
        expect(within(panel).getByRole('status')).toHaveTextContent(/bereits vollständig übernommen/);
    });

    it('hängt einen Beleg aus dem Jahresverzeichnis an (D-85)', async () => {
        const user = userEvent.setup();
        setAssets(asset({documents: []}));
        vi.mocked(useEurDocuments).mockReturnValue(swrResult<ReturnType<typeof useEurDocuments>>({
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
        vi.mocked(attachEurAssetDocument).mockResolvedValue({
            document: {id: 7, receipt_no: 'R-7', original_name: 'rechnung.pdf', sha256: 'abc'},
            already_linked: false,
        });

        renderWithProviders(<EurAssetsPanel year={2026}/>);
        const panel = screen.getByTestId('eur-assets-panel');

        await user.selectOptions(within(panel).getByLabelText('Beleg'), '7');
        await user.click(within(panel).getByRole('button', {name: 'Beleg anhängen'}));

        expect(attachEurAssetDocument).toHaveBeenCalledWith(1, 7);
        expect(within(panel).getByRole('status')).toHaveTextContent(/rechnung.pdf/);
        expect(panel).not.toHaveTextContent('storage_path');
    });

    it('löst einen verknüpften Beleg, ohne den Beleg selbst zu löschen (D-85)', async () => {
        const user = userEvent.setup();
        setAssets(asset({
            documents: [{id: 7, receipt_no: 'R-7', original_name: 'rechnung.pdf', sha256: 'abc'}],
        }));
        vi.mocked(detachEurAssetDocument).mockResolvedValue(undefined);

        renderWithProviders(<EurAssetsPanel year={2026}/>);
        const panel = screen.getByTestId('eur-assets-panel');

        expect(within(panel).getByText('rechnung.pdf')).toBeVisible();
        // Nur Metadaten — der Speicherpfad verlässt die API nie.
        expect(panel).not.toHaveTextContent('storage_path');

        await user.click(within(panel).getByRole('button', {name: 'Lösen'}));

        expect(detachEurAssetDocument).toHaveBeenCalledWith(1, 7);
        expect(within(panel).getByRole('status')).toHaveTextContent(/rechnung.pdf/);
    });
});
