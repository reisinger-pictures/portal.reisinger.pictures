import { describe, it, expect, vi, beforeEach, afterEach, type Mock } from 'vitest';
import { screen, waitFor, fireEvent, within } from '@testing-library/react';
import { renderWithProviders } from '../../test-setup';
import WatermarkSettingsCard from '../management/components/WatermarkSettingsCard';

// --------------------------------------------------------------------------
// Module-level mocks (hoisted by vitest)
// --------------------------------------------------------------------------

vi.mock('../../logic/useSettings', () => ({
    useSettings: vi.fn(),
}));

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../../ui/components/UIContext', () => ({
    useUI: vi.fn(),
}));

vi.mock('../../logic/useBrand', () => ({
    useBrand: vi.fn(),
}));

vi.mock('../../logic/watermarkRenderer', () => ({
    renderSvgToDataUrl: vi.fn(),
    renderSvgToCanvas: vi.fn(),
}));

// --------------------------------------------------------------------------
// Imports after mocks — these are the mocked versions
// --------------------------------------------------------------------------

import { useSettings } from '../../logic/useSettings';
import { usePermissions } from '../../logic/usePermissions';
import { useUI } from '../../ui/components/UIContext';
import { useBrand } from '../../logic/useBrand';
import { renderSvgToDataUrl, renderSvgToCanvas } from '../../logic/watermarkRenderer';

// --------------------------------------------------------------------------
// Constants
// --------------------------------------------------------------------------

const defaultPermissions = {
    isStaff: false,
    isSuperAdmin: false,
    isAdmin: true,
    isPhotographer: false,
    isOrgAdmin: false,
    canEditMetadata: false,
    isPowerUser: false,
    canAccessB2BFeatures: false,
    canAccessProjectsBoard: false,
    canAccessProductionBoard: false,
    showOrgsSection: false,
    showCRM: false,
    showInvoicing: false,
    showPayouts: false,
};

// --------------------------------------------------------------------------
// Suite
// --------------------------------------------------------------------------

type ShowToast = (type: 'success' | 'error' | 'info', text: string) => void;

let showToastMock: Mock<ShowToast>;
let updateWatermarkMock: Mock<(formData: FormData) => Promise<void>>;

