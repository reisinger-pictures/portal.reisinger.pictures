import { Locator, Page, expect } from '@playwright/test';

const searchPlaceholder = 'Suche in allen Galerien...';

export class SearchHelper {
    private _input: Locator;

    constructor(private page: Page) {
        this._input = this.page
            .getByRole('banner')
            .getByRole('textbox', { name: searchPlaceholder });
    }

    get input() {
        return this._input;
    }

    async expectVisible() {
        await expect(this.input).toBeVisible();
    }

    async search(term: string) {
        await this.input.fill(term);
        await this.input.press('Enter');
    }

    async fill(term: string) {
        await this.input.fill(term);
    }

    async expectSuggestionsContain(text: string) {
        await expect(this.page.locator(`text=Suche nach "${text}"`)).toBeVisible();
    }

    async expectNoSuggestions() {
        await expect(this.page.locator('text=Suche nach').first()).toBeHidden();
    }

    async clickSuggestion(text: string) {
        await this.page.locator(`text=Suche nach "${text}"`).click();
    }
}
