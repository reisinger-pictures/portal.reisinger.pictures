import { t } from "@lingui/core/macro";
import { type KeyboardEvent, type ReactNode, type RefObject, useId } from 'react';
import { isTopmostTrapContainer, useFocusTrap } from '../../logic/useFocusTrap';

/**
 * The accessibility contract for every dialog in this app, with no opinion
 * about what the dialog contains.
 *
 * FE-8 found thirteen modals that each reimplemented `modal-box` and most of
 * them omitted the accessible name, the modal role, the focus trap and the
 * Escape handler. Fixing that per component fixes thirteen instances and leaves
 * the next modal free to get it wrong again.
 *
 * So the contract lives here and nothing else: `role="dialog"`, the modal
 * flag, the accessible name wired to the title, a focus trap, Escape and
 * backdrop close, and a labelled close button. Consumers supply content.
 *
 * `onFormSubmit` is deliberately optional rather than required. ModalDialogShell
 * is form-shaped (submit footer, `editing`, `isSubmitting`) and only two of
 * the affected modals actually have a form; the other eleven are viewers and
 * editors. Making the form mandatory would have forced those eleven to pass
 * `editing={false} isSubmitting={false} onSubmit={() => {}}` and to render a
 * submit button they do not want, which is worse than the defect. When it is
 * set, children and footer render inside a `<form>` so Enter-to-submit and
 * `type="submit"` keep working exactly as before.
 */
