import { Trans } from "@lingui/react/macro";

interface InvoiceTotalSummaryProps {
    total: number;
}

export default function InvoiceTotalSummary({total}: InvoiceTotalSummaryProps) {
    const totalFormatted = total.toFixed(2);
    return (
        <div className="text-right text-2xl font-bold mt-6 pt-4 border-t border-base-300">
            <Trans>Gesamtbetrag: {totalFormatted} €</Trans>
        </div>
    );
}
