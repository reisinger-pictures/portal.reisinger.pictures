import WatermarkSettingsCard from './components/WatermarkSettingsCard';
import LicenseCatalogSettings from './components/LicenseCatalogSettings';
import BillingDetailsCard from './components/BillingDetailsCard';
import useSWR from 'swr';
import {fetcher, SystemInfo} from '../../api';
import {useBillingDetails} from '../../logic/useLicenseTerms';
import {usePermissions} from '../../logic/usePermissions';
import CalculatorSettingsCard from './components/CalculatorSettingsCard';

declare const __APP_BUILD_TIME__: string;


export default function ManagementSettingsView() {
    const {billingDetails, isLoading: termsLoading} = useBillingDetails();
    const {isSuperAdmin} = usePermissions();
    const {data: sysInfo} = useSWR<SystemInfo>('/api/management/settings/system', fetcher);

    let reactTime = 'Unbekannt';
    if (typeof __APP_BUILD_TIME__ !== 'undefined') {
        reactTime = new Date(__APP_BUILD_TIME__).toLocaleString('de-DE');
    }

    const isImpressumMissing = isSuperAdmin && !termsLoading && (!billingDetails?.bank_holder || !billingDetails?.company_street || !billingDetails?.company_zip || !billingDetails?.company_city || !billingDetails?.bank_iban);

    return (
        <div className="p-10 max-w-4xl mx-auto w-full flex flex-col gap-8">
            <div className="border-b border-base-300 pb-4">
                <h1 className="text-4xl font-bold">System-Einstellungen</h1>
            </div>

            {isImpressumMissing && (
                <div className="alert alert-error shadow-sm">
                    <span className="iconify mdi--alert-circle text-xl"></span>
                    <div>
                        <h3 className="font-bold">Impressum & Bankdaten unvollständig!</h3>
                        <p className="text-sm">Bitte fülle alle Pflichtfelder (*) aus, um den Rechnungs- und
                            Bestellprozess zu aktivieren.</p>
                    </div>
                </div>
            )}

            <LicenseCatalogSettings/>

            <BillingDetailsCard/>
            <WatermarkSettingsCard/>

            <CalculatorSettingsCard/>

            <div className="mt-8 pt-8 border-t border-base-300">
                <div
                    className="text-sm opacity-60 font-mono bg-base-200 p-4 rounded-box border border-base-300 shadow-sm leading-relaxed">
                    <div className="text-primary font-bold mb-2 text-base">System Info</div>
                    <div className="grid grid-cols-[120px_1fr] gap-2">
                        <span className="font-semibold text-primary">Datenbank Version:</span> <span
                        className="font-bold text-primary">{sysInfo ? 'v' + sysInfo.db_version : 'Lädt...'}</span>
                        <span className="font-semibold">React Build:</span> <span>{reactTime}</span>
                        <span className="font-semibold">Laravel Update:</span>
                        <span>{sysInfo ? new Date(sysInfo.laravel_build_time).toLocaleString('de-DE') : 'Lädt...'}</span>
                        {sysInfo && <><span className="font-semibold">Backend:</span> <span>PHP {sysInfo.php_version} / Laravel {sysInfo.laravel_version}</span></>}
                    </div>
                </div>
            </div>
        </div>
    );
}