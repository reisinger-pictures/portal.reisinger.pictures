import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import {
    createEurCorrectionDocument,
    ocrEurDocument,
    uploadSignedEurDocument,
    useEurAssetGroups,
    useEurDocuments,
    type EurDocument,
} from '../../../../logic/useEur';
import { renderWithProviders } from '../../../../test-setup';
import EurDocumentsPanel from '../EurDocumentsPanel';

vi.mock('../../../../logic/useEur', async (importOriginal) => {
    const actual = await importOriginal<typeof import('../../../../logic/useEur')>();
    return {
        ...actual,
        useEurDocuments: vi.fn(),
        useEurAssetGroups: vi.fn(),
        createEurCorrectionDocument: vi.fn(),
        createEurDocumentPositions: vi.fn(),
        deleteEurDocumentPosition: vi.fn(),
        setEurDocumentGroup: vi.fn(),
        ocrEurDocument: vi.fn(),
        uploadSignedEurDocument: vi.fn(),
    };
});

function swrResult<R>(data: unknown): R {
    return {data, error: undefined, isLoading: false, mutate: vi.fn()} as unknown as R;
}

function emptyDocuments() {
    return swrResult<ReturnType<typeof useEurDocuments>>({documents: []});
}

const unsignedDocument: EurDocument = {
    id: 42,
    year: 2026,
    month: 1,
    receipt_no: null,
    original_name: 'korrektur-2026-01.pdf',
    mime_type: 'application/pdf',
    size_bytes: 2048,
    status: 'unsigned',
    sha256: 'original-hash',
    signed_at: null,
    signed_sha256: null,
    signed_storage_path: null,
    signed_size_bytes: null,
    linked_entry_ids: [],
    total_cents: null,
    allocated_cents: 0,
    remaining_cents: null,
    positions: [],
};

function documentsWithUnsigned() {
    return swrResult<ReturnType<typeof useEurDocuments>>({documents: [unsignedDocument]});
}

beforeEach(() => {
    vi.clearAllMocks();
    vi.mocked(useEurAssetGroups).mockReturnValue(swrResult<ReturnType<typeof useEurAssetGroups>>({groups: []}));
});

describe('EurDocumentsPanel upload control', () => {
    it('shows a German-labelled file control without browser-default English strings', () => {
        vi.mocked(useEurDocuments).mockReturnValue(emptyDocuments());

        renderWithProviders(<EurDocumentsPanel year={2026} month={1}/>);

        expect(screen.getByText('Datei auswählen')).toBeVisible();
        expect(screen.getByText('Keine Datei ausgewählt')).toBeVisible();
        expect(screen.queryByText(/Choose File/i)).not.toBeInTheDocument();
        expect(screen.queryByText(/No file chosen/i)).not.toBeInTheDocument();

        const input = screen.getByLabelText('Datei auswählen');
        expect(input).toHaveAttribute('type', 'file');
        expect(input).toBeRequired();
    });

    it('shows the selected file name instead of the empty-state text after choosing a file', async () => {
        vi.mocked(useEurDocuments).mockReturnValue(emptyDocuments());

        renderWithProviders(<EurDocumentsPanel year={2026} month={1}/>);

        await userEvent.upload(screen.getByLabelText('Datei auswählen'),
            new File(['beleg'], 'beleg-2026.pdf', {type: 'application/pdf'}));

        expect(screen.getByText('beleg-2026.pdf')).toBeVisible();
        expect(screen.queryByText('Keine Datei ausgewählt')).not.toBeInTheDocument();
    });
});

describe('EurDocumentsPanel Korrektur-PDF', () => {
    it('creates an unsigned correction document and offers its download link', async () => {
        vi.mocked(useEurDocuments).mockReturnValue(emptyDocuments());
        vi.mocked(createEurCorrectionDocument).mockResolvedValue({
            document: {...unsignedDocument, id: 7},
        });

        renderWithProviders(<EurDocumentsPanel year={2026} month={3}/>);

        await userEvent.click(screen.getByRole('button', {name: 'Korrektur-PDF erstellen'}));

        expect(createEurCorrectionDocument).toHaveBeenCalledWith({kind: 'monthly', year: 2026, month: 3});
        const link = await screen.findByRole('link', {name: 'Unsigniertes Korrektur-PDF herunterladen'});
        expect(link).toHaveAttribute('href', '/api/management/eur/documents/7/download');
    });

    it('omits the month for an asset correction document', async () => {
        vi.mocked(useEurDocuments).mockReturnValue(emptyDocuments());
        vi.mocked(createEurCorrectionDocument).mockResolvedValue({
            document: {...unsignedDocument, id: 8},
        });

        renderWithProviders(<EurDocumentsPanel year={2026} month={3}/>);

        await userEvent.selectOptions(screen.getByLabelText('Art des Korrektur-PDF'), 'assets');
        await userEvent.click(screen.getByRole('button', {name: 'Korrektur-PDF erstellen'}));

        expect(createEurCorrectionDocument).toHaveBeenCalledWith({kind: 'assets', year: 2026});
    });
});

