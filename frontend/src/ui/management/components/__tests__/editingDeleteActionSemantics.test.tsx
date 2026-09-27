import { describe, it, expect, vi, beforeEach } from 'vitest';
import { screen } from '@testing-library/react';
import { renderWithProviders } from '../../../../test-setup';
import { UIContext, type UIContextType } from '../../../components/UIContext';
import TextSnippetModal from '../TextSnippetModal';
import ProductModal from '../ProductModal';
import CustomerModal from '../CustomerModal';
import CouponFormDrawer from '../CouponFormDrawer';
import ProjectModal from '../ProjectModal';
import type { Customer, Product, TextSnippet } from '../../../../api';
import type { Coupon } from '../CouponFormDrawer';
import type { Project } from '../../../../logic/useProjectsBoard';

/**
 * `editing` means two different things in this app, and this file is about the
 * one that is not the shell's.
 *
 * `ModalDialogShell.editing` asks a single question: "does this dialog have a
 * delete action for an existing record?" It is a question about the *footer*,
 * and the shell's answer renders a `Löschen` button. Five dialogs also carry a
 * value that reads as "is this an edit session" — the record they were opened
 * with — and the two are unrelated: none of the five has a delete action, so all
 * five pass a literal `false` while editing a record that very much exists.
 *
 * That is a trap with one obvious wrong move in it. The props were named
 * `editingSnippet` / `editingProduct` / `editingCustomer` / `editingCoupon` /
 * `editing`, one of them exactly `editing`, so at every one of these call sites
 * `editing={!!editing}` is a plausible-looking edit that compiles, renders, and
 * ships a delete button wired to no handler. The local names are now the
 * records' own (`snippet`, `product`, `customer`, `coupon`, `project`), and this
 * test pins the consequence rather than the naming: opened on an existing
 * record, none of the five offers a delete action.
 */
const statusOptions = [{ value: 'anfrage', label: 'Anfrage' }];

const snippet: TextSnippet = {
    id: 's1',
    title: 'Datenschutz',
    shortcut: 'dsg',
    content_html: '<p>Hinweis zum Datenschutz</p>',
};

const product: Product = {
    id: 'p1',
    type: 'item',
    name: 'Album',
    description: 'Leinwand 40x60',
    price: 12000,
};

const customer: Customer = {
    id: 'c1',
    name: 'Max Mustermann',
    company: 'Mustermann GmbH',
    email: 'max@example.de',
    birthdate: '1990-04-01',
    street: 'Hauptstraße 1',
    zip: '1010',
    city: 'Wien',
    country: 'Österreich',
    uid: 'ATU12345678',
};

const coupon: Coupon = {
    id: 7,
    code: 'SUMMER',
    type: 'fixed',
    value: 10,
    scope_type: 'global',
    active: true,
    used_count: 2,
};

const project: Project = {
    id: 'pr1',
    status: 'bezahlt',
    position: 0,
    owner: { id: 'u1', name: 'Max Mustermann' },
    assignee: null,
    created_at: '2026-01-02T10:00:00Z',
    client_name: 'Max Mustermann',
    email: 'max@example.de',
    phone: null,
    package: 'hochzeit',
    price_cents: 150000,
    payment_status: 'bezahlt',
    linked_photo_job_id: null,
    notes: null,
};

const uiContext: UIContextType = {
    showToast: vi.fn(),
    confirm: vi.fn().mockResolvedValue(true),
    hasUnsavedChanges: false,
    setUnsavedChanges: vi.fn(),
};

vi.mock('../../../components/WysiwygEditor', () => ({
    default: ({ value, onChange }: { value: string; onChange: (value: string) => void }) => (
        <textarea aria-label="Inhalt (HTML)" value={value} onChange={event => onChange(event.target.value)} />
    ),
}));

vi.mock('../../../../logic/useUsers', () => ({
    useUsers: vi.fn(() => ({ users: [] })),
    UserRole: { ADMIN: 'admin', SUPER_ADMIN: 'super_admin' },
}));

vi.mock('swr', () => ({
    default: vi.fn(() => ({ data: undefined, error: undefined, isLoading: false, mutate: vi.fn() })),
    mutate: vi.fn(),
}));

function renderWithUI(node: React.ReactNode) {
    return renderWithProviders(<UIContext.Provider value={uiContext}>{node}</UIContext.Provider>);
}

describe('dialogs without a delete action', () => {
    beforeEach(() => {
        vi.clearAllMocks();
    });

    /**
     * Each case is the dialog opened on an *existing* record — the state in
     * which the shell's `editing` prop is supposed to turn the delete button on.
     * The `Löschen` button is the shell's, so the only way one can appear here
     * is a caller passing the edit-session value into a prop that asks about
     * delete actions.
     */
    const cases: Array<[string, React.ReactNode, string]> = [
        [
            'TextSnippetModal',
            <TextSnippetModal isOpen onClose={vi.fn()} editingSnippet={snippet} onSave={vi.fn()} />,
            'Textbaustein bearbeiten',
        ],
        [
            'ProductModal',
            <ProductModal isOpen onClose={vi.fn()} editingProduct={product} onSave={vi.fn()} />,
            'Katalog-Eintrag bearbeiten',
        ],
        [
            'CustomerModal',
            <CustomerModal isOpen onClose={vi.fn()} editingCustomer={customer} onSave={vi.fn()} />,
            'Kunde bearbeiten',
        ],
        [
            'CouponFormDrawer',
            <CouponFormDrawer isOpen onClose={vi.fn()} editingCoupon={coupon} onSave={vi.fn()} />,
            'Rabattcode bearbeiten',
        ],
        [
            'ProjectModal',
            <ProjectModal
                isOpen
                onClose={vi.fn()}
                defaultStatus="anfrage"
                statusOptions={statusOptions}
                editing={project}
                onSave={vi.fn()}
            />,
            'Projekt bearbeiten',
        ],
    ];

    it.each(cases)('%s offers no delete action while editing a record', (_name, node, dialogName) => {
        renderWithUI(node);

        // The dialog really is in its edit state, so this is not the create-mode
        // dialog passing by accident.
        expect(screen.getByRole('dialog', { name: dialogName })).toBeInTheDocument();
        // The shared footer, i.e. the one place a delete button can come from.
        expect(screen.getByRole('button', { name: 'Speichern' })).toBeInTheDocument();
        expect(screen.queryByRole('button', { name: 'Löschen' })).toBeNull();
    });
});
