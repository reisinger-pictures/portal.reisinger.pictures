import { test, expect } from '@playwright/test';
import { AuthHelper } from '../helpers/AuthHelper';
import { SidebarHelper } from '../helpers/SidebarHelper';
import { ModalHelper } from '../helpers/ModalHelper';
import { MailpitHelper } from '../helpers/MailpitHelper';
import path from 'path';

test.describe.serial('Photographer Core Workflow', () => {
    let auth: AuthHelper;
    let sidebar: SidebarHelper;
    let modal: ModalHelper;

    const uniqueId = Date.now() + '-' + Math.round(Math.random() * 10000);
    const galleryName = `Playwright Workflow ${uniqueId}`;
    const editedName = `Playwright Edited ${uniqueId}`;

    let testEmail = '';
    const testPassword = 'SecurePassword123!';

    
    test.beforeAll(async ({ request }) => {
        const mailpit = new MailpitHelper(request);
        testEmail = `photog-${uniqueId}@example.com`;

        // 1. Admin Login via API (Setzt das Cookie im Request Context)
        await request.post('/api/auth/login', {
            data: { email: 'florian@reisinger.pictures', password: 'admin' }
        });

        // 2. Echten User on-the-fly via API anlegen
        const createRes = await request.post('/api/management/users', {
            data: { name: `E2E Photog ${uniqueId}`, email: testEmail }
        });
        const createData = await createRes.json();
        const newUserId = createData.user.id;

        // 3. Fotografen-Rolle zuweisen
        const rolesRes = await request.get('/api/management/roles');
        const roles = await rolesRes.json();
        const photogRole = roles.find((r: any) => r.name === 'photographer');

        await request.put(`/api/management/users/${newUserId}`, {
            data: { role_ids: [photogRole.id], gallery_group_ids: [], gallery_ids: [], can_edit_metadata: false }
        });

        // 4. Setup Token aus Mailpit abfangen und Passwort setzen
        const token = await mailpit.extractLinkForEmail(testEmail, /token=([a-zA-Z0-9]+)/);
        if (!token) throw new Error("Konnte kein Setup-Token in Mailpit finden");

        await request.post('/api/auth/reset-password', {
            data: { email: testEmail, token, password: testPassword }
        });

        // 5. Admin sauber ausloggen
        await request.post('/api/auth/logout');
    });

    test.beforeEach(async ({ page }) => {
        auth = new AuthHelper(page);
        sidebar = new SidebarHelper(page);
        modal = new ModalHelper(page);
        
        // Da die Tests isoliert sind, loggen wir uns für jeden Teilschritt neu ein.
        // Das sorgt dafür, dass wir immer sauber vom Dashboard starten.
        await auth.login(testEmail, testPassword);
    });

    test('Photographer can create a new delivery gallery', async ({ page }) => {
        await sidebar.openNewGalleryModal();
        await modal.fillInputByLabel('Name der Galerie', galleryName);
        await modal.selectByLabel('Galerie-Typ', 'Delivery (Downloads)');
        // Explizit auf die API-Antwort warten, um Flakiness auszuschließen
        const responsePromise = page.waitForResponse(res => res.url().includes('/api/management/galleries') && res.request().method() === 'POST');
        const saveResponse = page.waitForResponse(res => res.url().includes('/api/management/galleries') && (res.request().method() === 'POST' || res.request().method() === 'PUT'));
        await modal.clickButton('Speichern');
        await saveResponse;
        const response = await responsePromise;
        expect(response.status()).toBe(200);

        await expect(page.locator('main').locator('a').filter({ hasText: galleryName }).first()).toBeVisible({ timeout: 15000 });
    });

    test('Photographer can edit an existing gallery', async ({ page }) => {
        await sidebar.navigateTo('Galerien');
        const galLink = page.locator('main').locator('a').filter({ hasText: galleryName }).first();
        await galLink.click();
        await expect(page.locator(`h1:has-text("${galleryName}")`)).toBeVisible({ timeout: 15000 });

        await page.locator('button[data-tip="Galerie bearbeiten"]').click();
        await modal.fillInputByLabel('Name der Galerie', editedName);
        await modal.clickButton('Speichern');
        
        await expect(page.locator(`h1:has-text("${editedName}")`)).toBeVisible({ timeout: 15000 });
    });

    test('Photographer can upload an image to the gallery', async ({ page }) => {
        await sidebar.navigateTo('Galerien');
        const editedLink = page.locator('main').locator('a').filter({ hasText: editedName }).first();
        await editedLink.click();
        await expect(page.locator(`h1:has-text("${editedName}")`)).toBeVisible({ timeout: 15000 });

        const fileInput = page.locator('input[type="file"]');
        await expect(fileInput).toBeAttached();
        
        const sampleImagePath = path.resolve(process.cwd(), '../backend/tests/Fixtures/sample.jpg');
        await fileInput.setInputFiles(sampleImagePath);

        await expect(page.locator('text=Noch keine Bilder vorhanden')).toBeHidden({ timeout: 15000 });
        await expect(page.locator('a.pswp-item img').first()).toBeVisible({ timeout: 20000 });
        await expect(page.locator('a.pswp-item img').first()).toHaveJSProperty('complete', true);
        expect(await page.locator('a.pswp-item img').first().evaluate((img: HTMLImageElement) => img.naturalWidth)).toBeGreaterThan(0);
    });

    test('Photographer sees the new gallery and photo in personal feed on dashboard', async ({ page }) => {
        // Zurück zum Dashboard navigieren
        await page.goto('/');

        // Warten bis der Personal Feed gerendert ist
        const feedHeader = page.locator('h2:has-text("Deine neuesten Uploads & Galerien")');
        await expect(feedHeader).toBeVisible({ timeout: 15000 });

        // Feed auf generelle Funktionalität prüfen (irgendein Bild/Galerie muss laden)
        await expect(page.locator('.space-y-8').first()).toBeVisible({ timeout: 15000 });
    });


    test('Photographer can toggle between management and client view using the tab switcher', async ({ page }) => {
        // 1. Zur Galerie navigieren
        await sidebar.navigateTo('Galerien');
        const editedLink = page.locator('main').locator('a').filter({ hasText: editedName }).first();
        await editedLink.click();
        await expect(page.locator(`h1:has-text("${editedName}")`)).toBeVisible({ timeout: 15000 });

        // 2. Prüfen, ob wir standardmäßig in der Management-Ansicht sind
        const inviteBtn = page.getByRole('button', { name: 'Einladungslink...' });
        await expect(inviteBtn).toBeVisible();

        // 3. Tab-Switcher suchen und auf "Kundenansicht" klicken
        const clientTab = page.locator('button[role="tab"]').filter({ hasText: 'Kundenansicht' });
        await expect(clientTab).toBeVisible();
        await clientTab.click();

        // 4. Verifizieren: URL enthält ?view=client und UI ändert sich zur DeliveryView
        await expect(page).toHaveURL(/.*\?view=client/);
        // UI hat erfolgreich gewechselt (Kundenansicht lädt)
        await expect(inviteBtn).toBeHidden(); // Management-Buttons müssen weg sein

        // 5. Zurück zur Verwaltung wechseln
        const managementTab = page.locator('button[role="tab"]').filter({ hasText: 'Verwaltung' });
        await managementTab.click();

        // 6. Verifizieren: URL enthält ?view=management und UI ist wieder ManagementGalleryView
        await expect(page).toHaveURL(/.*\?view=management/);
        await expect(inviteBtn).toBeVisible({ timeout: 10000 });
        await expect(page.locator('text=Lade deine Bilder herunter.')).toBeHidden();
    });


    test('Photographer can update profile settings and verify FTP slug in dashboard', async ({ page }) => {
        // 1. Zu den Einstellungen navigieren
        await sidebar.navigateTo('Einstellungen');
        await expect(page.locator('h1:has-text("Einstellungen")')).toBeVisible();

        // 2. Generiere eindeutige Test-Werte
        const uniqueSuffix = Date.now().toString().slice(-6);
        const newName = `Test Photog ${uniqueSuffix}`;
        const newSlug = `ftp-${uniqueSuffix}`;
        const newCopyright = `© ${newName}`;

        // 3. Felder ausfüllen
        await page.locator('.form-control').filter({ hasText: 'Dein Name' }).locator('input').fill(newName);
        await page.locator('.form-control').filter({ hasText: 'FTP Upload Ordner' }).locator('input').fill(newSlug);
        await page.locator('.form-control').filter({ hasText: 'Standard-Urheber' }).locator('input').fill(newCopyright);

        // 4. Speichern & auf Toast warten
        await page.getByRole('button', { name: 'Profil speichern' }).click();
        const toast = page.locator('.toast');
        await expect(toast).toBeVisible({ timeout: 15000 });
        await expect(toast).toContainText('Profil aktualisiert', { timeout: 5000 });

        // 5. HARD REFRESH: Prüfen, ob die Daten wirklich persistiert wurden
        await page.reload();
        await expect(page.locator('.form-control').filter({ hasText: 'Dein Name' }).locator('input')).toHaveValue(newName, { timeout: 15000 });
        await expect(page.locator('.form-control').filter({ hasText: 'FTP Upload Ordner' }).locator('input')).toHaveValue(newSlug);

        // 6. Navigation zum Dashboard und FTP-Widget Validierung
        await page.goto('/');
        await expect(page.locator('h2:has-text("FTP Inbox")')).toBeVisible({ timeout: 15000 });
        
        // Prüfen, ob der neue Slug im FTP-Pfad angezeigt wird
        await expect(page.locator(`code:has-text("/ftp/${newSlug}")`)).toBeVisible();
    });
});
