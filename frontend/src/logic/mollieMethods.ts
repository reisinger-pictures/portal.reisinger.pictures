import {t} from '@lingui/core/macro';

/**
 * One selectable Mollie payment method as the cart renders it.
 *
 * The backend owns the allowlist and sends only ids; labels are a frontend
 * presentation concern. Ids this build does not know are ignored — the server
 * still validates every submitted id, so an unknown id can never reach a
 * payment.
 */
export interface MollieMethodOption {
    id: string;
    label: string;
    hint: string;
}

const describeMollieMethod = (id: string): MollieMethodOption | null => {
    switch (id) {
        case 'paybybank':
            return {
                id,
                label: t`Pay by Bank`,
                hint: t`Sofortige Bezahlung über dein Online-Banking.`,
            };
        case 'eps':
            return {
                id,
                label: t`EPS-Überweisung`,
                hint: t`Österreichisches Online-Banking.`,
            };
        case 'ideal':
            return {
                id,
                label: t`iDEAL`,
                hint: t`Niederländisches Online-Banking.`,
            };
        case 'bancontact':
            return {
                id,
                label: t`Bancontact`,
                hint: t`Belgische Bankkarte.`,
            };
        case 'creditcard':
            return {
                id,
                label: t`Kreditkarte`,
                hint: t`Visa, Mastercard oder American Express.`,
            };
        case 'directdebit':
            return {
                id,
                label: t`SEPA-Lastschrift`,
                hint: t`Abbuchung von deinem Konto per Mandat.`,
            };
        case 'banktransfer':
            return {
                id,
                label: t`Banküberweisung`,
                hint: t`Zahlung per Überweisung; die Freigabe dauert einige Tage.`,
            };
        case 'paypal':
            return {
                id,
                label: t`PayPal`,
                hint: t`Bezahlung über dein PayPal-Konto.`,
            };
        case 'applepay':
            return {
                id,
                label: t`Apple Pay`,
                hint: t`Bezahlung mit einer hinterlegten Karte.`,
            };
        case 'googlepay':
            return {
                id,
                label: t`Google Pay`,
                hint: t`Bezahlung mit einer hinterlegten Karte.`,
            };
        default:
            return null;
    }
};

/**
 * Map the server allowlist to known options, preserving the server order and
 * dropping unknown or repeated ids.
 */
export const mollieMethodOptions = (ids: readonly string[]): MollieMethodOption[] => {
    const seen = new Set<string>();
    const options: MollieMethodOption[] = [];

    for (const id of ids) {
        if (seen.has(id)) continue;
        const option = describeMollieMethod(id);
        if (option === null) continue;
        seen.add(id);
        options.push(option);
    }

    return options;
};
