import { Page, expect } from '@playwright/test';

export class AuthHelper {
    constructor(private page: Page) {}

    async login(email = 'florian@reisinger.pictures', password = 'admin') {
        await this.page.goto('/');
        
        // Hamburger-Menü auf Mobile öffnen, damit die Login-Felder klickbar werden
        const menuBtn = this.page.locator('button:has(.mdi--menu)').first();
        if (await menuBtn.isVisible()) {
            await menuBtn.click({ force: true });
            await this.page.waitForTimeout(500); // Kurz warten, bis das CSS-Menü reingefahren ist
        }

        await this.page.fill('input[placeholder="E-Mail Adresse"]', email);
        await this.page.fill('input[placeholder="Passwort"]', password);
        await this.page.click('button:has-text("Login")');
        
        // Strenger Indikator: Wir warten auf den "Abmelden" Button. 
        // Nur dann sind wir wirklich erfolgreich im Dashboard des Users angekommen!
        // Strenger Indikator: Wir warten darauf, dass der Login-Button verschwindet.
        // Das bedeutet, SWR hat die Session erkannt und das Dashboard geladen (funktioniert auf Desktop & Mobile!).
        // Wir warten darauf, dass der Abmelden Button im DOM eingehängt wird.
        // Dieser ist zwar auf Mobile im Menü versteckt, aber er existiert im DOM,
        // sobald das Dashboard vollständig geladen und autorisiert wurde.
        await expect(this.page.locator('button:has-text("Abmelden")').first()).toBeAttached({ timeout: 15000 });
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
