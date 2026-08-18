import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2ESessionHelper } from '../helpers/E2ESessionHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';

test.describe('WYSIWYG-Editor', () => {
    let helper: E2ESessionHelper;
    let user: { email: string; password: string };

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
        user = await helper.createIsolatedUser('super_admin');
    });

    test.afterEach(async () => {
        await helper.teardown();
    });

    test('supports headings, lists, inline marks, links and table manipulation', { tag: ['@feature:admin:documents'] }, async ({ page }) => {
        await new AuthHelper(page).login(user.email, user.password);
        await new SidebarHelper(page).navigateTo('Manuelles Angebot');

        const editor = page.locator('.ProseMirror').first();
        const heading = page.getByRole('combobox', { name: 'Überschrift' });
        await editor.click();
        await editor.fill('Eine Überschrift');
        await heading.selectOption('2');
        await expect(editor.locator('h2')).toContainText('Eine Überschrift');

        // Active-style reactivity regression: clicking "Fett" must flip the
        // toolbar button to the active (btn-neutral) state. Previously the
        // render-time `editor.isActive('bold')` read was frozen by the React
        // Compiler, so the button never updated to its active appearance.
        await page.getByRole('button', { name: 'Fett' }).click();
        await expect(page.getByRole('button', { name: 'Fett' })).toHaveClass(/btn-neutral/);

        await editor.press('End');
        await editor.press('Enter');
        await page.getByRole('button', { name: 'Nummerierte Liste' }).click();
        await editor.pressSequentially('Punkt eins');
        await editor.press('Enter');
        await editor.pressSequentially('Punkt zwei');
        await expect(editor.locator('ol li')).toHaveCount(2);

        // Select "Punkt zwei" via a mouse drag. Keyboard caret navigation
        // (Home/End/Shift+Arrow) is unreliable here: Tiptap v3 restores the
        // caret after toolbar blurs asynchronously and maps Home/End to the
        // document edges. The click below re-focuses the editor first — a drag
        // right after a toolbar interaction (blur) does not reliably register
        // its selection with ProseMirror.
        await editor.locator('li').last().click();
        const lastItem = editor.locator('li').last();
        const itemBox = await lastItem.locator('p').boundingBox();
        if (!itemBox) throw new Error('list item paragraph has no bounding box');
        await page.mouse.move(itemBox.x + 2, itemBox.y + itemBox.height / 2);
        await page.mouse.down();
        await page.mouse.move(itemBox.x + itemBox.width - 2, itemBox.y + itemBox.height / 2, { steps: 5 });
        await page.mouse.up();
        await page.getByRole('button', { name: 'Link einfügen' }).click();
        await page.getByRole('textbox', { name: 'Link-Adresse' }).fill('https://example.com');
        await page.getByRole('button', { name: 'Anwenden' }).click();
        await expect(editor.locator('a[href="https://example.com"]')).toContainText('Punkt zwei');

        // Link-removal flow regression: clicking into the link must reactively
        // enable the "Link einfügen" button (previously frozen/disabled by the
        // frozen `isActive('link')` read) and reveal the "Entfernen" action in
        // the popover.
        await editor.locator('a[href="https://example.com"]').click();
        await expect(page.getByRole('button', { name: 'Link einfügen' })).toBeEnabled();
        await page.getByRole('button', { name: 'Link einfügen' }).click();
        await expect(page.getByRole('button', { name: 'Entfernen', exact: true })).toBeVisible();
        await page.getByRole('button', { name: 'Entfernen', exact: true }).click();
        await expect(editor.locator('a[href="https://example.com"]')).toHaveCount(0);

        // The underline step runs AFTER the link flow: a toolbar interaction
        // directly before the drag selection leaves a pending async
        // blur/empty-selection update that races the drag's selection on
        // Desktop (button stays disabled).
        await editor.press('ControlOrMeta+A');
        await page.getByRole('button', { name: 'Unterstrichen' }).click();
        await expect(editor.locator('u').last()).toContainText('Punkt zwei');

        // Leave the ordered list so the table is inserted from a fresh paragraph
        // (a real user never inserts a table inside a list item). Clicking the
        // document's trailing paragraph is deterministic — the caret lands there
        // without any keyboard/selection timing races.
        await editor.locator('p').last().click();
        await expect(editor.locator('ol li')).toHaveCount(2);

        await page.getByRole('button', { name: 'Tabelle einfügen' }).click();
        const table = editor.locator('table');
        await expect(table).toBeVisible();
        await expect(editor.locator('ol table')).toHaveCount(0);
        await table.locator('th').first().click();
        await expect(page.getByTitle('Spalte löschen')).toBeVisible();
        await expect(table.locator('th')).toHaveCount(3);
        await expect(table.locator('tr')).toHaveCount(3);
        await page.getByTitle('Spalte löschen').click();
        await expect(table.locator('th')).toHaveCount(2);
        await page.getByTitle('Spalte danach hinzufügen').click();
        await expect(table.locator('th')).toHaveCount(3);
        // Cursor is still in the header row, so "Zeile löschen" removes it: 3 tr -> 2 tr
        await page.getByTitle('Zeile löschen').click();
        await expect(table.locator('tr')).toHaveCount(2);
    });

    test('scrolls long editor content inside a resizable text area while toolbar stays visible', { tag: ['@feature:admin:documents'] }, async ({ page }) => {
        await new AuthHelper(page).login(user.email, user.password);
        await new SidebarHelper(page).navigateTo('Manuelles Angebot');

        const editor = page.locator('.ProseMirror').first();
        const scroll = page.getByTestId('editor-scroll');
        const heading = page.getByRole('combobox', { name: 'Überschrift' });

        await editor.click();
        // Long content: only the text area must scroll, not the whole page.
        for (let i = 0; i < 80; i++) {
            await editor.pressSequentially(`Absatz ${i}`);
            await editor.press('Enter');
        }

        await expect.poll(() => scroll.evaluate(el => el.scrollHeight > el.clientHeight)).toBe(true);
        await expect(heading).toBeVisible();

        // The native CSS `resize-y` handle is a mouse-only browser feature. Under
        // the touch emulation of the Mobile Chrome project (hover: none) it does
        // not respond to mouse drags at all — same platform limitation as
        // drag & drop (cf. projects-board.spec.ts) — so the resize sub-step runs
        // on Desktop only. The scroll/toolbar assertions above stay cross-platform.
        if (test.info().project.name !== 'Mobile Chrome') {
            // Native resize handle sits at the bottom-right corner: shrink by
            // dragging UP ~80px. With 80 paragraphs the container already sits at
            // max-h-160 (640px) and CSS max-height would block any grow —
            // shrinking is deterministic and stays well above min-h-48 (192px).
            await scroll.scrollIntoViewIfNeeded();
            const before = await scroll.boundingBox();
            if (!before) throw new Error('editor scroll container has no bounding box');
            await page.mouse.move(before.x + before.width - 5, before.y + before.height - 5);
            await page.mouse.down();
            await page.mouse.move(before.x + before.width - 5, before.y + before.height - 80, { steps: 5 });
            await page.mouse.up();

            await expect.poll(async () => {
                const after = await scroll.boundingBox();
                return after !== null && after.height !== before.height;
            }).toBe(true);
        }
    });
});