describe('WatermarkSettingsCard', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        // Kept as named references so the submit path can be asserted on the
        // toast and on the payload the backend would receive.
        showToastMock = vi.fn<ShowToast>();
        updateWatermarkMock = vi
            .fn<(formData: FormData) => Promise<void>>()
            .mockResolvedValue(undefined);

        // --- mock implementations for the exported render functions ---
        vi.mocked(renderSvgToDataUrl).mockResolvedValue('data:image/png;base64,test');
        vi.mocked(renderSvgToCanvas).mockResolvedValue(null);

        // --- mock all consumed hooks ---
        vi.mocked(useSettings).mockReturnValue({
            watermark: undefined,
            updateWatermark: updateWatermarkMock,
        });
        vi.mocked(usePermissions).mockReturnValue(defaultPermissions);
        vi.mocked(useUI).mockReturnValue({
            showToast: showToastMock,
            confirm: vi.fn(),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
        vi.mocked(useBrand).mockReturnValue({
            brand: 'rp',
            features: { coupons: true, orgs: true },
            config: null,
            logoSrc: '',
            svgUrl: '/brand.svg',
            portalName: 'Test Portal',
            impressumUrl: '',
            theme: { light: 'rp-light', dark: 'rp-dark' },
            primaryColor: '#1E5631',
            secondaryColor: '#A4B494',
        });

        // --- default fetch: return a valid SVG blob ---
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: true,
                blob: () =>
                    Promise.resolve(
                        new Blob(['<svg>test</svg>'], { type: 'image/svg+xml' }),
                    ),
            }),
        );
    });

    afterEach(() => {
        vi.unstubAllGlobals();
        vi.restoreAllMocks();
    });

    // ----------------------------------------------------------------------
    // Helpers
    // ----------------------------------------------------------------------

    const preview = () => screen.getByTestId('watermark-preview');
    const previewSpinner = () => preview().querySelector('.loading-spinner');
    const expectPreviewError = () =>
        expect(within(preview()).getByRole('alert')).toHaveTextContent(
            'Brand-Logo konnte nicht geladen werden.',
        );

    // ----------------------------------------------------------------------
    // Access control
    // ----------------------------------------------------------------------

    it('renders nothing when isAdmin is false', () => {
        vi.mocked(usePermissions).mockReturnValue({
            ...defaultPermissions,
            isAdmin: false,
        });

        const { container } = renderWithProviders(<WatermarkSettingsCard />);
        expect(container.innerHTML).toBe('');
    });

    // ----------------------------------------------------------------------
    // Slider → single render invocation
    // ----------------------------------------------------------------------

    it('calls renderSvgToDataUrl once per slider change', async () => {
        renderWithProviders(<WatermarkSettingsCard />);

        // Wait for the initial preview image to appear (initial render completed)
        await waitFor(() => {
            expect(screen.getByAltText('Watermark Preview')).toBeInTheDocument();
        });

        // Reset call-count so we only measure slider-driven invocations
        vi.mocked(renderSvgToDataUrl).mockClear();

        // Simulate a slider change to opacity = 0.5
        const slider = screen.getByRole('slider');
        fireEvent.change(slider, { target: { value: '0.5' } });

        // The onChange handler calls renderSvgToDataUrl exactly once
        await waitFor(() => {
            expect(vi.mocked(renderSvgToDataUrl)).toHaveBeenCalledTimes(1);
        });

        // Verify the correct arguments were passed
        expect(vi.mocked(renderSvgToDataUrl)).toHaveBeenCalledWith(
            expect.any(Blob),
            0.5,
            500,
        );
    });

    // ----------------------------------------------------------------------
    // Preview outcome: ready, loading, failed
    // ----------------------------------------------------------------------

    it('shows the rendered preview and no error state when the render succeeds', async () => {
        renderWithProviders(<WatermarkSettingsCard />);

        await waitFor(() => {
            expect(within(preview()).getByAltText('Watermark Preview')).toHaveAttribute(
                'src',
                'data:image/png;base64,test',
            );
        });
        expect(within(preview()).queryByRole('alert')).toBeNull();
        expect(previewSpinner()).toBeNull();
    });

    it('shows the loading state while the first render is in flight', async () => {
        // A render that never settles is a spinner; a render that settles with
        // null is a failure. These must not look alike.
        vi.mocked(renderSvgToDataUrl).mockReturnValue(new Promise(() => {}));

        renderWithProviders(<WatermarkSettingsCard />);

        await waitFor(() => {
            expect(screen.getByText(/Lade Logo/i)).toBeInTheDocument();
        });
        expect(previewSpinner()).not.toBeNull();
        expect(within(preview()).queryByRole('alert')).toBeNull();
    });

    it('shows an error instead of a spinner when the render returns null', async () => {
        // This is the production failure: the CSP blocks the image source, the
        // renderer resolves null, and nothing but a console line used to remain.
        vi.mocked(renderSvgToDataUrl).mockResolvedValue(null);

        renderWithProviders(<WatermarkSettingsCard />);

        await waitFor(expectPreviewError);
        expect(previewSpinner()).toBeNull();
        expect(screen.queryByText(/Lade Logo/i)).not.toBeInTheDocument();
    });

    it('shows an error instead of a spinner when the render rejects', async () => {
        // The inner render promise was neither returned nor caught, so a
        // rejection used to escape unhandled and leave the state untouched.
        vi.mocked(renderSvgToDataUrl).mockRejectedValue(new Error('CSP refused blob:'));

        renderWithProviders(<WatermarkSettingsCard />);

        await waitFor(expectPreviewError);
        expect(previewSpinner()).toBeNull();
    });

    it('clears the error state when a later render succeeds', async () => {
        vi.mocked(renderSvgToDataUrl)
            .mockResolvedValueOnce(null)
            .mockResolvedValue('data:image/png;base64,recovered');

        renderWithProviders(<WatermarkSettingsCard />);
        await waitFor(expectPreviewError);

        fireEvent.change(screen.getByRole('slider'), { target: { value: '0.5' } });

        await waitFor(() => {
            expect(within(preview()).getByAltText('Watermark Preview')).toHaveAttribute(
                'src',
                'data:image/png;base64,recovered',
            );
        });
        expect(within(preview()).queryByRole('alert')).toBeNull();
    });

    // ----------------------------------------------------------------------
    // No render call when blob is unavailable
    // ----------------------------------------------------------------------

    it('only calls renderSvgToDataUrl when serverSvgBlob is available', async () => {
        // Make fetch fail → serverSvgBlob stays null
        vi.stubGlobal(
            'fetch',
            vi.fn().mockResolvedValue({
                ok: false,
                blob: () => Promise.resolve(null),
            }),
        );

        renderWithProviders(<WatermarkSettingsCard />);

        // A refused logo request is a failure, not a load that never finishes:
        // the card has to say so rather than spin forever.
        await waitFor(expectPreviewError);
        expect(previewSpinner()).toBeNull();

        // renderSvgToDataUrl should have been called 0 times so far
        // (fetch failed, so the initial render never fired)
        expect(vi.mocked(renderSvgToDataUrl)).not.toHaveBeenCalled();
        vi.mocked(renderSvgToDataUrl).mockClear();

        // Simulate a slider change
        const slider = screen.getByRole('slider');
        fireEvent.change(slider, { target: { value: '0.5' } });

        // renderSvgToDataUrl must NOT be called because serverSvgBlob is null
        expect(vi.mocked(renderSvgToDataUrl)).not.toHaveBeenCalled();
    });

    it('shows an error when the logo request rejects', async () => {
        vi.stubGlobal('fetch', vi.fn().mockRejectedValue(new TypeError('Failed to fetch')));

        renderWithProviders(<WatermarkSettingsCard />);

        await waitFor(expectPreviewError);
        expect(previewSpinner()).toBeNull();
        expect(vi.mocked(renderSvgToDataUrl)).not.toHaveBeenCalled();
    });

    // ----------------------------------------------------------------------
    // Submit: the card must never claim a result it did not achieve
    // ----------------------------------------------------------------------

    describe('submit', () => {
        const pngBlob = (label: string) => new Blob([label], { type: 'image/png' });

        const BUCKET_FIELDS = [
            'bucket_500',
            'bucket_1000',
            'bucket_2000',
            'bucket_500_sel',
            'bucket_1000_sel',
            'bucket_2000_sel',
        ] as const;

        const LOAD_ERROR = 'Brand-Logo konnte nicht geladen werden.';
        const SAVED = 'Wasserzeichen erfolgreich generiert und gespeichert!';

        const submitButton = () => screen.getByRole('button', { name: /generieren/i });

        /** Render the card and wait until submitting is possible (logo loaded). */
        const renderReadyCard = async () => {
            renderWithProviders(<WatermarkSettingsCard />);
            await waitFor(() => expect(submitButton()).toBeEnabled());
        };

        const submittedPayload = (): FormData => {
            const [first] = updateWatermarkMock.mock.calls;
            if (!first) throw new Error('updateWatermark was not called');
            return first[0];
        };

        it('does not submit and does not claim success when every render returned null', async () => {
            // The production failure: the renderer resolves null for all six
            // buckets, the request used to go out carrying only opacity + svg,
            // and the card reported a watermark that was never written.
            vi.mocked(renderSvgToCanvas).mockResolvedValue(null);

            await renderReadyCard();
            fireEvent.click(submitButton());

            await waitFor(() => {
                expect(showToastMock).toHaveBeenCalledWith('error', LOAD_ERROR);
            });
            expect(updateWatermarkMock).not.toHaveBeenCalled();
            expect(showToastMock).not.toHaveBeenCalledWith('success', expect.any(String));
        });

        it('submits nothing when a single bucket fails to render', async () => {
            // Partial is not "good enough": ImageProcessor::resolveWatermarkAsset
            // has no fallback for a missing bucket file — it returns null and the
            // caller deletes the output, so every size in that bucket stops being
            // produced. A partial write would also leave the newly saved svg next
            // to rasters rendered from the previous logo and opacity.
            vi.mocked(renderSvgToCanvas)
                .mockResolvedValueOnce(pngBlob('500'))
                .mockResolvedValueOnce(pngBlob('1000'))
                .mockResolvedValueOnce(pngBlob('2000'))
                .mockResolvedValueOnce(null);

            await renderReadyCard();
            fireEvent.click(submitButton());

            await waitFor(() => {
                expect(showToastMock).toHaveBeenCalledWith('error', LOAD_ERROR);
            });
            // The three buckets that did render are discarded rather than saved.
            expect(updateWatermarkMock).not.toHaveBeenCalled();
            expect(showToastMock).not.toHaveBeenCalledWith('success', expect.any(String));
        });

        it('submits all six buckets and reports success when every render succeeded', async () => {
            vi.mocked(renderSvgToCanvas).mockResolvedValue(pngBlob('png'));

            await renderReadyCard();
            fireEvent.click(submitButton());

            await waitFor(() => {
                expect(showToastMock).toHaveBeenCalledWith('success', SAVED);
            });

            const payload = submittedPayload();
            expect(payload.get('opacity')).toBe('0.15');
            expect(payload.get('svg')).toBeInstanceOf(Blob);
            for (const field of BUCKET_FIELDS) {
                expect(payload.get(field), `missing ${field}`).toBeInstanceOf(Blob);
            }
        });
    });
});
