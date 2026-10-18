import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import WatermarkSettingsCard from './components/WatermarkSettingsCard';
import PricingSettingsTabs from './components/PricingSettingsTabs';
import BillingDetailsCard from './components/BillingDetailsCard';
import BrandSettingsCard from './components/BrandSettingsCard';
import useSWR from 'swr';
import {fetcher, SystemInfo} from '../../api';
import {useState} from 'react';
import {useBillingDetails} from '../../logic/useLicenseTerms';
import {usePermissions} from '../../logic/usePermissions';
import {apiMutate, TestEmailResponse, StripeCheckResponse, StripeCheckFinding, StripeCheckStatus} from '../../api';
import {useUI} from '../components/UIContext';
import CalculatorSettingsCard from './components/CalculatorSettingsCard';

declare const __APP_BUILD_TIME__: string;

/**
 * Derive the test/live mode from a raw Stripe publishable key. The rule mirrors
 * the backend's `StripeKeyMode::fromKey()`: the mode token must sit *directly*
 * behind the prefix, so a `_live_` buried in the random portion (e.g.
 * `pk_test_x_live_y`) does not flip the answer. Keeping both sides on the same
 * anchored expression is what makes this comparison honest.
 */
const STRIPE_KEY_MODE_PATTERN = /^(pk|sk|rk)_(test|live)(?:_|$)/i;

const deriveBundleMode = (key: string | undefined): 'test' | 'live' | null => {
    const match = key?.match(STRIPE_KEY_MODE_PATTERN);
    if (!match) return null;
    return match[2].toLowerCase() === 'live' ? 'live' : 'test';
};

const STRIPE_CHECK_STATUS_STYLES: Record<StripeCheckStatus, { icon: string; text: string }> = {
    ok: { icon: 'mdi--check-circle', text: 'text-success' },
    warn: { icon: 'mdi--alert-circle-outline', text: 'text-warning' },
    fail: { icon: 'mdi--alert-circle', text: 'text-error' }
};


