import { describe, it, expect, vi, beforeEach } from 'vitest';
import { fireEvent, screen, within } from '@testing-library/react';
import { renderWithProviders } from '../../../test-setup';

// Der Deep-Link-Parameter `model` bestimmt, welches Profil der Detail-Dialog
// zeigt. Der Mock muss ihn daher veränderbar machen, sonst könnte kein Test
// den **Profilwechsel** fahren — und genau der ist der Gegenstand des
// Regressionstests am Extern-Link unten. `vi.hoisted` hebt die Deklaration
// über den `vi.mock`-Aufruf (der Factory läuft beim Import des Moduls, bevor
// der Modul-Body ausgeführt wäre).
const { searchParams } = vi.hoisted(() => ({
    searchParams: { current: new URLSearchParams() },
}));

vi.mock('react-router-dom', () => ({
    useSearchParams: () => [searchParams.current, vi.fn()],
}));

vi.mock('../../../logic/useModels', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../logic/useModels')>()),
    useModels: vi.fn(),
}));

vi.mock('../../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../../components/UIContext', () => ({
    useUI: vi.fn(),
}));

vi.mock('../../../logic/modelContactSheet', async importOriginal => ({
    ...(await importOriginal<typeof import('../../../logic/modelContactSheet')>()),
    createModelContactSheetShare: vi.fn(),
}));

import { useModels, type ManagedModel } from '../../../logic/useModels';
import { usePermissions } from '../../../logic/usePermissions';
import { useUI } from '../../components/UIContext';
import { createModelContactSheetShare } from '../../../logic/modelContactSheet';
import ManagementModelsView from '../ManagementModelsView';

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
    age_proof_required: false,
    age_proof_uploaded_at: null,
    submitted_at: '2026-09-19T10:00:00Z',
    last_confirmed_at: null,
    lifecycle_status: 'active',
    answers: [],
    willingness: {},
    photos: [],
    primary_photo_id: null,
    access_link: null,
};

/** Das zweite Profil — der Dialog zeigt es nach dem Wechsel des Deep-Links. */
const secondModel: ManagedModel = {
    ...model,
    id: 'model-2',
    customer_id: 'customer-2',
    display_name: 'E2E Model 2',
};

function renderView() {
    return renderWithProviders(<ManagementModelsView />);
}

/** Die Auswahlleiste erscheint erst ab einem ausgewählten Model. */
function selectModel() {
    fireEvent.click(screen.getByTestId(`model-select-${model.id}`));
}

describe('ManagementModelsView selection toolbar', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        searchParams.current = new URLSearchParams();
        vi.mocked(useUI).mockReturnValue({
            showToast: vi.fn(),
            confirm: vi.fn().mockResolvedValue(true),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
        vi.mocked(usePermissions).mockReturnValue(basePermissions);
        vi.mocked(useModels).mockReturnValue({
            models: [model],
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        });
    });

    it('offers the export and share actions to an admin', () => {
        renderView();
        selectModel();

        expect(screen.getByTestId('model-selection-toolbar')).toBeInTheDocument();
        expect(screen.getByTestId('model-bulk-contact-sheet-internal')).toBeInTheDocument();
        expect(screen.getByTestId('model-bulk-contact-sheet-external')).toBeInTheDocument();
        expect(screen.getByTestId('model-bulk-contact-sheet-share')).toBeInTheDocument();
    });

    /**
     * Beide Erzeugungsstellen des Extern-Links sind **Admin-only** — auch die
     * Auswahlleiste der Ergebnisliste. Der Server lehnt den Export ohnehin ab
     * (`AdminOnly`); die UI bietet die Aktion gar nicht erst an.
     */
    it('hides the export and share actions from a non-admin', () => {
        vi.mocked(usePermissions).mockReturnValue({
            ...basePermissions,
            isAdmin: false,
            isStaff: false,
            showCRM: false,
        });

        renderView();
        selectModel();

        expect(screen.getByTestId('model-selection-toolbar')).toBeInTheDocument();
        expect(screen.queryByTestId('model-bulk-contact-sheet-internal')).toBeNull();
        expect(screen.queryByTestId('model-bulk-contact-sheet-external')).toBeNull();
        expect(screen.queryByTestId('model-bulk-contact-sheet-share')).toBeNull();
    });

    /**
     * Der Wrapper der Model-Karte trägt **nicht** das Präfix `model-card-`:
     * ein Präfix-Selektor (`[data-testid^="model-card-"]`) trifft damit genau
     * einen Button je Model — sonst wäre der Selektor mehrdeutig.
     */
    it('keeps the card wrapper outside the `model-card-` test id prefix', () => {
        const { container } = renderView();

        expect(container.querySelector(`[data-testid="model-frame-${model.id}"]`)).not.toBeNull();
        expect(container.querySelector(`[data-testid^="model-card-"]`)).not.toBeNull();

        const prefixed = Array.from(container.querySelectorAll('[data-testid^="model-card-"]'));
        expect(prefixed).toHaveLength(1);
        expect(prefixed[0].tagName).toBe('BUTTON');
    });

    /**
     * „Auswahl aufheben" verwirft den angezeigten Extern-Link.
     *
     * Der Link gilt für die Auswahl, für die er erzeugt wurde. Wird die
     * Auswahl geleert, darf der Link nicht weiter angezeigt werden — er
     * gehört zu einer Auswahl, die es nicht mehr gibt.
     */
    it('discards the share link when the selection is cleared', async () => {
        vi.mocked(createModelContactSheetShare).mockResolvedValue({
            url: 'https://portal.test/kontaktbogen/token-abc',
            expires_at: '2026-10-28T10:00:00Z',
            variant: 'external',
            count: 1,
        });

        renderView();
        selectModel();

        fireEvent.click(screen.getByTestId('model-bulk-contact-sheet-share'));

        // Der Link ist erzeugt und wird angezeigt.
        expect(await screen.findByTestId('model-bulk-share-link')).toBeInTheDocument();
        expect(screen.getByTestId('model-bulk-share-link-url')).toHaveValue(
            'https://portal.test/kontaktbogen/token-abc',
        );

        fireEvent.click(screen.getByTestId('model-bulk-clear-selection'));

        // „Auswahl aufheben" verwirft den Link mit — er gilt für eine
        // Auswahl, die es nicht mehr gibt.
        expect(screen.queryByTestId('model-bulk-share-link')).toBeNull();
        expect(screen.queryByTestId('model-bulk-share-link-url')).toBeNull();
    });
});