interface ModalShellProps {
    title: ReactNode;
    icon?: string;
    onClose: () => void;
    modalRef?: RefObject<HTMLDialogElement | null>;
    /**
     * The box width, as a two-state choice: daisyUI's own `.modal-box` default
     * (`max-width: 32rem`) or `max-w-2xl`.
     *
     * Two states rather than a scale, deliberately. This prop used to accept
     * `'lg'` and `'xl'` and drop both, which is the worst of the fates an
     * accepted value can have: a caller writing `maxWidth="lg"` got a dialog at
     * daisyUI's 32rem, so the box silently changed size and nothing said so.
     * Implementing the scale would not have removed that trap — `max-w-lg` *is*
     * 32rem, so the silent 32rem is still what a migrating caller gets — and it
     * would not have covered the widths these dialogs actually use, which run
     * from `max-w-lg` to `max-w-7xl` and pass the top of the scale through
     * `boxClassName` regardless. So the type was narrowed instead: `'lg'` and
     * `'xl'` are a compile error at the call site now, not a silent resize.
     * Every other width goes through `boxClassName`, which is the escape hatch
     * this prop never needed to grow into.
     */
    maxWidth?: 'default' | '2xl';
    /** Rendered in the header next to the title, e.g. a save-state indicator. */
    secondaryAction?: ReactNode;
    descriptionId?: string;
    /** Applied to the <dialog> wrapper. */
    className?: string;
    /** Applied to the modal-box, for any width the two states above do not express. */
    boxClassName?: string;
    /**
     * `data-testid` for the modal-box, so a caller can address the dialog
     * element it does not own.
     *
     * The shell owns the box, and without a hook here a consumer that needed a
     * stable handle on the dialog had to invent one: ModelInviteDialog wrapped
     * its whole content in an extra `<div>` purely to carry
     * `data-testid="model-invite-dialog"`, which the E2E assertions in
     * `tests/e2e/crm/` scope through. `boxClassName` cannot carry a testid — it
     * is a class string — so this prop exists rather than an overload of that
     * one.
     *
     * Left unset it renders no attribute at all, which is what keeps every
     * other dialog byte-identical. The count is deliberately not written down
     * here: dialogs are added and migrated, and a number in this comment went
     * stale the moment the eleven own-`modal-box` dialogs came over. See
     * `features/tech/08-dialog-height-contract.md` §6.2 for the current
     * inventory and the counting rule that makes it reproducible.
     */
    testId?: string;
    /** When provided, children and footer render inside a form element. */
    onFormSubmit?: (e: React.FormEvent) => void;
    /**
     * Disables native browser constraint validation on that form.
     *
     * PhotoJobModal relied on this: its title input is `required`, but the form
     * submitted through Zod validation, and native validation would have
     * blocked submit with a browser tooltip instead of surfacing the field
     * error. It is not the default because most forms here do want it.
     */
    noValidate?: boolean;
    /** Rendered after children, inside the form when `onFormSubmit` is set. */
    footer?: ReactNode;
    /**
     * Opt-in: bound the box to 90vh, scroll the children in their own region
     * and keep the footer pinned below that region instead of below the fold.
     *
     * Opt-in rather than default, and that is load-bearing. daisyUI caps
     * `.modal-box` at `100vh` and makes it the scroll region, so the default
     * layout is one scrolling column — header, body and footer together. That
     * is correct for a dialog that fits, and dialogs that do not fit have
     * already worked around it locally (RatingStatusModal, the camera guide in
     * ManagementFtpInbox, GalleryModal) by hand-rolling `boxClassName` and a
     * `flex-1 overflow-y-auto` body. But making the bounded layout the default
     * would move the scroll boundary under every one of the 28 dialogs
     * that render here, including ones that tuned that boundary on purpose:
     * ModelDetailModal fades its last 2rem with `scroll-fade-bottom` and keeps
     * `pb-10` clear of it, which only reads as "there is more below" while the
     * box itself is the thing that scrolls. That is a visible change everywhere
     * to fix the two dialogs whose content genuinely outgrows a viewport — and
     * it would give the hand-rolled dialogs a second, nested scroll region.
     *
     * So a dialog opts in, and only once it has content that can exceed the
     * viewport. What it buys: the submit/cancel row stays reachable without
     * scrolling the form out from under the user.
     */
    scrollableBody?: boolean;
    /**
     * Escape hatch for the bounded body region, and only for it: the caller
     * cannot reach it any other way, because the shell creates it.
     *
     * `scrollableBody` puts the scroll boundary on a region the shell owns
     * (`flex-1 min-h-0 overflow-y-auto`), which is what makes the footer stay
     * reachable — but it also means the boundary is a knob nobody but the shell
     * can turn. A dialog whose content needs the boundary *tuned* then has
     * nowhere to put that tuning: ModelDetailModal fades its last 2rem with
     * `scroll-fade-bottom` and keeps `pb-10` clear of it, and both of those are
     * only correct on the element that actually scrolls. Left on the box, the
     * fade would sit outside the scroll port and the boundary would slice the
     * last row of text with nothing softening it — the exact defect the UI
     * review caught cutting "Linz"/"Österreich" through the glyphs.
     *
     * So the boundary is tunable, but only from the inside: the classes land on
     * the body, and the caller stays responsible for keeping any mask it adds in
     * step with that body's padding.
     *
     * Deliberately inert without `scrollableBody`. In the default layout there
     * is no bounded body — children and footer render straight into the box, so
     * there is no region for the class to land on. The prop is accepted and
     * dropped rather than folded into `boxClassName` on the caller's behalf,
     * because silently reinterpreting it as a box class would move the DOM
     * under the other 28 dialogs the moment one of them passed it. Passing
     * it without the opt-in is a mistake that stays visibly a mistake: nothing
     * changes.
     */
    bodyClassName?: string;
    /**
     * Content that stays out of the scrolling body: rendered between the header
     * and the body region, inside a `shrink-0` wrapper, so a search field or a
     * control that changes the list below it does not scroll away with that
     * list.
     *
     * It exists for the bounded layout, where the shell owns the scroll region
     * a head can be pinned above (`scrollableBody`). Without that opt-in it
     * still renders, in the same place, but pins nothing — there is no region
     * for it to be pinned above.
     */
    bodyHead?: ReactNode;
    /**
     * The bounded box height, as one of two named values. Each maps to exactly
     * one static class (`max-h-80vh` / `max-h-90vh`, both `@utility`
     * definitions in `index.css`) and is applied only in the bounded layout.
     *
     * Named rather than free-form for the two reasons the repo has already paid
     * for: a height interpolated into a class name is invisible to Tailwind's
     * content scan and is purged from the production bundle, and a caller
     * stacking its own `max-h-*` onto the shell's leaves the winner to
     * stylesheet order instead of to intent. `'90vh'` is the default because it
     * is the bound the opt-in has always used.
     */
    height?: ModalShellHeight;
    children: ReactNode;
}

/** Named heights for the bounded box; see `height` above and `BOUNDED_HEIGHT_CLASS`. */
type ModalShellHeight = '80vh' | '90vh';

/**
 * The one place a named height becomes a class. Kept as a module-level map so
 * the class strings are static literals — Tailwind has to see `max-h-80vh` and
 * `max-h-90vh` written out to keep them, and an interpolated `` `max-h-${height}` ``
 * would look like compliance and break in production.
 */
const BOUNDED_HEIGHT_CLASS: Record<ModalShellHeight, string> = {
    '80vh': 'max-h-80vh',
    '90vh': 'max-h-90vh',
};

