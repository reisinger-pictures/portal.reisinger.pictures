import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import AIGalleryDefaultsModal from '../AIGalleryDefaultsModal';

vi.mock('../../../../logic/useAI', () => ({
    useAI: vi.fn(),
}));

vi.mock('../../../components/UIContext', () => ({
    useUI: vi.fn(),
}));

import { useAI } from '../../../../logic/useAI';
import { useUI } from '../../../components/UIContext';

function setupMocks(overrides: Record<string, unknown> = {}) {
    const mockGenerateMetadataFromText = vi.fn();
    const showToast = vi.fn();

    vi.mocked(useAI).mockReturnValue({
        isAvailable: true,
        generateMetadataFromText: mockGenerateMetadataFromText,
        ...overrides,
    });

    vi.mocked(useUI).mockReturnValue({ showToast });

    return { mockGenerateMetadataFromText, showToast };
}

describe('AIGalleryDefaultsModal', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders modal with textarea and KI generieren button', () => {
        setupMocks();
        render(<AIGalleryDefaultsModal isOpen={true} onClose={vi.fn()} onApply={vi.fn()} />);

        expect(screen.getByText(/KI-Vorschlag für Vorgaben/)).toBeInTheDocument();
        expect(screen.getByText(/KI generieren/)).toBeInTheDocument();
        expect(screen.getByPlaceholderText(/z\.B\. Hochzeitsreportage/i)).toBeInTheDocument();
    });

    it('button disabled when textarea empty', () => {
        setupMocks();
        render(<AIGalleryDefaultsModal isOpen={true} onClose={vi.fn()} onApply={vi.fn()} />);

        expect(screen.getByText(/KI generieren/)).toBeDisabled();
    });

    it('click KI generieren → spinner → mock returns → preview visible', async () => {
        const user = userEvent.setup();
        const { mockGenerateMetadataFromText } = setupMocks();
        mockGenerateMetadataFromText.mockResolvedValue({
            title: 'AI Title',
            description: 'AI Description',
            keywords: 'kw1, kw2',
            location: 'Vienna',
            detected_city: 'Vienna',
        });

        render(<AIGalleryDefaultsModal isOpen={true} onClose={vi.fn()} onApply={vi.fn()} />);

        const textarea = screen.getByPlaceholderText(/z\.B\. Hochzeitsreportage/i);
        await user.type(textarea, 'Wedding in Vienna');

        const generateButton = screen.getByText(/KI generieren/);
        await user.click(generateButton);

        await vi.waitFor(() => {
            expect(screen.getByText(/AI Title/)).toBeInTheDocument();
        });
    });

    it('preview shows title, description, keywords, location from mock', async () => {
        const user = userEvent.setup();
        const { mockGenerateMetadataFromText } = setupMocks();
        mockGenerateMetadataFromText.mockResolvedValue({
            title: 'Preview Title',
            description: 'Preview Description',
            keywords: 'prev, kw',
            location: 'Berlin',
            detected_city: 'Berlin',
        });

        render(<AIGalleryDefaultsModal isOpen={true} onClose={vi.fn()} onApply={vi.fn()} />);

        const textarea = screen.getByPlaceholderText(/z\.B\. Hochzeitsreportage/i);
        await user.type(textarea, 'Test');
        await user.click(screen.getByText(/KI generieren/));

        await vi.waitFor(() => {
            expect(screen.getByText('Preview Title')).toBeInTheDocument();
            expect(screen.getByText('Preview Description')).toBeInTheDocument();
            expect(screen.getByText('prev, kw')).toBeInTheDocument();
            expect(screen.getByText('Berlin')).toBeInTheDocument();
        });
    });

    it('click Vorschlag übernehmen calls onApply with correct data', async () => {
        const user = userEvent.setup();
        const onApply = vi.fn();
        const { mockGenerateMetadataFromText } = setupMocks();
        mockGenerateMetadataFromText.mockResolvedValue({
            title: 'Apply Title',
            description: 'Apply Desc',
            keywords: 'apply, kw',
            location: 'Munich',
            detected_city: 'Munich',
        });

        render(<AIGalleryDefaultsModal isOpen={true} onClose={vi.fn()} onApply={onApply} />);

        const textarea = screen.getByPlaceholderText(/z\.B\. Hochzeitsreportage/i);
        await user.type(textarea, 'Test');
        await user.click(screen.getByText(/KI generieren/));

        await vi.waitFor(() => {
            expect(screen.getByText('Vorschlag übernehmen')).toBeInTheDocument();
        });

        await user.click(screen.getByText('Vorschlag übernehmen'));

        expect(onApply).toHaveBeenCalledWith({
            title: 'Apply Title',
            description: 'Apply Desc',
            keywords: 'apply, kw',
            location: 'Munich',
            city: 'Munich',
        });
    });

    it('button disabled when !isAvailable', () => {
        setupMocks({ isAvailable: false });

        render(<AIGalleryDefaultsModal isOpen={true} onClose={vi.fn()} onApply={vi.fn()} />);

        expect(screen.getByText(/KI generieren/)).toBeDisabled();
    });
});
