interface InvoiceTotalSummaryProps {
    total: number;
}

export default function InvoiceTotalSummary({total}: InvoiceTotalSummaryProps) {
    return (
        <div className="text-right text-2xl font-bold mt-6 pt-4 border-t border-base-300">
            Gesamtbetrag: {total.toFixed(2)} €
        </div>
    );
}
