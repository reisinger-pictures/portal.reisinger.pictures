import { describe, expect, it, vi } from 'vitest';
import { useRef, useState } from 'react';
import { fireEvent, screen, within } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../test-setup';
import ModalDialogShell from '../ModalDialogShell';

function renderDialog(onClose = vi.fn(), props: Partial<React.ComponentProps<typeof ModalDialogShell>> = {}) {
    const result = renderWithProviders(
        <>
            <ModalDialogShell
                title="Galerie bearbeiten"
                onClose={onClose}
                editing={false}
                isSubmitting={false}
                onSubmit={(event) => event.preventDefault()}
                {...props}
            >
                <div data-testid="dialog-content">
                    <button type="button">Erste Aktion</button>
                    <button type="button">Letzte Aktion</button>
                </div>
            </ModalDialogShell>
            <button type="button" data-testid="outside-control">Außerhalb</button>
        </>,
    );

    return { onClose, ...result };
}

/**
 * The element that is a direct child of `ancestor` and wraps `node` — the
 * region the shell put around that content. Resolved by walking the tree, so
 * the assertions below pin structure instead of Tailwind class names.
 */
function wrapperUnder(ancestor: HTMLElement, node: HTMLElement): HTMLElement | null {
    let current: HTMLElement | null = node;
    while (current && current.parentElement !== ancestor) current = current.parentElement;
    return current;
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

    it('keeps the submit row out of the scrolling body when the bounded layout is on', () => {
        // A form dialog with more content than a viewport has to offer: the box
        // is daisyUI's scroll region, so an unbounded submit row is simply the
        // last row of a long column and Speichern lands below the fold. The
        // bounded layout gives the children their own region inside the form and
        // leaves the submit row beside it.
        const { container } = renderDialog(vi.fn(), { scrollableBody: true });

        const form = container.querySelector('form') as HTMLFormElement;
        const body = screen.getByTestId('dialog-content').parentElement as HTMLElement;
        const submit = screen.getByRole('button', { name: 'Speichern' });

        // The body is a region inside the form…
        expect(body.parentElement).toBe(form);
        // …and the submit row is its sibling, so scrolling the body cannot take
        // it out of reach. Still inside the form: Enter-to-submit and
        // `type="submit"` are untouched.
        expect(wrapperUnder(form, submit)).not.toBe(body);
        expect(body).not.toContainElement(submit);
        expect(form).toContainElement(submit);
    });

    it('leaves the unbounded structure alone by default', () => {
        // The default path is the load-bearing one: every dialog that does not
        // opt in is rendered against it, and there the whole box scrolls as one
        // column. The bounded layout must stay opt-in, so the unwrapped body
        // and the submit row remain direct siblings of the form.
        const { container } = renderDialog();

        const form = container.querySelector('form') as HTMLFormElement;
        const submit = screen.getByRole('button', { name: 'Speichern' });

        expect(screen.getByTestId('dialog-content').parentElement).toBe(form);
        expect(wrapperUnder(form, submit)?.parentElement).toBe(form);
    });

    it('forwards boxClassName to the modal-box', () => {
        // It used to be dropped, which is why the bounded long-dialog layout was
        // unreachable through this shell: GalleryModal abandoned the shared
        // submit row and reimplemented it. A class-name pass-through is asserted
        // on the class name — there is nothing structural left to check.
        const { container } = renderDialog(vi.fn(), { boxClassName: 'max-w-4xl' });

        const box = (container.querySelector('.modal-box') as HTMLElement).className;
        expect(box).toContain('max-w-4xl');
    });
});
