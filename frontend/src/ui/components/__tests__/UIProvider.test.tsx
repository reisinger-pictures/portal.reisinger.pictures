import { useEffect } from 'react';
import { act, fireEvent, screen, waitFor } from '@testing-library/react';
import { describe, expect, it } from 'vitest';
import { renderWithProviders } from '../../../test-setup';
import { useUI, type ConfirmOptions } from '../UIContext';
import UIProvider from '../UIProvider';

type Confirm = (options: ConfirmOptions) => Promise<boolean>;

function ConfirmCapture({ onReady }: { onReady: (confirm: Confirm) => void }) {
    const { confirm } = useUI();

    useEffect(() => {
        onReady(confirm);
    }, [confirm, onReady]);

    return null;
}

function renderProvider(onReady: (confirm: Confirm) => void) {
    return renderWithProviders(
        <UIProvider>
            <ConfirmCapture onReady={onReady} />
        </UIProvider>,
    );
}

function requireConfirm(confirm: Confirm | undefined): Confirm {
    if (!confirm) throw new Error('UIProvider confirm function was not captured');
    return confirm;
}

function requirePromise(promise: Promise<boolean> | undefined): Promise<boolean> {
    if (!promise) throw new Error('Expected a confirm promise');
    return promise;
}

describe('UIProvider confirmations', () => {
    it('queues concurrent confirmations while preserving the dialog controls', async () => {
        let capturedConfirm: Confirm | undefined;
        renderProvider((confirm) => {
            capturedConfirm = confirm;
        });
        await waitFor(() => expect(capturedConfirm).toBeDefined());

        const confirm = requireConfirm(capturedConfirm);
        let firstPromise: Promise<boolean> | undefined;
        let secondPromise: Promise<boolean> | undefined;

        act(() => {
            firstPromise = confirm({
                title: 'Erste Aktion',
                message: 'Erste Nachricht',
                confirmText: 'Fortfahren',
            });
            secondPromise = confirm({
                title: 'Zweite Aktion',
                message: 'Zweite Nachricht',
                cancelText: 'Nicht jetzt',
            });
        });

        await waitFor(() => expect(screen.getByRole('dialog', { name: 'Erste Aktion' })).toBeInTheDocument());
        expect(screen.queryByRole('dialog', { name: 'Zweite Aktion' })).not.toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Fortfahren' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Abbrechen' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Schließen' })).toHaveFocus();

        fireEvent.click(screen.getByRole('button', { name: 'Fortfahren' }));
        await expect(requirePromise(firstPromise)).resolves.toBe(true);
        await waitFor(() => expect(screen.getByRole('dialog', { name: 'Zweite Aktion' })).toBeInTheDocument());
        expect(screen.getByRole('button', { name: 'Bestätigen' })).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Nicht jetzt' })).toBeInTheDocument();

        fireEvent.click(screen.getByRole('button', { name: 'Nicht jetzt' }));
        await expect(requirePromise(secondPromise)).resolves.toBe(false);
        await waitFor(() => expect(screen.queryByRole('dialog')).not.toBeInTheDocument());
    });

    it('resolves active, queued, and post-unmount confirmations', async () => {
        let capturedConfirm: Confirm | undefined;
        const view = renderProvider((confirm) => {
            capturedConfirm = confirm;
        });
        await waitFor(() => expect(capturedConfirm).toBeDefined());

        const confirm = requireConfirm(capturedConfirm);
        let activePromise: Promise<boolean> | undefined;
        let queuedPromise: Promise<boolean> | undefined;
        act(() => {
            activePromise = confirm({ title: 'Aktive Aktion', message: 'Aktiv' });
            queuedPromise = confirm({ title: 'Wartende Aktion', message: 'Wartend' });
        });
        await waitFor(() => expect(screen.getByRole('dialog', { name: 'Aktive Aktion' })).toBeInTheDocument());

        view.unmount();

        await expect(Promise.all([
            requirePromise(activePromise),
            requirePromise(queuedPromise),
        ])).resolves.toEqual([false, false]);
        await expect(confirm({ title: 'Nach dem Unmount', message: 'Nicht mehr verfügbar' })).resolves.toBe(false);
    });
});
