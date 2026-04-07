import WatermarkSettingsCard from './components/WatermarkSettingsCard';
import LicenseSettingsCard from './components/LicenseSettingsCard';

export default function ManagementSettingsView() {
    return (
        <div className="p-10 max-w-4xl mx-auto w-full flex flex-col gap-8">
            <h1 className="text-4xl font-bold">System-Einstellungen</h1>
            <LicenseSettingsCard />
            <WatermarkSettingsCard />
        </div>
    );
}
