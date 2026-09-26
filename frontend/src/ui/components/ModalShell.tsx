import { t } from "@lingui/core/macro";
import { type KeyboardEvent, type ReactNode, type RefObject, useId } from 'react';
import { useFocusTrap } from '../../logic/useFocusTrap';

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
    /** Rendered after children, inside the form when `onFormSubmit` is set. */
    footer?: ReactNode;
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
    footer,
    children,
}: ModalShellProps) {
    const widthClass = [maxWidth === '2xl' ? 'max-w-2xl' : '', boxClassName]
        .filter(Boolean)
        .join(' ');
    const titleId = useId();
    const internalRef = useFocusTrap<HTMLDialogElement>(true, { containerRef: modalRef });
    const resolvedRef = modalRef ?? internalRef;

    const handleKeyDown = (event: KeyboardEvent<HTMLDialogElement>) => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        onClose();
    };

    const body = onFormSubmit ? (
        <form onSubmit={onFormSubmit}>
            {children}
            {footer}
        </form>
    ) : (
        <>
            {children}
            {footer}
        </>
    );

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
