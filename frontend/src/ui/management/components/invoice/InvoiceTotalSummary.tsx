import { Trans } from "@lingui/react/macro";
import {formatEuro} from '../../../../logic/formatCurrency';

interface InvoiceTotalSummaryProps {
    total: number;
}

export default function InvoiceTotalSummary({total}: InvoiceTotalSummaryProps) {
    // The symbol comes from formatEuro, which also guarantees the German
    // separators and the non-breaking space — appending a literal " €" here
    // would print "1.234,50 € €".
    const totalFormatted = formatEuro(total);
    return (
        <div className="text-right text-2xl font-bold mt-6 pt-4 border-t border-base-300">
            <Trans>Gesamtbetrag: {totalFormatted}</Trans>
        </div>
    );
}
