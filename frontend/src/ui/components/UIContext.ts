import { createContext, useContext } from 'react';

export interface Toast {
    id: string;
    type: 'success' | 'error' | 'info';
    text: string;
}

export interface ConfirmOptions {
    title: string;
    message: string;
    confirmText?: string;
    cancelText?: string;
    confirmColor?: 'primary' | 'error' | 'warning' | 'info' | 'success';
}

export interface UIContextType {
    showToast: (type: 'success' | 'error' | 'info', text: string) => void;
    confirm: (options: ConfirmOptions) => Promise<boolean>;
    hasUnsavedChanges: boolean;
    setUnsavedChanges: (value: boolean) => void;
}

export const UIContext = createContext<UIContextType | undefined>(undefined);

export const useUI = () => {
    const context = useContext(UIContext);
    if (!context) throw new Error('useUI must be used within UIProvider');
    return context;
};