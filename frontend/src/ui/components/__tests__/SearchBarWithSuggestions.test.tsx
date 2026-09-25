import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, Route, Routes, useLocation, useNavigate, useSearchParams } from 'react-router-dom';
import { renderWithProviders } from '../../../test-setup';
import SearchBarWithSuggestions from '../SearchBarWithSuggestions';

vi.mock('../../../logic/useSearch', () => ({
    useSearch: vi.fn(() => ({ results: undefined, isLoading: false, isError: undefined })),
}));

import { useSearch } from '../../../logic/useSearch';

function SearchHarness() {
    const [, setSearchParams] = useSearchParams();

    return (
        <>
            <button type="button" onClick={() => setSearchParams({ q: 'updated-query' })}>
                Query ändern
            </button>
            <button type="button" onClick={() => setSearchParams({ q: 'initial-query' })}>
                Query wiederholen
            </button>
            <button type="button" onClick={() => setSearchParams({})}>
                Query entfernen
            </button>
            <SearchBarWithSuggestions />
        </>
    );
}

function SearchRouteHarness() {
    const location = useLocation();
    const navigate = useNavigate();

    return (
        <>
            <output aria-label="Aktuelle Such-URL">{`${location.pathname}${location.search}`}</output>
            <button type="button" onClick={() => navigate('/search?q=first-query')}>
                Erste Suche
            </button>
            <button type="button" onClick={() => navigate(-1)}>
                Eine Suche zurück
            </button>
            <button type="button" onClick={() => navigate(1)}>
                Eine Suche vorwärts
            </button>
            <Routes>
                <Route path="/" element={<SearchBarWithSuggestions />} />
                <Route path="/search" element={<SearchBarWithSuggestions />} />
            </Routes>
        </>
    );
}

describe('SearchBarWithSuggestions', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('exposes an accessible name for the submit button', () => {
        renderWithProviders(
            <MemoryRouter>
                <SearchBarWithSuggestions />
            </MemoryRouter>,
        );

        expect(screen.getByRole('button', { name: 'Suche' })).toBeVisible();
    });

    it('uses the URL query as the initial and subsequent input value', async () => {
        const user = userEvent.setup();

        renderWithProviders(
            <MemoryRouter initialEntries={['/search?q=initial-query']}>
                <SearchHarness />
            </MemoryRouter>,
        );

        const input = screen.getByPlaceholderText('Suche in allen Galerien...');
        expect(input).toHaveValue('initial-query');

        await user.click(screen.getByRole('button', { name: 'Query ändern' }));

        await waitFor(() => {
            expect(input).toHaveValue('updated-query');
        });
        expect(vi.mocked(useSearch).mock.calls.at(-1)?.[0]).toBe('updated-query');
    });

    it('does not restore a stale draft when a previous query is revisited', async () => {
        const user = userEvent.setup();

        renderWithProviders(
            <MemoryRouter initialEntries={['/search?q=initial-query']}>
                <SearchHarness />
            </MemoryRouter>,
        );

        const input = screen.getByPlaceholderText('Suche in allen Galerien...');
        await user.click(screen.getByRole('button', { name: 'Query ändern' }));
        await waitFor(() => expect(input).toHaveValue('updated-query'));

        await user.click(screen.getByRole('button', { name: 'Query wiederholen' }));
        await waitFor(() => expect(input).toHaveValue('initial-query'));
    });

    it('keeps the mounted input synchronized with same-route back/forward navigation', async () => {
        const user = userEvent.setup();

        renderWithProviders(
            <MemoryRouter initialEntries={['/']}>
                <SearchRouteHarness />
            </MemoryRouter>,
        );

        const getInput = () => screen.getByRole('textbox', { name: 'Suche' });
        const getUrl = () => screen.getByRole('status', { name: 'Aktuelle Such-URL' });

        expect(getInput()).toHaveAccessibleName('Suche');
        expect(getInput()).toHaveValue('');
        expect(getUrl()).toHaveTextContent('/');

        await user.click(screen.getByRole('button', { name: 'Erste Suche' }));
        await waitFor(() => {
            expect(getUrl()).toHaveTextContent('/search?q=first-query');
            expect(getInput()).toHaveValue('first-query');
        });

        const input = getInput();
        const mountMarker = 'mounted-search-input';
        input.setAttribute('data-mount-marker', mountMarker);

        await user.clear(input);
        await user.type(input, 'second-query');
        await user.keyboard('{Enter}');
        await waitFor(() => {
            expect(getUrl()).toHaveTextContent('/search?q=second-query');
            expect(getInput()).toBe(input);
            expect(getInput()).toHaveAttribute('data-mount-marker', mountMarker);
            expect(getInput()).toHaveValue('second-query');
        });

        await user.click(screen.getByRole('button', { name: 'Eine Suche zurück' }));
        await waitFor(() => {
            expect(getUrl()).toHaveTextContent('/search?q=first-query');
            expect(getInput()).toBe(input);
            expect(getInput()).toHaveAttribute('data-mount-marker', mountMarker);
            expect(getInput()).toHaveValue('first-query');
        });

        await user.click(screen.getByRole('button', { name: 'Eine Suche vorwärts' }));
        await waitFor(() => {
            expect(getUrl()).toHaveTextContent('/search?q=second-query');
            expect(getInput()).toBe(input);
            expect(getInput()).toHaveAttribute('data-mount-marker', mountMarker);
            expect(getInput()).toHaveValue('second-query');
        });
    });

    it('clears the input when the q parameter is removed', async () => {
        const user = userEvent.setup();

        renderWithProviders(
            <MemoryRouter initialEntries={['/search?q=initial-query']}>
                <SearchHarness />
            </MemoryRouter>,
        );

        const input = screen.getByPlaceholderText('Suche in allen Galerien...');
        await user.click(screen.getByRole('button', { name: 'Query entfernen' }));

        await waitFor(() => {
            expect(input).toHaveValue('');
        });
        expect(vi.mocked(useSearch).mock.calls.at(-1)?.[0]).toBe('');
    });

    it('preserves clearOnSubmit after navigating to the results URL', async () => {
        const user = userEvent.setup();

        renderWithProviders(
            <MemoryRouter initialEntries={['/']}>
                <SearchBarWithSuggestions clearOnSubmit />
            </MemoryRouter>,
        );

        const input = screen.getByPlaceholderText('Suche in allen Galerien...');
        await user.type(input, 'submitted-query');
        await user.keyboard('{Enter}');

        await waitFor(() => {
            expect(input).toHaveValue('');
        });
    });
});
