import {FrameLocator, Page} from '@playwright/test';
import {CreditCard} from './CreditCardHelper';
import {ModalHelper} from './ModalHelper';

export interface FillGalleryModalParams {
    name?: string;
    type?: string;
    visibility?: string;
    freeDownload?: boolean;
    editorialOnly?: boolean;
    hidden?: boolean;
    live?: boolean;
    expiresAt?: string;
}

export interface FillGroupModalParams {
    name?: string;
    visibility?: string;
    freeDownload?: boolean;
    editorialOnly?: boolean;
    hidden?: boolean;
}

export interface FillUserModalParams {
    name?: string;
    email?: string;
}

export interface FillTenantModalParams {
    name?: string;
}

export interface FillInviteModalParams {
    type: 'mass' | 'personal';
    name?: string;
    canEditMeta?: boolean;
}

export interface FillCheckoutFormParams {
    name?: string;
    street?: string;
    zip?: string;
    city?: string;
    acceptAgb?: boolean;
    waiveWithdrawal?: boolean;
}

export interface FillProfileFormParams {
    name?: string;
    ftpSlug?: string;
    copyright?: string;
}

export class FormHelper {
    constructor(private page: Page, private modal: ModalHelper) {
    }

    async fillGalleryModal(params: FillGalleryModalParams) {
        if (params.name !== undefined) await this.modal.fillInputByLabel('Name der Galerie', params.name);
        if (params.type !== undefined) await this.modal.selectByLabel('Galerie-Typ', params.type);
        if (params.visibility !== undefined) await this.modal.selectByLabel('Sichtbarkeit', params.visibility);
        if (params.freeDownload !== undefined) await this.modal.toggleCheckboxByLabel('Kostenlosen Download erlauben', params.freeDownload);
        if (params.editorialOnly !== undefined) await this.modal.toggleCheckboxByLabel('Nur für redaktionelle Nutzung (Shop)', params.editorialOnly);
        if (params.hidden !== undefined) await this.modal.toggleCheckboxByLabel('Im Frontend verstecken', params.hidden);
        if (params.live !== undefined) await this.modal.toggleCheckboxByLabel('LIVE Galerie', params.live);
        if (params.expiresAt) await this.modal.fillInputByLabel('Ablaufdatum', params.expiresAt);
    }

    async fillGroupModal(params: FillGroupModalParams) {
        if (params.name !== undefined) await this.modal.fillInputByLabel('Name', params.name);
        if (params.visibility !== undefined) await this.modal.selectByLabel('Sichtbarkeits-Vorgabe', params.visibility);
        if (params.freeDownload !== undefined) await this.modal.toggleCheckboxByLabel('Kostenlosen Download erlauben', params.freeDownload);
        if (params.editorialOnly !== undefined) await this.modal.toggleCheckboxByLabel('Nur für redaktionelle Nutzung (Shop)', params.editorialOnly);
        if (params.hidden !== undefined) await this.modal.toggleCheckboxByLabel('Im Frontend verstecken', params.hidden);
    }

    async fillUserModal(params: FillUserModalParams) {
        if (params.name) await this.modal.fillInputByLabel('Name', params.name);
        if (params.email !== undefined) await this.modal.fillInputByLabel('E-Mail Adresse', params.email);
    }

    async fillTenantModal(params: FillTenantModalParams) {
        if (params.name !== undefined) await this.modal.fillInputByLabel('Name (z.B. Firma XYZ)', params.name);
    }

    async fillInviteModal(params: FillInviteModalParams) {
        if (params.type === 'mass') {
            await this.page.locator('.form-control').filter({hasText: 'Massen-Link'}).locator('input[type="radio"]').check();
        } else {
            await this.page.locator('.form-control').filter({hasText: 'Persönlicher Link'}).locator('input[type="radio"]').check();
            if (params.name) await this.modal.fillInputByLabel('Name des Gastes', params.name);
        }
        if (params.canEditMeta !== undefined) {
            await this.modal.toggleCheckboxByLabel('Gast darf Metadaten bearbeiten', params.canEditMeta);
        }
    }

    async fillCheckoutForm(params: FillCheckoutFormParams) {
        if (params.name) await this.page.fill('input[name="billing_name"]', params.name);
        if (params.street) await this.page.fill('input[name="billing_street"]', params.street);
        if (params.zip) await this.page.fill('input[name="billing_zip"]', params.zip);
        if (params.city) await this.page.fill('input[name="billing_city"]', params.city);
        if (params.acceptAgb) await this.page.locator('input[name="agb_accepted"]').check();
        if (params.waiveWithdrawal) await this.page.locator('input[name="withdrawal_waived"]').check();
    }

    async fillProfileForm(params: FillProfileFormParams) {
        if (params.name) await this.page.locator('.form-control').filter({hasText: 'Dein Name'}).locator('input').fill(params.name);
        if (params.ftpSlug) await this.page.locator('.form-control').filter({hasText: 'FTP Upload Ordner'}).locator('input').fill(params.ftpSlug);
        if (params.copyright) await this.page.locator('.form-control').filter({hasText: 'Standard-Urheber'}).locator('input').fill(params.copyright);
    }

    async fillStripeForm(frame: FrameLocator, card: CreditCard) {
        const cardNumberInput = frame.locator('input[autocomplete="cc-number"], input[name="cardnumber"], input[name="number"]').first();
        const expDateInput = frame.locator('input[autocomplete="cc-exp"], input[name="exp-date"], input[name="expiry"]').first();
        const cvcInput = frame.locator('input[autocomplete="cc-csc"], input[name="cvc"]').first();

        // Desktop: Stripe Payment Element rendert jedes Feld in einem separaten Iframe.
        // Fallback: Suche Felder in ihren dedizierten Stripe-Iframes via page-Ebene.
        const desktopCardFrame = this.page.frameLocator(
            'iframe[title*="card number" i], iframe[title*="kartennummer" i]'
        ).first();
        const desktopExpiryFrame = this.page.frameLocator(
            'iframe[title*="expiration date" i], iframe[title*="expiry" i], iframe[title*="expiration" i], iframe[title*="ablauf" i]'
        ).first();
        const desktopCvcFrame = this.page.frameLocator(
            'iframe[title*="security code" i], iframe[title*="cvc" i], iframe[title*="sicherheitscode" i]'
        ).first();

        await cardNumberInput.fill(card.number).catch(() =>
            desktopCardFrame.locator('input').first().fill(card.number)
        );
        await expDateInput.fill(card.exp).catch(() =>
            desktopExpiryFrame.locator('input').first().fill(card.exp)
        );
        await cvcInput.fill(card.cvc).catch(() =>
            desktopCvcFrame.locator('input').first().fill(card.cvc)
        );

        // Anti-Flakiness: Blur erzwingt Stripe-interne Validierung. Kurzer Wait stellt sicher,
        // dass der Submit-Button im DOM den State-Update mitbekommt.
        await this.page.locator('body').blur().catch(() => {});
        await this.page.waitForTimeout(1000);
    }
}
