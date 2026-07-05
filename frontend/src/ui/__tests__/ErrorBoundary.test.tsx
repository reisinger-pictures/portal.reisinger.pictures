import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import userEvent from '@testing-library/user-event';
import ErrorBoundary from '../components/ErrorBoundary';

describe('ErrorBoundary', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders children when there is no error', () => {
        renderWithProviders(
            <ErrorBoundary>
                <div data-testid="child">Hello</div>
            </ErrorBoundary>,
        );
        expect(screen.getByTestId('child')).toBeInTheDocument();
        expect(screen.getByText('Hello')).toBeInTheDocument();
    });

    it('renders custom fallback when provided and an error occurs', () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});

        const Throw = () => { throw new Error('Test error'); };

        renderWithProviders(
            <ErrorBoundary fallback={<div data-testid="custom-fallback">Custom Error</div>}>
                <Throw />
            </ErrorBoundary>,
        );

        expect(screen.getByTestId('custom-fallback')).toBeInTheDocument();
        expect(screen.getByText('Custom Error')).toBeInTheDocument();
        vi.mocked(console.error).mockRestore();
    });

    it('shows default error UI when error occurs and no fallback is provided', () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});

        const Throw = () => { throw new Error('Ein Fehler ist passiert'); };

        renderWithProviders(
            <ErrorBoundary>
                <Throw />
            </ErrorBoundary>,
        );

        expect(screen.getByText('Ein unerwarteter Fehler ist aufgetreten')).toBeInTheDocument();
        expect(screen.getByText('Ein Fehler ist passiert')).toBeInTheDocument();
        vi.mocked(console.error).mockRestore();
    });

    it('shows retry button when retryCount is less than 3', () => {
        vi.spyOn(console, 'error').mockImplementation(() => {});

        const Throw = () => { throw new Error('retry test'); };

        renderWithProviders(
            <ErrorBoundary>
                <Throw />
            </ErrorBoundary>,
        );

        expect(screen.getByText('Erneut versuchen')).toBeInTheDocument();
        vi.mocked(console.error).mockRestore();
    });

    it('retry button resets the error state and shows children again', async () => {
        const user = userEvent.setup();
        vi.spyOn(console, 'error').mockImplementation(() => {});

        let shouldThrow = true;
        const ConditionalThrow = () => {
            if (shouldThrow) throw new Error('Conditional error');
            return <div data-testid="recovered">Recovered</div>;
        };

        renderWithProviders(
            <ErrorBoundary>
                <ConditionalThrow />
            </ErrorBoundary>,
        );

        expect(screen.getByText('Ein unerwarteter Fehler ist aufgetreten')).toBeInTheDocument();

        shouldThrow = false;
        await user.click(screen.getByText('Erneut versuchen'));

        expect(screen.getByTestId('recovered')).toBeInTheDocument();
        expect(screen.getByText('Recovered')).toBeInTheDocument();
        vi.mocked(console.error).mockRestore();
    });
});