/**
 * Der Extern-Link des **Detail-Dialogs** gehört zum gezeigten Profil.
 *
 * Der Dialog wurde ohne `key` gerendert und unmountet beim Schließen nicht
 * (`if (!model) return null` rendert null, die Instanz bleibt). Sein
 * `shareLink`-State überlebte deshalb den Profilwechsel: Profil A öffnen →
 * Extern-Link erstellen → schließen → Profil B öffnen ⇒ der Block
 * „Extern-Link" zeigte weiterhin A's URL und Ablauf, während die Kachel die
 * Daten von B zeigte. Wer die URL kopierte, schickte das **falsche** Blatt
 * (Künstlername, Ort, Foto eines anderen Models) an den Empfänger.
 *
 * Der `key` des Dialogs bindet ihn an das Profil: ein Wechsel baut die
 * Komponente neu auf und verwirft damit **jeden** profilbezogenen State.
 */
describe('ManagementModelsView profile-scoped share link', () => {
    beforeEach(() => {
        vi.clearAllMocks();
        searchParams.current = new URLSearchParams();
        vi.mocked(useUI).mockReturnValue({
            showToast: vi.fn(),
            confirm: vi.fn().mockResolvedValue(true),
            hasUnsavedChanges: false,
            setUnsavedChanges: vi.fn(),
        });
        vi.mocked(usePermissions).mockReturnValue(basePermissions);
        vi.mocked(useModels).mockReturnValue({
            models: [model, secondModel],
            error: undefined,
            isLoading: false,
            mutate: vi.fn(),
        });
        vi.mocked(createModelContactSheetShare).mockResolvedValue({
            url: 'https://portal.test/kontaktbogen/token-abc',
            expires_at: '2026-10-28T10:00:00Z',
            variant: 'external',
            count: 1,
        });
    });

    it('discards the share link when another profile is shown', async () => {
        // Der Deep-Link öffnet den Detail-Dialog für das erste Profil.
        searchParams.current = new URLSearchParams('model=model-1');
        const { rerender } = renderView();

        fireEvent.click(screen.getByTestId('model-contact-sheet-share'));

        // Der Link des ersten Profils ist erzeugt und wird angezeigt.
        expect(await screen.findByTestId('model-contact-sheet-share-url')).toBeInTheDocument();
        expect(screen.getByTestId('model-contact-sheet-share-url')).toHaveValue(
            'https://portal.test/kontaktbogen/token-abc',
        );

        // Ein anderes Profil öffnet (Deep-Link auf das zweite Profil).
        searchParams.current = new URLSearchParams('model=model-2');
        rerender(<ManagementModelsView />);

        // Der Link des ersten Profils darf nicht mehr dastehen: er gehört zu
        // einem anderen Profil und würde das falsche Blatt verschicken.
        expect(screen.queryByTestId('model-contact-sheet-share-url')).toBeNull();
        expect(screen.getByTestId('model-contact-sheet-share-panel')).toHaveTextContent(
            'Noch kein Extern-Link erstellt.',
        );
        // Der Dialog zeigt tatsächlich das **andere** Profil — sonst würde
        // die Assertion oben auch für einen gar nicht geöffneten Dialog gelten.
        // (Der Kartentitel trägt denselben Namen — deshalb der Dialog-Scope.)
        const dialog = within(screen.getByRole('dialog'));
        expect(dialog.getByRole('heading', { name: 'E2E Model 2' })).toBeInTheDocument();
    });
});
