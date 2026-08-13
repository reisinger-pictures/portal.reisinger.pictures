import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../../test-setup';
import { CartItemList } from '../components/CartItemList';
import type { CartItem } from '../../../logic/CartContext';
import { formatMoney } from '../../../logic/utils';

const mockItems: CartItem[] = [
    {
        photoId: 'p1',
        tier: 'web' as const,
        price: 1500,
        filename: 'photo1.jpg',
        thumb_url: '/thumbs/p1.jpg',
        useCaseName: 'Web-Nutzung',
    },
    {
        photoId: 'p2',
        tier: 'print' as const,
        price: 2500,
        thumb_url: '/thumbs/p2.jpg',
        useCaseName: 'Print-Nutzung',
    },
];

const defaultProps = {
    items: [] as CartItem[],
    handleUpdateItem: vi.fn(),
    removeFromCart: vi.fn(),
    hasQuotes: false,
    totalAmount: 0,
    volumeLicensing: undefined,
};

function renderList(props: Partial<typeof defaultProps> = {}) {
    return renderWithProviders(
        <CartItemList {...defaultProps} {...props} />,
    );
}

describe('CartItemList', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    it('renders empty state when no items', () => {
        renderList();

        expect(screen.getByText('Deine Lizenzen')).toBeInTheDocument();
        expect(screen.getByText('Gesamtsumme')).toBeInTheDocument();
        expect(screen.getByText(formatMoney(0))).toBeInTheDocument();
    });

    it('renders cart items with thumbnails and names', () => {
        renderList({ items: mockItems, totalAmount: 4000 });

        const imgs = screen.getAllByAltText('Vorschau');
        expect(imgs).toHaveLength(mockItems.length);
        mockItems.forEach((item, idx) => {
            expect(imgs[idx]).toHaveAttribute('src', item.thumb_url);
            expect(screen.getByText(item.useCaseName!)).toBeInTheDocument();
        });
    });

    it('displays correct total amount', () => {
        renderList({ items: mockItems, totalAmount: 4000 });

        expect(screen.getByText(formatMoney(4000))).toBeInTheDocument();
    });

    it('calls removeFromCart when delete button is clicked', async () => {
        const removeFromCart = vi.fn();
        const user = (await import('@testing-library/user-event')).default;
        user.setup();

        renderList({ items: mockItems, removeFromCart, totalAmount: 4000 });

        const removeButtons = screen.getAllByTitle('Entfernen');
        expect(removeButtons).toHaveLength(2);

        await user.click(removeButtons[0]);
        expect(removeFromCart).toHaveBeenCalledWith('p1');
    });

    it('displays quote items with textarea and fallback price', () => {
        const quoteItems: CartItem[] = [
            {
                photoId: 'q1',
                tier: 'web' as const,
                price: 0,
                isQuote: true,
                notes: 'My special request',
            },
        ];

        renderList({ items: quoteItems, hasQuotes: true });

        expect(screen.getByText('Individuelles Angebot')).toBeInTheDocument();
        expect(screen.getByText('(Preis auf Anfrage)')).toBeInTheDocument();
        const dashElements = screen.getAllByText('--- €');
        expect(dashElements.length).toBe(2);

        const textarea = screen.getByPlaceholderText(/Beschreibe deine speziellen Nutzungsanforderungen/);
        expect(textarea).toBeInTheDocument();
        expect(textarea).toHaveValue('My special request');
    });

    it('calls handleUpdateItem when quote textarea changes', async () => {
        const handleUpdateItem = vi.fn();
        const user = (await import('@testing-library/user-event')).default;
        user.setup();

        const quoteItems: CartItem[] = [
            {
                photoId: 'q1',
                tier: 'web' as const,
                price: 0,
                isQuote: true,
                notes: '',
            },
        ];

        renderList({ items: quoteItems, handleUpdateItem, hasQuotes: true });

        const textarea = screen.getByPlaceholderText(/Beschreibe deine speziellen Nutzungsanforderungen/);
        await user.type(textarea, 'New request');

        expect(handleUpdateItem).toHaveBeenCalled();
    });

    it('renders volume licensing banner when enabled', () => {
        renderList({
            items: mockItems,
            totalAmount: 4000,
            volumeLicensing: {
                tierIndex: 2 as const,
                isMaxTier: false,
                pricePerItemCents: 2500,
                totalCents: 5000,
                nextTierCount: 10,
                nextTierLabel: '20 Bilder (20 €)',
                tiers: [],
                isVolumePricing: true,
            },
        });

        expect(screen.getByText('Mengenrabatt')).toBeInTheDocument();
        expect(screen.getByText(/Noch 10.*er.*bis 20 Bilder \(20 €\)/)).toBeInTheDocument();
    });

    it('shows "Bester Rabatt aktiv" when max tier is active', () => {
        renderList({
            items: mockItems,
            totalAmount: 4000,
            volumeLicensing: {
                tierIndex: 2 as const,
                isMaxTier: true,
                pricePerItemCents: 2000,
                totalCents: 4000,
                nextTierCount: 0,
                nextTierLabel: '',
                tiers: [],
                isVolumePricing: true,
            },
        });

        expect(screen.getByText('Bester Rabatt aktiv')).toBeInTheDocument();
    });

    it('shows per-item volume price instead of individual price', () => {
        renderList({
            items: mockItems,
            totalAmount: 4000,
            volumeLicensing: {
                tierIndex: 1 as const,
                isMaxTier: false,
                pricePerItemCents: 3000,
                totalCents: 6000,
                nextTierCount: 8,
                nextTierLabel: '10 Bilder (25 €)',
                tiers: [],
                isVolumePricing: true,
            },
        });

        const priceElements = screen.getAllByText(formatMoney(3000));
        expect(priceElements.length).toBeGreaterThanOrEqual(2);
        const volumeLabels = screen.getAllByText('(Volumenpreis)');
        expect(volumeLabels.length).toBe(2);
    });

    it('shows item count × pricePerItem in total section for volume', () => {
        renderList({
            items: mockItems,
            totalAmount: 6000,
            volumeLicensing: {
                tierIndex: 1 as const,
                isMaxTier: false,
                pricePerItemCents: 3000,
                totalCents: 6000,
                nextTierCount: 8,
                nextTierLabel: '10 Bilder (25 €)',
                tiers: [],
                isVolumePricing: true,
            },
        });

        expect(screen.getByText(/2 Bilder × 30\.00 € \(Tier 1\)/)).toBeInTheDocument();
    });

    it('hides volume banner when not in volume licensing mode', () => {
        renderList({ items: mockItems, totalAmount: 4000 });

        expect(screen.queryByText('Mengenrabatt')).not.toBeInTheDocument();
        expect(screen.queryByText('Bester Rabatt aktiv')).not.toBeInTheDocument();
    });

    it('renders modifier names when present', () => {
        const itemsWithModifiers: CartItem[] = [
            {
                photoId: 'p3',
                tier: 'web' as const,
                price: 2000,
                useCaseName: 'Web-Nutzung',
                modifierNames: ['Exklusivrecht', 'Weltweit'],
            },
        ];

        renderList({ items: itemsWithModifiers, totalAmount: 2000 });

        expect(screen.getByText('Exklusivrecht, Weltweit')).toBeInTheDocument();
    });

    it('shows "Deine Lizenzen & Anfragen" when hasQuotes is true', () => {
        renderList({ items: mockItems, hasQuotes: true, totalAmount: 4000 });

        expect(screen.getByText('Deine Lizenzen & Anfragen')).toBeInTheDocument();
    });

    it('shows "Deine Lizenzen" when hasQuotes is false', () => {
        renderList({ items: mockItems, hasQuotes: false, totalAmount: 4000 });

        expect(screen.getByText('Deine Lizenzen')).toBeInTheDocument();
    });

    it('shows mock data tax notice', () => {
        renderList();

        expect(screen.getByText(/Steuerfrei gem/)).toBeInTheDocument();
    });
});
