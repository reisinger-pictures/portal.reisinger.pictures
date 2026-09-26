import { Page, expect } from '@playwright/test';
import { NetworkHelper } from './NetworkHelper';

export class AuthHelper {
    private network: NetworkHelper;

    constructor(private page: Page) {
        this.network = new NetworkHelper(page);
    }

    async login(email = 'admin@example.com', password = 'admin', loginUrl?: string) {
        await this.page.goto(loginUrl ?? '/');

        await expect(this.page.getByTestId('app-loader').first()).toBeHidden({ timeout: 15000 });
        await expect(this.page.getByRole('main').first()).toBeVisible({ timeout: 15000 });

        const menuBtn = this.page.getByRole('button', { name: 'Menü öffnen' }).first();
        const sidebar = this.page.getByRole('complementary');
        const emailInput = sidebar.getByRole('textbox', { name: 'E-Mail Adresse' }).first();
        const passwordInput = sidebar.getByLabel('Passwort', { exact: true }).first();
        const backdrop = this.page.locator('div.fixed.inset-0').first();

        if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
            await expect(async () => {
                if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
                    await menuBtn.click();
                }
                await expect(backdrop).toBeVisible({ timeout: 2000 });
            }).toPass({ timeout: 10000 });
        }

        if (await emailInput.isVisible()) {
            await emailInput.fill(email);
            await passwordInput.fill(password);

            const loginPromise = this.network.waitForLogin();
            const mePromise = this.network.waitForMe();

            await sidebar.getByRole('button', { name: 'Login', exact: true }).first().scrollIntoViewIfNeeded();
            await this.page.keyboard.press('Enter');
            await loginPromise;
            await mePromise;

            await expect(emailInput).toBeHidden({ timeout: 15000 });
        }

        if (await backdrop.isVisible()) {
            await backdrop.click();
            await expect(backdrop).toBeHidden({ timeout: 5000 });
        }
    }

    async logout(logoutUrl?: string) {
        await this.page.context().clearCookies();
        await this.page.goto(logoutUrl ?? '/');
        await expect(this.page.locator('.loading-spinner.loading-lg').first()).toBeHidden({ timeout: 5000 });

        const sidebar = this.page.getByRole('complementary');
        const emailInput = sidebar.getByRole('textbox', { name: 'E-Mail Adresse' }).first();
        await expect(async () => {
            const menuBtn = this.page.getByRole('button', { name: 'Menü öffnen' }).first();
            const backdrop = this.page.locator('div.fixed.inset-0').first();
            
            if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
                await expect(async () => {
                    if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
                        await menuBtn.click();
                    }
                    await expect(backdrop).toBeVisible({ timeout: 2000 });
                }).toPass({ timeout: 10000 });
            }
            await expect(emailInput).toBeVisible({ timeout: 2000 });
        }).toPass({ timeout: 5000 });
    }
}
