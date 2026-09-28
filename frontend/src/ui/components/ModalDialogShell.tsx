import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { type ReactNode, type RefObject } from 'react';
import ModalShell from './ModalShell';

interface ModalDialogShellProps {
    title: ReactNode;
    icon?: string;
    onClose: () => void;
    onDelete?: () => void;
    editing: boolean;
    isSubmitting: boolean;
    onSubmit: (e: React.FormEvent) => void;
    /**
     * Disables native browser constraint validation on the form. PhotoJobModal
     * depends on this: it validates through Zod and asserts rendered field
     * errors, so native validation would block submit with a browser tooltip
     * the test never sees.
     */
    noValidate?: boolean;
    modalRef?: RefObject<HTMLDialogElement | null>;
    /** Forwarded to ModalShell; see its docblock for why it is two states, not a scale. */
    maxWidth?: 'default' | '2xl';
    secondaryAction?: ReactNode;
    submitText?: string;
    cancelText?: string;
    submitClassName?: string;
    descriptionId?: string;
    className?: string;
    /**
     * Forwarded to the modal-box, for dialogs that need to size it themselves.
     * It was missing here, which is why the bounded long-dialog layout was
     * unreachable through this shell: GalleryModal gave up on the shared form
     * and submit row and reimplemented both, so the same submit markup existed
     * twice and could drift.
     */
    boxClassName?: string;
    /**
     * Forwarded to ModalShell: bound the box, scroll the children in their own
     * region and keep this shell's submit row below that region. A form dialog
     * with more content than a viewport has to offer is the case this exists
     * for — see the `scrollableBody` docblock in ModalShell for why it is not
     * the default.
     */
    scrollableBody?: boolean;
    /**
     * Forwarded to ModalShell: `data-testid` on the modal-box, so a caller can
     * address the dialog it cannot own without wrapping its content in an
     * element that exists only to carry the id. Left unset, the box renders no
     * `data-testid` at all.
     */
    testId?: string;
    children: ReactNode;
}

/**
 * A form-shaped dialog: header, body, and a submit/cancel footer.
 *
 * The accessibility contract is not here, it lives in ModalShell. This
 * component only adds the form and its footer, which is why the two are split:
 * eleven of the modals fixed for FE-8 have no form at all and could not
 * sensibly use this one.
 */
export default function ModalDialogShell({
    title,
    icon,
    onClose,
    onDelete,
    editing,
    isSubmitting,
    onSubmit,
    noValidate = false,
    modalRef,
    maxWidth = 'default',
    secondaryAction,
    submitText = t`Speichern`,
    cancelText = t`Abbrechen`,
    submitClassName = 'btn-primary',
    descriptionId,
    className = '',
    boxClassName,
    scrollableBody = false,
    testId,
    children,
}: ModalDialogShellProps) {
    // No `shrink-0` on this row, on purpose: in the bounded layout ModalShell
    // wraps the footer in its own `shrink-0` region, so pinning the row is the
    // shell's job. That is what lets a bounded dialog use this footer verbatim
    // instead of hand-rolling one — which is what GalleryModal had to do.
    const footer = (
        <div className="modal-action col-span-full flex justify-between mt-8">
            {editing ? (
                <button type="button" className="btn btn-outline btn-error" onClick={onDelete}><Trans>Löschen</Trans></button>
            ) : <div></div>}
            <div>
                <button type="button" className="btn btn-ghost mr-2" onClick={onClose}>{cancelText}</button>
                <button type="submit" className={`btn ${submitClassName}`} disabled={isSubmitting}>
                    {isSubmitting ? <span className="loading loading-spinner"></span> : submitText}
                </button>
            </div>
        </div>
    );

    return (
        <ModalShell
            title={title}
            icon={icon}
            onClose={onClose}
            modalRef={modalRef}
            maxWidth={maxWidth}
            secondaryAction={secondaryAction}
            descriptionId={descriptionId}
            className={className}
            boxClassName={boxClassName}
            scrollableBody={scrollableBody}
            testId={testId}
            onFormSubmit={onSubmit}
            noValidate={noValidate}
            footer={footer}
        >
            {children}
        </ModalShell>
    );
}
