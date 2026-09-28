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

    /**
     * The opt-in pair (`scrollableBody`, `bodyClassName`) and the off-path they
     * must leave alone.
     *
     * Two dialogs need a bounded body; the other sixteen render through this
     * shell unchanged. Giving the bounded layout a working default would move
     * the scroll boundary under all of them at once — which is why the props are
     * opt-in, and why the two properties the opt-in design rests on are pinned
     * here instead of being left to a throwaway harness: without the flag the
     * new props do *nothing* (no class, no region, no new element), and with the
     * flag the caller's class *tunes* the region the shell created rather than
     * replacing it.
     */
    it('ignores bodyClassName without the opt-in', () => {
        // The silent failure is the dangerous one, and it is the shape the prop
        // invites: dropping an unusable class gets reported immediately, while
        // folding it into `boxClassName` on the caller's behalf would land the
        // tuning on the box — the wrong element, silently, under every dialog
        // that never opted in. So the class has to reach no element at all…
        const { container } = renderShell({ bodyClassName: 'scroll-fade-bottom pb-10' });

        const box = screen.getByRole('dialog').firstElementChild as HTMLElement;
        const content = screen.getByTestId('shell-content');

        // …neither token of it, anywhere in the rendered output. The search
        // starts at the container rather than at the box, because a descendant
        // query never matches the box itself — and the box is the element most
        // likely to receive the class by mistake.
        expect(container.querySelectorAll('.scroll-fade-bottom')).toHaveLength(0);
        expect(container.querySelectorAll('.pb-10')).toHaveLength(0);
        // …and the children are not put inside a region to receive it. The shell
        // builds a body region on the opt-in branch and nowhere else, so here
        // they sit straight in the box with nothing between.
        expect(content.parentElement).toBe(box);
        expect(container.querySelector('.overflow-y-auto')).toBeNull();
    });

    it('appends bodyClassName to the region it tunes instead of replacing the shell classes', () => {
        // `bodyClassName` exists because a boundary the shell owns is a knob the
        // caller otherwise cannot turn (ModelDetailModal's scroll fade and the
        // padding that keeps its content clear of it). Tuning means adding to
        // that region: a caller that *replaced* the class string would take
        // `flex-1`/`min-h-0` with it, and the box would grow with its content
        // again — the same defect the opt-in exists to fix, reached by the
        // other door.
        renderShell({
            scrollableBody: true,
            bodyClassName: 'scroll-fade-bottom pb-10',
        });

        const body = screen.getByTestId('shell-content').parentElement as HTMLElement;

        // The shell's own region classes survive…
        expect(body).toHaveClass('flex-1', 'min-h-0', 'overflow-y-auto', 'pr-2');
        // …with the caller's alongside them, not in their place.
        expect(body).toHaveClass('scroll-fade-bottom', 'pb-10');
    });

    it('renders the off-path structure it rendered before the props existed', () => {
        // The default layout is one scrolling column: header, children and footer
        // in the box, and the box scrolls. So neither region the opt-in creates
        // may be present here — every dialog that did not opt in is rendered
        // against exactly this DOM, and an element the shell started wrapping
        // things in is an element they never had to account for. (The form
        // variant of this is pinned in ModalDialogShell.test.tsx, which is the
        // only way to reach it with a submit handler set.)
        renderShell({ footer: <button type="button">Fußzeile</button> });

        const box = screen.getByRole('dialog').firstElementChild as HTMLElement;
        const content = screen.getByTestId('shell-content');
        const footerButton = screen.getByRole('button', { name: 'Fußzeile' });

        // The children are a direct child of the box, not of a body region…
        expect(content.parentElement).toBe(box);
        // …and the footer is a direct child too, so the `shrink-0` wrapper the
        // bounded layout puts around it is absent along with everything else.
        expect(footerButton.parentElement).toBe(box);
        // The box itself is untouched: its height cap and the flex chain that
        // lets the body shrink are what the opt-in switches on, so the box
        // carries its own two classes and nothing else.
        expect(Array.from(box.classList)).toEqual(['modal-box', 'relative']);
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

    /**
     * `boxTestId` — the hook that lets a caller address the box it cannot own.
     *
     * ModelInviteDialog carried `data-testid="model-invite-dialog"` on a wrapper
     * element purely because the shell exposed no way to place one, and eight
     * E2E assertions scope through that id. Both properties matter and they pull
     * in opposite directions: the id has to end up on the box (otherwise the
     * wrapper stays and the hole is only papered over), and the 28
     * dialogs that ask for nothing must not grow an attribute they never had.
     */
    it('puts a caller-supplied testid on the modal-box itself', () => {
        renderShell({ boxTestId: 'harness-dialog' });

        const box = screen.getByTestId('harness-dialog');
        // The box, not a stand-in for it: `boxClassName` cannot carry a testid,
        // so an id landing anywhere else would mean the wrapper came back.
        expect(box).toHaveClass('modal-box');
        expect(box).toBe(screen.getByRole('dialog').firstElementChild);
        // …and it encloses the caller's content, which is the whole reason a
        // dialog testid is used: scoping to it must still reach every node an E2E
        // spec targets through it.
        expect(box).toContainElement(screen.getByTestId('shell-content'));
        expect(box).toContainElement(screen.getByRole('button', { name: 'Erste Aktion' }));
    });

    it('renders no testid at all when none is asked for', () => {
        // The off-path for the hook, and the reason it is safe to add: React
        // omits an attribute whose value is `undefined`, so the other 28
        // dialogs keep byte-identical markup. An always-present `data-testid`
        // would hang a new test handle on every dialog in the app.
        renderShell();

        const box = screen.getByRole('dialog').firstElementChild as HTMLElement;
        expect(box).not.toHaveAttribute('data-testid');
    });

    /**
     * `maxWidth` — the two states the type admits, and only those.
     *
     * The prop once accepted `'lg'` and `'xl'` and dropped both, so the type
     * promised widths the shell never produced. A caller asking for `'lg'` got a
     * dialog at daisyUI's default `max-width: 32rem` and no indication that the
     * box had changed size. The type is now `'default' | '2xl'` — the other two
     * are a compile error, which `tsc` in the build gate enforces and no test
     * here can — so what remains has to actually reach the box.
     */
    it('renders the only two width states the type offers', () => {
        const wide = renderShell({ maxWidth: '2xl' });
        const wideBox = screen.getByRole('dialog').firstElementChild as HTMLElement;
        expect(wideBox).toHaveClass('modal-box', 'max-w-2xl');
        wide.unmount();

        renderShell();
        const defaultBox = screen.getByRole('dialog').firstElementChild as HTMLElement;
        // `default` is daisyUI's own width, so it contributes nothing: not a
        // second `max-w-*` class that could win the cascade in a different order.
        expect(defaultBox).toHaveClass('modal-box');
        expect(defaultBox).not.toHaveClass('max-w-2xl');
    });

    it('keeps a boxClassName width in force regardless of the maxWidth state', () => {
        // The escape hatch the narrowed type pushes every other width onto: a
        // caller that sizes the box itself must get its class even when
        // `maxWidth` is at its default, and both classes have to coexist because
        // the box reads the *last* `max-w-*` in the list.
        renderShell({ boxClassName: 'max-w-4xl' });

        const box = screen.getByRole('dialog').firstElementChild as HTMLElement;
        expect(Array.from(box.classList)).toEqual(['modal-box', 'relative', 'max-w-4xl']);
    });
});
