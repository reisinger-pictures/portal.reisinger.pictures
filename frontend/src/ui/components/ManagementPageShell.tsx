import { type ReactNode } from 'react';
import ErrorMessage from './ErrorMessage';

interface ManagementPageShellProps {
    isLoading?: boolean;
    isError?: boolean;
    errorMessage?: string;
    title?: string;
    icon?: string;
    subtitle?: string;
    action?: ReactNode;
    children: ReactNode;
    className?: string;
}

export default function ManagementPageShell({
    isLoading,
    isError,
    errorMessage,
    title,
    icon,
    subtitle,
    action,
    children,
    className = '',
}: ManagementPageShellProps) {
    if (isLoading) {
        return (
            <div className="p-10 flex justify-center">
                <span className="loading loading-spinner loading-lg"></span>
            </div>
        );
    }

    if (isError) {
        return (
            <div className="p-10">
                <ErrorMessage message={errorMessage ?? 'Ein Fehler ist aufgetreten.'} />
            </div>
        );
    }

    return (
        <div className={`p-6 md:p-10 max-w-7xl mx-auto w-full ${className}`}>
            {title && (
                <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                    <div>
                        <h1 className="text-3xl md:text-4xl font-bold flex items-center gap-3">
                            {icon && <span className={`iconify ${icon} text-primary`}></span>}
                            {title}
                        </h1>
                        {subtitle && <p className="opacity-70 mt-1">{subtitle}</p>}
                    </div>
                    {action && <div>{action}</div>}
                </div>
            )}
            {children}
        </div>
    );
}
