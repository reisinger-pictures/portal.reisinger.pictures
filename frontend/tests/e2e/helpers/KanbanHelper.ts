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
     * Prüft die relative Reihenfolge der übergebenen Karten innerhalb einer Spalte von oben nach unten.
     * Isoliert: Es zählt NICHT alle Karten der Spalte (dirty DB / parallele Tests können weitere Karten
     * enthalten) — nur die relative Position der angefragten Karten zueinander wird geprüft.
     * Die Karten-Container sind über `data-testid="kanban-card"` adressierbar (semantischer Locator).
     */
    async expectCardOrder(columnLabel: string, cardTexts: string[]) {
        const cards = this.column(columnLabel).locator('[data-testid="kanban-card"]');
        await expect(cards.first()).toBeVisible();
        for (let i = 0; i < cardTexts.length; i++) {
            for (let j = i + 1; j < cardTexts.length; j++) {
                await expect(cards.filter({ hasText: cardTexts[i] }).first()).toBeVisible();
                await expect(cards.filter({ hasText: cardTexts[j] }).first()).toBeVisible();
                await expect
                    .poll(() => this.isAbove(cards, cardTexts[i], cardTexts[j]), { timeout: 10000 })
                    .toBe(true);
            }
        }
    }

    /**
     * Verschiebt eine Karte über das Karten-Status-Select (Mobile-Fallback & Desktop).
     * Wählt im `<select aria-label="Status ändern">` der Karte den Ziel-Status und
     * wartet, bis die Karte in der Zielspalte sichtbar ist. Kein DnD involviert.
     */
    async selectCardStatus(cardText: string, targetLabel: string) {
        const card = this.main().getByText(cardText, { exact: false }).first()
            .locator('xpath=ancestor::div[contains(@class,"card")][1]');
        await card.waitFor({ state: 'visible', timeout: 5000 });
        await card.getByLabel('Status ändern').selectOption(targetLabel);
        await expect(this.column(targetLabel)).toContainText(cardText, { timeout: 10000 });
    }

    /** Prüft, ob Karte a im DOM über Karte b steht (isoliert auf eigene Karten). */
    private async isAbove(cards: ReturnType<Page['locator']>, a: string, b: string) {
        const boxA = await cards.filter({ hasText: a }).first().boundingBox();
        const boxB = await cards.filter({ hasText: b }).first().boundingBox();
        if (!boxA || !boxB) return false;
        return boxA.y < boxB.y;
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
     * Verschiebt eine Karte per Drag-and-Drop in die Zielspalte.
     *
     * Nutzt ECHTE Maus-Events (`page.mouse.move/down/up` = Input.dispatchMouseEvent in
     * Chromium): Der Browser erzeugt daraus native HTML5-DragEvents (dragstart/dragover/drop),
     * die `@atlaskit/pragmatic-drag-and-drop` benötigt. Der `dragTo()`-Locator-Shortcut ist
     * dafür NICHT geeignet — er berechnet den Drop-Punkt einmalig vor dem Hover und setzt
     * die Scroll-Position zurück, sodass der in-flow Drop-Shadow das Layout live verschiebt
     * und der Drop ins Leere fällt (v. a. bei parallelen Läufen auf dirty DB).
     *
     * Ablauf: Quelle in den Viewport scrollen → `mouse.down()` am Quell-Zentrum (Drag ist an
     * der Quelle gebunden) → Ziel-Scroll-Reset → Drop-Punkt LIVE aus frischen Boxen der
     * ersten Zielkarte auflösen → `mouse.move(steps)` → `mouse.up()`.
     *
     * Mit `opts.position` wird die vertikale Drop-Position innerhalb der Zielspalte gewählt
     * (`top` = oberer Bereich → Position 0, `bottom` = unterer Bereich → ans Ende). Touch-DnD
     * wird nicht simuliert — Drag & Drop ist laut SOLL nur am Desktop aktiv, Mobile nutzt den
     * Status-Select-Fallback (`selectCardStatus`).
     */
    async dragCard(cardText: string, targetLabel: string, opts?: {
        position?: 'top' | 'bottom';
        /** Optionaler positionsabhängiger Erfolgscheck (z.B. für Same-Column-Drags, wo
         * `toContainText` immer wahr ist und die echte Position verifiziert werden muss). */
        verify?: () => Promise<boolean>;
    }) {
        const card = this.cardWrapper(cardText);
        const target = this.column(targetLabel);
        await card.waitFor({ state: 'visible', timeout: 5000 });

        // Parallele Läufe auf geteilter (dirty) DB verändern das Board live (andere Tests
        // erzeugen/verschieben Karten) — Layout und Drop-Ziele können sich zwischen zwei
        // Schritten verschieben. Bis zu 3 Versuche: Nach jedem Versuch wird geprüft, ob der
        // Drag wirklich die gewünschte Position erreicht hat (via `verify` bzw. Sichtbarkeit
        // in der Zielspalte); sonst wird der Drag mit frischen Geometrien wiederholt.
        const attempts = 3;
        for (let attempt = 1; attempt <= attempts; attempt++) {
            await this.performDragAttempt(card, target, cardText, targetLabel, opts);
            await this.page.waitForTimeout(400);
            const ok = opts?.verify
                ? await opts.verify()
                : (await target.getByText(cardText, { exact: false }).count()) > 0;
            if (ok) {
                break;
            }
            console.warn(`[KanbanHelper] dragCard "${cardText}" → ${targetLabel}: nach Versuch ${attempt}/${attempts} noch nicht am Ziel — neuer Versuch`);
            await this.page.waitForTimeout(700);
        }

        await expect(this.column(targetLabel)).toContainText(cardText, { timeout: 10000 });
        await expect(this.page.locator('.modal-open')).toHaveCount(0, { timeout: 5000 });
    }

    /**
     * Der draggable Karten-Container (`data-testid="kanban-card"`). pragmatic-dnd registriert
     * `draggable` auf diesem Wrapper — für den nativen Drag muss das Quell-Element genau das
     * sein, auf dem `draggable="true"` gesetzt ist (bzw. ein Nachfahre davon).
     */
    private cardWrapper(cardText: string) {
        return this.main().getByText(cardText, { exact: false }).first()
            .locator('xpath=ancestor::div[@data-testid="kanban-card"][1]');
    }

    /** Führt einen einzelnen Drag-and-Drop-Versuch aus (kein Positions-Ergebnis-Statement). */
    private async performDragAttempt(
        card: ReturnType<Page['locator']>,
        target: ReturnType<Page['locator']>,
        cardText: string,
        targetLabel: string,
        opts?: { position?: 'top' | 'bottom' },
    ) {
        // Die Quelle (neu angelegte Karte) liegt am Ende einer überfüllten Spalte (dirty DB /
        // parallele Tests) oft außerhalb des Viewports — mouse.down() würde dann kein Element
        // treffen und der Drag startet nie. Erst in den Viewport scrollen.
        await card.scrollIntoViewIfNeeded();

        const cb = await card.boundingBox();
        const tb = await target.boundingBox();
        if (!cb || !tb) throw new Error(`dragCard: bounding boxes fehlen (card=${cardText}, target=${targetLabel})`);

        const sx = cb.x + cb.width / 2;
        const sy = cb.y + cb.height / 2;
        const ex = tb.x + tb.width / 2;
        const dropOffset = opts?.position === 'top' ? 70 : 90;
        const ey = tb.y + dropOffset;
        const vp = this.page.viewportSize();
        const safeTop = 40;
        const safeBottom = (vp?.height ?? 1000) - 40;

        // Setzt das interne Scrollen des Ziel-Drop-Containers auf 0, damit die erste Karte
        // (bzw. der leere Container) oben liegt und der Drop-Punkt sie wirklich trifft —
        // z.B. beim Reorder in derselben Spalte, deren Drop-Container durch
        // scrollIntoViewIfNeeded auf die Quelle (unten) gescrollt wurde. WICHTIG: Erst NACH
        // mouse.down() ausführen — der native Drag ist dann an der Quelle gebunden und ein
        // Scroll-Reset des Containers hebt ihn nicht auf.
        const resetTargetScroll = async () => {
            const scrollable = target.locator('.overflow-y-auto');
            if (await scrollable.count()) {
                await scrollable.first().evaluate((el) => { el.scrollTop = 0; });
                await this.page.waitForTimeout(80);
            }
        };

        // Bestimmt den Drop-Punkt aus einer frischen Spalten-Box: auf der ersten Karte (wenn
        // vorhanden) in deren oberem Bereich, sonst auf dem leeren Drop-Container.
        const resolveDropPoint = async (colBox: { x: number; y: number; width: number; height: number } | null) => {
            if (!colBox) return { x: ex, y: ey };
            const x = colBox.x + colBox.width / 2;
            const cards = target.locator('[data-testid="kanban-card"]');
            if ((await cards.count()) > 0) {
                const fc = await cards.first().boundingBox();
                if (fc && fc.height > 0) {
                    return { x, y: fc.y + Math.min(30, fc.height * 0.3) };
                }
            }
            return { x, y: colBox.y + dropOffset };
        };

        // Nativer HTML5-Drag über ECHTE Maus-Events (Input.dispatchMouseEvent in Chromium):
        // mouse.move → mouse.down startet den Drag am Quell-Element (pragmatic-dnd hört auf
        // native dragstart/dragover/drop), danach Scroll-Reset + live aufgelöster Drop-Punkt.
        await this.page.mouse.move(sx, sy);
        await this.page.mouse.down();
        await resetTargetScroll();
        let dropPoint = await resolveDropPoint(tb);
        if (vp && (dropPoint.y < safeTop || dropPoint.y > safeBottom)) {
            // Zielpunkt liegt außerhalb des Viewports (überfüllte Spalten / Seite durch
            // Quelle-Scroll verschoben): Pointer am passenden Viewport-Rand verweilen lassen
            // → der Browser auto-scrollt, dann live zur aktuellen Zielposition ziehen.
            const scrollUp = dropPoint.y < safeTop;
            const edgeY = scrollUp ? 12 : vp.height - 12;
            const edgeX = dropPoint.x > vp.width / 2 ? vp.width - 12 : 12;
            for (let i = 0; i < 100; i++) {
                await this.page.mouse.move(edgeX, edgeY);
                await this.page.waitForTimeout(30);
                const targetBox = await target.boundingBox();
                if (!targetBox) break;
                const candidate = await resolveDropPoint(targetBox);
                if (candidate.y >= safeTop && candidate.y <= safeBottom) {
                    dropPoint = candidate;
                    break;
                }
            }
        }
        await this.page.mouse.move(dropPoint.x, dropPoint.y, { steps: 10 });
        await this.page.mouse.up();
        await this.waitForMove(4000);
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

    private async waitForMove(timeout = 15000) {
        await this.page.waitForResponse(
            res => /\/move$/.test(res.url()) && res.request().method() === 'PATCH',
            { timeout },
        ).catch(() => console.warn('[KanbanHelper] Timeout beim Warten auf move-PATCH'));
    }
}
