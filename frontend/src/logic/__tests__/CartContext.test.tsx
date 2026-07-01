import { describe, it, expect, vi, beforeEach } from 'vitest';
import { renderHook } from '@testing-library/react';
import { CartContext, useCart } from '../CartContext';
import type { ReactNode } from 'react';

const mockContextValue = {
    items: [
        { photoId: 'p1', filename: 'Photo 1', tier: 'web' as const, price: 5000, useCaseId: 'uc1', useCaseName: 'Web-Nutzung', modifierIds: [], modifierNames: [] },
    ],
    addToCart: vi.fn(),
    removeFromCart: vi.fn(),
    clearCart: vi.fn(),
    totalAmount: 5000,
    itemCount: 1,
};

function createWrapper(value = mockContextValue) {
    return function Wrapper({ children }: { children: ReactNode }) {
        return (
            <CartContext.Provider value={value}>
                {children}
            </CartContext.Provider>
        );
    };
}

describe('useCart', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('throws error when used outside of CartProvider', () => {
        expect(() => {
            renderHook(() => useCart());
        }).toThrow('useCart must be used within CartProvider');
    });

    it('returns context value when used within CartProvider', () => {
        const { result } = renderHook(() => useCart(), { wrapper: createWrapper() });
        expect(result.current.items).toHaveLength(1);
        expect(result.current.items[0].photoId).toBe('p1');
        expect(result.current.totalAmount).toBe(5000);
        expect(result.current.itemCount).toBe(1);
    });

    it('provides addToCart function', () => {
        const { result } = renderHook(() => useCart(), { wrapper: createWrapper() });
        const newItem = { photoId: 'p2', filename: 'Photo 2', tier: 'print' as const, price: 15000, useCaseId: 'uc2', useCaseName: 'Print', modifierIds: [], modifierNames: [] };
        result.current.addToCart(newItem);
        expect(mockContextValue.addToCart).toHaveBeenCalledWith(newItem);
    });

    it('provides removeFromCart function', () => {
        const { result } = renderHook(() => useCart(), { wrapper: createWrapper() });
        result.current.removeFromCart('p1');
        expect(mockContextValue.removeFromCart).toHaveBeenCalledWith('p1');
    });

    it('provides clearCart function', () => {
        const { result } = renderHook(() => useCart(), { wrapper: createWrapper() });
        result.current.clearCart();
        expect(mockContextValue.clearCart).toHaveBeenCalled();
    });

    it('returns empty items array from provider', () => {
        const emptyValue = {
            ...mockContextValue,
            items: [],
            totalAmount: 0,
            itemCount: 0,
        };
        const { result } = renderHook(() => useCart(), { wrapper: createWrapper(emptyValue) });
        expect(result.current.items).toEqual([]);
        expect(result.current.totalAmount).toBe(0);
        expect(result.current.itemCount).toBe(0);
    });
});
