import {Page} from '@playwright/test';
import {CreditCard} from './CreditCardHelper';
import {ModalHelper} from './ModalHelper';
import {StripeHelper} from './StripeHelper';

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

export interface FillOrgModalParams {
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

    async fillOrgModal(params: FillOrgModalParams) {
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
        const main = this.page.getByRole('main');
        if (params.name) await main.getByLabel('Vor- & Nachname').fill(params.name);
        if (params.street) await main.getByLabel('Straße & Hausnummer').fill(params.street);
        if (params.zip) await main.getByLabel('PLZ').fill(params.zip);
        if (params.city) await main.getByLabel('Ort').fill(params.city);
        if (params.acceptAgb) await main.getByRole('checkbox', {name: /allgemeinen geschäftsbedingungen/i}).check();
        if (params.waiveWithdrawal) await main.getByRole('checkbox', {name: /widerrufsrecht/i}).check();
    }

    async fillProfileForm(params: FillProfileFormParams) {
        if (params.name) await this.page.locator('.form-control').filter({hasText: 'Dein Name'}).locator('input').fill(params.name);
        if (params.ftpSlug) await this.page.locator('.form-control').filter({hasText: 'FTP Upload Ordner'}).locator('input').fill(params.ftpSlug);
        if (params.copyright) await this.page.locator('.form-control').filter({hasText: 'Standard-Urheber'}).locator('input').fill(params.copyright);
    }

    async fillStripeForm(_stripeFrame: unknown, card: CreditCard) {
        await StripeHelper.fillStripeForm(this.page, card);
    }
}
