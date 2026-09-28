import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../../../test-setup';
import userEvent from '@testing-library/user-event';
import AIBatchEditModal from '../AIBatchEditModal';

vi.mock('../../../../logic/useAI', () => ({
    useAI: vi.fn(),
}));

vi.mock('../../../../logic/usePhoto', () => ({
    usePhoto: vi.fn(),
}));

vi.mock('../../../components/UIContext', () => ({
    useUI: vi.fn(),
}));

import { useAI } from '../../../../logic/useAI';
import { usePhoto } from '../../../../logic/usePhoto';
import { useUI } from '../../../components/UIContext';

const mockPhotos = [
    { id: 'p1', gallery_id: 'g1', filename: 'p1.jpg', lr_uuid: 'uuid-p1', width: 100, height: 100, rating: 0, comment: '', title: '', description: '', keywords: '', location: '', city: '', state: '', country: '', iso_country: '', thumb_url: '/thumb1.jpg', url: '/full1.jpg' },
    { id: 'p2', gallery_id: 'g1', filename: 'p2.jpg', lr_uuid: 'uuid-p2', width: 100, height: 100, rating: 0, comment: '', title: '', description: '', keywords: '', location: '', city: '', state: '', country: '', iso_country: '', thumb_url: '/thumb2.jpg', url: '/full2.jpg' },
    { id: 'p3', gallery_id: 'g1', filename: 'p3.jpg', lr_uuid: 'uuid-p3', width: 100, height: 100, rating: 0, comment: '', title: '', description: '', keywords: '', location: '', city: '', state: '', country: '', iso_country: '', thumb_url: '/thumb3.jpg', url: '/full3.jpg' },
];

function setupMocks(overrides: Record<string, unknown> = {}) {
    const mockGenerateMetadata = vi.fn();
    const mockUpdateMetadata = vi.fn();
    const showToast = vi.fn();

    vi.mocked(useAI).mockReturnValue({
        isAvailable: true,
        mode: 'server',
        modelId: 'gpt-4o',
        generateMetadata: mockGenerateMetadata,
        generateMetadataFromText: vi.fn(),
        updateBaseUrl: vi.fn(),
        ...overrides,
    });

    vi.mocked(usePhoto).mockReturnValue({
        updateMetadata: mockUpdateMetadata,
        getVersions: vi.fn(),
        revertMetadata: vi.fn(),
        deletePhoto: vi.fn(),
    });

    vi.mocked(useUI).mockReturnValue({ showToast, confirm: vi.fn(), hasUnsavedChanges: false, setUnsavedChanges: vi.fn() });

    return { mockGenerateMetadata, mockUpdateMetadata, showToast };
}

