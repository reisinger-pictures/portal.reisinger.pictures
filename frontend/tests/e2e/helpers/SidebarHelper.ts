import { Page, expect } from '@playwright/test';

export class SidebarHelper {
    constructor(private page: Page) {}

    async navigateTo(menuText: string) {
        // Anti-Flakiness: Sicherstellen, dass keine Fade-Out Animationen von Modals den Klick blockieren
        await expect(this.page.locator('.modal-open')).toHaveCount(0, { timeout: 5000 });

        // Anti-Flakiness: Nach einem Reload ersetzt der App-Loader in `ProtectedRoute` kurzzeitig das
        // ganze Dashboard. Erst wenn die Navigations-Shell wieder montiert ist, darf über den
        // Drawer-Zustand entschieden werden — sonst arbeitet navigateTo auf einem Element, das gleich
        // wieder ersetzt wird.
        //
        // Als Readiness-Signal taugt dafür nur das `<aside>`-Landmark, nicht der Drawer-Trigger: der
        // trägt `md:hidden` und ist ab dem `md`-Breakpoint nicht Teil des Accessibility-Tree, den
        // `getByRole` auswertet. `toBeAttached()` auf dem Trigger-Locator bleibt dort dauerhaft leer
        // (Button im DOM vorhanden, Locator ohne Treffer) und läuft in den Timeout. Das Landmark wird
        // dagegen von jedem Dashboard (Staff, Client, Gast) auf jedem Viewport montiert; mobil ist es
        // nur per `-translate-x-full` aus dem Viewport verschoben, was es im Tree belässt. Beide
        // Elemente werden im selben React-Commit montiert, unterhalb des `md`-Breakpoints löst sich
        // das Gate also zum identischen Zeitpunkt wie der frühere Trigger-Wait.
        const shell = this.page.getByRole('complementary');
        await expect(shell).toBeAttached({ timeout: 10000 });

        const menuBtn = this.page.getByRole('button', { name: 'Menü öffnen' }).first();
        const backdrop = this.page.locator('div.fixed.inset-0').first();

        // Nur unterhalb des `md`-Breakpoints ist der Drawer geschlossen und der Trigger sichtbar; ab
        // `md` ist `isVisible()` dauerhaft false und der Zweig entfällt. Die Prüfung ist damit
        // viewport-agnostisch, der Drawer wird auf Mobile wie bisher vor dem Link-Klick geöffnet.
        if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
            await expect(async () => {
                if (await menuBtn.isVisible() && !(await backdrop.isVisible())) {
                    await menuBtn.click();
                }
                await expect(backdrop).toBeVisible({ timeout: 2000 });
            }).toPass({ timeout: 10000 });
        }

        const link = shell
            .getByRole('link', { name: menuText, exact: false })
            .first();
        // click() performs its own actionability and scrolling checks.
        await expect(link).toBeVisible({ timeout: 10000 });
        await link.click();
    }

    async navigateToClientGalleries() {
        // Client users reach public galleries through discovery, not the staff-only gallery manager.
        await this.navigateTo('Suche & Entdecken');
    }

    async openNewGalleryModal() {
        await this.navigateTo('Galerien & Ordner');
        const btn = this.page.getByRole('button', { name: 'Neue Galerie' });
        await expect(btn).toBeVisible();
        await btn.click();
    }

    async openNewGroupModal() {
        await this.navigateTo('Galerien & Ordner');
        await this.page.getByRole('button', { name: 'Neuer Ordner' }).click();
    }

    async assertNotVerticallyScrollable() {
        const sidebarMenu = this.page.locator('aside .overflow-y-auto').first();
        if (await sidebarMenu.isVisible()) {
            const isScrollable = await sidebarMenu.evaluate((el) => el.scrollHeight > el.clientHeight);
            if (isScrollable) {
                throw new Error('Sidebar ist vertikal scrollbar, obwohl sie es nicht sein sollte.');
            }
        }
    }
}
