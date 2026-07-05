import { Trans } from "@lingui/react/macro";
import { type ReactNode, type RefObject, useRef } from 'react';

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
    children,
}: ModalDialogShellProps) {
    const widthClass = maxWidth === '2xl' ? 'max-w-2xl' : '';
    const fallbackRef = useRef<HTMLDialogElement | null>(null);
    const resolvedRef: RefObject<HTMLDialogElement | null> = modalRef ?? fallbackRef;

    return (
        <dialog ref={resolvedRef} className="modal modal-open">
            <div className={`modal-box relative ${widthClass}`}>
                <button type="button" className="btn btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>

                <div className="flex justify-between items-center mb-6 mr-8">
                    <h3 className="font-bold text-xl flex items-center gap-2">
                        {icon && <span className={`iconify ${icon} text-primary`}></span>}
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
                            <button type="button" className="btn btn-ghost mr-2" onClick={onClose}><Trans>Abbrechen</Trans></button>
                            <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                                {isSubmitting ? <span className="loading loading-spinner"></span> : <Trans>Speichern</Trans>}
                            </button>
                        </div>
                    </div>
                </form>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </dialog>
    );
}
