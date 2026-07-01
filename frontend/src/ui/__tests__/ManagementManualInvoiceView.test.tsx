import { describe, it, expect, vi, beforeEach } from 'vitest';
import { render, screen } from '@testing-library/react';
import userEvent from '@testing-library/user-event';
import { MemoryRouter } from 'react-router-dom';
import ManagementManualInvoiceView from '../management/ManagementManualInvoiceView';
import { usePermissions } from '../../logic/usePermissions';

vi.mock('../../logic/usePermissions', () => ({
    usePermissions: vi.fn(),
}));

vi.mock('../components/ErrorMessage', () => ({
    default: ({ message }: { message: string }) => <div data-testid="error-message">{message}</div>,
}));

vi.mock('../components/WysiwygEditor', () => ({
    default: () => <div data-testid="wysiwyg-editor">Wysiwyg Editor</div>,
}));

vi.mock('../management/components/RecipientFormSection', () => ({
    default: () => <div data-testid="recipient-form-section" />,
}));

vi.mock('../management/components/ManualDocumentHeader', () => ({
    default: () => <div data-testid="manual-document-header" />,
}));

vi.mock('../management/components/ShootingCalculatorModal', () => ({
    default: ({ isOpen }: { isOpen: boolean }) => (
        isOpen ? <div data-testid="calculator-modal" /> : null
    ),
}));

vi.mock('../management/components/invoice/InvoiceItemsTable', () => ({
    default: () => <div data-testid="invoice-items-table" />,
}));

vi.mock('../management/components/invoice/InvoiceDiscountsSection', () => ({
    default: () => <div data-testid="invoice-discounts-section" />,
}));

vi.mock('../management/components/invoice/InvoiceTotalSummary', () => ({
    default: () => <div data-testid="invoice-total-summary" />,
}));

vi.mock('../management/components/invoice/InvoiceDragDropZone', () => ({
    default: () => <div data-testid="invoice-drag-drop-zone" />,
}));

vi.mock('../../logic/useInvoiceDraft', () => ({
    useInvoiceDraft: vi.fn(),
}));

vi.mock('../../logic/useInvoiceDragDrop', () => ({
    useInvoiceDragDrop: vi.fn(() => ({
        isDragging: false,
        handleDragOver: vi.fn(),
        handleDragLeave: vi.fn(),
        handleDrop: vi.fn(),
    })),
}));

vi.mock('../../logic/usePdfExtraction', () => ({
    usePdfExtraction: vi.fn(() => ({
        processPdfFile: vi.fn(),
        handleFileUpload: vi.fn(),
    })),
}));

import { useInvoiceDraft } from '../../logic/useInvoiceDraft';

const baseDraft = {
    formData: {
        type: 'invoice', invoice_number: '', date: '', due_date: '', service_date: '', validity: '',
        customer_name: '', customer_company: '', customer_street: '', customer_zip: '', customer_city: '',
        customer_country: '', customer_email: '', customer_uid: '', terms_html: '',
    },
    items: [],
    discounts: [],
    dueDateOption: '30' as const,
    isGenerating: false,
    isOffer: false,
    handleUpdateField: vi.fn(),
    handleOptionChange: vi.fn(),
    handleServiceDateManualChange: vi.fn(),
    handleItemChange: vi.fn(),
    handleDiscountChange: vi.fn(),
    addItem: vi.fn(),
    removeItem: vi.fn(),
    moveItemUp: vi.fn(),
    moveItemDown: vi.fn(),
    addDiscount: vi.fn(),
    removeDiscount: vi.fn(),
    handleAddPackageFromCalculator: vi.fn(),
    handleMultiUpdate: vi.fn(),
    loadExtractedData: vi.fn(),
    handleDownload: vi.fn(e => e.preventDefault()),
    total: 0,
    subtotal: 0,
    hasInvalidItems: false,
    isDirty: false,
    isFormValid: true,
};

function renderView(props: { type?: 'invoice' | 'offer' } = {}) {
    return render(
        <MemoryRouter>
            <ManagementManualInvoiceView {...props} />
        </MemoryRouter>,
    );
}

describe('ManagementManualInvoiceView', () => {
    beforeEach(() => {
        vi.clearAllMocks();

        vi.mocked(usePermissions).mockReturnValue({
            isStaff: true,
            isSuperAdmin: true,
            isAdmin: true,
            isPhotographer: false,
            isCustomerManager: false,
            showTenantsSection: false,
            canEditMetadata: false,
            isPowerUser: false,
            canAccessB2BFeatures: false,
            showCRM: false,
            showInvoicing: false,
            showPayouts: false,
        });

        vi.mocked(useInvoiceDraft).mockReturnValue(baseDraft);
    });

    it('shows permission denied for non-super-admin users', () => {
        vi.mocked(usePermissions).mockReturnValue({
            isStaff: false,
            isSuperAdmin: false,
            isAdmin: false,
            isPhotographer: false,
            isCustomerManager: false,
            showTenantsSection: false,
            canEditMetadata: false,
            isPowerUser: false,
            canAccessB2BFeatures: false,
            showCRM: false,
            showInvoicing: false,
            showPayouts: false,
        });

        renderView();
        expect(screen.getByTestId('error-message')).toBeInTheDocument();
        expect(screen.getByText('Keine Berechtigung.')).toBeInTheDocument();
    });

    it('renders invoice form with all sections for super admin', () => {
        renderView();
        expect(screen.getByTestId('recipient-form-section')).toBeInTheDocument();
        expect(screen.getByTestId('manual-document-header')).toBeInTheDocument();
        expect(screen.getByTestId('invoice-items-table')).toBeInTheDocument();
        expect(screen.getByTestId('invoice-discounts-section')).toBeInTheDocument();
        expect(screen.getByTestId('invoice-total-summary')).toBeInTheDocument();
        expect(screen.getByTestId('invoice-drag-drop-zone')).toBeInTheDocument();
    });

    it('renders offer text WysiwygEditor when type is offer', () => {
        vi.mocked(useInvoiceDraft).mockReturnValue({
            ...baseDraft,
            isOffer: true,
        });

        renderView({ type: 'offer' });
        expect(screen.getByText('Angebotstext (Einleitung)')).toBeInTheDocument();
    });

    it('renders additional terms WysiwygEditor when type is invoice', () => {
        renderView({ type: 'invoice' });
        expect(screen.getByText('Zusatztexte / Sonderkonditionen')).toBeInTheDocument();
    });

    it('shows calculator modal when Paket-Kalkulator button is clicked', async () => {
        const user = userEvent.setup();
        renderView();
        await user.click(screen.getByText('Paket-Kalkulator'));
        expect(screen.getByTestId('calculator-modal')).toBeInTheDocument();
    });

    it('submit button is disabled when form is invalid', () => {
        vi.mocked(useInvoiceDraft).mockReturnValue({
            ...baseDraft,
            isFormValid: false,
        });

        renderView();
        const submitButton = screen.getByText('PDF Generieren');
        expect(submitButton.closest('button')).toBeDisabled();
    });

    it('submit button shows spinner when generating', () => {
        vi.mocked(useInvoiceDraft).mockReturnValue({
            ...baseDraft,
            isGenerating: true,
        });

        renderView();
        const form = document.querySelector('form');
        const submitButton = form!.querySelector('button[type="submit"]');
        expect(submitButton!.querySelector('.loading')).toBeInTheDocument();
        expect(submitButton).toBeDisabled();
    });
});