export default function ModalShell({
    title,
    icon,
    onClose,
    modalRef,
    maxWidth = 'default',
    secondaryAction,
    descriptionId,
    className = '',
    boxClassName = '',
    testId,
    onFormSubmit,
    noValidate = false,
    footer,
    scrollableBody = false,
    bodyClassName = '',
    bodyHead,
    height = '90vh',
    children,
}: ModalShellProps) {
    // `BOUNDED_HEIGHT_CLASS[height]` is what the hand-rolled long dialogs
    // already pass as `boxClassName` (the camera guide in ManagementFtpInbox,
    // RatingStatusModal), so the shared mode lands on the same height the repo
    // already agreed on rather than a second, competing one. The `false` branch
    // contributes an empty string that `.filter(Boolean)` drops, which is what
    // keeps the non-opt-in class list exactly as it was; `height` is ignored
    // there, like `bodyClassName`, because there is no bounded box to cap.
    const widthClass = [
        maxWidth === '2xl' ? 'max-w-2xl' : '',
        boxClassName,
        scrollableBody ? `${BOUNDED_HEIGHT_CLASS[height]} flex flex-col` : '',
    ]
        .filter(Boolean)
        .join(' ');
    const titleId = useId();
    const internalRef = useFocusTrap<HTMLDialogElement>(true, { containerRef: modalRef });
    const resolvedRef = modalRef ?? internalRef;

    const handleKeyDown = (event: KeyboardEvent<HTMLDialogElement>) => {
        if (event.key !== 'Escape') return;
        // A nested dialog's Escape bubbles to this handler too. Acting on it
        // here would close both the inner and the outer dialog, so only the
        // innermost trap may respond.
        if (!isTopmostTrapContainer(event.currentTarget)) return;
        event.preventDefault();
        onClose();
    };

    // Bounded layout: the body takes whatever height the box has left and
    // scrolls inside it, the footer keeps its own height at the bottom. The
    // `shrink-0` wrapper is the shell's, not the caller's, so the guarantee
    // "the footer never gets squeezed out" holds for every caller instead of
    // depending on each footer remembering to carry that class.
    //
    // `bodyClassName` is appended after the shell's own classes so a caller tunes
    // the scroll region rather than replacing it, and `.trim()` keeps the string
    // byte-identical to the shell's when the prop is unused. This element is only
    // ever rendered on the `scrollableBody` branch, which is what makes the prop
    // inert everywhere else — see its docblock.
    const boundedBody = (
        <>
            <div className={`flex-1 min-h-0 overflow-y-auto pr-2 ${bodyClassName}`.trim()}>
                {children}
            </div>
            {footer ? <div className="shrink-0">{footer}</div> : null}
        </>
    );

    // A form cannot stay an unclassed block in the bounded layout: a flex item's
    // automatic minimum size is its content height, so a `flex-1` body below an
    // unclassed form grows the form instead of scrolling, and the box grows with
    // it. Handing the flex chain to the form — the only element between the box
    // and the body — is what keeps the shrink working. The submit row stays
    // inside the form either way, so Enter-to-submit and `type="submit"` are
    // unaffected by the mode.
    let body: ReactNode;
    if (scrollableBody) {
        body = onFormSubmit ? (
            <form onSubmit={onFormSubmit} noValidate={noValidate} className="flex flex-col flex-1 min-h-0">
                {boundedBody}
            </form>
        ) : (
            boundedBody
        );
    } else {
        body = onFormSubmit ? (
            <form onSubmit={onFormSubmit} noValidate={noValidate}>
                {children}
                {footer}
            </form>
        ) : (
            <>
                {children}
                {footer}
            </>
        );
    }

    return (
        <dialog
            ref={resolvedRef}
            open
            role="dialog"
            aria-modal="true"
            aria-labelledby={titleId}
            aria-describedby={descriptionId}
            className={`modal modal-open ${className}`.trim()}
            onKeyDown={handleKeyDown}
            onCancel={(event) => {
                if (!isTopmostTrapContainer(event.currentTarget)) return;
                event.preventDefault();
                onClose();
            }}
        >
            <div className={`modal-box relative ${widthClass}`} data-testid={testId}>
                <button
                    type="button"
                    className="btn btn-circle btn-ghost absolute right-2 top-2"
                    onClick={onClose}
                    aria-label={t`Schließen`}
                >
                    <span aria-hidden="true">✕</span>
                </button>

                <div className="flex justify-between items-center mb-6 mr-8">
                    <h3 id={titleId} className="font-bold text-xl flex items-center gap-2">
                        {icon && <span className={`iconify ${icon} text-primary`} aria-hidden="true"></span>}
                        {title}
                    </h3>
                    {secondaryAction}
                </div>

                {/* Between the header and the body, and `shrink-0`: in the
                    bounded layout this is the region that stays put while the
                    body below it scrolls. Its own wrapper (rather than the head
                    nodes directly) is what makes the pin a guarantee of the
                    shell instead of something every caller has to remember. */}
                {bodyHead ? <div className="shrink-0">{bodyHead}</div> : null}

                {body}
            </div>
            <div className="modal-backdrop" aria-hidden="true" onClick={onClose}></div>
        </dialog>
    );
}
