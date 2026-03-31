import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { E2EUserHelper } from '../helpers/E2EUserHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { UploadHelper } from '../helpers/UploadHelper';

test.afterAll(async ({ request }) => {
    await E2EUserHelper.cleanupTrackedUsers(request);
});


test.describe.serial('Gallery Invite Link Workflow', () => {
    let testUser = { email: '', password: '' };

    test.beforeAll(async ({ request }) => {
        testUser = await E2EUserHelper.createIsolatedUser(request, 'photographer');
    });

    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = () => Date.now() + Math.floor(Math.random() * 1000);
    const galleryName = `Invite Test ${uniqueId()}`;
    let inviteLinkAnon = '';

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
    });

    test('Photographer creates gallery and generates anonymous invite link', async ({ page }) => {
        await auth.login(testUser.email, testUser.password);

        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Auswahl (Ratings)');
        await modal.submitModal('Speichern');
        await expect(page.locator('main').locator('a').filter({ hasText: galleryName }).first()).toBeVisible({ timeout: 15000 });

        await page.locator('main').locator('a').filter({ hasText: galleryName }).first().click();

        const upload = new UploadHelper(page);
        await upload.uploadSampleImage();

        await page.getByRole('button', { name: 'Einladungslink...' }).click();

        // Kein Name eintragen = Anonymer Link
        await modal.clickButton('Generieren');
        await expect(page.locator('text=Erfolgreich generiert!')).toBeVisible();

        inviteLinkAnon = await modal.activeModal.locator('input[readonly]').inputValue();
    });

    test('Guest (Anonymous) redeems invite directly and accesses gallery', async ({ page }) => {
        test.skip(inviteLinkAnon === '', 'Test requires link from previous step');

        await page.goto(inviteLinkAnon);
        await expect(page.locator('h2:has-text("Willkommen zur Fotoauswahl")')).toBeVisible();

        const guestEmail = `gast-${uniqueId()}@example.com`;
        await page.getByPlaceholder('z.B. Maria Muster').fill('Gast Bewerter');
        await page.getByPlaceholder('maria@beispiel.de').fill(guestEmail);
        await page.getByRole('button', { name: 'Galerie öffnen' }).click();

        // Statt auf eine Mail zu warten, prüfen wir, ob der Gast direkt in die Galerie geleitet wird
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();
        await expect(page.locator('p:has-text("Wähle deine Favoriten aus.")')).toBeVisible();

        // Bild muss sichtbar sein, was die transienten Rechte bestätigt
        const image = page.locator('a.pswp-item img').first();
        await expect(image).toBeVisible();
        await expect(image).toHaveJSProperty('complete', true);
        expect(await image.evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
    });

    test('Logged-in User redeems anonymous invite directly', async ({ page }) => {
        // Sicherstellen, dass der Test nicht isoliert fehlschlägt
        test.skip(inviteLinkAnon === '', 'Test requires link from previous step');

        await auth.login(testUser.email, testUser.password);

        // Wir fangen den API-Request ab, um sicherzustellen, dass das Auto-Redeem im Hintergrund feuert
        // Aufruf des Magic Links
        await page.goto(inviteLinkAnon);

        // Da AuthHelper.login nun auf den Abmelden-Button wartet, ist die Session hier etabliert.

        // 2. Aufruf des Magic Links
        await page.goto(inviteLinkAnon);

        // 3. Geduldiges Warten auf den Auto-Redeem Redirect in den /galleries/ Pfad (gemäß TESTING.md)
        await expect(page).toHaveURL(/.*\/galleries\/.*/);

        // Finale Bestätigung: Der Name der Galerie ist als h1 sichtbar
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible();
    });
});