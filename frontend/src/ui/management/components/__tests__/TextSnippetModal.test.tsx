import { describe, it, expect, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../../test-setup';
import TextSnippetModal from '../TextSnippetModal';

vi.mock('../../../components/WysiwygEditor', () => ({
    default: ({ value, onChange }: { value: string; onChange: (value: string) => void }) => (
        <textarea
            aria-label="Inhalt (HTML)"
            value={value}
            onChange={event => onChange(event.target.value)}
        />
    ),
}));

describe('TextSnippetModal', () => {
    it('keeps the entered snippet data open when saving rejects', async () => {
        const user = userEvent.setup();
        const onClose = vi.fn();
        const onSave = vi.fn().mockRejectedValue(new Error('Speichern fehlgeschlagen'));

        const { container } = renderWithProviders(
            <TextSnippetModal isOpen onClose={onClose} onSave={onSave} />,
        );

        const titleInput = container.querySelector('input[name="title"]') as HTMLInputElement;
        const shortcutInput = container.querySelector('input[name="shortcut"]') as HTMLInputElement;
        const contentInput = screen.getByLabelText('Inhalt (HTML)') as HTMLTextAreaElement;

        await user.type(titleInput, 'Datenschutz');
        await user.type(shortcutInput, 'datenschutz');
        await user.type(contentInput, 'Hinweis zum Datenschutz');
        await user.click(screen.getByRole('button', { name: 'Speichern' }));

        await waitFor(() => expect(onSave).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(screen.getByRole('button', { name: 'Speichern' })).toBeEnabled());

        expect(onClose).not.toHaveBeenCalled();
        expect(titleInput).toHaveValue('Datenschutz');
        expect(shortcutInput).toHaveValue('datenschutz');
        expect(contentInput).toHaveValue('Hinweis zum Datenschutz');
    });
});
