import { Page, expect } from '@playwright/test';

export class KanbanHelper {
    constructor(private page: Page) {}

    private main() {
        return this.page.locator('main');
    }

    /**
     * Gibt die Spalten-Container-Locator zurück, gescoped über `main` (Semantic Locator Scoping).
     * `exact: false` weil der Spalten-Header das Label zusammen mit dem Counter-Badge rendert (z.B. "Anfrage0").
     * Seit der Hybrid-Grid-UX (V026) sind Spalten `w-full min-w-0` (kein fixer `w-72`); der stabile
     * Anker ist der Header-Text innerhalb der Spalten-Box (`bg-base-200 rounded-box border`).
     * `kanban-grid`-Scope verhindert Fehl-Anker über substring-Kollisionen (z.B. h1 "Bildbearbeitung" ⊃ "Bearbeitung").
     */
    column(label: string) {
        return this.main()
            .locator('.kanban-grid')
            .getByText(label, { exact: false })
            .first()
            .locator('xpath=ancestor::div[contains(@class,"bg-base-200")][contains(@class,"rounded-box")][1]');
    }

    async expectColumn(label: string) {
        await expect(this.column(label)).toBeVisible();
    }

    /**
     * Moves a card through its semantic status control. This is the stable path for
     * ordinary board coverage on both desktop and mobile; it does not depend on pixel
     * coordinates or native drag events.
     */
    async selectCardStatus(cardText: string, targetLabel: string) {
        const card = this.main().getByText(cardText, { exact: false }).first()
            .locator('xpath=ancestor::div[contains(@class,"card")][1]');
        await expect(card).toBeVisible({ timeout: 10000 });

        const statusControl = card.getByLabel('Status ändern', { exact: true });
        const targetValue = await statusControl.locator('option')
            .filter({ hasText: targetLabel })
            .getAttribute('value');
        if (!targetValue) throw new Error(`selectCardStatus: option "${targetLabel}" not found`);

        // Register the listener before changing the control so a fast PATCH cannot be missed.
        // Matching the requested status also avoids accepting a concurrent board mutation.
        const moveResponse = this.waitForMove(targetValue);
        await statusControl.selectOption(targetValue);
        const response = await moveResponse;
        expect(response.ok()).toBeTruthy();
        await expect(this.cardInColumn(targetLabel, cardText)).toBeVisible({ timeout: 10000 });
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
        await expect(modal.locator(`.form-control:has-text("${labelText}")`).first()).toContainText(message);
    }

    async submit() {
        const modal = this.page.locator('.modal-open');
        await modal.getByRole('button', { name: 'Speichern' }).click();
    }

    async modalIsClosed() {
        await expect(this.page.locator('.modal-open')).toHaveCount(0, { timeout: 10000 });
    }

    /**
     * Performs one real native desktop drag. This method is intentionally reserved for
     * the dedicated DnD regression; ordinary status changes must use selectCardStatus().
     * There are no retries or fixed dwell delays: geometry is resolved once and the
     * move-PATCH response is the synchronization point.
     */
    async dragCard(cardText: string, targetLabel: string) {
        const card = this.cardWrapper(cardText);
        const target = this.column(targetLabel);
        const dropZone = target.locator('.overflow-y-auto').first();
        await expect(card).toBeVisible({ timeout: 10000 });
        await expect(dropZone).toBeVisible({ timeout: 10000 });

        // Keep the source in view and reset the independent target scroller without
        // waiting for an animation. The dedicated test is desktop-only and must fail
        // clearly if its target is not visible instead of retrying a pixel gesture.
        await card.scrollIntoViewIfNeeded();
        await dropZone.evaluate((element) => { element.scrollTop = 0; });
        await expect(dropZone).toHaveJSProperty('scrollTop', 0);

        const cardBox = await card.boundingBox();
        const targetBox = await dropZone.boundingBox();
        if (!cardBox || !targetBox) {
            throw new Error(`dragCard: missing bounding box (card=${cardText}, target=${targetLabel})`);
        }

        const source = {
            x: cardBox.x + cardBox.width / 2,
            y: cardBox.y + cardBox.height / 2,
        };
        const destination = {
            x: targetBox.x + targetBox.width / 2,
            y: targetBox.y + Math.min(30, targetBox.height / 2),
        };
        const viewport = this.page.viewportSize();
        const pointIsVisible = (point: { x: number; y: number }) =>
            !!viewport && point.x >= 0 && point.x <= viewport.width && point.y >= 0 && point.y <= viewport.height;
        if (!pointIsVisible(source) || !pointIsVisible(destination)) {
            throw new Error(
                `dragCard: source and target must be in the desktop viewport (card=${cardText}, target=${targetLabel})`,
            );
        }

        // Register before mouse.up: the browser can dispatch the PATCH immediately
        // after the drop, so waiting afterwards would introduce a response race.
        const moveResponse = this.waitForMove();
        await this.page.mouse.move(source.x, source.y);
        await this.page.mouse.down();
        await this.page.mouse.move(destination.x, destination.y, { steps: 10 });
        await this.page.mouse.up();

        const response = await moveResponse;
        expect(response.ok()).toBeTruthy();
        await expect(this.cardInColumn(targetLabel, cardText)).toBeVisible({ timeout: 10000 });
    }

    /**
     * The draggable card wrapper. pragmatic-dnd registers draggable() on its inner
     * element, while this stable test id scopes the source and target cards.
     */
    private cardWrapper(cardText: string) {
        return this.main().getByText(cardText, { exact: false }).first()
            .locator('xpath=ancestor::div[@data-testid="kanban-card"][1]');
    }

    private cardInColumn(columnLabel: string, cardText: string) {
        // Mobile/non-super-admin cards intentionally do not render the drag test id;
        // the card root is stable in both the draggable and fallback layouts.
        return this.column(columnLabel)
            .locator('.card')
            .filter({ hasText: cardText })
            .first();
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

    private waitForMove(expectedStatus?: string) {
        return this.page.waitForResponse(res => {
            if (!/\/move$/.test(res.url()) || res.request().method() !== 'PATCH') return false;
            if (!expectedStatus) return true;
            try {
                const payload = res.request().postDataJSON() as { status?: unknown };
                return payload.status === expectedStatus;
            } catch {
                return false;
            }
        }, { timeout: 15000 });
    }
}