export default function ManagementSettingsView() {
    const {billingDetails, isLoading: termsLoading} = useBillingDetails();
    const {isSuperAdmin} = usePermissions();
    const {data: sysInfo} = useSWR<SystemInfo>('/api/management/settings/system', fetcher);
    const {showToast} = useUI();
    const [sendingTestMail, setSendingTestMail] = useState(false);
    const [stripeChecking, setStripeChecking] = useState(false);
    const [stripeResults, setStripeResults] = useState<StripeCheckFinding[] | null>(null);

    const handleStripeCheck = async () => {
        if (!isSuperAdmin) return;
        setStripeChecking(true);
        try {
            const data = await apiMutate<StripeCheckResponse>('/api/management/settings/stripe-check', 'POST', {
                bundle_mode: deriveBundleMode(import.meta.env.VITE_STRIPE_PUBLIC_KEY)
            });
            setStripeResults(data.checks);
        } catch (err: unknown) {
            setStripeResults(null);
            const stripeError = err instanceof Error ? err.message : t`Unbekannter Fehler`;
            showToast('error', t`Fehler beim Stripe-Verbindungstest: ${stripeError}`);
        }
        setStripeChecking(false);
    };

    const handleSendTestMail = async () => {
        if (!isSuperAdmin) return;
        setSendingTestMail(true);
        try {
            const data = await apiMutate<TestEmailResponse>('/api/management/settings/test-email', 'POST', {});
            const sentTo = data.sent_to;
            showToast('success', t`Test-E-Mail gesendet an ${sentTo}.`);
        } catch (err: unknown) {
            const mailError = err instanceof Error ? err.message : t`Unbekannter Fehler`;
            showToast('error', t`Fehler beim Senden: ${mailError}`);
        }
        setSendingTestMail(false);
    };

    let reactTime = 'Unbekannt';
    if (typeof __APP_BUILD_TIME__ !== 'undefined') {
        reactTime = new Date(__APP_BUILD_TIME__).toLocaleString('de-DE');
    }

    const isImpressumMissing = isSuperAdmin && !termsLoading && (!billingDetails?.bank_holder || !billingDetails?.company_street || !billingDetails?.company_zip || !billingDetails?.company_city || !billingDetails?.bank_iban);

    return (
        <div className="p-10 mx-auto w-full flex flex-col gap-8">
            <div className="border-b border-base-300 pb-4">
                <h1 className="text-4xl font-bold"><Trans>System-Einstellungen</Trans></h1>
            </div>

            {isImpressumMissing && (
                <div className="alert alert-error shadow-sm">
                    <span className="iconify mdi--alert-circle text-xl"></span>
                    <div>
                        <h3 className="font-bold"><Trans>Impressum & Bankdaten unvollständig!</Trans></h3>
                        <p className="text-sm"><Trans>Bitte fülle alle Pflichtfelder (*) aus, um den Rechnungs- und
                            Bestellprozess zu aktivieren.</Trans></p>
                    </div>
                </div>
            )}

            <PricingSettingsTabs/>

            <BillingDetailsCard/>
            {isSuperAdmin && <BrandSettingsCard/>}
            <WatermarkSettingsCard/>

            {/* `CalculatorSettingsCard` reads the full (super_admin-only)
                license-terms payload — gate it like `BrandSettingsCard`. */}
            {isSuperAdmin && <CalculatorSettingsCard/>}

            {isSuperAdmin && (
                <div className="card bg-base-100 border border-base-300 shadow-sm">
                    <div className="card-body">
                        <h2 className="card-title text-xl"><Trans>SMTP-Verbindungstest</Trans></h2>
                        <p className="text-sm opacity-70"><Trans>Sendet eine Test-E-Mail über den konfigurierten Mailer an deine eigene (Administrator-)Adresse.</Trans></p>
                        <div className="card-actions justify-end">
                            <button
                                className="btn btn-primary"
                                data-testid="send-test-email"
                                disabled={sendingTestMail}
                                onClick={handleSendTestMail}
                            >
                                {sendingTestMail
                                    ? <span className="loading loading-spinner"></span>
                                    : <Trans>Test-E-Mail senden</Trans>}
                            </button>
                        </div>
                    </div>
                </div>
            )}

            {isSuperAdmin && (
                <div className="card bg-base-100 border border-base-300 shadow-sm" data-testid="stripe-check-card">
                    <div className="card-body">
                        <h2 className="card-title text-xl"><Trans>Stripe-Verbindungstest</Trans></h2>
                        <p className="text-sm opacity-70"><Trans>Prüft die Stripe-Konfiguration (Schlüssel-Modus, Bundle-Modus,
                            Balance, Konto und Webhook) direkt über die Stripe-API — nur bei explizitem Klick.</Trans></p>
                        <div className="card-actions justify-end">
                            <button
                                className="btn btn-primary"
                                data-testid="stripe-check-button"
                                disabled={stripeChecking}
                                onClick={handleStripeCheck}
                            >
                                {stripeChecking
                                    ? <span className="loading loading-spinner"></span>
                                    : <Trans>Verbindung testen</Trans>}
                            </button>
                        </div>
                        {stripeResults && (
                            <ul className="mt-2 flex flex-col gap-1">
                                {stripeResults.map((finding) => (
                                    <li
                                        key={finding.key}
                                        className="flex items-center gap-2 text-sm"
                                        data-testid="stripe-check-result"
                                        data-key={finding.key}
                                        data-status={finding.status}
                                    >
                                        <span className={`iconify ${STRIPE_CHECK_STATUS_STYLES[finding.status].icon} ${STRIPE_CHECK_STATUS_STYLES[finding.status].text}`}></span>
                                        <span>{finding.message}</span>
                                    </li>
                                ))}
                            </ul>
                        )}
                    </div>
                </div>
            )}

            <div className="mt-8 pt-8 border-t border-base-300">
                <div
                    className="text-sm opacity-60 font-mono bg-base-200 p-4 rounded-box border border-base-300 shadow-sm leading-relaxed">
                    <div className="text-primary font-bold mb-2 text-base"><Trans>System Info</Trans></div>
                    <div className="grid grid-cols-2 gap-2">
                        <span className="font-semibold text-primary"><Trans>Datenbank Version:</Trans></span> <span
                        className="font-bold text-primary">{sysInfo ? 'v' + sysInfo.db_version : t`Lädt...`}</span>
                        <span className="font-semibold"><Trans>React Build:</Trans></span> <span>{reactTime}</span>
                        <span className="font-semibold"><Trans>Laravel Update:</Trans></span>
                        <span>{sysInfo ? new Date(sysInfo.laravel_build_time).toLocaleString('de-DE') : t`Lädt...`}</span>
                        {sysInfo && <><span className="font-semibold"><Trans>Backend:</Trans></span> <span><Trans>PHP</Trans> {sysInfo.php_version} / <Trans>Laravel</Trans> {sysInfo.laravel_version}</span></>}
                    </div>
                </div>
            </div>
        </div>
    );
}