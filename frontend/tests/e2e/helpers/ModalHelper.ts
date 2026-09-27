import { NetworkHelper } from './NetworkHelper';
import { Page, Locator, expect } from '@playwright/test';

/**
 * Attach a rejection handler now without discarding the rejection.
 *
 * The mutation wait is started *before* the click, because the click is what
 * causes the request. If the click throws first, nobody has awaited the pending
 * wait and Node reports its eventual timeout as an unhandled rejection —
 * attributed to the click rather than to the response that never arrived.
 * Carrying the outcome to the await point keeps the failure where it belongs.
 */
type Settled<T> = { ok: true; value: T } | { ok: false; error: unknown };

function capture<T>(promise: Promise<T>): Promise<Settled<T>> {
    return promise.then(
        value => ({ ok: true as const, value }),
        (error: unknown) => ({ ok: false as const, error })
    );
}

export class ModalHelper {
    private network: NetworkHelper;
    constructor(private page: Page) { this.network = new NetworkHelper(page); }

    get activeModal(): Locator {
        // DaisyUI hält Modals oft im DOM, wir fokussieren uns strikt auf das aktuell geöffnete.
        return this.page.locator('.modal-open').last();
    }

    async fillInputByLabel(labelText: string, value: string) {
        await this.activeModal.locator('.form-control').filter({ hasText: labelText }).locator('input').fill(value);
    }

    async selectByLabel(labelText: string, optionLabel: string) {
        await this.activeModal.locator('.form-control').filter({ hasText: labelText }).locator('select').selectOption({ label: optionLabel });
    }

    // ✨ NEUE UTILITY: Robustes Checkbox-Toggling für DaisyUI
    async toggleCheckboxByLabel(labelText: string, targetState: boolean = true) {
        const container = this.activeModal.locator('.form-control, .label').filter({ hasText: labelText }).first();
        const checkbox = container.locator('input[type="checkbox"]');
        
        await checkbox.setChecked(targetState);
        
        // Kurz warten, bis React den State verarbeitet hat (Anti-Flakiness)
        if (targetState) {
            await expect(checkbox).toBeChecked({ timeout: 2000 });
        } else {
            await expect(checkbox).not.toBeChecked({ timeout: 2000 });
        }
    }

    async assertCheckboxByLabel(labelText: string, expectedState: boolean = true) {
        const container = this.activeModal.locator('.form-control, .label').filter({ hasText: labelText }).first();
        const checkbox = container.locator('input[type="checkbox"]');
        if (expectedState) {
            await expect(checkbox).toBeChecked();
        } else {
            await expect(checkbox).not.toBeChecked();
        }
    }

    async clickButton(buttonText: string) {
        const btn = this.activeModal.getByRole('button', { name: buttonText });
        await btn.scrollIntoViewIfNeeded();
        // Anti-Flakiness: React Hook Form braucht einen Render-Cycle fuer State-Sync nach Select/Dropdown
        await expect(btn).toBeEnabled({ timeout: 3000 });
        await btn.click();
    }

    async closeModal() {
        await this.activeModal.locator('button').filter({ hasText: '✕' }).click();
    }

    /**
     * Submit a modal and wait for the resulting management mutation.
     *
     * The three outcomes are kept strictly apart, so a failure is reported
     * against the thing that actually failed:
     *  - the request was never answered → `NetworkHelper` throws, naming the
     *    endpoint, the method and the timeout;
     *  - the request was answered with an error status → this method throws
     *    with that status and body;
     *  - the request succeeded but the modal stayed open → only the
     *    `toBeHidden()` below reports, and it is then genuinely about the modal.
     *
     * There is deliberately no catch-all around the wait. The previous version
     * logged a warning, returned `{}` and fell through to `toBeHidden()`, so an
     * in-flight POST surfaced as `expect(locator).toBeHidden() failed` and the
     * missing response was discarded.
     */
    async submitModal(buttonText: string = 'Speichern', urlPattern?: string) {
        const mutation = capture(this.network.waitForManagementMutation(urlPattern));

        await this.clickButton(buttonText);

        const settled = await mutation;
        if (!settled.ok) throw settled.error;
        const res = settled.value;

        if (!res.ok()) {
            const errorText = await res.text();
            throw new Error(`API Error ${res.status()}: ${errorText}`);
        }

        await expect(this.activeModal).toBeHidden({ timeout: 15000 });
        return await res.json().catch(() => ({}));
    }
}
