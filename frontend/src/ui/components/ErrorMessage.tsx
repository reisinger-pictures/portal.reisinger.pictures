import React from 'react';

interface ErrorMessageProps {
    title?: string;
    message: string;
    onRetry?: () => void;
    className?: string;
}

export default function ErrorMessage({ title = 'Ein Fehler ist aufgetreten', message, onRetry, className = '' }: ErrorMessageProps) {
    return (
        <div className={`alert alert-error shadow-sm flex flex-col sm:flex-row items-start sm:items-center gap-4 ${className}`}>
            <span className="iconify mdi--alert-circle text-4xl shrink-0 opacity-80"></span>
            <div className="flex-1">
                <h3 className="font-bold text-lg leading-tight mb-1">{title}</h3>
                <p className="text-sm opacity-90">{message}</p>
            </div>
            {onRetry && (
                <button onClick={onRetry} className="btn btn-sm btn-ghost border-current opacity-80 hover:opacity-100 shrink-0">
                    <span className="iconify mdi--refresh mr-1 text-lg"></span> Erneut versuchen
                </button>
            )}
        </div>
    );
}
