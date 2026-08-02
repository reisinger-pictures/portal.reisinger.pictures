import { Page, expect } from '@playwright/test';

export class KanbanHelper {
    constructor(private page: Page) {}

    private main() {
        return this.page.locator('main');
    }

    /**
     * Gibt die Spalten-Container-Locator zurück, gescoped über `main` (Semantic Locator Scoping).
     * `exact: false` weil der Spalten-Header das Label zusammen mit dem Counter-Badge rendert (z.B. "Anfrage0").
     */
    column(label: string) {
        return this.main()
            .getByText(label, { exact: false })
            .first()
            .locator('xpath=ancestor::div[contains(@class,"w-72")][1]');
    }

    async expectColumn(label: string) {
        await expect(this.column(label)).toBeVisible();
    }

    /** öffnet das "Neues ..."-Modal über das Plus in der Kopfzeile einer Spalte. */
    async openCreateModal(columnLabel: string, plusTitle: string) {
        await this.column(columnLabel).getByTitle(plusTitle).click();
        await expect(this.page.locator('.modal-open')).toBeVisible({ timeout: 5000 });
    }

    /** Füllt ein Feld im offenen Modal anhand seines Label-Texts (zuverlässig ohne HTML-Nesting von label/input). */
    async fillField(labelText: string, value: string) {
        const modal = this.page.locator('.modal-open');
        const field = modal.locator(`.form-control:has-text("${labelText}")`).first().locator('input,select,textarea').first();
        await field.scrollIntoViewIfNeeded();
        await field.fill(value);
    }

    async expectFieldError(labelText: string, message: string) {
        const modal = this.page.locator('.modal-open');
        await expect(modal.locator(`.form-control:has-text("${labelText}")`)).toContainText(message);
    }

    async submit() {
        const modal = this.page.locator('.modal-open');
        await modal.getByRole('button', { name: 'Speichern' }).click();
    }

    async modalIsClosed() {
        await expect(this.page.locator('.modal-open')).toHaveCount(0, { timeout: 10000 });
    }

    /**
     * Verschiebt eine Karte per Drag-and-Drop in die Zielspalte.
     * Standard: Maus-Pointer-Events (dnd-kit PointerSensor). Mit `opts.touch` wird eine
     * synthetische Touch-Pointer-Sequenz erzeugt (Mobile-Gesten).
     */
    async dragCard(cardText: string, targetLabel: string, opts?: { touch?: boolean }) {
        const card = this.main().getByText(cardText, { exact: false }).first();
        const target = this.column(targetLabel);
        await card.waitFor({ state: 'visible', timeout: 5000 });

        const cb = await card.boundingBox();
        const tb = await target.boundingBox();
        if (!cb || !tb) throw new Error(`dragCard: bounding boxes fehlen (card=${cardText}, target=${targetLabel})`);

        const sx = cb.x + cb.width / 2;
        const sy = cb.y + (cb.height / 2);
        const ex = tb.x + (tb.width / 2);
        const ey = tb.y + (tb.height * 0.7);

        if (opts?.touch) {
            await card.dispatchEvent('pointerdown', {
                pointerId: 1, pointerType: 'touch', isPrimary: true, bubbles: true, clientX: sx, clientY: sy,
            });
            await this.page.waitForTimeout(80);
            const steps = 12;
            for (let i = 1; i <= steps; i++) {
                await this.page.locator('body').dispatchEvent('pointermove', {
                    pointerId: 1, pointerType: 'touch', isPrimary: true, bubbles: true,
                    clientX: sx + ((ex - sx) * i) / steps, clientY: sy + ((ey - sy) * i) / steps,
                });
                await this.page.waitForTimeout(22);
            }
            await target.dispatchEvent('pointerup', {
                pointerId: 1, pointerType: 'touch', isPrimary: true, bubbles: true,
                clientX: ex, clientY: ey,
            });
        } else {
            await this.page.mouse.move(sx, sy);
            await this.page.mouse.down();
            await this.page.mouse.move(ex, ey, { steps: 18 });
            await this.page.mouse.up();
        }

        await this.waitForMove();
        await expect(this.column(targetLabel)).toContainText(cardText, { timeout: 10000 });
        await expect(this.page.locator('.modal-open')).toHaveCount(0, { timeout: 5000 });
    }

    async waitForCreate(endpoint: string) {
        await this.page.waitForResponse(
            res => res.url().includes(endpoint) && res.request().method() === 'POST',
            { timeout: 15000 },
        );
    }

    async waitForDelete(endpoint: string) {
        await this.page.waitForResponse(
            res => res.url().includes(endpoint) && res.request().method() === 'DELETE',
            { timeout: 15000 },
        );
    }

    private async waitForMove() {
        await this.page.waitForResponse(
            res => /\/move$/.test(res.url()) && res.request().method() === 'PATCH',
            { timeout: 15000 },
        ).catch(() => console.warn('[KanbanHelper] Timeout beim Warten auf move-PATCH'));
    }
}