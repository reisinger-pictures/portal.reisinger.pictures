import { Component, ErrorInfo, ReactNode } from 'react';

interface Props {
    children?: ReactNode;
    fallback?: ReactNode;
}

interface State {
    hasError: boolean;
    error: Error | null;
}

export default class ErrorBoundary extends Component<Props, State> {
    public state: State = {
        hasError: false,
        error: null
    };

    public static getDerivedStateFromError(error: Error): State {
        return {hasError: true, error};
    }

    public componentDidCatch(error: Error, errorInfo: ErrorInfo) {
        console.error('Uncaught Error in ErrorBoundary:', error, errorInfo);
    }

    public render() {
        if (this.state.hasError) {
            if (this.props.fallback) return this.props.fallback;

            return (
                <div className="p-4 m-4 border border-error bg-error/10 rounded-box text-error overflow-auto">
                    <h2 className="font-bold text-lg mb-2 flex items-center gap-2">
                        <span className="iconify mdi--alert"></span> Ein unerwarteter Fehler ist aufgetreten
                    </h2>
                    <p className="text-sm font-mono break-all mb-4 opacity-80">
                        {this.state.error?.message || 'Unbekannter Fehler'}
                    </p>
                    <button
                        className="btn btn-sm btn-outline btn-error"
                        onClick={() => this.setState({hasError: false, error: null})}
                    >
                        Erneut versuchen
                    </button>
                </div>
            );
        }

        return this.props.children;
    }
}
