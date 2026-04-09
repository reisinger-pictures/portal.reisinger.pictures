import WatermarkSettingsCard from './components/WatermarkSettingsCard';
import LicenseSettingsCard from './components/LicenseSettingsCard';
import useSWR from 'swr';
import { fetcher } from '../../api';
import { useLicenseTerms } from '../../logic/useLicenseTerms';

declare const __APP_BUILD_TIME__: string;

export default function ManagementSettingsView() {
    const { terms: licenseTerms, updateTerms } = useLicenseTerms();
    const { data: sysInfo } = useSWR<{laravel_build_time: string, php_version: string, laravel_version: string, db_version?: string}>('/api/management/settings/system', fetcher);
    
    let reactTime = 'Unbekannt';
    if (typeof __APP_BUILD_TIME__ !== 'undefined') {
        reactTime = new Date(__APP_BUILD_TIME__).toLocaleString('de-DE');
    }

    return (
        <div className="p-10 max-w-4xl mx-auto w-full flex flex-col gap-8">
            <div className="border-b border-base-300 pb-4">
                <h1 className="text-4xl font-bold">System-Einstellungen</h1>
            </div>
            
            <LicenseSettingsCard />
            
            <div className="card bg-base-200 border border-base-300">
                <div className="card-body">
                    <h2 className="card-title text-2xl mb-4 flex items-center gap-2">
                        <span className="iconify mdi--bank text-primary text-3xl"></span> Bankverbindung & Impressum
                    </h2>
                    <p className="text-sm opacity-70 mb-6">Diese Daten werden im Footer deiner PDF-Rechnungen und Lieferscheine angezeigt.</p>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Kontoinhaber</span></label>
                            <input type="text" className="input input-bordered" placeholder="Name des Inhabers" 
                                value={licenseTerms?.bank_holder || ''} 
                                onChange={e => updateTerms({ bank_holder: e.target.value })} />
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">IBAN</span></label>
                            <input type="text" className="input input-bordered font-mono" placeholder="AT..." 
                                value={licenseTerms?.bank_iban || ''} 
                                onChange={e => updateTerms({ bank_iban: e.target.value })} />
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">BIC</span></label>
                            <input type="text" className="input input-bordered font-mono" placeholder="BIC" 
                                value={licenseTerms?.bank_bic || ''} 
                                onChange={e => updateTerms({ bank_bic: e.target.value })} />
                        </div>
                    </div>
                </div>
            </div>
            <WatermarkSettingsCard />
            
            <div className="mt-8 pt-8 border-t border-base-300">
                <div className="text-sm opacity-60 font-mono bg-base-200 p-4 rounded-box border border-base-300 shadow-sm leading-relaxed">
                    <div className="text-primary font-bold mb-2 text-base">System Info</div>
                    <div className="grid grid-cols-[120px_1fr] gap-2">
                        <span className="font-semibold text-primary">Portal Version:</span> <span className="font-bold text-primary">{sysInfo ? 'v' + sysInfo.db_version : 'Lädt...'}</span>
                        <span className="font-semibold">React Build:</span> <span>{reactTime}</span>
                        <span className="font-semibold">Laravel Update:</span> <span>{sysInfo ? new Date(sysInfo.laravel_build_time).toLocaleString('de-DE') : 'Lädt...'}</span>
                        {sysInfo && <><span className="font-semibold">Backend:</span> <span>PHP {sysInfo.php_version} / Laravel {sysInfo.laravel_version}</span></>}
                    </div>
                </div>
            </div>
        </div>
    );
}
