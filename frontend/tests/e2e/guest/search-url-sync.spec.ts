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
        await expect(page.getByRole('main').getByRole('heading', {
            name: new RegExp(`Suchergebnisse für.*${firstTerm}`),
        })).toBeVisible({ timeout: 15000 });
        await expect(search.input).toHaveValue(firstTerm);

        // The route marker above confirms that the first result view is mounted
        // before this DOM identity marker is attached. It then must survive all
        // same-route history transitions.
        const mountMarker = `mounted-${Math.random().toString(36).substring(2, 10)}`;
        await search.input.evaluate((input, marker) => {
            input.setAttribute('data-e2e-mount-marker', marker);
        }, mountMarker);

        await search.search(secondTerm);
        await expect(page).toHaveURL(new RegExp(`/search\\?q=${secondTerm}`));
        await expect(page.getByRole('main').getByRole('heading', {
            name: new RegExp(`Suchergebnisse für.*${secondTerm}`),
        })).toBeVisible({ timeout: 15000 });
        await expect(search.input).toHaveValue(secondTerm);
        await expect(search.input).toHaveAttribute('data-e2e-mount-marker', mountMarker);

        await page.goBack();
        await expect(page).toHaveURL(new RegExp(`/search\\?q=${firstTerm}`));
        await expect(page.getByRole('main').getByRole('heading', {
            name: new RegExp(`Suchergebnisse für.*${firstTerm}`),
        })).toBeVisible({ timeout: 15000 });
        await expect(search.input).toHaveValue(firstTerm);
        await expect(search.input).toHaveAttribute('data-e2e-mount-marker', mountMarker);

        await page.goForward();
        await expect(page).toHaveURL(new RegExp(`/search\\?q=${secondTerm}`));
        await expect(page.getByRole('main').getByRole('heading', {
            name: new RegExp(`Suchergebnisse für.*${secondTerm}`),
        })).toBeVisible({ timeout: 15000 });
        await expect(search.input).toHaveValue(secondTerm);
        await expect(search.input).toHaveAttribute('data-e2e-mount-marker', mountMarker);
    });
});
