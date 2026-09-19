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
