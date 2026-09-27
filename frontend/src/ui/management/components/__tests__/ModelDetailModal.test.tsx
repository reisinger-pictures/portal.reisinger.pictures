import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { renderWithProviders } from '../../../../test-setup';

vi.mock('../../../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../../../../logic/modelContactSheet', () => ({
    downloadModelContactSheet: vi.fn(),
}));

vi.mock('../../../components/UIContext', () => ({
    useUI: vi.fn(),
}));

import { usePermissions } from '../../../../logic/usePermissions';
import { downloadModelContactSheet } from '../../../../logic/modelContactSheet';
import { useUI } from '../../../components/UIContext';
import ModelDetailModal from '../ModelDetailModal';
import type { ManagedModel } from '../../../../logic/useModels';

const showToast = vi.fn();

const basePermissions = {
    isStaff: true,
    isAdmin: true,
    isSuperAdmin: false,
    isPhotographer: false,
    isOrgAdmin: false,
    canEditMetadata: false,
    isPowerUser: false,
    canAccessB2BFeatures: true,
    canAccessProjectsBoard: true,
    canAccessProductionBoard: false,
    showOrgsSection: true,
    showCRM: true,
    showInvoicing: true,
    showPayouts: false,
};

const model: ManagedModel = {
    id: 'model-1',
    customer_id: 'customer-1',
    display_name: 'E2E Model',
    birthdate: '1995-05-05',
    age: 31,
    gender: 'weiblich',
    city: 'Linz',
    country: 'Österreich',
    categories: [],
    act_types: [],
    catalog_version: 'v1',
    age_proof_required: true,
    age_proof_uploaded_at: '2026-09-19T10:00:00Z',
    submitted_at: '2026-09-19T10:00:00Z',
    last_confirmed_at: null,
    lifecycle_status: 'active',
    answers: [],
    willingness: {},
    photos: [],
    primary_photo_id: null,
    access_link: null,
};

function renderModal() {
    return renderWithProviders(
        <ModelDetailModal model={model} onClose={vi.fn()} onChanged={vi.fn()} />,
    );
}

/** Column spans a grid child declares, e.g. `col-span-2 md:col-span-3` → base 2, md 3. */
function columnSpans(element: Element | null): { base: number; md: number } {
    const classes = element?.getAttribute('class') ?? '';
    const base = classes.match(/(?:^|\s)col-span-(\d+)(?:\s|$)/);
    const md = classes.match(/(?:^|\s)md:col-span-(\d+)(?:\s|$)/);
    return { base: base ? Number(base[1]) : 1, md: md ? Number(md[1]) : 1 };
}

describe('ModelDetailModal contact sheet actions', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useUI).mockReturnValue({ showToast, confirm: vi.fn().mockResolvedValue(true) });
        vi.mocked(usePermissions).mockReturnValue(basePermissions);
    });

    it('shows both contact-sheet actions for an admin', () => {
        renderModal();

        expect(screen.getByTestId('model-contact-sheet-internal')).toBeInTheDocument();
        expect(screen.getByTestId('model-contact-sheet-external')).toBeInTheDocument();
        expect(screen.getByRole('button', { name: 'Contact Sheet (intern)' })).toBeInTheDocument();
    });

    it('hides the contact-sheet actions for a non-admin', () => {
        vi.mocked(usePermissions).mockReturnValue({ ...basePermissions, isAdmin: false, isStaff: false });

        renderModal();

        expect(screen.queryByTestId('model-contact-sheet-internal')).toBeNull();
        expect(screen.queryByTestId('model-contact-sheet-external')).toBeNull();
    });

    it('downloads the internal variant and confirms via toast', async () => {
        const user = userEvent.setup();
        vi.mocked(downloadModelContactSheet).mockResolvedValue(undefined);

        renderModal();
        await user.click(screen.getByTestId('model-contact-sheet-internal'));

        await waitFor(() => expect(downloadModelContactSheet).toHaveBeenCalledWith('model-1', 'internal'));
        expect(showToast).toHaveBeenCalledWith('success', expect.stringContaining('heruntergeladen'));
    });

    it('surfaces download failures as an error toast', async () => {
        const user = userEvent.setup();
        vi.mocked(downloadModelContactSheet).mockRejectedValue(new Error('HTTP Fehler 422'));

        renderModal();
        await user.click(screen.getByTestId('model-contact-sheet-external'));

        await waitFor(() => expect(downloadModelContactSheet).toHaveBeenCalledWith('model-1', 'external'));
        expect(showToast).toHaveBeenCalledWith('error', 'HTTP Fehler 422');
    });
});

/**
 * The three layout invariants the UI review of 2026-09-27 found broken. They are
 * cheap to state and cheap to lose, so they are pinned here instead of being left
 * to the next screenshot pass; the visual confirmation stays an E2E concern.
 */
describe('ModelDetailModal layout affordances', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useUI).mockReturnValue({ showToast, confirm: vi.fn().mockResolvedValue(true) });
        vi.mocked(usePermissions).mockReturnValue(basePermissions);
    });

    it('fades the bottom edge of the scroll region it owns', () => {
        const { container } = renderModal();

        // The modal-box is the scroll region (`max-h-90vh overflow-y-auto`).
        // Without the fade its boundary slices whatever row it lands on, which
        // the review caught cutting "Linz"/"Österreich" through the glyphs.
        const box = container.querySelector('.modal-box');
        expect(box).toHaveClass('overflow-y-auto', 'scroll-fade-bottom');
        // The fade band is 2rem (see the `@utility` in index.css), so the bottom
        // padding has to hold the content and the footer clear of it.
        expect(box).toHaveClass('pb-10');
    });

    it('nests the answer sections one heading level below their group', () => {
        renderWithProviders(
            <ModelDetailModal
                model={{
                    ...model,
                    answers: [{ scope: 'person', key: 'first_name', label: 'Vorname', type: 'text', value: 'E2E' }],
                }}
                onClose={vi.fn()}
                onChanged={vi.fn()}
            />,
        );

        // Both used to be `h4` at the same size and weight, so "Profil-Angaben"
        // and "Basisdaten" read as one flat level.
        expect(screen.getByRole('heading', { level: 4, name: 'Profil-Angaben' })).toBeInTheDocument();
        expect(screen.getByRole('heading', { level: 5, name: 'Basisdaten' })).toBeInTheDocument();
    });

    it('gives the age proof the same column span as the data state below it', () => {
        renderModal();

        // The label sits directly in the card, so its parent is the grid child.
        const ageProof = screen.getByText('Altersnachweis').parentElement;
        const dataState = screen.getByTestId('model-datasource');
        expect(ageProof).not.toBeNull();

        const ageProofSpans = columnSpans(ageProof);
        const dataStateSpans = columnSpans(dataState);

        // On the 2-column grid both cards take the full width: the age proof
        // used to take a single column, which left ~150px of dead space beside
        // the full-width data state.
        expect(ageProofSpans.base).toBe(dataStateSpans.base);
        // The desktop split still fills the 4-column row.
        expect(ageProofSpans.md + dataStateSpans.md).toBe(4);
    });
});