describe('EurDocumentsPanel signed upload', () => {
    it('uploads the signed PDF and shows its download link', async () => {
        vi.mocked(useEurDocuments).mockReturnValue(documentsWithUnsigned());
        vi.mocked(uploadSignedEurDocument).mockResolvedValue({
            document: {
                ...unsignedDocument,
                status: 'signed',
                signed_at: '2026-11-22T10:00:00Z',
                signed_sha256: 'signed-hash',
                signed_storage_path: 'private/signed.pdf',
                signed_size_bytes: 4096,
            },
        });

        renderWithProviders(<EurDocumentsPanel year={2026} month={1}/>);

        const row = screen.getByRole('row', {name: /korrektur-2026-01\.pdf/});
        const chosen = new File(['%PDF-1.4 signed'], 'signiert.pdf', {type: 'application/pdf'});
        await userEvent.upload(within(row).getByLabelText('Signiertes PDF hochladen'), chosen);

        expect(uploadSignedEurDocument).toHaveBeenCalledWith(42, chosen);
        const link = await within(row).findByRole('link', {name: 'Signiertes PDF herunterladen'});
        expect(link).toHaveAttribute('href', '/api/management/eur/documents/42/signed/download');
        expect(within(row).queryByRole('alert')).not.toBeInTheDocument();
    });

    it('shows the German error and action from a 422 response', async () => {
        vi.mocked(useEurDocuments).mockReturnValue(documentsWithUnsigned());
        const rejected = Object.assign(new Error('Die Inhaltsprüfung ist fehlgeschlagen.'), {
            status: 422,
            info: {
                error: 'Die hochgeladene Datei ist nicht die signierte Version unseres PDFs.',
                action: 'Signieren Sie unser Original-PDF erneut und laden Sie es hier hoch.',
            },
        });
        vi.mocked(uploadSignedEurDocument).mockRejectedValue(rejected);

        renderWithProviders(<EurDocumentsPanel year={2026} month={1}/>);

        const row = screen.getByRole('row', {name: /korrektur-2026-01\.pdf/});
        await userEvent.upload(within(row).getByLabelText('Signiertes PDF hochladen'),
            new File(['falsch'], 'falsch.pdf', {type: 'application/pdf'}));

        const alert = await within(row).findByRole('alert');
        expect(alert).toHaveTextContent('Die hochgeladene Datei ist nicht die signierte Version unseres PDFs.');
        expect(alert).toHaveTextContent('Signieren Sie unser Original-PDF erneut und laden Sie es hier hoch.');
        expect(within(row).queryByRole('link', {name: 'Signiertes PDF herunterladen'})).not.toBeInTheDocument();
    });

    it('zeigt beratende OCR-Hinweise, ohne daraus eine Buchung zu machen (D-82)', async () => {
        const user = userEvent.setup();
        vi.mocked(useEurDocuments).mockReturnValue(documentsWithUnsigned());
        vi.mocked(ocrEurDocument).mockResolvedValue({ocr: {
            supported: true,
            receipt_no: 'R-2026-42',
            date: '2026-05-15',
            amount_cents: 123456,
        }});

        renderWithProviders(<EurDocumentsPanel year={2026} month={1}/>);

        const row = screen.getByRole('row', {name: /korrektur-2026-01\.pdf/});
        await user.click(within(row).getByRole('button', {name: 'OCR-Hinweise'}));

        expect(ocrEurDocument).toHaveBeenCalledWith(42);
        expect(within(row).getByText(/R-2026-42 · 2026-05-15 · 1\.234,56/)).toBeVisible();
    });
});
