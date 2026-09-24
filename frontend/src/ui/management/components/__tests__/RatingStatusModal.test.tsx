import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import { renderWithProviders } from '../../../../test-setup';
import RatingStatusModal from '../RatingStatusModal';
import { fetcher } from '../../../../api';

vi.mock('../../../../api', () => ({
    fetcher: vi.fn(),
}));

const exportRatings = [
    {
        lr_uuid: 'photo-1',
        filename: 'portrait.jpg',
        avg_rating: 4,
        all_comments: 'Great portrait',
        thumb_url: '/media/portrait.jpg',
    },
];

const ratingStatus = {
    users: [
        {
            user_id: 'user-1',
            name: 'Alex Example',
            email: 'alex@example.com',
            rated_count: 1,
        },
    ],
    total_photos: 1,
};

describe('RatingStatusModal', () => {
    beforeEach(() => {
        vi.mocked(fetcher).mockReset();
        vi.stubGlobal('fetch', vi.fn());
    });

    afterEach(() => {
        vi.unstubAllGlobals();
    });

    it('loads both management responses through the shared refresh-aware fetcher', async () => {
        vi.mocked(fetcher)
            .mockResolvedValueOnce(exportRatings)
            .mockResolvedValueOnce(ratingStatus);

        renderWithProviders(
            <RatingStatusModal galleryId="gallery-1" isOpen={true} onClose={vi.fn()} />,
        );

        await waitFor(() => {
            expect(fetcher).toHaveBeenNthCalledWith(1, '/api/management/galleries/gallery-1/export');
            expect(fetcher).toHaveBeenNthCalledWith(2, '/api/management/galleries/gallery-1/rating-status');
        });

        expect(await screen.findByText('Alex Example')).toBeInTheDocument();
        expect(screen.getByText('portrait.jpg')).toBeInTheDocument();
        expect(fetch).not.toHaveBeenCalled();
    });

    it('shows the error state when a shared request fails', async () => {
        vi.mocked(fetcher).mockRejectedValue(new Error('request failed'));

        renderWithProviders(
            <RatingStatusModal galleryId="gallery-1" isOpen={true} onClose={vi.fn()} />,
        );

        expect(await screen.findByText(/Fehler beim Laden der Bewertungen\./)).toBeInTheDocument();
        expect(fetch).not.toHaveBeenCalled();
    });
});
