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
    modalRef?: RefObject<HTMLDialogElement | null>;
    maxWidth?: 'default' | 'lg' | 'xl' | '2xl';
    secondaryAction?: ReactNode;
    submitText?: string;
    cancelText?: string;
    submitClassName?: string;
    descriptionId?: string;
    className?: string;
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
    modalRef,
    maxWidth = 'default',
    secondaryAction,
    submitText = t`Speichern`,
    cancelText = t`Abbrechen`,
    submitClassName = 'btn-primary',
    descriptionId,
    className = '',
    children,
}: ModalDialogShellProps) {
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
            onFormSubmit={onSubmit}
            footer={footer}
        >
            {children}
        </ModalShell>
    );
}
