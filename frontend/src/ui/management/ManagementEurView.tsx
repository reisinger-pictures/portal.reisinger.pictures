import { Trans } from "@lingui/react/macro";
import { Link } from 'react-router-dom';
import { useBrand } from '../../logic/useBrand';
import { useBillingDetails } from '../../logic/useLicenseTerms';

const display = (value: string | undefined): string => (value && value.trim() !== '' ? value : '—');

export default function ManagementEurView() {
    const {portalName} = useBrand();
    const {billingDetails} = useBillingDetails();

    const address = [
        billingDetails?.company_street,
        [billingDetails?.company_zip, billingDetails?.company_city].filter(Boolean).join(' '),
        billingDetails?.company_country,
    ].filter((part) => part && part.trim() !== '').join(', ');

    return (
        <div className="p-6 md:p-10 max-w-5xl mx-auto w-full">
            <h1 className="text-4xl font-bold mb-2"><Trans>Buchhaltung (EÜR)</Trans></h1>
            <p className="opacity-70 mb-8"><Trans>Ihre Buchhaltung für {portalName}</Trans></p>

            <div className="card bg-base-100 border border-base-300 shadow-sm mb-6">
                <div className="card-body p-6">
                    <h2 className="card-title text-primary mb-1"><Trans>Unternehmensdaten</Trans></h2>
                    <p className="text-sm opacity-70 mb-6">
                        <Trans>Diese Angaben stammen aus Ihren Einstellungen und können dort geändert werden.</Trans>
                    </p>

                    <dl className="grid grid-cols-1 md:grid-cols-2 gap-x-8 gap-y-4">
                        <div>
                            <dt className="text-sm opacity-60"><Trans>Firmenname</Trans></dt>
                            <dd className="font-medium">{display(billingDetails?.bank_holder)}</dd>
                        </div>
                        <div>
                            <dt className="text-sm opacity-60"><Trans>Rechtsform</Trans></dt>
                            <dd className="font-medium">{display(billingDetails?.rechtsform)}</dd>
                        </div>
                        <div className="md:col-span-2">
                            <dt className="text-sm opacity-60"><Trans>Anschrift</Trans></dt>
                            <dd className="font-medium">{display(address)}</dd>
                        </div>
                        <div>
                            <dt className="text-sm opacity-60"><Trans>E-Mail für Rückfragen</Trans></dt>
                            <dd className="font-medium">{display(billingDetails?.company_email)}</dd>
                        </div>
                        <div>
                            <dt className="text-sm opacity-60"><Trans>Steuernummer</Trans></dt>
                            <dd className="font-medium">{display(billingDetails?.steuernummer)}</dd>
                        </div>
                        <div>
                            <dt className="text-sm opacity-60"><Trans>Finanzamt</Trans></dt>
                            <dd className="font-medium">{display(billingDetails?.finanzamt)}</dd>
                        </div>
                        <div>
                            <dt className="text-sm opacity-60"><Trans>UID-Nummer</Trans></dt>
                            <dd className="font-medium">{display(billingDetails?.uid)}</dd>
                        </div>
                        <div className="md:col-span-2">
                            <dt className="text-sm opacity-60"><Trans>Bankverbindung</Trans></dt>
                            <dd className="font-medium font-mono">{display(billingDetails?.bank_iban)}</dd>
                        </div>
                    </dl>

                    <div className="mt-6">
                        <Link to="/settings" className="btn btn-outline btn-sm">
                            <Trans>Unternehmensdaten bearbeiten</Trans>
                        </Link>
                    </div>
                </div>
            </div>

            <div className="card bg-base-100 border border-base-300 shadow-sm mb-6">
                <div className="card-body p-6">
                    <h2 className="card-title text-primary mb-4"><Trans>Geringwertige Wirtschaftsgüter</Trans></h2>
                    <p>
                        <Trans>Anschaffungen unter 1.000 € können Sie im Jahr des Kaufs direkt als Ausgabe erfassen.</Trans>
                    </p>
                </div>
            </div>

            <div className="card bg-base-100 border border-base-300 shadow-sm mb-6">
                <div className="card-body p-6">
                    <h2 className="card-title text-primary mb-4"><Trans>Betriebsanteil</Trans></h2>
                    <p>
                        <Trans>Ausgaben können einen betrieblichen Anteil von 0 % (privat) bis 100 % (betrieblich) haben.</Trans>
                    </p>
                </div>
            </div>

            <p className="opacity-70 mb-6">
                <Trans>Bald finden Sie hier Ihre Buchungen, Belege und Auswertungen. Die Buchhaltung ist noch im Aufbau.</Trans>
            </p>

            <Link to="/" className="btn btn-primary"><Trans>Zurück zum Portal</Trans></Link>
        </div>
    );
}
