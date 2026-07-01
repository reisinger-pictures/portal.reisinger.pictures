import { describe, it, expect, vi, beforeEach, afterEach } from 'vitest';
import { render, screen, waitFor, fireEvent } from '@testing-library/react';
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
    isCustomerManager: false,
    canEditMetadata: false,
    isPowerUser: false,
    canAccessB2BFeatures: false,
    showTenantsSection: false,
    showCRM: false,
    showInvoicing: false,
    showPayouts: false,
};

// --------------------------------------------------------------------------
// Suite
// --------------------------------------------------------------------------

describe('WatermarkSettingsCard', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        // --- mock implementations for the exported render functions ---
        vi.mocked(renderSvgToDataUrl).mockResolvedValue('data:image/png;base64,test');
        vi.mocked(renderSvgToCanvas).mockResolvedValue(null);

        // --- mock all consumed hooks ---
        vi.mocked(useSettings).mockReturnValue({
            watermark: undefined,
            updateWatermark: vi.fn(),
        });
        vi.mocked(usePermissions).mockReturnValue(defaultPermissions);
        vi.mocked(useUI).mockReturnValue({
            showToast: vi.fn(),
            confirm: vi.fn(),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
        vi.mocked(useBrand).mockReturnValue({
            logoSrc: '',
            portalName: 'Test Portal',
            impressumUrl: '',
            svgUrl: '/brand.svg',
            brand: 'rp' as const,
            isSrp: false,
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
    });

    // ----------------------------------------------------------------------
    // Access control
    // ----------------------------------------------------------------------

    it('renders nothing when isAdmin is false', () => {
        vi.mocked(usePermissions).mockReturnValue({
            ...defaultPermissions,
            isAdmin: false,
        });

        const { container } = render(<WatermarkSettingsCard />);
        expect(container.innerHTML).toBe('');
    });

    // ----------------------------------------------------------------------
    // Slider → single render invocation
    // ----------------------------------------------------------------------

    it('calls renderSvgToDataUrl once per slider change', async () => {
        render(<WatermarkSettingsCard />);

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

        render(<WatermarkSettingsCard />);

        // The loading indicator should be visible (no preview)
        await waitFor(() => {
            expect(screen.getByText(/Lade Logo/i)).toBeInTheDocument();
        });

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
});
