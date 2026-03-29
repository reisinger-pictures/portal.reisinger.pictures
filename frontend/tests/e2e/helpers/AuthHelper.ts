import { Page, expect } from '@playwright/test';

export class AuthHelper {
    constructor(private page: Page) {}

    async login(email = 'florian@reisinger.pictures', password = 'admin') {
        await this.page.goto('/');
        
        const spinner = this.page.locator('.loading-spinner.loading-lg').first();
        if (await spinner.isVisible()) {
            await expect(spinner).toBeHidden({ timeout: 15000 });
        }

        const menuBtn = this.page.locator('header button.btn-square').filter({ has: this.page.locator('.mdi--menu') }).first();
        const backdrop = this.page.locator('div.fixed.inset-0.z-40').first();

        // Auf Mobile das Menü öffnen, um Login-Felder zu sehen
        if (await menuBtn.isVisible()) {
            if (await backdrop.count() === 0 || !(await backdrop.isVisible())) {
                await menuBtn.evaluate((el: HTMLElement) => el.click());
                await backdrop.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
                await this.page.waitForTimeout(350);
            }
        }

        const emailInput = this.page.locator('input[placeholder="E-Mail Adresse"]').first();
        if (!(await emailInput.isVisible())) {
            // Schon eingeloggt -> Sidebar wieder schließen (mit nativem JS-Click)
            const closeBtn = this.page.locator('button:has(.mdi--close)').first();
            if (await closeBtn.count() > 0) {
                await closeBtn.evaluate((el: HTMLElement) => el.click()).catch(() => {});
                await this.page.waitForTimeout(400);
            }
            return;
        }

        await emailInput.fill(email);
        await this.page.fill('input[placeholder="Passwort"]', password);
        
        const loginPromise = this.page.waitForResponse(res => res.url().includes('/api/auth/login') && res.request().method() === 'POST');
        const mePromise = this.page.waitForResponse(res => res.url().includes('/api/auth/me') && res.request().method() === 'GET');
        
        await this.page.getByRole('button', { name: 'Login' }).first().evaluate((el: HTMLElement) => el.click());
        
        await loginPromise;
        await mePromise;
        
        await expect(emailInput).toBeHidden({ timeout: 15000 });
        
        // Nach dem Login Sidebar auf Mobile schließen (nativer Klick)
        if (await menuBtn.isVisible()) {
             const closeBtn = this.page.locator('button:has(.mdi--close)').first();
             if (await closeBtn.count() > 0) {
                 await closeBtn.evaluate((el: HTMLElement) => el.click()).catch(() => {});
                 await this.page.waitForTimeout(400);
             }
        }
    }

    async logout() {
        const menuBtn = this.page.locator('header button.btn-square').filter({ has: this.page.locator('.mdi--menu') }).first();
        const backdrop = this.page.locator('div.fixed.inset-0.z-40').first();

        if (await menuBtn.isVisible()) {
            if (await backdrop.count() === 0 || !(await backdrop.isVisible())) {
                await menuBtn.evaluate((el: HTMLElement) => el.click());
                await backdrop.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
                await this.page.waitForTimeout(350);
            }
        }
        
        const logoutBtn = this.page.getByRole('button', { name: 'Abmelden' }).first();
        if (await logoutBtn.count() > 0) {
            await logoutBtn.evaluate((el: HTMLElement) => el.click()).catch(() => {});
            await this.page.waitForTimeout(500); 
        }
        
        await this.page.goto('/');
        
        if (await menuBtn.isVisible()) {
            if (await backdrop.count() === 0 || !(await backdrop.isVisible())) {
                await menuBtn.evaluate((el: HTMLElement) => el.click());
                await backdrop.waitFor({ state: 'visible', timeout: 5000 }).catch(() => {});
                await this.page.waitForTimeout(350);
            }
        }
        
        await expect(this.page.getByPlaceholder('E-Mail Adresse').first()).toBeVisible({ timeout: 15000 });
    }
}
