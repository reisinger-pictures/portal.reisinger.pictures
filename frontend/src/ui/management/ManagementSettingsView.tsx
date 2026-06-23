import WatermarkSettingsCard from './components/WatermarkSettingsCard';
import LicenseCatalogSettings from './components/LicenseCatalogSettings';
import useSWR from 'swr';
import {fetcher, SystemInfo} from '../../api';
import {useBillingDetails} from '../../logic/useLicenseTerms';
import {useAuth} from '../../logic/useAuth';
import CalculatorSettingsCard from './components/CalculatorSettingsCard';
import { useBrand } from '../../logic/useBrand';

declare const __APP_BUILD_TIME__: string;


export default function ManagementSettingsView() {
    const {billingDetails, updateBillingDetails, isLoading: termsLoading} = useBillingDetails();
    const {user} = useAuth();
    const {data: sysInfo} = useSWR<SystemInfo>('/api/management/settings/system', fetcher);
    const { isAtr } = useBrand();

    let reactTime = 'Unbekannt';
    if (typeof __APP_BUILD_TIME__ !== 'undefined') {
        reactTime = new Date(__APP_BUILD_TIME__).toLocaleString('de-DE');
    }

    const isImpressumMissing = user?.is_super_admin && !termsLoading && (!billingDetails?.bank_holder || !billingDetails?.company_street || !billingDetails?.company_zip || !billingDetails?.company_city || !billingDetails?.bank_iban);

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

            <div className="card bg-base-200 border border-base-300">
                <div className="card-body">
                    <h2 className="card-title text-2xl mb-4 flex items-center gap-2">
                        <span className="iconify mdi--bank text-primary text-3xl"></span> Bankverbindung & Impressum
                    </h2>
                    <p className="text-sm opacity-70 mb-6">Diese Daten werden im Header und Footer deiner PDF-Rechnungen
                        und Lieferscheine angezeigt.</p>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
                        <div className="form-control md:col-span-2">
                            <label className="label"><span
                                className="label-text font-bold">Firmenname / Kontoinhaber *</span></label>
                            <input type="text"
                                   className={`input input-bordered ${!billingDetails?.bank_holder ? 'input-error' : ''}`}
                                   placeholder="Name des Inhabers"
                                   value={billingDetails?.bank_holder || ''}
                                   onChange={e => updateBillingDetails({bank_holder: e.target.value})}/>
                        </div>

                        <div className="form-control md:col-span-2">
                            <label className="label"><span className="label-text font-bold">Straße & Hausnummer *</span></label>
                            <input type="text"
                                   className={`input input-bordered ${!billingDetails?.company_street ? 'input-error' : ''}`}
                                   placeholder="Musterstraße 1"
                                   value={billingDetails?.company_street || ''}
                                   onChange={e => updateBillingDetails({company_street: e.target.value})}/>
                        </div>

                        <div className="flex gap-4 md:col-span-2 w-full">
                            <div className="form-control w-1/3">
                                <label className="label"><span className="label-text font-bold">PLZ *</span></label>
                                <input type="text"
                                       className={`input input-bordered w-full ${!billingDetails?.company_zip ? 'input-error' : ''}`}
                                       placeholder="4020"
                                       value={billingDetails?.company_zip || ''}
                                       onChange={e => updateBillingDetails({company_zip: e.target.value})}/>
                            </div>
                            <div className="form-control flex-1">
                                <label className="label"><span className="label-text font-bold">Stadt *</span></label>
                                <input type="text"
                                       className={`input input-bordered w-full ${!billingDetails?.company_city ? 'input-error' : ''}`}
                                       placeholder="Linz"
                                       value={billingDetails?.company_city || ''}
                                       onChange={e => updateBillingDetails({company_city: e.target.value})}/>
                            </div>
                        </div>

                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Land</span></label>
                            <input type="text" className="input input-bordered" placeholder="Österreich"
                                   value={billingDetails?.company_country || ''}
                                   onChange={e => updateBillingDetails({company_country: e.target.value})}/>
                        </div>

                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">E-Mail für Rückfragen</span></label>
                            <input type="email" className="input input-bordered" placeholder="hello@reisinger.pictures"
                                   value={billingDetails?.company_email || ''}
                                   onChange={e => updateBillingDetails({company_email: e.target.value})}/>
                        </div>
                    </div>

                    <div className="divider">Bankdaten</div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">IBAN *</span></label>
                            <input type="text"
                                   className={`input input-bordered font-mono ${!billingDetails?.bank_iban ? 'input-error' : ''}`}
                                   placeholder="AT..."
                                   value={billingDetails?.bank_iban || ''}
                                   onChange={e => updateBillingDetails({bank_iban: e.target.value})}/>
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">BIC</span></label>
                            <input type="text" className="input input-bordered font-mono" placeholder="BIC"
                                   value={billingDetails?.bank_bic || ''}
                                   onChange={e => updateBillingDetails({bank_bic: e.target.value})}/>
                        </div>
                    </div>
                </div>
            </div>
            <WatermarkSettingsCard/>

            {!isAtr && <CalculatorSettingsCard/>}

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