import {beforeEach, describe, expect, it, vi} from 'vitest';
import {screen} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import type {Photo} from '../../../logic/useGallery';
import type {CartItem} from '../../../logic/CartContext';
import {formatMoney} from '../../../logic/utils';
import {useCart} from '../../../logic/CartContext';
import {useVolumeLicensing} from '../../../logic/useVolumeLicensing';
import {useUI} from '../../components/UIContext';
import VolumeLicensingCard from '../components/VolumeLicensingCard';
import {renderWithProviders} from '../../../test-setup';

vi.mock('../../../logic/CartContext', () => ({
    useCart: vi.fn(),
}));

vi.mock('../../../logic/useVolumeLicensing', () => ({
    useVolumeLicensing: vi.fn(),
}));

vi.mock('../../components/UIContext', () => ({
    useUI: vi.fn(),
}));

const photo: Photo = {
    id: 'displayed-photo',
    gallery_id: 'displayed-gallery',
    filename: 'displayed.jpg',
    lr_uuid: 'displayed-uuid',
    width: 1200,
    height: 800,
    url: '/displayed.jpg',
    thumb_url: '/displayed-thumb.jpg',
    title: 'Displayed photo',
    rating: 0,
    comment: '',
    gallery: {
        id: 'displayed-gallery',
        name: 'Displayed Gallery',
        slug: 'displayed-gallery',
        full_path: 'displayed-gallery',
        type: 'delivery',
        is_live: false,
        is_public: true,
        gallery_group_id: 'displayed-group',
    },
};

const items: CartItem[] = [
    {
        photoId: 'other-gallery-photo',
        tier: 'original',
        galleryId: 'other-gallery',
        price: 3000,
    },
];

describe('VolumeLicensingCard', () => {
    const addToCart = vi.fn();
    const showToast = vi.fn();
    const onAddToCart = vi.fn();

    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useCart).mockReturnValue({
            items,
            quoteToken: null,
            addToCart,
            setQuoteToken: vi.fn(),
            removeFromCart: vi.fn(),
            clearCart: vi.fn(),
            totalAmount: 0,
            itemCount: items.length,
        });
        vi.mocked(useUI).mockReturnValue({
            showToast,
            confirm: vi.fn(),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
        vi.mocked(useVolumeLicensing).mockReturnValue({
            isVolumePricing: true,
            tierIndex: 0,
            isMaxTier: false,
            pricePerItemCents: 6000,
            totalCents: 6000,
            nextTierCount: 2,
            nextTierLabel: 'Ab 3 Bildern 40,00 € pro Bild',
            tiers: [
                {minQuantity: 0, priceCents: 6000},
                {minQuantity: 3, priceCents: 4000},
            ],
        });
    });

    it('prices the displayed photo with its gallery preset instead of the first cart gallery', () => {
        renderWithProviders(
            <VolumeLicensingCard photo={photo} onAddToCart={onAddToCart} />,
        );

        expect(useVolumeLicensing).toHaveBeenCalledWith(items, 'displayed-gallery');
        expect(screen.getAllByText(formatMoney(6000)).length).toBeGreaterThanOrEqual(2);
        expect(screen.getByText(formatMoney(4000))).toBeInTheDocument();
    });

    it('adds the displayed gallery and the same custom-preset price to the cart', async () => {
        const user = userEvent.setup();
        renderWithProviders(
            <VolumeLicensingCard photo={photo} onAddToCart={onAddToCart} />,
        );

        await user.click(screen.getByRole('button', {name: 'In den Warenkorb'}));

        expect(addToCart).toHaveBeenCalledWith(expect.objectContaining({
            photoId: 'displayed-photo',
            galleryId: 'displayed-gallery',
            galleryGroupId: 'displayed-group',
            price: 6000,
        }));
        expect(onAddToCart).toHaveBeenCalledOnce();
    });
});
