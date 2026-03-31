import { Page, expect } from '@playwright/test';

export class AuthHelper {
    constructor(private page: Page) {}

    async login(email = 'florian@reisinger.pictures', password = 'admin') {
        await this.page.goto('/');

        await expect(this.page.getByTestId('app-loader').first()).toBeHidden({ timeout: 5000 });
        await expect(this.page.locator('main').first()).toBeVisible({ timeout: 5000 });

        const menuBtn = this.page.locator('header button.btn-square').filter({ has: this.page.locator('.mdi--menu') }).first();
        const backdrop = this.page.locator('div.fixed.inset-0.z-40').first();

        if (await menuBtn.isVisible()) {
            if (await backdrop.count() === 0 || !(await backdrop.isVisible())) {
                await menuBtn.click();
                await expect(backdrop).toBeVisible({ timeout: 5000 });
            }
        }

        const emailInput = this.page.locator('input[placeholder="E-Mail Adresse"]').first();

        if (await emailInput.isHidden()) {
            const closeBtn = this.page.locator('button:has(.mdi--close)').first();
            if (await closeBtn.isVisible()) {
                await closeBtn.click();
            }
            return;
        }

        await emailInput.fill(email);
        await this.page.fill('input[placeholder="Passwort"]', password);

        const loginPromise = this.page.waitForResponse(res => res.url().includes('/api/auth/login') && res.request().method() === 'POST');
        const mePromise = this.page.waitForResponse(res => res.url().includes('/api/auth/me') && res.request().method() === 'GET');

        await this.page.getByRole('button', { name: 'Login' }).first().click();
        await loginPromise;
        await mePromise;

        await expect(emailInput).toBeHidden({ timeout: 5000 });

        // CLEANUP FIX: Resilienter Sidebar-Close für Mobile
        if (await menuBtn.isVisible()) {
            const closeBtn = this.page.locator('button:has(.mdi--close)').first();
            if (await closeBtn.isVisible()) {
                try {
                    await closeBtn.click({ timeout: 2000 });
                } catch (e) {
                    // Ignorieren: Sidebar wurde evtl. bereits durch Navigation unmounted
                }
            }
        }
    }

    async logout() {
        await this.page.context().clearCookies();
        await this.page.goto('/');
        await expect(this.page.locator('.loading-spinner.loading-lg').first()).toBeHidden({ timeout: 5000 });

        const emailInput = this.page.getByPlaceholder('E-Mail Adresse').first();
        await expect(async () => {
            const menuBtn = this.page.locator('header button.btn-square').filter({ has: this.page.locator('.mdi--menu') }).first();
            if (await menuBtn.isVisible() && await emailInput.isHidden()) {
                await menuBtn.click();
            }
            await expect(emailInput).toBeVisible({ timeout: 2000 });
        }).toPass({ timeout: 5000 });
    }
}