import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
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
    { id: 'p1', title: '', description: '', keywords: '', location: '', city: '', state: '', country: '', iso_country: '', thumb_url: '/thumb1.jpg', url: '/full1.jpg' },
    { id: 'p2', title: '', description: '', keywords: '', location: '', city: '', state: '', country: '', iso_country: '', thumb_url: '/thumb2.jpg', url: '/full2.jpg' },
    { id: 'p3', title: '', description: '', keywords: '', location: '', city: '', state: '', country: '', iso_country: '', thumb_url: '/thumb3.jpg', url: '/full3.jpg' },
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
    });

    vi.mocked(useUI).mockReturnValue({ showToast });

    return { mockGenerateMetadata, mockUpdateMetadata, showToast };
}

describe('AIBatchEditModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new Error('no network')));
    });

    it('renders modal with 3 photos, all rows visible', () => {
        setupMocks();

        render(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        expect(screen.getByText('KI Beschriftung')).toBeInTheDocument();
        expect(screen.getByText('Alle generieren (leere)')).toBeInTheDocument();
    });

    it('KI Generieren button disabled when !isAvailable', () => {
        setupMocks({ isAvailable: false });

        render(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        const buttons = screen.getAllByText('KI Generieren');
        buttons.forEach(btn => expect(btn).toBeDisabled());
    });

    it('badge shows "Nicht verfügbar" when mode=unavailable', () => {
        setupMocks({ mode: 'unavailable', isAvailable: false });

        render(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        expect(screen.getByText('Nicht verfügbar')).toBeInTheDocument();
    });

    it('badge shows "Server: gpt-4o" when mode=server', () => {
        setupMocks({ mode: 'server', modelId: 'gpt-4o' });

        render(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

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

        render(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        const generateButtons = screen.getAllByText('KI Generieren');
        await user.click(generateButtons[0]);

        await vi.waitFor(() => {
            expect(mockGenerateMetadata).toHaveBeenCalledWith('p1', '', '', expect.any(AbortSignal));
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

        render(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        const contextInput = screen.getByPlaceholderText(/z\.B\. Sommerfest/i);
        await user.type(contextInput, 'Global Context');

        const specificInputs = screen.getAllByPlaceholderText(/Spezifischer Bild-Kontext/i);
        await user.type(specificInputs[0], 'Specific Context');

        const generateButtons = screen.getAllByText('KI Generieren');
        await user.click(generateButtons[0]);

        await vi.waitFor(() => {
            expect(mockGenerateMetadata).toHaveBeenCalledWith('p1', 'Global Context', 'Specific Context', expect.any(AbortSignal));
        });
    });

    it('text input for specific context is editable per row', async () => {
        const user = userEvent.setup();
        setupMocks();

        render(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

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

        render(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        await user.click(screen.getByText('Alle generieren (leere)'));

        await vi.waitFor(() => {
            expect(mockGenerateMetadata).toHaveBeenCalledTimes(3);
        });
    });

    it('Speichern saves metadata and shows success toast', async () => {
        const user = userEvent.setup();
        const { mockUpdateMetadata, showToast } = setupMocks();
        mockUpdateMetadata.mockResolvedValue(undefined);

        render(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        const saveButtons = screen.getAllByText('Speichern');
        await user.click(saveButtons[0]);

        await vi.waitFor(() => {
            expect(mockUpdateMetadata).toHaveBeenCalled();
        });

        expect(showToast).toHaveBeenCalledWith('success', 'Gespeichert!');
    });

    it('error toast shown when generateMetadata throws', async () => {
        const user = userEvent.setup();
        const { mockGenerateMetadata, showToast } = setupMocks();
        mockGenerateMetadata.mockRejectedValue(new Error('AI Error'));

        render(<AIBatchEditModal isOpen={true} onClose={vi.fn()} photos={mockPhotos} galleryId="g1" />);

        const generateButtons = screen.getAllByText('KI Generieren');
        await user.click(generateButtons[0]);

        await vi.waitFor(() => {
            expect(showToast).toHaveBeenCalledWith('error', expect.stringContaining('Fehler'));
        });
    });
});
