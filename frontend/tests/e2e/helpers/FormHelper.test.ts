import type { Page } from '@playwright/test';
import { describe, expect, it, vi } from 'vitest';

vi.mock('./StripeHelper', () => ({
    StripeHelper: {
        fillStripeForm: vi.fn(),
    },
}));

import { FormHelper } from './FormHelper';
import type { ModalHelper } from './ModalHelper';

describe('FormHelper', () => {
    it('fills only the billing city textbox by its exact accessible name', async () => {
        const fill = vi.fn();
        const cityLocator = { fill };
        const mainLocator = {
            getByRole: vi.fn().mockReturnValue(cityLocator),
        };
        const page = {
            getByRole: vi.fn().mockReturnValue(mainLocator),
        } as unknown as Page;
        const modal = {} as unknown as ModalHelper;

        await new FormHelper(page, modal).fillCheckoutForm({city: 'Wien'});

        expect(page.getByRole).toHaveBeenCalledWith('main');
        expect(mainLocator.getByRole).toHaveBeenCalledWith('textbox', {name: 'Ort *', exact: true});
        expect(fill).toHaveBeenCalledWith('Wien');
    });

    it('fills profile fields by their scoped accessible names', async () => {
        const nameFill = vi.fn();
        const ftpSlugFill = vi.fn();
        const copyrightFill = vi.fn();
        const nameLocator = {fill: nameFill};
        const ftpSlugLocator = {fill: ftpSlugFill};
        const copyrightLocator = {fill: copyrightFill};
        const mainLocator = {
            getByRole: vi.fn()
                .mockReturnValueOnce(nameLocator)
                .mockReturnValueOnce(ftpSlugLocator)
                .mockReturnValueOnce(copyrightLocator),
        };
        const page = {
            getByRole: vi.fn().mockReturnValue(mainLocator),
        } as unknown as Page;
        const modal = {} as unknown as ModalHelper;

        await new FormHelper(page, modal).fillProfileForm({
            name: 'Max Mustermann',
            ftpSlug: 'max',
            copyright: 'Max Mustermann Fotografie',
        });

        expect(page.getByRole).toHaveBeenCalledWith('main');
        expect(mainLocator.getByRole).toHaveBeenNthCalledWith(1, 'textbox', {
            name: 'Dein Name',
            exact: true,
        });
        expect(mainLocator.getByRole).toHaveBeenNthCalledWith(2, 'textbox', {
            name: /^FTP Upload Ordner \(Slug\)/,
        });
        expect(mainLocator.getByRole).toHaveBeenNthCalledWith(3, 'textbox', {
            name: /^Standard-Urheber \(IPTC Copyright\)/,
        });
        expect(nameFill).toHaveBeenCalledWith('Max Mustermann');
        expect(ftpSlugFill).toHaveBeenCalledWith('max');
        expect(copyrightFill).toHaveBeenCalledWith('Max Mustermann Fotografie');
    });
});
