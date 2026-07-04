interface EmptyStateProps {
    icon?: string;
    title: string;
    message?: string;
    className?: string;
    children?: React.ReactNode;
}

export default function EmptyState({ icon, title, message, className = '', children }: EmptyStateProps) {
    return (
        <div className={`py-20 text-center flex flex-col items-center justify-center opacity-70 bg-base-200 rounded-box border border-base-300 ${className}`}>
            {icon && <span className={`iconify ${icon} text-6xl mb-4 text-primary`}></span>}
            <h3 className="text-2xl font-bold">{title}</h3>
            {message && <p className="mt-2">{message}</p>}
            {children}
        </div>
    );
}
