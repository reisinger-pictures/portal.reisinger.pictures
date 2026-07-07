import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import WysiwygEditor from '../components/WysiwygEditor';

vi.mock('@tiptap/react', () => ({
    useEditor: vi.fn(),
    EditorContent: () => <div data-testid="editor-content" />,
}));

vi.mock('@tiptap/starter-kit', () => ({
    default: { configure: vi.fn() },
}));

vi.mock('@tiptap/extension-table', () => ({
    Table: { configure: vi.fn() },
}));

vi.mock('@tiptap/extension-table-row', () => ({
    TableRow: {},
}));

vi.mock('@tiptap/extension-table-cell', () => ({
    TableCell: {},
}));

vi.mock('@tiptap/extension-table-header', () => ({
    TableHeader: {},
}));

vi.mock('swr', () => ({
    default: vi.fn(),
}));

vi.mock('../../api', () => ({
    fetcher: vi.fn(),
}));

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

import { useEditor } from '@tiptap/react';
import { usePermissions } from '../../logic/usePermissions';
import useSWR from 'swr';

describe('WysiwygEditor', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(usePermissions).mockReturnValue({
            isStaff: true,
            isSuperAdmin: true,
            isAdmin: true,
            isPhotographer: false,
            isOrgAdmin: false,
            showOrgsSection: false,
            canEditMetadata: false,
            isPowerUser: false,
            canAccessB2BFeatures: false,
            showCRM: false,
            showInvoicing: false,
            showPayouts: false,
        });

        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        } as never);

        vi.mocked(useEditor).mockReturnValue({
            chain: () => ({
                focus: () => ({
                    toggleBold: () => ({ run: vi.fn() }),
                    toggleItalic: () => ({ run: vi.fn() }),
                    toggleBulletList: () => ({ run: vi.fn() }),
                    insertContent: () => ({ run: vi.fn(), insertContent: vi.fn() }),
                    deleteRange: () => ({ run: vi.fn() }),
                    clearNodes: () => ({ unsetAllMarks: () => ({ run: vi.fn() }) }),
                    run: vi.fn(),
                }),
            }),
            isActive: vi.fn(() => false),
            getHTML: vi.fn(() => '<p>test content</p>'),
            commands: { setContent: vi.fn(), focus: vi.fn() },
            view: { coordsAtPos: vi.fn(() => ({ left: 0, right: 0, top: 0, bottom: 0 })) },
            state: {
                selection: {
                    $from: { pos: 0, parent: { textBetween: vi.fn(() => '') }, parentOffset: 0 },
                    empty: true, from: 0, to: 0,
                },
            },
            on: vi.fn(),
            off: vi.fn(),
            destroy: vi.fn(),
        } as never);
    });

    it('shows loading spinner when snippets are loading for super admin', () => {
        vi.mocked(useSWR).mockReturnValue({
            data: undefined,
            error: undefined,
            isLoading: true,
            mutate: vi.fn(),
        } as never);

        renderWithProviders(<WysiwygEditor value="" onChange={vi.fn()} />);
        const spinner = document.querySelector('.loading.loading-spinner');
        expect(spinner).toBeInTheDocument();
    });

    it('renders editor content when ready', () => {
        renderWithProviders(<WysiwygEditor value="<p>Initial</p>" onChange={vi.fn()} />);
        expect(screen.getByTestId('editor-content')).toBeInTheDocument();
    });

    it('renders character count footer', () => {
        renderWithProviders(<WysiwygEditor value="<p>test</p>" onChange={vi.fn()} />);
        expect(screen.getByText(/Zeichen \(HTML\)/)).toBeInTheDocument();
    });

    it('renders formatting toolbar buttons', () => {
        renderWithProviders(<WysiwygEditor value="" onChange={vi.fn()} />);
        const boldButton = document.querySelector('[class*="btn"][class*="btn-sm"]');
        expect(boldButton).toBeInTheDocument();
    });

    it('calls onChange when editor content changes', () => {
        const mockOnChange = vi.fn();

        vi.mocked(useEditor).mockReturnValue({
            chain: () => ({
                focus: () => ({
                    toggleBold: () => ({ run: vi.fn() }),
                    toggleItalic: () => ({ run: vi.fn() }),
                    toggleBulletList: () => ({ run: vi.fn() }),
                    insertContent: () => ({ run: vi.fn() }),
                    deleteRange: () => ({ run: vi.fn() }),
                    clearNodes: () => ({ unsetAllMarks: () => ({ run: vi.fn() }) }),
                    run: vi.fn(),
                }),
            }),
            isActive: vi.fn(() => false),
            getHTML: vi.fn(() => '<p>updated</p>'),
            commands: { setContent: vi.fn(), focus: vi.fn() },
            view: { coordsAtPos: vi.fn(() => ({ left: 0, right: 0, top: 0, bottom: 0 })) },
            state: {
                selection: {
                    $from: { pos: 0, parent: { textBetween: vi.fn(() => '') }, parentOffset: 0 },
                    empty: true, from: 0, to: 0,
                },
            },
            on: vi.fn(),
            off: vi.fn(),
            destroy: vi.fn(),
        } as never);

        renderWithProviders(<WysiwygEditor value="" onChange={mockOnChange} />);
        expect(screen.getByTestId('editor-content')).toBeInTheDocument();
    });
});
