import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import {useEffect, useState} from 'react';
import {usePermissions} from '../../logic/usePermissions';
import ErrorMessage from '../components/ErrorMessage';
import WysiwygEditor from '../components/WysiwygEditor';
import RecipientFormSection from './components/RecipientFormSection';
import ManualDocumentHeader from './components/ManualDocumentHeader';
import ShootingCalculatorModal from './components/ShootingCalculatorModal';
import InvoiceItemsTable from './components/invoice/InvoiceItemsTable';
import InvoiceDiscountsSection from './components/invoice/InvoiceDiscountsSection';
import InvoiceTotalSummary from './components/invoice/InvoiceTotalSummary';
import InvoiceDragDropZone from './components/invoice/InvoiceDragDropZone';
import {useInvoiceDraft} from '../../logic/useInvoiceDraft';
import {useInvoiceDragDrop} from '../../logic/useInvoiceDragDrop';
import {usePdfExtraction} from '../../logic/usePdfExtraction';

export interface ManagementManualInvoiceViewProps {
    type?: 'invoice' | 'offer';
}

export default function ManagementManualInvoiceView({type = 'invoice'}: ManagementManualInvoiceViewProps) {
    const {isSuperAdmin} = usePermissions();
    const docType = type;

    const draft = useInvoiceDraft(type);
    const {
        formData,
        items,
        discounts,
        dueDateOption,
        isGenerating,
        isOffer,
        isDirty,
        handleUpdateField,
        handleOptionChange,
        handleServiceDateManualChange,
        handleItemChange,
        handleDiscountChange,
        addItem,
        removeItem,
        moveItemUp,
        moveItemDown,
        addDiscount,
        removeDiscount,
        moveDiscountUp,
        moveDiscountDown,
        handleAddPackageFromCalculator,
        handleMultiUpdate,
        loadExtractedData,
        handleDownload,
        subtotal,
        total,
        isFormValid,
    } = draft;

    const {processPdfFile, handleFileUpload} = usePdfExtraction(loadExtractedData);
    const {isDragging, handleDragOver, handleDragLeave, handleDrop} = useInvoiceDragDrop(isOffer, processPdfFile);

    const [isCalculatorOpen, setIsCalculatorOpen] = useState(false);

    useEffect(() => {
        if (!isDirty) return;
        const handler = (e: BeforeUnloadEvent) => {
            e.preventDefault();
            e.returnValue = '';
        };
        window.addEventListener('beforeunload', handler);
        return () => window.removeEventListener('beforeunload', handler);
    }, [isDirty]);

    if (!isSuperAdmin) return <div className="p-8"><ErrorMessage message={t`Keine Berechtigung.`}/></div>;

    return (
        <div
            className={`p-6 md:p-10 max-w-6xl mx-auto w-full relative transition-colors duration-200 ${isDragging ? 'bg-primary/5 rounded-box border-2 border-dashed border-primary' : ''}`}
            onDragOver={handleDragOver}
            onDragLeave={handleDragLeave}
            onDrop={handleDrop}
        >
            <InvoiceDragDropZone
                isDragging={isDragging}
                isOffer={isOffer}
                onFileUpload={handleFileUpload}
            />

            <form onSubmit={handleDownload} className="space-y-8">
                <ManualDocumentHeader
                    docType={docType}
                    data={formData}
                    dueDateOption={dueDateOption}
                    onUpdate={handleUpdateField}
                    onOptionChange={handleOptionChange}
                    onServiceDateChange={handleServiceDateManualChange}
                />

                <RecipientFormSection formData={formData} onUpdate={handleUpdateField}
                                      onMultiUpdate={handleMultiUpdate}/>

                <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                    <div className="flex justify-between items-center border-b border-base-300 pb-2 mb-4">
                        <h2 className="font-bold text-xl text-primary"><Trans>Leistungen / Positionen</Trans></h2>
                        <div className="flex gap-2">
                            <button type="button" onClick={() => setIsCalculatorOpen(true)}
                                    className="btn btn-sm btn-primary">
                                    <span className="iconify mdi--calculator"></span> <Trans>Paket-Kalkulator</Trans>
                                </button>
                        </div>
                    </div>

                    <InvoiceItemsTable
                        items={items}
                        onItemChange={handleItemChange}
                        onAddItem={addItem}
                        onRemoveItem={removeItem}
                        onMoveItemUp={moveItemUp}
                        onMoveItemDown={moveItemDown}
                    />

                    <InvoiceDiscountsSection
                        discounts={discounts}
                        subtotal={subtotal}
                        onDiscountChange={handleDiscountChange}
                        onAddDiscount={addDiscount}
                        onRemoveDiscount={removeDiscount}
                        onMoveDiscountUp={moveDiscountUp}
                        onMoveDiscountDown={moveDiscountDown}
                    />

                    <InvoiceTotalSummary total={total}/>
                </div>

                {isOffer && (
                    <div className="bg-base-100 p-6 rounded-box border border-primary/30 shadow-md">
                        <h2 className="font-bold text-xl mb-4 text-primary"><Trans>Angebotstext (Einleitung)</Trans></h2>
                        <WysiwygEditor value={formData.terms_html} onChange={v => handleUpdateField('terms_html', v)}/>
                    </div>
                )}

                {!isOffer && (
                    <div className="bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
                        <h2 className="font-bold text-xl mb-4"><Trans>Zusatztexte / Sonderkonditionen</Trans></h2>
                        <WysiwygEditor value={formData.terms_html} onChange={v => handleUpdateField('terms_html', v)}/>
                    </div>
                )}

                <div className="flex justify-end pt-4 pb-20">
                    <button type="submit" disabled={isGenerating || !isFormValid}
                            className="btn btn-primary btn-lg shadow-xl w-full md:w-auto"
                            title={!isFormValid ? t`Bitte alle Pflichtfelder ausfüllen (Titel/Menge).` : ''}>
                        {isGenerating ? <span className="loading loading-spinner"></span> : <Trans>PDF Generieren</Trans>}
                    </button>
                </div>
            </form>

            <ShootingCalculatorModal
                isOpen={isCalculatorOpen}
                onClose={() => setIsCalculatorOpen(false)}
                onAddPackage={handleAddPackageFromCalculator}
            />
        </div>
    );
}
