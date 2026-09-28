import { describe, it, expect } from 'vitest';
import {
    useEffect,
    useInsertionEffect,
    useLayoutEffect,
    type ReactNode,
} from 'react';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../test-setup';
import { useFocusTrap } from '../useFocusTrap';

/**
 * D-12 — the focus trap's ordering contract.
 *
 * The owner's decision is that a dialog's own `autoFocus` wins over the trap's
 * "focus the first focusable element" default, implemented as an ordering
 * decision inside this hook rather than a new prop or per-dialog focus code.
 *
 * Two properties make that work, and both are measured here rather than
 * assumed:
 *
 *   1. React focuses an `autoFocus` element during the commit, before any
 *      passive effect runs. The trap's effect therefore observes focus already
 *      inside the dialog and must not move it.
 *   2. The return target has to be captured before that focus lands, or the
 *      captured "previous focus" is the dialog's own field and restoration on
 *      close has nothing to return to. The insertion phase is the only hook
 *      phase that runs before React's imperative focus.
 */
function TrapHarness({ children }: { children: ReactNode }) {
    const ref = useFocusTrap<HTMLDivElement>(true);
    return <div ref={ref}>{children}</div>;
}

describe('useFocusTrap', () => {
    /**
     * The measurement, not the conclusion. If a future React moved `autoFocus`
     * after the layout phase the property would break, and this test says so
     * before the behaviour test below turns into a mystery.
     */
    it('sees the trigger in the insertion phase and the autoFocus field only afterwards', () => {
        const seen = { insertion: '', layout: '', passive: '' };

        function DialogContent() {
            useInsertionEffect(() => {
                seen.insertion = document.activeElement?.id ?? 'none';
            });
            useLayoutEffect(() => {
                seen.layout = document.activeElement?.id ?? 'none';
            });
            useEffect(() => {
                seen.passive = document.activeElement?.id ?? 'none';
            });
            return <input id="field" autoFocus />;
        }

        const { rerender } = renderWithProviders(
            <button id="trigger" type="button">Öffnen</button>,
        );
        document.getElementById('trigger')!.focus();
        expect(document.activeElement?.id).toBe('trigger');

        // A dialog content mounts in a later commit than its trigger.
        rerender(
            <>
                <button id="trigger" type="button">Öffnen</button>
                <DialogContent />
            </>,
        );

        // React's `commitMount` focuses the field in the layout phase, i.e.
        // after the insertion phase and before the passive one.
        expect(seen.insertion).toBe('trigger');
        expect(seen.layout).toBe('field');
        expect(seen.passive).toBe('field');
    });

    it('keeps focus on the autoFocus element instead of the first focusable element', () => {
        renderWithProviders(
            <TrapHarness>
                <button type="button">Erste Aktion</button>
                <input data-testid="field" autoFocus />
            </TrapHarness>,
        );

        // The first focusable element is the button; React's autoFocus is on the
        // input. Before D-12 the trap moved focus to the button.
        expect(screen.getByTestId('field')).toHaveFocus();
        expect(screen.getByRole('button', { name: 'Erste Aktion' })).not.toHaveFocus();
    });

    it('focuses the first enabled element when the first one is disabled', () => {
        renderWithProviders(
            <TrapHarness>
                <button type="button" disabled>Deaktiviert</button>
                <button type="button">Aktiv</button>
            </TrapHarness>,
        );

        expect(screen.getByRole('button', { name: 'Aktiv' })).toHaveFocus();
    });

    it('captures the return target before autoFocus lands and restores it on close', () => {
        const { rerender } = renderWithProviders(
            <button data-testid="trigger" type="button">Öffnen</button>,
        );
        const trigger = screen.getByTestId('trigger');
        trigger.focus();

        rerender(
            <>
                <button data-testid="trigger" type="button">Öffnen</button>
                <TrapHarness>
                    <input data-testid="field" autoFocus />
                </TrapHarness>
            </>,
        );
        // The field wins while the dialog is open…
        expect(screen.getByTestId('field')).toHaveFocus();

        // …and closing returns focus to the trigger, not to the field the
        // dialog itself focused. Reading `activeElement` in the passive effect
        // would have captured the field, which is gone by now.
        rerender(<button data-testid="trigger" type="button">Öffnen</button>);
        expect(trigger).toHaveFocus();
    });

    it('falls back to the first focusable element when focus is outside the trap', () => {
        renderWithProviders(
            <TrapHarness>
                <button type="button" data-testid="first">Erste Aktion</button>
                <button type="button">Letzte Aktion</button>
            </TrapHarness>,
        );

        expect(screen.getByTestId('first')).toHaveFocus();
    });

    it('contains focus inside the trap on Tab', async () => {
        const user = userEvent.setup();
        renderWithProviders(
            <TrapHarness>
                <button type="button">Erste Aktion</button>
                <button type="button">Letzte Aktion</button>
            </TrapHarness>,
        );

        // No autoFocus: the trap takes the first element, as it always has.
        expect(screen.getByRole('button', { name: 'Erste Aktion' })).toHaveFocus();

        await user.tab();
        expect(screen.getByRole('button', { name: 'Letzte Aktion' })).toHaveFocus();

        await user.tab();
        expect(screen.getByRole('button', { name: 'Erste Aktion' })).toHaveFocus();
    });
});
