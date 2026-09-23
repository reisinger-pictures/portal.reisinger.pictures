import {afterEach, describe, expect, it, vi} from 'vitest';
import {
    checkoutSessionStorageKey,
    clearCheckoutSession,
    createCheckoutCartMarker,
    loadOrCreateCheckoutSession,
    parseCheckoutSession,
    replaceCheckoutSession
} from '../checkoutSession';

const firstKey = '11111111-1111-4111-8111-111111111111';
const secondKey = '22222222-2222-4222-8222-222222222222';
const thirdKey = '33333333-3333-4333-8333-333333333333';

const baseItem = {
    photoId: 'photo-1',
    tier: 'web' as const,
    useCaseId: 'use-case-1',
    modifierIds: ['modifier-b', 'modifier-a'],
    isQuote: false
};

afterEach(() => {
    sessionStorage.clear();
});

describe('checkout session recovery', () => {
    it('creates a deterministic marker without storing raw cart or quote data', () => {
        const first = createCheckoutCartMarker([
            baseItem,
            {photoId: 'photo-2', tier: 'print', modifierIds: [], isQuote: false}
        ], 'signed-quote-token');
        const equivalent = createCheckoutCartMarker([
            {...baseItem, modifierIds: ['modifier-a', 'modifier-b']},
            {photoId: 'photo-2', tier: 'print', isQuote: false}
        ], 'signed-quote-token');
        const changed = createCheckoutCartMarker([baseItem], 'different-quote-token');

        expect(first).toMatch(/^cart-v1-[0-9a-f]{16}$/);
        expect(equivalent).toBe(first);
        expect(changed).not.toBe(first);
        expect(first).not.toContain('photo-1');
        expect(first).not.toContain('signed-quote-token');
    });

    it('reuses a valid per-user key and replaces it when the cart marker changes', () => {
        const createKey = vi.fn()
            .mockReturnValueOnce(firstKey)
            .mockReturnValueOnce(secondKey);
        const marker = createCheckoutCartMarker([baseItem]);

        const initial = loadOrCreateCheckoutSession('user-1', marker, createKey, sessionStorage);
        const recovered = loadOrCreateCheckoutSession('user-1', marker, createKey, sessionStorage);
        const changed = loadOrCreateCheckoutSession('user-1', 'cart-v1-0000000000000000', createKey, sessionStorage);
        const replaced = replaceCheckoutSession('user-1', marker, () => thirdKey, sessionStorage);

        expect(recovered).toEqual(initial);
        expect(changed.idempotencyKey).toBe(secondKey);
        expect(replaced.idempotencyKey).toBe(thirdKey);
        expect(createKey).toHaveBeenCalledTimes(2);
    });

    it('isolates recovery records by user', () => {
        const marker = createCheckoutCartMarker([baseItem]);
        const firstUser = loadOrCreateCheckoutSession('user-1', marker, () => firstKey, sessionStorage);
        const secondUser = loadOrCreateCheckoutSession('user-2', marker, () => secondKey, sessionStorage);

        expect(firstUser.idempotencyKey).not.toBe(secondUser.idempotencyKey);
        expect(sessionStorage.getItem(checkoutSessionStorageKey('user-1'))).toContain(firstKey);
        expect(sessionStorage.getItem(checkoutSessionStorageKey('user-2'))).toContain(secondKey);
    });

    it('rejects malformed records and replaces records containing forbidden payment data', () => {
        const marker = createCheckoutCartMarker([baseItem]);
        const unsafeRecord = JSON.stringify({
            version: 1,
            userId: 'user-1',
            cartMarker: marker,
            idempotencyKey: firstKey,
            client_secret: 'cs_test_must_not_persist',
            payment_intent_id: 'pi_must_not_persist'
        });
        const storageKey = checkoutSessionStorageKey('user-1');
        sessionStorage.setItem(storageKey, unsafeRecord);

        expect(parseCheckoutSession(unsafeRecord, 'user-1', marker)).toBeNull();
        const recovered = loadOrCreateCheckoutSession('user-1', marker, () => secondKey, sessionStorage);
        const persisted = sessionStorage.getItem(storageKey);

        expect(recovered.idempotencyKey).toBe(secondKey);
        expect(persisted).not.toContain('client_secret');
        expect(persisted).not.toContain('payment_intent_id');
        expect(persisted).not.toContain('cs_test_must_not_persist');
    });

    it('clears only the requested user recovery record', () => {
        const marker = createCheckoutCartMarker([baseItem]);
        loadOrCreateCheckoutSession('user-1', marker, () => firstKey, sessionStorage);
        loadOrCreateCheckoutSession('user-2', marker, () => secondKey, sessionStorage);

        clearCheckoutSession('user-1', sessionStorage);

        expect(sessionStorage.getItem(checkoutSessionStorageKey('user-1'))).toBeNull();
        expect(sessionStorage.getItem(checkoutSessionStorageKey('user-2'))).toContain(secondKey);
    });
});
