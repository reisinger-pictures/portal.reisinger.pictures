import { useState, ReactNode, useCallback, useEffect, useRef } from 'react';
import { UIContext, Toast, ConfirmOptions } from './UIContext';

let toastIdCounter = 0;

export interface UIProviderProps {
    children: ReactNode;
}

export interface ConfirmState {
    options: ConfirmOptions;
    resolve: (value: boolean) => void;
}

const UNSAVED_CHANGES_MESSAGE = 'Ungespeicherte Änderungen gehen verloren. Trotzdem fortfahren?';

export default function UIProvider({ children }: UIProviderProps) {
    const [toasts, setToasts] = useState<Toast[]>([]);
    const [confirmState, setConfirmState] = useState<ConfirmState | null>(null);
    const [hasUnsavedChanges, setHasUnsavedChanges] = useState(false);
    const hasUnsavedRef = useRef(false);

    useEffect(() => {
        hasUnsavedRef.current = hasUnsavedChanges;
    }, [hasUnsavedChanges]);

    const showToast = useCallback((type: 'success' | 'error' | 'info', text: string) => {
        const id = ++toastIdCounter;
        setToasts(prev => [...prev, { id, type, text }]);
        setTimeout(() => {
            setToasts(prev => prev.filter(t => t.id !== id));
        }, 4000);
    }, []);

    const setUnsavedChanges = useCallback((value: boolean) => {
        setHasUnsavedChanges(value);
    }, []);

    const confirm = useCallback((options: ConfirmOptions) => {
        return new Promise<boolean>((resolve) => {
            setConfirmState({ options, resolve });
        });
    }, []);

    const handleConfirm = (result: boolean) => {
        if (confirmState) {
            confirmState.resolve(result);
            setConfirmState(null);
        }
    };

    useEffect(() => {
        const handler = (e: MouseEvent) => {
            if (!hasUnsavedRef.current) return;
            const target = (e.target as HTMLElement).closest('a');
            if (!target) return;
            if (!target.href || target.hostname !== window.location.hostname) return;
            if (target.getAttribute('target') === '_blank') return;
            if (!window.confirm(UNSAVED_CHANGES_MESSAGE)) {
                e.preventDefault();
                e.stopPropagation();
            }
        };
        document.addEventListener('click', handler, { capture: true });
        return () => document.removeEventListener('click', handler, { capture: true });
    }, []);

    return (
        <UIContext.Provider value={{ showToast, confirm, hasUnsavedChanges, setUnsavedChanges }}>
            {children}

            {/* Global Toasts */}
            <div className="toast toast-top toast-center toast-global mt-12 md:mt-4 transition-all pointer-events-none z-[100]">
                {toasts.map(toast => (
                    <div key={toast.id} className={`alert ${toast.type === "success" ? "alert-success bg-success text-white" : toast.type === "error" ? "alert-error bg-error text-white" : "alert-info bg-info text-info-content"} shadow-xl pointer-events-auto border-none`}>
                        <span className={`iconify ${toast.type === 'error' ? 'mdi--alert-circle' : toast.type === 'success' ? 'mdi--check-circle' : 'mdi--information'} text-xl`}></span>
                        <span>{toast.text}</span>
                        <button className="btn btn-ghost btn-sm btn-circle" onClick={() => setToasts(prev => prev.filter(t => t.id !== toast.id))}>✕</button>
                    </div>
                ))}
            </div>

            {/* Global Confirm Modal */}
            {confirmState && (
                <div className="modal modal-open modal-global">
                    <div className="modal-box relative">
                        <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={() => handleConfirm(false)}>✕</button>
                        <h3 className="font-bold text-xl mb-4">{confirmState.options.title}</h3>
                        <p className="mb-8 opacity-80">{confirmState.options.message}</p>
                        <div className="modal-action col-span-full flex justify-end gap-2 mt-0">
                            <button className="btn btn-ghost" onClick={() => handleConfirm(false)}>
                                {confirmState.options.cancelText || 'Abbrechen'}
                            </button>
                            <button className={`btn ${{primary: 'btn-primary', error: 'btn-error', warning: 'btn-warning', info: 'btn-info', success: 'btn-success'}[confirmState.options.confirmColor || 'primary']}`} onClick={() => handleConfirm(true)}>
                                {confirmState.options.confirmText || 'Bestätigen'}
                            </button>
                        </div>
                    </div>
                    <div className="modal-backdrop" onClick={() => handleConfirm(false)}></div>
                </div>
            )}
        </UIContext.Provider>
    );
};