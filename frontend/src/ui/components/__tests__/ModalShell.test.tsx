import { describe, expect, it, vi } from 'vitest';
import { fireEvent, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../test-setup';
import ModalShell from '../ModalShell';

/**
 * The dialog accessibility contract lives in exactly one place, so it is
 * asserted exactly once, here.
 *
 * FE-8 found thirteen modals reimplementing `modal-box`, most of them without
 * a role, an accessible name, a focus trap or Escape. A per-component test
 * would have been thirteen near-identical files, and the fourteenth modal would
 * still have been free to get it wrong. The contract being in one component is
 * what makes a single test sufficient.
 *
 * This is deliberately a component-contract test, not a use-case test: the
 * behaviour it pins (announced as a dialog, named, focus contained, Escape
 * closes) is a property of the shell, true regardless of which use case
 * renders it. Use-case tests stay where they belong, on what the dialogs are
 * for.
 */
/**
 * The element a direct child of `ancestor` that wraps `node` — the region the
 * shell put around that content. Resolved by walking the tree, so the
 * assertions below pin structure instead of Tailwind class names.
 */
function wrapperUnder(ancestor: HTMLElement, node: HTMLElement): HTMLElement | null {
    let current: HTMLElement | null = node;
    while (current && current.parentElement !== ancestor) current = current.parentElement;
    return current;
}

describe('ModalShell', () => {
    function renderShell(props: Partial<React.ComponentProps<typeof ModalShell>> = {}) {
        const onClose = vi.fn();
        const result = renderWithProviders(
            <>
                <ModalShell title="Historie" onClose={onClose} {...props}>
                    <div data-testid="shell-content">
                        <button type="button">Erste Aktion</button>
                        <button type="button">Letzte Aktion</button>
                    </div>
                </ModalShell>
                <button type="button" data-testid="outside-control">Außerhalb</button>
            </>,
        );

        return { onClose, ...result };
    }

    it('announces itself as a named modal dialog', () => {
        renderShell();

        const dialog = screen.getByRole('dialog', { name: 'Historie' });
        expect(dialog).toHaveAttribute('aria-modal', 'true');
        // The accessible name must come from a real element, not a literal.
        const labelledBy = dialog.getAttribute('aria-labelledby');
        expect(labelledBy).toBeTruthy();
        expect(document.getElementById(labelledBy!)).toHaveTextContent('Historie');
    });

    it('closes on Escape', () => {
        const { onClose } = renderShell();

        const dialog = screen.getByRole('dialog');
        fireEvent.keyDown(dialog, { key: 'Escape' });

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('ignores other keys', () => {
        const { onClose } = renderShell();

        const dialog = screen.getByRole('dialog');
        fireEvent.keyDown(dialog, { key: 'Enter' });

        expect(onClose).not.toHaveBeenCalled();
    });

    it('closes on the native cancel event, so the browser cannot dismiss it unowned', () => {
        const { onClose } = renderShell();

        fireEvent(screen.getByRole('dialog'), new Event('cancel', { cancelable: true }));

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('exposes a labelled close button', async () => {
        const { onClose } = renderShell();

        await userEvent.click(screen.getByRole('button', { name: 'Schließen' }));

        expect(onClose).toHaveBeenCalledTimes(1);
    });

    it('renders no form when no submit handler is given', () => {
        // The eleven non-form modals of FE-8 rely on this: a shell that always
        // rendered a form would force a submit button they have no use for.
        const { container } = renderShell();

        expect(container.querySelector('form')).toBeNull();
    });

    it('wraps children and footer in a form when a submit handler is given', () => {
        // ModalDialogShell depends on this exact structure: the submit button
        // lives in the footer, so both must be inside the form for Enter-to-submit
        // and type="submit" to work.
        const onSubmit = vi.fn((event: React.FormEvent) => event.preventDefault());
        const { container } = renderShell({
            onFormSubmit: onSubmit,
            footer: <button type="submit">Speichern</button>,
        });

        const form = container.querySelector('form');
        expect(form).not.toBeNull();
        expect(form).toContainElement(screen.getByRole('button', { name: 'Speichern' }));
        expect(form).toContainElement(screen.getByRole('button', { name: 'Erste Aktion' }));
    });

    it('closes only the innermost dialog when two are nested', () => {
        // AIGalleryDefaultsModal opens inside another dialog. A key event on
        // the inner one bubbles to the outer handler, so without the
        // top-of-stack check a single Escape closed both at once.
        const closeOuter = vi.fn();
        const closeInner = vi.fn();

        renderWithProviders(
            <ModalShell title="Äußeres Dialog" onClose={closeOuter}>
                <ModalShell title="Inneres Dialog" onClose={closeInner}>
                    <button type="button">Innen</button>
                </ModalShell>
            </ModalShell>,
        );

        const inner = screen.getByRole('dialog', { name: 'Inneres Dialog' });
        fireEvent.keyDown(inner, { key: 'Escape' });

        expect(closeInner).toHaveBeenCalledTimes(1);
        expect(closeOuter).not.toHaveBeenCalled();
    });

    it('passes noValidate through to the form', () => {
        // PhotoJobModal submits through Zod and relied on native constraint
        // validation being off; losing it blocks submit behind a browser
        // tooltip instead of surfacing the field error.
        const { container } = renderShell({
            onFormSubmit: (event: React.FormEvent) => event.preventDefault(),
            noValidate: true,
        });

        expect(container.querySelector('form')).toHaveAttribute('novalidate');
    });

    it('leaves native validation on by default', () => {
        const { container } = renderShell({
            onFormSubmit: (event: React.FormEvent) => event.preventDefault(),
        });

        expect(container.querySelector('form')).not.toHaveAttribute('novalidate');
    });

    it('keeps focus inside the dialog', async () => {
        renderShell();

        const first = screen.getByRole('button', { name: 'Erste Aktion' });
        const outside = screen.getByTestId('outside-control');

        await userEvent.tab();
        expect(first).toHaveFocus();

        // The outside control must not be reachable while the dialog is open.
        for (let i = 0; i < 8; i++) {
            await userEvent.tab();
            expect(outside).not.toHaveFocus();
        }
    });

    it('scrolls the body and keeps the footer outside it when asked to', () => {
        // The defect the opt-in mode exists for: daisyUI makes `.modal-box` the
        // scroll region (`max-height: 100vh`, `overflow-y: auto`) and the footer
        // is the last thing inside it, so a dialog taller than the viewport
        // carries its own footer below the fold. The body has to become a
        // region of its own for the footer to stay reachable.
        const { container } = renderShell({
            scrollableBody: true,
            footer: <button type="button">Fußzeile</button>,
        });

        // <dialog> renders the modal-box and the backdrop as its two children.
        const box = screen.getByRole('dialog').firstElementChild as HTMLElement;
        const body = screen.getByTestId('shell-content').parentElement as HTMLElement;
        const footerButton = screen.getByRole('button', { name: 'Fußzeile' });

        // The body is a region of its own, held by the box…
        expect(body.parentElement).toBe(box);
        // …and the footer is its sibling, not something inside it: scrolling
        // the body can no longer carry the footer out of reach.
        expect(wrapperUnder(box, footerButton)).not.toBe(body);
        expect(body).not.toContainElement(footerButton);
        // No form in this shell, so nothing may sit between box and body.
        expect(container.querySelector('form')).toBeNull();
    });

    it('hands the bounded body to its own form instead of letting the form block it', () => {
        // A form between the box and the body is a plain block, and a flex
        // item's automatic minimum size is its content height — so an unclassed
        // form between them makes the `flex-1` body grow the form, and the box
        // with it, instead of scrolling. The form has to carry the flex chain
        // for the bounded layout to hold; the submit row still lives inside it,
        // which is what keeps Enter-to-submit and `type="submit"` working.
        const { container } = renderShell({
            scrollableBody: true,
            onFormSubmit: (event: React.FormEvent) => event.preventDefault(),
            footer: <button type="submit">Speichern</button>,
        });

        const form = container.querySelector('form');
        const body = screen.getByTestId('shell-content').parentElement as HTMLElement;
        const submit = screen.getByRole('button', { name: 'Speichern' });

        // box → form → body is an unbroken chain, and the footer is a sibling
        // of the body inside the form rather than a descendant of it.
        expect(body.parentElement).toBe(form);
        expect(form).toContainElement(submit);
        expect(body).not.toContainElement(submit);
    });
});
