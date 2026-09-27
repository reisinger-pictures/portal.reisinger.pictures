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
    maxWidth?: 'default' | 'lg' | 'xl' | '2xl';
    /** Rendered in the header next to the title, e.g. a save-state indicator. */
    secondaryAction?: ReactNode;
    descriptionId?: string;
    /** Applied to the <dialog> wrapper. */
    className?: string;
    /** Applied to the modal-box, for dialogs wider than the maxWidth scale. */
    boxClassName?: string;
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
     * would move the scroll boundary under every one of the eighteen dialogs
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
    children: ReactNode;
}

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
    onFormSubmit,
    noValidate = false,
    footer,
    scrollableBody = false,
    children,
}: ModalShellProps) {
    // `max-h-90vh flex flex-col` is what the hand-rolled long dialogs already
    // pass as `boxClassName` (the camera guide in ManagementFtpInbox,
    // RatingStatusModal), so the shared mode lands on the same height the repo
    // already agreed on rather than a second, competing one. The `false` branch
    // contributes an empty string that `.filter(Boolean)` drops, which is what
    // keeps the non-opt-in class list exactly as it was.
    const widthClass = [
        maxWidth === '2xl' ? 'max-w-2xl' : '',
        boxClassName,
        scrollableBody ? 'max-h-90vh flex flex-col' : '',
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
    const boundedBody = (
        <>
            <div className="flex-1 min-h-0 overflow-y-auto pr-2">{children}</div>
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
            <div className={`modal-box relative ${widthClass}`}>
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

                {body}
            </div>
            <div className="modal-backdrop" aria-hidden="true" onClick={onClose}></div>
        </dialog>
    );
}
