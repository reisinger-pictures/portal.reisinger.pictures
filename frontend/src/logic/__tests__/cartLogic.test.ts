import {describe, it, expect, vi, afterEach} from 'vitest';
import {
    cartItemSchema,
    cartSchema,
    addToCartPure,
    removeFromCartPure,
    calculateTotalAmount,
    loadCartItems,
} from '../cartLogic';
import {CartItem} from '../CartContext';

const item = (overrides: Partial<CartItem> = {}): CartItem => ({
    photoId: 'p1',
    tier: 'web',
    price: 1000,
    ...overrides,
});

describe('addToCartPure', () => {
    it('appends a new item to an empty cart', () => {
        expect(addToCartPure([], item())).toEqual([item()]);
    });

    it('appends items with different photoIds', () => {
        const result = addToCartPure([item({photoId: 'a'})], item({photoId: 'b'}));
        expect(result.map(i => i.photoId)).toEqual(['a', 'b']);
    });

    it('replaces an item with the same photoId (update, not duplicate)', () => {
        const result = addToCartPure([item({photoId: 'a', price: 1000})], item({photoId: 'a', price: 2000}));
        expect(result).toHaveLength(1);
        expect(result[0].price).toBe(2000);
    });

    it('preserves other items on update', () => {
        const result = addToCartPure(
            [item({photoId: 'a'}), item({photoId: 'b'})],
            item({photoId: 'a', price: 9999}),
        );
        expect(result.map(i => i.photoId)).toEqual(['a', 'b']);
        expect(result.find(i => i.photoId === 'a')?.price).toBe(9999);
    });
});

describe('removeFromCartPure', () => {
    it('removes the matching photoId', () => {
        const result = removeFromCartPure([item({photoId: 'a'}), item({photoId: 'b'})], 'a');
        expect(result.map(i => i.photoId)).toEqual(['b']);
    });

    it('leaves the cart unchanged when the photoId is absent', () => {
        const prev = [item({photoId: 'a'})];
        expect(removeFromCartPure(prev, 'x')).toEqual(prev);
    });

    it('returns an empty array for an empty cart', () => {
        expect(removeFromCartPure([], 'a')).toEqual([]);
    });
});

describe('calculateTotalAmount', () => {
    it('returns 0 for an empty cart', () => {
        expect(calculateTotalAmount([])).toBe(0);
    });

    it('sums the prices of all items', () => {
        expect(calculateTotalAmount([item({price: 1000}), item({price: 2500})])).toBe(3500);
    });

    it('excludes quote items (isQuote true)', () => {
        expect(calculateTotalAmount([item({price: 1000, isQuote: true}), item({price: 2500})])).toBe(2500);
    });

    it('includes items where isQuote is undefined (falsy)', () => {
        expect(calculateTotalAmount([item({price: 1000})])).toBe(1000);
    });

    it('includes items where isQuote is false', () => {
        expect(calculateTotalAmount([item({price: 1000, isQuote: false})])).toBe(1000);
    });

    it('handles zero and negative prices by plain summation', () => {
        expect(calculateTotalAmount([item({price: 0}), item({price: -500})])).toBe(-500);
    });
});

describe('cartSchema', () => {
    it('accepts a fully valid item', () => {
        expect(cartItemSchema.safeParse({photoId: '1', tier: 'web', price: 100}).success).toBe(true);
    });

    it('accepts all optionals omitted', () => {
        expect(cartSchema.safeParse([{photoId: '1', tier: 'print', price: 0}]).success).toBe(true);
    });

    it('rejects a missing required photoId', () => {
        expect(cartItemSchema.safeParse({tier: 'web', price: 100}).success).toBe(false);
    });

    it('rejects an invalid tier', () => {
        expect(cartItemSchema.safeParse({photoId: '1', tier: 'huge', price: 100}).success).toBe(false);
    });

    it('rejects a non-numeric price', () => {
        expect(cartItemSchema.safeParse({photoId: '1', tier: 'web', price: '100'}).success).toBe(false);
    });

    it('strips unknown extra fields', () => {
        const res = cartItemSchema.safeParse({photoId: '1', tier: 'web', price: 100, evil: 'x'});
        expect(res.success).toBe(true);
        if (res.success) expect('evil' in res.data).toBe(false);
    });
});

describe('loadCartItems', () => {
    afterEach(() => vi.restoreAllMocks());

    it('returns empty (no error) for null', () => {
        expect(loadCartItems(null)).toEqual({items: [], error: 'none'});
    });

    it('returns empty (invalid-json) for corrupted JSON', () => {
        expect(loadCartItems('THIS_IS_NOT_JSON')).toEqual({items: [], error: 'invalid-json'});
    });

    it('returns empty (schema) and warns for valid JSON that fails the schema', () => {
        const warnSpy = vi.spyOn(console, 'warn').mockImplementation(() => {});
        const result = loadCartItems(JSON.stringify([{invalid: 'data', price: 'no'}]));
        expect(result.error).toBe('schema');
        expect(result.items).toEqual([]);
        expect(warnSpy).toHaveBeenCalled();
    });

    it('loads validated items for a schema-conforming cart', () => {
        const stored = JSON.stringify([{photoId: '1', tier: 'web', price: 1500}]);
        const result = loadCartItems(stored);
        expect(result.error).toBe('none');
        expect(result.items).toHaveLength(1);
        expect(result.items[0].photoId).toBe('1');
    });
});
