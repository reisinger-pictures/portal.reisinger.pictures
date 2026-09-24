import type { Page } from '@playwright/test';
import { describe, expect, it, vi } from 'vitest';
import { FormHelper } from './FormHelper';
import type { ModalHelper } from './ModalHelper';

describe('FormHelper', () => {
    it('fills only the billing city textbox', async () => {
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
        expect(mainLocator.getByRole).toHaveBeenCalledWith('textbox', {name: /^Ort\s*\*?$/});
        expect(fill).toHaveBeenCalledWith('Wien');
    });
});
