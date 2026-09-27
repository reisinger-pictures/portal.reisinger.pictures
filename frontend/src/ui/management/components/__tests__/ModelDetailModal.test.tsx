import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen, waitFor, within } from '@testing-library/react';
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

/**
 * The regions the shell puts inside the modal-box, located by structure rather
 * than by a guessed index: the bounded body that scrolls, and the wrapper that
 * holds the footer next to it. Both are direct children of the box, which is
 * what makes the sibling relation between them the thing to assert — the
 * affordance has to be on the region that scrolls, and the footer has to be
 * outside it, and neither is visible from the other region's classes.
 */
function boundedRegions(container: HTMLElement): { box: HTMLElement; body: HTMLElement; footer: HTMLElement } {
    const box = container.querySelector<HTMLElement>('.modal-box');
    if (!box) throw new Error('ModelDetailModal renders no .modal-box');
    const body = box.querySelector<HTMLElement>(':scope > .overflow-y-auto');
    const footer = box.querySelector<HTMLElement>(':scope > .shrink-0');
    // A named throw rather than a bare `expect(...).not.toBeNull()`: the only way
    // to get here is that the shell built no bounded body, which means the dialog
    // stopped opting into `scrollableBody` — so say that, and show what the box
    // does contain, instead of leaving the next reader to re-derive it.
    if (!body || !footer) {
        throw new Error(
            'ModalShell built no bounded body/footer wrapper — ModelDetailModal is '
            + 'no longer opting into `scrollableBody`. Box children: '
            + Array.from(box.children).map(child => child.className).join(' | '),
        );
    }
    return { box, body, footer };
}

describe('ModelDetailModal contact sheet actions', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useUI).mockReturnValue({ showToast, confirm: vi.fn().mockResolvedValue(true), hasUnsavedChanges: false, setUnsavedChanges: vi.fn() });
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
 * The three layout invariants the UI review of 2026-09-27 found broken, plus the
 * boundary they hang on. They are cheap to state and cheap to lose, so they are
 * pinned here instead of being left to the next screenshot pass; the visual
 * confirmation stays an E2E concern.
 */
describe('ModelDetailModal layout affordances', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        vi.mocked(useUI).mockReturnValue({ showToast, confirm: vi.fn().mockResolvedValue(true), hasUnsavedChanges: false, setUnsavedChanges: vi.fn() });
        vi.mocked(usePermissions).mockReturnValue(basePermissions);
    });

    it('fades the region that scrolls, not the box that bounds it', () => {
        const { container } = renderModal();

        const { box, body } = boundedRegions(container);

        // The box only bounds the dialog (`max-h-90vh flex flex-col`); it is the
        // body that has the height to spare and therefore the thing that scrolls.
        expect(box).toHaveClass('max-h-90vh', 'flex', 'flex-col');
        expect(body).toHaveClass('flex-1', 'min-h-0', 'overflow-y-auto');

        // The fade and its padding live on that body. The mask only reads as
        // "there is more below" on the element with a scroll port under it; on
        // the box it would fade nothing, because the box no longer scrolls, and
        // the boundary would slice the last row of text with nothing softening
        // it — the defect the review caught cutting "Linz"/"Österreich" through
        // the glyphs. The padding is half of the same invariant: the mask always
        // covers the last 2rem of the padding box, so `pb-10` (2.5rem) is what
        // keeps the content clear of the band once the body is scrolled to its end.
        expect(body).toHaveClass('scroll-fade-bottom', 'pb-10');

        // Pinned in the negative too: a fade "somewhere" is not the invariant, the
        // fade being AT the scroll boundary is. Leaving the tuning on the box as
        // well would leave two competing masks and the test green.
        expect(box).not.toHaveClass('overflow-y-auto', 'scroll-fade-bottom', 'pb-10');
    });

    it('keeps the footer reachable outside the scrolling body', () => {
        const { container } = renderModal();

        const { body, footer } = boundedRegions(container);
        const contactSheetActions = screen.getByTestId('model-contact-sheet-actions');

        // The footer is the body's next sibling, not something inside it: with
        // the footer inside the scroll port, scrolling to the end of a long
        // profile would carry the contact-sheet actions and "Schließen" out of
        // reach — the symptom that made this dialog worth migrating at all.
        expect(body.nextElementSibling).toBe(footer);
        expect(body).not.toContainElement(contactSheetActions);
        expect(footer).toContainElement(contactSheetActions);
        // The footer's own close button travels with it, not with the content.
        const footerClose = within(footer).getByRole('button', { name: 'Schließen' });
        expect(body).not.toContainElement(footerClose);
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