describe('AIBatchEditModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('no network')));
    });

    it('renders modal with 3 photos, all rows visible', () => {
        setupMocks();

        renderWithProviders(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        expect(screen.getByText('KI Beschriftung')).toBeInTheDocument();
        expect(screen.getByText('Alle generieren (leere)')).toBeInTheDocument();
    });

    it('KI Generieren button disabled when !isAvailable', () => {
        setupMocks({ isAvailable: false });

        renderWithProviders(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        const buttons = screen.getAllByText('KI Generieren');
        buttons.forEach(btn => expect(btn).toBeDisabled());
    });

    it('badge shows "Nicht verfügbar" when mode=unavailable', () => {
        setupMocks({ mode: 'unavailable', isAvailable: false });

        renderWithProviders(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        expect(screen.getByText('Nicht verfügbar')).toBeInTheDocument();
    });

    it('badge shows "Server: gpt-4o" when mode=server', () => {
        setupMocks({ mode: 'server', modelId: 'gpt-4o' });

        renderWithProviders(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        expect(screen.getByText(/Server: gpt-4o/)).toBeInTheDocument();
    });

    it('click KI Generieren on one row with empty context fills fields', async () => {
        const user = userEvent.setup();
        const { mockGenerateMetadata } = setupMocks();
        mockGenerateMetadata.mockResolvedValue({
            title: 'AI Title',
            description: 'AI Description',
            keywords: 'kw1, kw2',
            location: 'Vienna',
            detected_city: 'Vienna',
        });

        renderWithProviders(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        const generateButtons = screen.getAllByText('KI Generieren');
        await user.click(generateButtons[0]);

        await vi.waitFor(() => {
            expect(mockGenerateMetadata).toHaveBeenCalledWith('p1', '', '', expect.any(AbortSignal), undefined);
        });
    });

    it('click KI Generieren with global+specific context passes correct parameters', async () => {
        const user = userEvent.setup();
        const { mockGenerateMetadata } = setupMocks();
        mockGenerateMetadata.mockResolvedValue({
            title: 'AI Title',
            description: 'AI Description',
            keywords: 'kw1, kw2',
            location: 'Vienna',
            detected_city: 'Vienna',
        });

        renderWithProviders(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        const contextInput = screen.getByPlaceholderText(/z\.B\. Sommerfest/i);
        await user.type(contextInput, 'Global Context');

        const specificInputs = screen.getAllByPlaceholderText(/Spezifischer Bild-Kontext/i);
        await user.type(specificInputs[0], 'Specific Context');

        const generateButtons = screen.getAllByText('KI Generieren');
        await user.click(generateButtons[0]);

        await vi.waitFor(() => {
            expect(mockGenerateMetadata).toHaveBeenCalledWith('p1', 'Global Context', 'Specific Context', expect.any(AbortSignal), undefined);
        });
    });

    it('text input for specific context is editable per row', async () => {
        const user = userEvent.setup();
        setupMocks();

        renderWithProviders(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        const specificInputs = screen.getAllByPlaceholderText(/Spezifischer Bild-Kontext/i);
        await user.type(specificInputs[0], 'Row-specific context');

        expect(specificInputs[0]).toHaveValue('Row-specific context');
    });

    it('Alle generieren (leere) calls generate for empty rows', async () => {
        const user = userEvent.setup();
        const { mockGenerateMetadata } = setupMocks();
        mockGenerateMetadata.mockResolvedValue({
            title: 'AI Title',
            description: 'AI Description',
            keywords: 'kw1, kw2',
            location: 'Vienna',
            detected_city: 'Vienna',
        });

        renderWithProviders(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        await user.click(screen.getByText('Alle generieren (leere)'));

        await vi.waitFor(() => {
            expect(mockGenerateMetadata).toHaveBeenCalledTimes(3);
        });

        // All batch calls share one stable session id (prompt-cache routing).
        const sessionIds = mockGenerateMetadata.mock.calls.map(call => call[4]);
        expect(sessionIds[0]).toEqual(expect.any(String));
        expect(sessionIds[1]).toBe(sessionIds[0]);
        expect(sessionIds[2]).toBe(sessionIds[0]);
    });

    it('Speichern saves metadata and shows success toast', async () => {
        const user = userEvent.setup();
        const { mockUpdateMetadata, showToast } = setupMocks();
        mockUpdateMetadata.mockResolvedValue(undefined);

        renderWithProviders(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        const saveButtons = screen.getAllByText('Speichern');
        await user.click(saveButtons[0]);

        // handleSave writes one row at a time and the test clicks the first
        // row's button, so exactly one call is correct. What the bare
        // toHaveBeenCalled() never checked is WHICH row and with what payload —
        // saving p2 or p3, or saving p1 with another row's fields, both passed.
        await vi.waitFor(() => {
            expect(mockUpdateMetadata).toHaveBeenCalledTimes(1);
        });
        expect(mockUpdateMetadata).toHaveBeenCalledWith('p1', expect.objectContaining({
            title: '',
            description: '',
        }));

        expect(showToast).toHaveBeenCalledWith('success', 'Gespeichert!');
    });

    it('error toast shown when generateMetadata throws', async () => {
        const user = userEvent.setup();
        const { mockGenerateMetadata, showToast } = setupMocks();
        mockGenerateMetadata.mockRejectedValue(new Error('AI Error'));

        renderWithProviders(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        const generateButtons = screen.getAllByText('KI Generieren');
        await user.click(generateButtons[0]);

        await vi.waitFor(() => {
            expect(showToast).toHaveBeenCalledWith('error', expect.stringContaining('Fehler'));
        });
    });

    // The migration onto the shell's bounded layout. The context card and the
    // rows used to share one hand-rolled `h-90vh` box with an inner scroll list;
    // now the shell bounds the box and the head sits outside its scroll region.
    // This pins that wiring: dropping `bodyHead` would take the global-context
    // input into the scroll region with the rows, and the tests above — which
    // only read values and click buttons — would not notice.
    it('keeps the context head out of the scroll region and bounds the box at 90vh', () => {
        setupMocks();

        renderWithProviders(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        const box = screen.getByRole('dialog').firstElementChild as HTMLElement;
        expect(box).toHaveClass('max-h-90vh', 'flex', 'flex-col');

        const scroller = box.querySelector('.overflow-y-auto') as HTMLElement;
        expect(scroller).not.toBeNull();
        // The control that changes the batch stays outside the region the rows
        // scroll in; the rows themselves are inside it.
        expect(scroller).not.toContainElement(screen.getByPlaceholderText(/z\.B\. Sommerfest/i));
        expect(scroller).toContainElement(screen.getAllByPlaceholderText(/Spezifischer Bild-Kontext/i)[0]);
    });
});
