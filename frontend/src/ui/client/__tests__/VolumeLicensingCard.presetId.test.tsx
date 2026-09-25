import {beforeEach, describe, expect, it, vi} from 'vitest';
import {screen, within} from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import useSWR from 'swr';
import type {Photo} from '../../../logic/useGallery';
import type {CartItem} from '../../../logic/CartContext';
import {formatMoney} from '../../../logic/utils';
import {useCart} from '../../../logic/CartContext';
import {useLicenseTerms} from '../../../logic/useLicenseTerms';
import {useUI} from '../../components/UIContext';
import VolumeLicensingCard from '../components/VolumeLicensingCard';
import {renderWithProviders} from '../../../test-setup';

// The real `useVolumeLicensing` runs here: only the two data sources it reads
// (SWR + the shared license-terms hook) are stubbed, so the card is rendered
// from an actual `/api/settings/license-terms` response shape.
vi.mock('swr', () => ({default: vi.fn()}));
vi.mock('../../../api', () => ({fetcher: vi.fn(), apiMutate: vi.fn()}));
vi.mock('../../../logic/useLicenseTerms', () => ({useLicenseTerms: vi.fn()}));
vi.mock('../../../logic/CartContext', () => ({useCart: vi.fn()}));
vi.mock('../../components/UIContext', () => ({useUI: vi.fn()}));

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
};

const items: CartItem[] = [
    {photoId: 'cart-photo', tier: 'original', galleryId: 'cart-gallery', price: 5000},
];

function mockTermsResponse(terms: unknown) {
    vi.mocked(useSWR).mockReturnValue({
        data: terms,
        error: undefined,
        isLoading: false,
        isValidating: false,
        mutate: vi.fn(),
    } as never);
}

describe('VolumeLicensingCard with a real volume-licensing payload', () => {
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
        } as never);
        vi.mocked(useUI).mockReturnValue({showToast, confirm: vi.fn()} as never);
        vi.mocked(useLicenseTerms).mockReturnValue({
            terms: {pricing_strategy: 'scope_licensing'},
            isLoading: false,
            updateTerms: vi.fn(),
        });
    });

    // Regression: `volume_pricing.preset_id` is the numeric `volume_presets.id`
    // primary key. The old `.trim()` on it threw "preset_id?.trim is not a
    // function", so the photo page was replaced by the ErrorBoundary and the
    // card (including "In den Warenkorb") never rendered.
    it('renders the licensing card and its price for a numeric preset id', async () => {
        const user = userEvent.setup();
        mockTermsResponse({
            'cart-gallery': {
                pricing_strategy: 'scope_licensing',
                volume_pricing: null,
            },
            'displayed-gallery': {
                pricing_strategy: 'volume_licensing',
                volume_pricing: {
                    preset_id: 7,
                    preset_name: 'Werbung',
                    tiers: [{min_quantity: 0, price_cents: 4000}],
                },
            },
        });

        renderWithProviders(<VolumeLicensingCard photo={photo} onAddToCart={onAddToCart} />);

        const card = within(screen.getByTestId('volume-pricing-card'));
        // Headline price plus the single tier row both render the resolved price.
        expect(card.getAllByText(formatMoney(4000)).length).toBeGreaterThanOrEqual(1);
        expect(screen.queryByText('Ein unerwarteter Fehler ist aufgetreten')).not.toBeInTheDocument();

        const button = card.getByRole('button', {name: 'In den Warenkorb', exact: true});
        expect(button).toBeEnabled();
        await user.click(button);
        expect(addToCart).toHaveBeenCalledWith(expect.objectContaining({
            photoId: 'displayed-photo',
            galleryId: 'displayed-gallery',
            price: 4000,
        }));
    });

    it('still resolves a stringified preset id', () => {
        mockTermsResponse({
            'cart-gallery': {
                pricing_strategy: 'scope_licensing',
                volume_pricing: null,
            },
            'displayed-gallery': {
                pricing_strategy: 'volume_licensing',
                volume_pricing: {
                    preset_id: '7',
                    preset_name: 'Werbung',
                    tiers: [{min_quantity: 0, price_cents: 4000}],
                },
            },
        });

        renderWithProviders(<VolumeLicensingCard photo={photo} onAddToCart={onAddToCart} />);

        const card = within(screen.getByTestId('volume-pricing-card'));
        expect(card.getAllByText(formatMoney(4000)).length).toBeGreaterThanOrEqual(1);
    });

    it('survives a malformed volume-pricing payload without unmounting the page', () => {
        mockTermsResponse({
            'cart-gallery': {
                pricing_strategy: 'scope_licensing',
                volume_pricing: null,
            },
            'displayed-gallery': {
                pricing_strategy: 'volume_licensing',
                volume_pricing: {
                    preset_id: {id: 7},
                    preset_name: 12,
                    tiers: [null, {min_quantity: 0, price_cents: 4000}],
                },
            },
        });

        expect(() => renderWithProviders(
            <VolumeLicensingCard photo={photo} onAddToCart={onAddToCart} />,
        )).not.toThrow();

        const card = within(screen.getByTestId('volume-pricing-card'));
        expect(card.getByRole('button', {name: 'In den Warenkorb', exact: true})).toBeEnabled();
    });
});
