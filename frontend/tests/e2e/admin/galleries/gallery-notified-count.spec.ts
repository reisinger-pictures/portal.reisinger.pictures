import { test, expect, type APIRequestContext } from '@playwright/test';
import { AuthHelper } from '../../helpers/AuthHelper';
import { E2ESessionHelper } from '../../helpers/E2ESessionHelper';
import { GalleryHelper } from '../../helpers/GalleryHelper';
import { NetworkHelper } from '../../helpers/NetworkHelper';
import { ToastHelper } from '../../helpers/ToastHelper';
import { createDeliveryGallery, STUDIO_ADMIN, type FixtureGallery } from '../../helpers/AccessScopeFixture';

/**
 * The subscriber count of a gallery is what the "E-Mail senden" dialog reports.
 *
 * `notified_count` is read from `gallery_notification_subscriptions` — the
 * table of the owner decision D-45 — and not from the grant pivot. The count
 * decides two things: whether the "E-Mail senden..." button is enabled at all
 * (`canSendMail = notified_count > 0`) and how many mails a manual send
 * actually delivers (`MailController::sendCustom()` counts the subscribers that
 * may still reach the gallery).
 *
 * The spec pins both: the button follows the subscriptions, and the number the
 * API reports is the number of mails that arrive.
 */

const TAG = ['@regression', '@feature:admin:galleries'];

const MAILPIT_API_URL = process.env.MAILPIT_API_URL || 'http://localhost:8025/api/v1';

/**
 * How many **gallery mails** Mailpit holds for the given recipient.
 *
 * The subject is the filter: the mailbox of a test client also holds the
 * password-reset mail of the fixture, and only the gallery mail carries the
 * name of the gallery.
 */
async function countGalleryMails(request: APIRequestContext, email: string, galleryName: string): Promise<number> {
    const response = await request.get(
        `${MAILPIT_API_URL}/messages?query=${encodeURIComponent(`to:"${email}"`)}&limit=1000`,
    );
    if (!response.ok()) {
        throw new Error(`Reading the Mailpit messages failed (HTTP ${response.status()})`);
    }
    const body = await response.json() as {
        messages?: Array<{ Subject?: string; To?: Array<{ Address?: string }> }>;
    };
    return (body.messages ?? []).filter((message) =>
        message.To?.some((recipient) => recipient.Address === email)
        && (message.Subject ?? '').includes(galleryName),
    ).length;
}

test.describe('The subscriber count of a gallery', () => {
    let helper: E2ESessionHelper;

    test.beforeEach(async ({ request }) => {
        helper = new E2ESessionHelper(request);
    });

    test.afterEach(async () => {
        if (helper) await helper.teardown();
    });

    test('the "E-Mail senden" dialog counts the subscribers and follows a new subscription', { tag: TAG }, async ({ page, request }) => {
        const suffix = Math.random().toString(36).substring(2, 10);
        const gallery: FixtureGallery = await createDeliveryGallery(request, helper, STUDIO_ADMIN, {
            name: `E2E Notify Count ${suffix}`,
        });

        const auth = new AuthHelper(page);
        const galleryHelper = new GalleryHelper(page, helper);
        await auth.login(STUDIO_ADMIN.email, STUDIO_ADMIN.password);
        await galleryHelper.openGallery(gallery.name);

        const main = page.getByRole('main');
        const mailButton = main.getByRole('button', { name: 'E-Mail senden...' });

        // Without a subscriber there is nobody to write to: the button is off.
        await expect(mailButton).toBeDisabled({ timeout: 20000 });

        // The first client subscribes to the gallery.
        const firstClient = await helper.createIsolatedUser('client', {
            assignGalleryId: gallery.id,
            wantsNotifications: true,
        });

        // The button follows the count.
        await page.reload();
        await expect(main.getByRole('button', { name: 'E-Mail senden...' })).toBeEnabled({ timeout: 20000 });

        // The dialog reports the count the API reports: one subscriber.
        await main.getByRole('button', { name: 'E-Mail senden...' }).click();
        const dialog = main.getByRole('dialog', { name: 'Nachricht an Kunden senden' });
        await expect(dialog).toBeVisible({ timeout: 20000 });

        const send = new NetworkHelper(page).waitForApi('/send-custom-email', 'POST');
        await dialog.getByRole('button', { name: 'Nachricht Senden' }).click();
        const sendResponse = await send;
        expect(sendResponse.ok()).toBeTruthy();
        const sendBody = await sendResponse.json() as { notified_count?: number };
        expect(sendBody.notified_count).toBe(1);

        await new ToastHelper(page).expectToast('Erfolg! 1 E-Mails versendet.');
        expect(await countGalleryMails(request, firstClient.email, gallery.name)).toBe(1);

        // A second client subscribes: the count follows.
        const secondClient = await helper.createIsolatedUser('client', {
            assignGalleryId: gallery.id,
            wantsNotifications: true,
        });

        await page.reload();
        await expect(main.getByRole('button', { name: 'E-Mail senden...' })).toBeEnabled({ timeout: 20000 });
        await main.getByRole('button', { name: 'E-Mail senden...' }).click();
        await expect(main.getByRole('dialog', { name: 'Nachricht an Kunden senden' })).toBeVisible({ timeout: 20000 });

        const secondSend = new NetworkHelper(page).waitForApi('/send-custom-email', 'POST');
        await main.getByRole('dialog', { name: 'Nachricht an Kunden senden' })
            .getByRole('button', { name: 'Nachricht Senden' })
            .click();
        const secondSendResponse = await secondSend;
        expect(secondSendResponse.ok()).toBeTruthy();
        const secondSendBody = await secondSendResponse.json() as { notified_count?: number };
        expect(secondSendBody.notified_count).toBe(2);

        await new ToastHelper(page).expectToast('Erfolg! 2 E-Mails versendet.');
        // Both subscribers got the second mail, the first one got both.
        expect(await countGalleryMails(request, secondClient.email, gallery.name)).toBe(1);
        expect(await countGalleryMails(request, firstClient.email, gallery.name)).toBe(2);
    });
});
