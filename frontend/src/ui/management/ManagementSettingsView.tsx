import WatermarkSettingsCard from './components/WatermarkSettingsCard';
import LicenseSettingsCard from './components/LicenseSettingsCard';
import useSWR from 'swr';
import { fetcher } from '../../api';

declare const __APP_BUILD_TIME__: string;

export default function ManagementSettingsView() {
    const { data: sysInfo } = useSWR<{laravel_build_time: string, php_version: string, laravel_version: string}>('/api/management/settings/system', fetcher);
    
    let reactTime = 'Unbekannt';
    try {
        if (typeof __APP_BUILD_TIME__ !== 'undefined') {
            reactTime = new Date(__APP_BUILD_TIME__).toLocaleString('de-DE');
        }
    } catch (e) {}

    return (
        <div className="p-10 max-w-4xl mx-auto w-full flex flex-col gap-8">
            <div className="border-b border-base-300 pb-4">
                <h1 className="text-4xl font-bold">System-Einstellungen</h1>
            </div>
            
            <LicenseSettingsCard />
            <WatermarkSettingsCard />
            
            <div className="mt-8 pt-8 border-t border-base-300">
                <div className="text-sm opacity-60 font-mono bg-base-200 p-4 rounded-box border border-base-300 shadow-sm leading-relaxed">
                    <div className="text-primary font-bold mb-2 text-base">System Info</div>
                    <div className="grid grid-cols-[120px_1fr] gap-2">
                        <span className="font-semibold">React Build:</span> <span>{reactTime}</span>
                        <span className="font-semibold">Laravel Update:</span> <span>{sysInfo ? new Date(sysInfo.laravel_build_time).toLocaleString('de-DE') : 'Lädt...'}</span>
                        {sysInfo && <><span className="font-semibold">Backend:</span> <span>PHP {sysInfo.php_version} / Laravel {sysInfo.laravel_version}</span></>}
                    </div>
                </div>
            </div>
        </div>
    );
}
