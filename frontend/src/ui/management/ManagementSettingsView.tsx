import WatermarkSettingsCard from './components/WatermarkSettingsCard';
import LicenseCatalogSettings from './components/LicenseCatalogSettings';
import useSWR from 'swr';
import {fetcher, SystemInfo} from '../../api';
import {useLicenseTerms} from '../../logic/useLicenseTerms';
import {useAuth} from '../../logic/useAuth';

import {useEffect, useState} from 'react';
import {useUI} from '../components/UIContext';

function CalculatorSettingsCard() {
    const {terms, updateTerms} = useLicenseTerms();
    const {showToast} = useUI();
    const [basePrice, setBasePrice] = useState('50');
    const [hourlyRate, setHourlyRate] = useState('100');
    const [imagesPerHour, setImagesPerHour] = useState('6');
    const [isSaving, setIsSaving] = useState(false);

    useEffect(() => {
        if (terms) {
            setBasePrice(terms.calc_base_price || '50');
            setHourlyRate(terms.calc_hourly_rate || '100');
            setImagesPerHour(terms.calc_images_per_hour || '6');
        }
    }, [terms]);

    const handleSave = async () => {
        setIsSaving(true);
        try {
            await updateTerms({
                calc_base_price: basePrice,
                calc_hourly_rate: hourlyRate,
                calc_images_per_hour: imagesPerHour
            });
            showToast('success', 'Kalkulator-Einstellungen gespeichert.');
        } catch {
            showToast('error', 'Fehler beim Speichern.');
        } finally {
            setIsSaving(false);
        }
    };

    return (
        <div className="card bg-base-100 border border-base-300 shadow-sm">
            <div className="card-body p-6 md:p-8">
                <h2 className="card-title text-2xl mb-4 flex items-center gap-2">
                    <span className="iconify mdi--calculator text-primary text-3xl"></span> Shooting-Paket Kalkulator
                </h2>
                <p className="text-sm opacity-70 mb-6">Definiere die Standardwerte für den automatischen Paket-Rechner
                    in den manuellen Angeboten und Rechnungen.</p>
                <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                    <div className="form-control">
                        <label className="label"><span className="label-text font-bold">Grundpreis (€) *</span></label>
                        <input type="number" step="any" min="0" className="input input-bordered" value={basePrice}
                               onChange={e => setBasePrice(e.target.value)}/>
                    </div>
                    <div className="form-control">
                        <label className="label"><span className="label-text font-bold">Stundensatz (€) *</span></label>
                        <input type="number" step="any" min="0" className="input input-bordered" value={hourlyRate}
                               onChange={e => setHourlyRate(e.target.value)}/>
                    </div>
                    <div className="form-control">
                        <label className="label"><span
                            className="label-text font-bold">Bearbeitung Bilder pro Stunde *</span></label>
                        <input type="number" min="1" className="input input-bordered" value={imagesPerHour}
                               onChange={e => setImagesPerHour(e.target.value)}/>
                    </div>
                </div>
                <div className="mt-6 border-t border-base-300 pt-6">
                    <button onClick={handleSave} disabled={isSaving} className="btn btn-primary px-8">
                        {isSaving ? <span className="loading loading-spinner"></span> : 'Einstellungen anwenden'}
                    </button>
                </div>
            </div>
        </div>
    );
}

declare const __APP_BUILD_TIME__: string;


export default function ManagementSettingsView() {
    const {terms: licenseTerms, updateTerms, isLoading: termsLoading} = useLicenseTerms();
    const {user} = useAuth();
    const {data: sysInfo} = useSWR<SystemInfo>('/api/management/settings/system', fetcher);

    let reactTime = 'Unbekannt';
    if (typeof __APP_BUILD_TIME__ !== 'undefined') {
        reactTime = new Date(__APP_BUILD_TIME__).toLocaleString('de-DE');
    }

    const isImpressumMissing = user?.is_super_admin && !termsLoading && (!licenseTerms?.bank_holder || !licenseTerms?.company_street || !licenseTerms?.company_zip || !licenseTerms?.company_city || !licenseTerms?.bank_iban);

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
                                   className={`input input-bordered ${!licenseTerms?.bank_holder ? 'input-error' : ''}`}
                                   placeholder="Name des Inhabers"
                                   value={licenseTerms?.bank_holder || ''}
                                   onChange={e => updateTerms({bank_holder: e.target.value})}/>
                        </div>

                        <div className="form-control md:col-span-2">
                            <label className="label"><span className="label-text font-bold">Straße & Hausnummer *</span></label>
                            <input type="text"
                                   className={`input input-bordered ${!licenseTerms?.company_street ? 'input-error' : ''}`}
                                   placeholder="Musterstraße 1"
                                   value={licenseTerms?.company_street || ''}
                                   onChange={e => updateTerms({company_street: e.target.value})}/>
                        </div>

                        <div className="flex gap-4 md:col-span-2 w-full">
                            <div className="form-control w-1/3">
                                <label className="label"><span className="label-text font-bold">PLZ *</span></label>
                                <input type="text"
                                       className={`input input-bordered w-full ${!licenseTerms?.company_zip ? 'input-error' : ''}`}
                                       placeholder="4020"
                                       value={licenseTerms?.company_zip || ''}
                                       onChange={e => updateTerms({company_zip: e.target.value})}/>
                            </div>
                            <div className="form-control flex-1">
                                <label className="label"><span className="label-text font-bold">Stadt *</span></label>
                                <input type="text"
                                       className={`input input-bordered w-full ${!licenseTerms?.company_city ? 'input-error' : ''}`}
                                       placeholder="Linz"
                                       value={licenseTerms?.company_city || ''}
                                       onChange={e => updateTerms({company_city: e.target.value})}/>
                            </div>
                        </div>

                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Land</span></label>
                            <input type="text" className="input input-bordered" placeholder="Österreich"
                                   value={licenseTerms?.company_country || ''}
                                   onChange={e => updateTerms({company_country: e.target.value})}/>
                        </div>

                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">E-Mail für Rückfragen</span></label>
                            <input type="email" className="input input-bordered" placeholder="hello@reisinger.pictures"
                                   value={licenseTerms?.company_email || ''}
                                   onChange={e => updateTerms({company_email: e.target.value})}/>
                        </div>
                    </div>

                    <div className="divider">Bankdaten</div>

                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">IBAN *</span></label>
                            <input type="text"
                                   className={`input input-bordered font-mono ${!licenseTerms?.bank_iban ? 'input-error' : ''}`}
                                   placeholder="AT..."
                                   value={licenseTerms?.bank_iban || ''}
                                   onChange={e => updateTerms({bank_iban: e.target.value})}/>
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">BIC</span></label>
                            <input type="text" className="input input-bordered font-mono" placeholder="BIC"
                                   value={licenseTerms?.bank_bic || ''}
                                   onChange={e => updateTerms({bank_bic: e.target.value})}/>
                        </div>
                    </div>
                </div>
            </div>
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