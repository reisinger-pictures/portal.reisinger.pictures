import { NetworkHelper } from './NetworkHelper';
import { Page, Locator, expect } from '@playwright/test';

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

        const currentState = await checkbox.isChecked();

        if (currentState !== targetState) {
            // Bei DaisyUI sind Checkboxen und Toggles sicht- und klickbare Elemente.
            // Direkter Klick auf den Input ist wesentlich robuster als Klick auf das Label/den Text.
            // Klick über das Label (Container) für stabiles DaisyUI Verhalten
            await container.click();

            // Kurz warten, bis React den State verarbeitet hat (Anti-Flakiness)
            if (targetState) {
                await expect(checkbox).toBeChecked({ timeout: 2000 });
            } else {
                await expect(checkbox).not.toBeChecked({ timeout: 2000 });
            }
        }
    }

    async clickButton(buttonText: string) {
        const btn = this.activeModal.getByRole('button', { name: buttonText });
        await btn.scrollIntoViewIfNeeded();
        // Anti-Flakiness: Dem React Hook Form kurz Zeit geben, den State (z.B. nach Dropdown-Selects) zu syncen
        await this.page.waitForTimeout(200);
        await btn.click();
    }

    async closeModal() {
        await this.activeModal.locator('button').filter({ hasText: '✕' }).click();
    }

    async submitModal(buttonText: string = 'Speichern') {
        const savePromise = this.network.waitForManagementMutation();
        await this.clickButton(buttonText);
        const res = await savePromise;
        
        if (!res.ok()) {
            const errorText = await res.text();
            throw new Error(`API Error ${res.status()}: ${errorText}`);
        }
        
        await expect(this.activeModal).toBeHidden({ timeout: 15000 });
        return await res.json().catch(() => ({}));
    }
}
