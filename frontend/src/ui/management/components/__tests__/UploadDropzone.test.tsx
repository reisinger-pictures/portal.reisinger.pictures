import { describe, expect, it, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../../test-setup';
import UploadDropzone from '../UploadDropzone';

const { apiUploadMock, showToastMock } = vi.hoisted(() => ({
    apiUploadMock: vi.fn(),
    showToastMock: vi.fn(),
}));

vi.mock('../../../../api', () => ({
    apiUpload: apiUploadMock,
}));

vi.mock('../../../components/UIContext', () => ({
    useUI: () => ({ showToast: showToastMock }),
}));

describe('UploadDropzone', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        apiUploadMock.mockResolvedValue({ success: true });
    });

    it('sends each selected file through the shared multipart API helper', async () => {
        const user = userEvent.setup();
        const onUploadComplete = vi.fn();
        const {container} = renderWithProviders(
            <UploadDropzone galleryId="gallery-1" onUploadComplete={onUploadComplete} />,
        );
        const input = container.querySelector('input[type="file"]');
        expect(input).not.toBeNull();

        const file = new File(['image'], 'photo.jpg', {type: 'image/jpeg'});
        await user.upload(input as HTMLInputElement, file);

        await waitFor(() => expect(apiUploadMock).toHaveBeenCalledTimes(1));
        const [url, body] = apiUploadMock.mock.calls[0];
        expect(url).toBe('/api/management/upload');
        expect(body).toBeInstanceOf(FormData);
        expect(body.get('gallery_id')).toBe('gallery-1');
        expect(body.get('replace')).toBe('0');
        expect(body.get('file')).toBe(file);
        await waitFor(() => expect(onUploadComplete).toHaveBeenCalledTimes(1));
        expect(showToastMock).toHaveBeenCalledWith('success', '1 Bild(er) hochgeladen');
        expect(screen.queryByText('Lade hoch... Bitte warten.')).not.toBeInTheDocument();
    });

    // FE-3 regression: a rejected upload (e.g. HTTP 422) must surface an error
    // toast instead of silently no-oping.
    it('shows the thrown error message and skips onUploadComplete when every file fails', async () => {
        const user = userEvent.setup();
        const onUploadComplete = vi.fn();
        apiUploadMock.mockRejectedValue(new Error('Die Datei ist ungültig.'));
        const {container} = renderWithProviders(
            <UploadDropzone galleryId="gallery-1" onUploadComplete={onUploadComplete} />,
        );
        const input = container.querySelector('input[type="file"]');

        const file = new File(['image'], 'photo.jpg', {type: 'image/jpeg'});
        await user.upload(input as HTMLInputElement, file);

        await waitFor(() => expect(apiUploadMock).toHaveBeenCalledTimes(1));
        await waitFor(() => expect(showToastMock).toHaveBeenCalledWith('error', 'Die Datei ist ungültig.'));
        expect(onUploadComplete).not.toHaveBeenCalled();
        expect(showToastMock).not.toHaveBeenCalledWith('success', expect.anything());
    });
});
