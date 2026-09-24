import { test, expect } from '@playwright/test';
import { SearchHelper } from '../helpers/SearchHelper';

test.describe('Global Search URL synchronization', () => {
    test('keeps a mounted search input synchronized with same-route back/forward navigation', { tag: ['@feature:search'] }, async ({ page }) => {
        await page.goto('/');

        const search = new SearchHelper(page);
        await expect(search.input).toBeVisible({ timeout: 15000 });

        const firstTerm = `UrlSyncFirst${Math.random().toString(36).substring(2, 8)}`;
        const secondTerm = `UrlSyncSecond${Math.random().toString(36).substring(2, 8)}`;

        await search.search(firstTerm);
        await expect(page).toHaveURL(new RegExp(`/search\\?q=${firstTerm}`));
        await expect(search.input).toHaveValue(firstTerm);

        // The second submit stays on /search. A marker on the input proves that
        // browser history navigation reuses this exact DOM node.
        const mountMarker = `mounted-${Math.random().toString(36).substring(2, 10)}`;
        await search.input.evaluate((input, marker) => {
            input.setAttribute('data-e2e-mount-marker', marker);
        }, mountMarker);

        await search.search(secondTerm);
        await expect(page).toHaveURL(new RegExp(`/search\\?q=${secondTerm}`));
        await expect(search.input).toHaveValue(secondTerm);
        await expect(search.input).toHaveAttribute('data-e2e-mount-marker', mountMarker);

        await page.goBack();
        await expect(page).toHaveURL(new RegExp(`/search\\?q=${firstTerm}`));
        await expect(search.input).toHaveValue(firstTerm);
        await expect(search.input).toHaveAttribute('data-e2e-mount-marker', mountMarker);

        await page.goForward();
        await expect(page).toHaveURL(new RegExp(`/search\\?q=${secondTerm}`));
        await expect(search.input).toHaveValue(secondTerm);
        await expect(search.input).toHaveAttribute('data-e2e-mount-marker', mountMarker);
    });
});
