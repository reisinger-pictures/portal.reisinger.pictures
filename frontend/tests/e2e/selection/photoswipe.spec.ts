import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import path from 'path';

test.describe.serial('PhotoSwipe in Selection Gallery', () => {
    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = Date.now();
    const galleryName = `Selection Lightbox ${uniqueId}`;
    let inviteLink = '';

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
    });

    test('Admin creates selection gallery and uploads image', async ({ page }) => {
        await auth.login();

        // 1. Selection Galerie erstellen
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Auswahl (Ratings)');
        await modal.clickButton('Speichern');
        await expect(modal.activeModal).toBeHidden({ timeout: 15000 });
        await expect(page.locator(`a:has-text("${galleryName}")`).first()).toBeVisible({ timeout: 15000 });

        await page.locator(`a:has-text("${galleryName}")`).first().click();

        // 2. Bild hochladen
        const fileInput = page.locator('input[type="file"]');
        const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');
        await fileInput.setInputFiles(sampleImagePath);
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 15000 });

        // 3. Invite Link für Gast generieren
        await page.getByRole('button', { name: 'Einladungslink...' }).click();
        await page.locator('text=Persönlicher Link (Einzelperson)').click();
        await modal.fillInputByLabel('Name des Gastes', 'Lightbox Tester');
        await modal.clickButton('Generieren');

        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible();
        inviteLink = await modal.activeModal.locator('input[readonly]').inputValue();
        await modal.clickButton('Schließen');
    });

    test('Client can interact with PhotoSwipe rating UI and use keyboard shortcuts', async ({ page }) => {
        expect(inviteLink).not.toBe('');

        // 1. Als Gast die Galerie öffnen
        await page.goto(inviteLink);
        await page.getByRole('button', { name: 'Weiter als Lightbox Tester' }).click();
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible({ timeout: 15000 });

        // 2. Lightbox öffnen
        await page.locator('a.pswp-item').first().click();

        // 3. Assert: Lightbox sichtbar
        const lightbox = page.locator('.pswp');
        await expect(lightbox).toBeVisible();

        // 4. Rating via Button & Comment Input
        const ratingBar = page.locator('#rating-portal-anchor .rating');
        await expect(ratingBar).toBeVisible();

        // Kommentar schreiben (MUSS vor dem Rating passieren, da das Rating zum nächsten Bild blättert!)
        const commentInput = page.locator('#rating-portal-anchor input[type="text"]');
        await commentInput.fill('Episches Bild im Fullscreen!');
        
        // 5 Sterne vergeben (speichert Kommentar und blättert automatisch weiter)
        const rateResponse = page.waitForResponse(res => res.url().includes('/rate') && res.request().method() === 'POST');
        await ratingBar.locator('input.mask-star-2').nth(4).click({ force: true });
        await rateResponse;

        // 5. Lightbox schließen
        await expect(async () => {
            await page.locator('button.pswp__button--close').click({ force: true });
            await expect(lightbox).toBeHidden({ timeout: 2000 });
        }).toPass({ timeout: 15000 });

        // 6. Verify im Grid (Kartenansicht)
        await page.waitForTimeout(1000);
        // Stern 5 sollte checked sein (index 4 in daisyUI rating)
        const star5 = page.locator('.card-body input.mask-star-2').nth(4);
        await expect(star5).toBeChecked();
        
        const gridComment = page.locator('input[placeholder="Kommentar..."]').first();
        await expect(gridComment).toHaveValue('Episches Bild im Fullscreen!');

        // 7. Rating via Keyboard Shortcut
        await page.locator('a.pswp-item').first().click();
        await expect(lightbox).toBeVisible();
        
        // Drücke '3' auf der Tastatur
        await page.keyboard.press('3');

        // Lightbox wieder schließen
        await expect(async () => {
            await page.locator('button.pswp__button--close').click({ force: true });
            await expect(lightbox).toBeHidden({ timeout: 2000 });
        }).toPass({ timeout: 15000 });

        // Stern 3 sollte checked sein (index 2)
        const star3 = page.locator('.card-body input.mask-star-2').nth(2);
        await expect(star3).toBeChecked();
    });
});
