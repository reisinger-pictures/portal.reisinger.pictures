import {describe, expect, it} from 'vitest';
import {
    calculateContractTotal,
    normalizeContractSnapshot,
    type ContractWireDiscount,
    type ContractWireItem,
} from '../contractPricing';
import {normalizeManagementContract, type Contract} from '../useContractManagement';

type ManagementContractInput = Omit<Contract, 'total'> & {total?: number};

const wireItem = (overrides: Partial<ContractWireItem> = {}): ContractWireItem => ({
    type: 'item',
    description: 'Fotoshooting',
    notes: '',
    qty: 2,
    price: 25000,
    ...overrides,
});

const wireDiscount = (overrides: Partial<ContractWireDiscount> = {}): ContractWireDiscount => ({
    type: 'discount_fixed',
    description: 'Bonus',
    notes: '',
    price: 10000,
    ...overrides,
});

const contract = (overrides: Partial<ManagementContractInput> = {}): ManagementContractInput => ({
    id: 'contract-1',
    type: 'contract',
    status: 'active',
    billing_details: null,
    items: [wireItem()],
    discounts: [],
    terms_html: '',
    available_roles: [],
    allow_multiple_roles_per_signer: false,
    join_token: null,
    closes_at: null,
    template_id: null,
    expires_at: null,
    created_at: '2026-01-01T00:00:00Z',
    updated_at: '2026-01-01T00:00:00Z',
    ...overrides,
});

const fallbackTotal = (input: ManagementContractInput): number => {
    const snapshot = normalizeContractSnapshot(input.items, input.discounts);
    return calculateContractTotal(snapshot);
};

/**
 * `normalizeManagementContract` is the money-facing boundary between the
 * management API and the editor: a server-provided `total` must be trusted only
 * when it is a non-negative safe integer, otherwise the client must recompute
 * it from the normalized snapshot (legacy fallback).
 */
describe('normalizeManagementContract total handling', () => {
    it('preserves a valid non-negative safe-integer server total', () => {
        // The computed snapshot total would be 50000; the server value wins.
        const result = normalizeManagementContract(contract({total: 60000}));

        expect(result.total).toBe(60000);
    });

    it('preserves a server total of zero', () => {
        const result = normalizeManagementContract(contract({total: 0}));

        expect(result.total).toBe(0);
    });

    it('falls back to the computed snapshot total when the server total is missing', () => {
        const input = contract();

        expect(normalizeManagementContract(input).total).toBe(fallbackTotal(input));
        expect(normalizeManagementContract(input).total).toBe(50000);
    });

    it('falls back when the server total is not an integer', () => {
        const input = contract({total: 50000.5});

        expect(normalizeManagementContract(input).total).toBe(fallbackTotal(input));
    });

    it('falls back when the server total is NaN or Infinity', () => {
        expect(normalizeManagementContract(contract({total: Number.NaN})).total)
            .toBe(fallbackTotal(contract()));
        expect(normalizeManagementContract(contract({total: Number.POSITIVE_INFINITY})).total)
            .toBe(fallbackTotal(contract()));
    });

    it('falls back when the server total is negative', () => {
        const input = contract({total: -1});

        expect(normalizeManagementContract(input).total).toBe(fallbackTotal(input));
    });

    it('falls back when the server total is not a safe integer', () => {
        const input = contract({total: Number.MAX_SAFE_INTEGER + 1});

        expect(normalizeManagementContract(input).total).toBe(fallbackTotal(input));
    });

    it('recomputes an ordered discount chain when falling back', () => {
        const input = contract({
            items: [wireItem({price: 25000, qty: 2})],
            discounts: [wireDiscount({price: 10000})],
        });

        // 2 * 25000 - 10000 = 40000.
        expect(normalizeManagementContract(input).total).toBe(40000);
    });
});
