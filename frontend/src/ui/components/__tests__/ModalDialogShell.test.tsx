import { describe, expect, it, vi } from 'vitest';
import { useRef, useState } from 'react';
import { fireEvent, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../test-setup';
import ModalDialogShell from '../ModalDialogShell';

function renderDialog(onClose = vi.fn()) {
    const result = renderWithProviders(
        <>
            <ModalDialogShell
                title="Galerie bearbeiten"
                onClose={onClose}
                editing={false}
                isSubmitting={false}
                onSubmit={(event) => event.preventDefault()}
            >
                <button type="button">Erste Aktion</button>
                <button type="button">Letzte Aktion</button>
            </ModalDialogShell>
            <button type="button" data-testid="outside-control">Außerhalb</button>
        </>,
    );

    return { onClose, ...result };
}

describe('ModalDialogShell', () => {
    it('exposes a named modal dialog contract', () => {
        renderDialog();

        const dialog = screen.getByRole('dialog', { name: 'Galerie bearbeiten' });
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        expect(dialog).toHaveAttribute('open');
        expect(screen.getByRole('button', { name: 'Schließen' })).toBeInTheDocument();
    });

    it('owns the focus trap even when a caller supplies a dialog ref', () => {
        function DialogWithExternalRef() {
            const dialogRef = useRef<HTMLDialogElement | null>(null);
            return (
                <ModalDialogShell
                    title="Externe Referenz"
                    modalRef={dialogRef}
                    onClose={vi.fn()}
                    editing={false}
                    isSubmitting={false}
                    onSubmit={(event) => event.preventDefault()}
                >
                    <button type="button">Inhalt</button>
                </ModalDialogShell>
            );
        }

        renderWithProviders(<DialogWithExternalRef />);

        expect(screen.getByRole('button', { name: 'Schließen' })).toHaveFocus();
    });

    it('closes when Escape is pressed', async () => {
        const user = userEvent.setup();
        const { onClose } = renderDialog();

        await user.keyboard('{Escape}');

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('keeps focus inside the dialog and wraps at both boundaries', async () => {
        const user = userEvent.setup();
        renderDialog();

        const closeButton = screen.getByRole('button', { name: 'Schließen' });
        const saveButton = screen.getByRole('button', { name: 'Speichern' });
        const outsideControl = screen.getByTestId('outside-control');

        expect(closeButton).toHaveFocus();

        await user.tab({ shift: true });
        expect(saveButton).toHaveFocus();

        await user.tab();
        expect(closeButton).toHaveFocus();

        outsideControl.focus();
        expect(closeButton).toHaveFocus();
    });

    it('restores focus to the trigger after the dialog closes', async () => {
        const user = userEvent.setup();

        function DialogWithTrigger() {
            const [isOpen, setIsOpen] = useState(false);
            return (
                <>
                    <button type="button" onClick={() => setIsOpen(true)}>Dialog öffnen</button>
                    {isOpen && (
                        <ModalDialogShell
                            title="Dialog"
                            onClose={() => setIsOpen(false)}
                            editing={false}
                            isSubmitting={false}
                            onSubmit={(event) => event.preventDefault()}
                        >
                            <button type="button">Inhalt</button>
                        </ModalDialogShell>
                    )}
                </>
            );
        }

        renderWithProviders(<DialogWithTrigger />);
        const trigger = screen.getByRole('button', { name: 'Dialog öffnen' });
        trigger.focus();
        await user.click(trigger);

        const closeButton = screen.getByRole('button', { name: 'Schließen' });
        expect(closeButton).toHaveFocus();

        await user.click(closeButton);
        expect(trigger).toHaveFocus();
    });

    it('keeps nested dialogs contained and restores focus through the trap stack', async () => {
        const user = userEvent.setup();

        function NestedDialogs() {
            const [outerOpen, setOuterOpen] = useState(false);
            const [innerOpen, setInnerOpen] = useState(false);
            return (
                <>
                    <button type="button" onClick={() => setOuterOpen(true)}>Äußeren Dialog öffnen</button>
                    <button type="button" data-testid="nested-outside-control">Außerhalb</button>
                    {outerOpen && (
                        <ModalDialogShell
                            title="Äußerer Dialog"
                            onClose={() => setOuterOpen(false)}
                            editing={false}
                            isSubmitting={false}
                            onSubmit={(event) => event.preventDefault()}
                        >
                            <button type="button" onClick={() => setInnerOpen(true)}>Inneren Dialog öffnen</button>
                        </ModalDialogShell>
                    )}
                    {outerOpen && innerOpen && (
                        <ModalDialogShell
                            title="Innerer Dialog"
                            onClose={() => setInnerOpen(false)}
                            editing={false}
                            isSubmitting={false}
                            onSubmit={(event) => event.preventDefault()}
                        >
                            <button type="button">Innerer Inhalt</button>
                        </ModalDialogShell>
                    )}
                </>
            );
        }

        renderWithProviders(<NestedDialogs />);
        const outerTrigger = screen.getByRole('button', { name: 'Äußeren Dialog öffnen' });
        outerTrigger.focus();
        await user.click(outerTrigger);

        const outerDialog = screen.getByRole('dialog', { name: 'Äußerer Dialog' });
        const innerOpener = within(outerDialog).getByRole('button', { name: 'Inneren Dialog öffnen' });
        await user.click(innerOpener);

        const innerDialog = screen.getByRole('dialog', { name: 'Innerer Dialog' });
        const innerCloseButton = within(innerDialog).getByRole('button', { name: 'Schließen' });
        expect(innerCloseButton).toHaveFocus();

        const innerSaveButton = within(innerDialog).getByRole('button', { name: 'Speichern' });
        innerSaveButton.focus();
        await user.tab();
        expect(innerCloseButton).toHaveFocus();

        screen.getByTestId('nested-outside-control').focus();
        expect(innerCloseButton).toHaveFocus();

        await user.click(innerCloseButton);
        expect(innerOpener).toHaveFocus();

        const outerCloseButton = within(outerDialog).getByRole('button', { name: 'Schließen' });
        await user.click(outerCloseButton);
        expect(outerTrigger).toHaveFocus();
    });

    it('restores the original trigger when nested dialogs unmount together', async () => {
        const user = userEvent.setup();

        function NestedDialogsWithSharedClose() {
            const [outerOpen, setOuterOpen] = useState(false);
            const [innerOpen, setInnerOpen] = useState(false);
            return (
                <>
                    <button type="button" onClick={() => setOuterOpen(true)}>Gemeinsamen Dialog öffnen</button>
                    {outerOpen && (
                        <>
                            <ModalDialogShell
                                title="Äußerer Dialog"
                                onClose={() => setOuterOpen(false)}
                                editing={false}
                                isSubmitting={false}
                                onSubmit={(event) => event.preventDefault()}
                            >
                                <button type="button" onClick={() => setInnerOpen(true)}>Inneren Dialog öffnen</button>
                            </ModalDialogShell>
                            {innerOpen && (
                                <ModalDialogShell
                                    title="Innerer Dialog"
                                    onClose={() => setInnerOpen(false)}
                                    editing={false}
                                    isSubmitting={false}
                                    onSubmit={(event) => event.preventDefault()}
                                >
                                    <button type="button">Innerer Inhalt</button>
                                </ModalDialogShell>
                            )}
                        </>
                    )}
                </>
            );
        }

        renderWithProviders(<NestedDialogsWithSharedClose />);
        const trigger = screen.getByRole('button', { name: 'Gemeinsamen Dialog öffnen' });
        trigger.focus();
        await user.click(trigger);

        const outerDialog = screen.getByRole('dialog', { name: 'Äußerer Dialog' });
        await user.click(within(outerDialog).getByRole('button', { name: 'Inneren Dialog öffnen' }));
        expect(screen.getByRole('dialog', { name: 'Innerer Dialog' })).toBeInTheDocument();

        fireEvent.click(within(outerDialog).getByRole('button', { name: 'Schließen' }));
        expect(trigger).toHaveFocus();
    });
});
