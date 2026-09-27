import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import { MemoryRouter } from 'react-router-dom';
import { renderWithProviders } from '../../../test-setup';
import MobileBrandLink from '../MobileBrandLink';

vi.mock('../../../logic/useBrand', () => ({
    useBrand: () => ({
        logoSrc: '/logo.svg',
        portalName: 'Reisinger Foto Portal',
    }),
}));

/**
 * The mobile brand lockup is one component because three dashboards used to
 * duplicate it, and all three cut the portal name off with `truncate`. This is
 * the regression pin for that defect: the full name must be in the DOM, the
 * lockup must link home, and the focus-yield behaviour must survive.
 */
describe('MobileBrandLink', () => {
    function renderLockup(hidden = false) {
        return renderWithProviders(
            <MemoryRouter>
                <MobileBrandLink hidden={hidden} />
            </MemoryRouter>,
        );
    }

    it('renders the full portal name without truncation', () => {
        renderLockup();

        const name = screen.getByText('Reisinger Foto Portal');
        expect(name).toBeInTheDocument();

        // jsdom applies no CSS, so this can only be a class-list check: the
        // class names are the only thing observable here, never the rendered
        // layout. `truncate` (overflow-hidden + text-ellipsis + nowrap) clipped
        // the name to "Reisinger Fot…" on a phone; the other mechanisms below
        // clip identically and must not sneak back in either. The real layout
        // guard — scrollWidth vs clientWidth in a browser — is the Playwright
        // spec `tests/e2e/photographer/mobile-brand-lockup.spec.ts`.
        const truncationClasses = ['truncate', 'text-ellipsis', 'overflow-hidden', 'whitespace-nowrap'];
        const applied = Array.from(name.classList).filter(
            (className) => truncationClasses.includes(className) || className.startsWith('line-clamp-'),
        );
        expect(applied).toEqual([]);
    });

    it('links to the portal home', () => {
        renderLockup();

        expect(screen.getByRole('link')).toHaveAttribute('href', '/');
    });

    it('applies the hidden state when hidden is true', () => {
        renderLockup(true);

        expect(screen.getByRole('link')).toHaveClass('hidden');
    });

    it('is visible by default', () => {
        renderLockup();

        expect(screen.getByRole('link')).not.toHaveClass('hidden');
    });
});
