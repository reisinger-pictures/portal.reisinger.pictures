import { beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter, useSearchParams } from 'react-router-dom';
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

describe('SearchBarWithSuggestions', () => {
    beforeEach(() => {
        vi.clearAllMocks();
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
