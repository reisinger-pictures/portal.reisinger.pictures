import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { type KeyboardEvent, type ReactNode, type RefObject, useId } from 'react';
import { useFocusTrap } from '../../logic/useFocusTrap';

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
    const widthClass = maxWidth === '2xl' ? 'max-w-2xl' : '';
    const titleId = useId();
    const internalRef = useFocusTrap<HTMLDialogElement>(true, { containerRef: modalRef });
    const resolvedRef = modalRef ?? internalRef;

    const handleKeyDown = (event: KeyboardEvent<HTMLDialogElement>) => {
        if (event.key !== 'Escape') return;
        event.preventDefault();
        onClose();
    };

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

                <form onSubmit={onSubmit}>
                    {children}

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
                </form>
            </div>
            <div className="modal-backdrop" aria-hidden="true" onClick={onClose}></div>
        </dialog>
    );
}
