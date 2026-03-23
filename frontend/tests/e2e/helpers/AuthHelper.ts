import { Page, expect } from '@playwright/test';

export class AuthHelper {
    constructor(private page: Page) {}

    async login(email = 'florian@reisinger.pictures', password = 'admin') {
        await this.page.goto('/');
        await this.page.fill('input[placeholder="E-Mail Adresse"]', email);
        await this.page.fill('input[placeholder="Passwort"]', password);
        await this.page.click('button:has-text("Login")');
        await expect(this.page.locator('text=Reisinger Foto Portal').first()).toBeVisible({ timeout: 10000 });
    }

    async logout() {
        // Logout Button erzwingen (force: true falls er außerhalb des Viewports ist)
        await this.page.getByRole('button', { name: 'Abmelden' }).click({ force: true });
        
        // Wir navigieren sicherheitshalber explizit zur Root-URL nach dem Klick
        await this.page.goto('/');
        
        // Warten auf das Gäste-UI (E-Mail Eingabefeld in der Sidebar)
        await expect(this.page.getByPlaceholder('E-Mail Adresse').first()).toBeVisible({ timeout: 10000 });
    }
}
