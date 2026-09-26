import { describe, expect, it, vi } from 'vitest';
import { screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import { renderWithProviders } from '../../../test-setup';
import GlobalSearchHeader from '../GlobalSearchHeader';

vi.mock('../../../logic/useBrand', () => ({
    useBrand: () => ({
        logoSrc: '/logo.svg',
        portalName: 'Reisinger Foto Portal',
    }),
}));

vi.mock('../SearchBarWithSuggestions', () => ({
    default: () => <input aria-label="Suche" />,
}));

describe('GlobalSearchHeader', () => {
    it('exposes a keyboard-operable mobile menu control', async () => {
        const onMenuClick = vi.fn();
        const user = userEvent.setup();
        const { rerender } = renderWithProviders(
            <MemoryRouter>
                <GlobalSearchHeader
                    onMenuClick={onMenuClick}
                    isSidebarOpen={false}
                    sidebarId="dashboard-sidebar"
                />
            </MemoryRouter>,
        );

        const menuButton = screen.getByRole('button', { name: 'Menü öffnen' });
        expect(menuButton).toHaveAttribute('aria-expanded', 'false');
        expect(menuButton).toHaveAttribute('aria-controls', 'dashboard-sidebar');
        menuButton.focus();
        await user.keyboard('{Enter}');

        expect(onMenuClick).toHaveBeenCalledTimes(1);

        rerender(
            <MemoryRouter>
                <GlobalSearchHeader
                    onMenuClick={onMenuClick}
                    isSidebarOpen
                    sidebarId="dashboard-sidebar"
                />
            </MemoryRouter>,
        );
        expect(menuButton).toHaveAttribute('aria-expanded', 'true');
    });
});
