import { Page, expect } from '@playwright/test';
import { NetworkHelper } from './NetworkHelper';

export class AuthHelper {
    private network: NetworkHelper;

    constructor(private page: Page) {
        this.network = new NetworkHelper(page);
    }

    async login(email = 'florian@reisinger.pictures', password = 'admin') {
        await this.page.goto('/');

        await expect(this.page.getByTestId('app-loader').first()).toBeHidden({ timeout: 5000 });
        await expect(this.page.locator('main').first()).toBeVisible({ timeout: 5000 });

        const menuBtn = this.page.locator('header button').filter({ has: this.page.locator('.mdi--menu') }).first();
        const emailInput = this.page.locator('input[placeholder="E-Mail Adresse"]').first();
        const backdrop = this.page.locator('div.fixed.inset-0.z-40').first();

        if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
            await expect(async () => {
                if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
                    await menuBtn.click();
                }
                await expect(backdrop).toBeVisible({ timeout: 2000 });
            }).toPass({ timeout: 10000 });
            await this.page.waitForTimeout(400);
        }

        if (await emailInput.isVisible()) {
            await emailInput.fill(email);
            await this.page.fill('input[placeholder="Passwort"]', password);

            const loginPromise = this.network.waitForLogin();
            const mePromise = this.network.waitForMe();

            await this.page.getByRole('button', { name: 'Login' }).first().click();
            await loginPromise;
            await mePromise;

            await expect(emailInput).toBeHidden({ timeout: 15000 });
        }

        if (await backdrop.isVisible()) {
            await backdrop.click();
            await expect(backdrop).toBeHidden({ timeout: 5000 });
        }
    }

    async logout() {
        await this.page.context().clearCookies();
        await this.page.goto('/');
        await expect(this.page.locator('.loading-spinner.loading-lg').first()).toBeHidden({ timeout: 5000 });

        const emailInput = this.page.getByPlaceholder('E-Mail Adresse').first();
        await expect(async () => {
            const menuBtn = this.page.locator('header button').filter({ has: this.page.locator('.mdi--menu') }).first();
            const backdrop = this.page.locator('div.fixed.inset-0.z-40').first();
            
            if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
                await expect(async () => {
                    if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
                        await menuBtn.click();
                    }
                    await expect(backdrop).toBeVisible({ timeout: 2000 });
                }).toPass({ timeout: 10000 });
                await this.page.waitForTimeout(400);
            }
            await expect(emailInput).toBeVisible({ timeout: 2000 });
        }).toPass({ timeout: 5000 });
    }
}
