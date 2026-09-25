import { useEffect, useRef, useState, type ReactNode } from 'react';
import { t } from '@lingui/core/macro';
import { UIContext } from './UIContext';
import type { ConfirmOptions, Toast } from './UIContext';
import ModalDialogShell from './ModalDialogShell';

export interface UIProviderProps {
    children: ReactNode;
}

export interface ConfirmState {
    id: string;
    options: ConfirmOptions;
    resolve: (value: boolean) => void;
}

type ConfirmColor = NonNullable<ConfirmOptions['confirmColor']>;

const CONFIRM_BUTTON_CLASS: Record<ConfirmColor, string> = {
    primary: 'btn-primary',
    error: 'btn-error',
    warning: 'btn-warning',
    info: 'btn-info',
    success: 'btn-success',
};

export default function UIProvider({ children }: UIProviderProps) {
    const [toasts, setToasts] = useState<Toast[]>([]);
    const [confirmState, setConfirmState] = useState<ConfirmState | null>(null);
    const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false);
    const hasUnsavedRef = useRef(false);
    const activeConfirmRef = useRef<ConfirmState | null>(null);
    const queuedConfirmsRef = useRef<ConfirmState[]>([]);
    const isMountedRef = useRef(false);
    const allowNavigationRef = useRef(false);

    const requestConfirm = (options: ConfirmOptions) => {
        return new Promise<boolean>((resolve) => {
            const request: ConfirmState = {
                id: crypto.randomUUID(),
                options,
                resolve,
            };

            if (!isMountedRef.current) {
                resolve(false);
                return;
            }

            if (activeConfirmRef.current) {
                queuedConfirmsRef.current.push(request);
                return;
            }

            activeConfirmRef.current = request;
            setConfirmState(request);
        });
    };

    const confirmRef = useRef(requestConfirm);

    useEffect(() => {
        isMountedRef.current = true;

        return () => {
            isMountedRef.current = false;
            const pendingRequests = activeConfirmRef.current
                ? [activeConfirmRef.current, ...queuedConfirmsRef.current]
                : [...queuedConfirmsRef.current];
            activeConfirmRef.current = null;
            queuedConfirmsRef.current = [];
            pendingRequests.forEach((request) => request.resolve(false));
        };
    }, []);

    useEffect(() => {
        hasUnsavedRef.current = hasUnsavedChanges;
    }, [hasUnsavedChanges]);

    const showToast = (type: 'success' | 'error' | 'info', text: string) => {
        const id = crypto.randomUUID();
        setToasts(prev => [...prev, { id, type, text }]);
        setTimeout(() => {
            setToasts(prev => prev.filter(toast => toast.id !== id));
        }, 4000);
    };

    const setUnsavedChanges = (value: boolean) => {
        hasUnsavedRef.current = value;
        setHasUnsavedChanges(value);
    };

    const handleConfirm = (result: boolean) => {
        const currentRequest = activeConfirmRef.current;
        if (!currentRequest) return;

        const nextRequest = queuedConfirmsRef.current.shift() ?? null;
        activeConfirmRef.current = nextRequest;
        setConfirmState(nextRequest);
        currentRequest.resolve(result);
    };

    useEffect(() => {
        const handler = (event: MouseEvent) => {
            if (!hasUnsavedRef.current) return;
            if (event.defaultPrevented || event.button !== 0 || event.metaKey || event.ctrlKey || event.shiftKey || event.altKey) return;

            if (allowNavigationRef.current) {
                allowNavigationRef.current = false;
                return;
            }

            const eventTarget = event.target;
            if (!(eventTarget instanceof Element)) return;
            const anchor = eventTarget.closest('a');
            if (!(anchor instanceof HTMLAnchorElement)) return;
            if (!anchor.href || anchor.origin !== window.location.origin) return;
            if (anchor.target && anchor.target !== '_self') return;
            if (anchor.hasAttribute('download')) return;

            const destination = new URL(anchor.href);
            const current = new URL(window.location.href);
            if (
                destination.pathname === current.pathname
                && destination.search === current.search
                && destination.hash === current.hash
            ) return;

            event.preventDefault();
            event.stopPropagation();

            const request = confirmRef.current;
            if (!request) return;

            void request({
                title: t`Ungespeicherte Änderungen`,
                message: t`Ungespeicherte Änderungen gehen verloren. Trotzdem fortfahren?`,
                confirmText: t`Trotzdem fortfahren`,
                confirmColor: 'warning',
            }).then((confirmed) => {
                if (!confirmed || !anchor.isConnected) return;
                hasUnsavedRef.current = false;
                setHasUnsavedChanges(false);
                allowNavigationRef.current = true;
                anchor.click();
                allowNavigationRef.current = false;
            });
        };

        document.addEventListener('click', handler, { capture: true });
        return () => document.removeEventListener('click', handler, { capture: true });
    }, []);

    return (
        <UIContext.Provider value={{ showToast, confirm: requestConfirm, hasUnsavedChanges, setUnsavedChanges }}>
            {children}

            {/* Global Toasts */}
            <div role="alert" aria-live="polite" aria-atomic="true" className="toast toast-top toast-center toast-global mt-12 md:mt-4 transition-all pointer-events-none z-50">
                {toasts.map(toast => (
                    <div key={toast.id} className={`alert ${toast.type === "success" ? "alert-success bg-success text-white" : toast.type === "error" ? "alert-error bg-error text-white" : "alert-info bg-info text-info-content"} shadow-xl pointer-events-auto border-none`}>
                        <span className={`iconify ${toast.type === 'error' ? 'mdi--alert-circle' : toast.type === 'success' ? 'mdi--check-circle' : 'mdi--information'} text-xl`}></span>
                        <span>{toast.text}</span>
                        <button type="button" className="btn btn-ghost btn-sm btn-circle" onClick={() => setToasts(prev => prev.filter(toast => toast.id !== toast.id))}>✕</button>
                    </div>
                ))}
            </div>

            {/* Global Confirm Modal */}
            {confirmState && (
                <ModalDialogShell
                    key={confirmState.id}
                    title={confirmState.options.title}
                    onClose={() => handleConfirm(false)}
                    editing={false}
                    isSubmitting={false}
                    onSubmit={(event) => {
                        event.preventDefault();
                        handleConfirm(true);
                    }}
                    submitText={confirmState.options.confirmText || t`Bestätigen`}
                    cancelText={confirmState.options.cancelText || t`Abbrechen`}
                    submitClassName={CONFIRM_BUTTON_CLASS[confirmState.options.confirmColor || 'primary']}
                    descriptionId={`confirm-message-${confirmState.id}`}
                    className="modal-global"
                >
                    <p id={`confirm-message-${confirmState.id}`} className="mb-8 opacity-80">
                        {confirmState.options.message}
                    </p>
                </ModalDialogShell>
            )}
        </UIContext.Provider>
    );
}
