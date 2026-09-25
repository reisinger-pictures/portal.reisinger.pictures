import { describe, expect, it } from 'vitest';
import type { InvoiceDiscount } from '../../api';
import {
    contractDiscountToEditor,
    contractDiscountToSnapshot,
    contractItemToEditor,
    contractItemToSnapshot,
    type ContractItem,
} from '../useContractManagement';

describe('contract snapshot serialization', () => {
    it('converts item prices between editor euros and API cents', () => {
        const snapshot: ContractItem = {
            type: 'item',
            description: 'Fotoshooting',
            notes: '',
            qty: 2,
            price: 25000,
            row_total: 50000,
        };

        const editorItem = contractItemToEditor(snapshot);

        expect(editorItem.price).toBe(250);
        expect(contractItemToSnapshot(editorItem)).toEqual({
            type: 'item',
            description: 'Fotoshooting',
            notes: '',
            qty: 2,
            price: 25000,
        });
    });

    it('serializes fixed discounts as cents and restores editor euros', () => {
        const editorDiscount: InvoiceDiscount = {
            type: 'discount_fixed',
            description: 'Bonus',
            notes: '',
            price: 5.5,
        };

        const snapshot = contractDiscountToSnapshot(editorDiscount);

        expect(snapshot.price).toBe(550);
        expect(contractDiscountToEditor(snapshot).price).toBe(5.5);
    });

    it('serializes percentage discounts as basis points and restores editor percent', () => {
        const editorDiscount: InvoiceDiscount = {
            type: 'discount_percent',
            description: 'Treue-Rabatt',
            notes: '',
            price: 33.33,
        };

        const snapshot = contractDiscountToSnapshot(editorDiscount);

        expect(snapshot.price).toBe(3333);
        expect(contractDiscountToEditor(snapshot).price).toBe(33.33);
    });
});
