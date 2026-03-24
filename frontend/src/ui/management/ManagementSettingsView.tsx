import { useState, useEffect } from 'react';
import {useSettings, WatermarkSettings} from '../../logic/useSettings';
import {useAuth} from '../../logic/useAuth';
import {apiMutate} from '../../api';
import {useUI} from '../components/UIContext';

export default function ManagementSettingsView() {
    const {watermark, updateWatermark} = useSettings();
    const {user, mutate: mutateUser} = useAuth();
    const {showToast} = useUI();

    // Watermark State
    const [file, setFile] = useState<File | null>(null);
    const [scale, setScale] = useState<number>(0.1);
    const [opacity, setOpacity] = useState<number>(0.6);
    const [position, setPosition] = useState<string>('bottom-right');
    const [savingWatermark, setSavingWatermark] = useState(false);
    const [prevWatermark, setPrevWatermark] = useState<WatermarkSettings | null>(null);

    // Profile State
    const [profileName, setProfileName] = useState(user?.name || '');
    const [profileCopyright, setProfileCopyright] = useState(user?.metadata_copyright || '');
    const [savingProfile, setSavingProfile] = useState(false);

    useEffect(() => {
        if (user) {
            setProfileName(user.name);
            setProfileCopyright(user.metadata_copyright || '');
        }
    }, [user]);

    if (watermark && watermark !== prevWatermark) {
        setPrevWatermark(watermark);
        setScale(watermark.scale);
        setOpacity(watermark.opacity);
        setPosition(watermark.position);
    }

    const handleSaveWatermark = async (e: React.FormEvent) => {
        e.preventDefault();
        setSavingWatermark(true);
        const fd = new FormData();
        if (file) fd.append('svg', file);
        fd.append('scale', scale.toString());
        fd.append('opacity', opacity.toString());
        fd.append('position', position);

        await updateWatermark(fd);
        showToast('success', 'Wasserzeichen gespeichert');
        setSavingWatermark(false);
        setFile(null);
    };

    const handleSaveProfile = async (e: React.FormEvent) => {
        e.preventDefault();
        setSavingProfile(true);
        try {
            await apiMutate('/api/auth/profile', 'PUT', { name: profileName, metadata_copyright: profileCopyright });
            await mutateUser();
            showToast('success', 'Profil aktualisiert');
        } catch {
            showToast('error', 'Fehler beim Speichern');
        }
        setSavingProfile(false);
    };

    return (
        <div className="p-10 max-w-4xl mx-auto w-full flex flex-col gap-8">
            <h1 className="text-4xl font-bold">Einstellungen</h1>

            {/* Profil Card */}
            <div className="card bg-base-200 border border-base-300">
                <div className="card-body">
                    <h2 className="card-title text-2xl mb-4">Profil & Standardwerte</h2>
                    <form onSubmit={handleSaveProfile} className="space-y-6">
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Dein Name</span></label>
                            <input type="text" required value={profileName} onChange={e => setProfileName(e.target.value)} className="input input-bordered w-full"/>
                        </div>
                        <div className="form-control">
                            <label className="label">
                                <span className="label-text font-bold">Standard-Urheber (IPTC Copyright)</span>
                                <span className="label-text-alt opacity-70">Dieser Wert wird in neue Bilder geschrieben, falls die Galerie Metadaten anwendet.</span>
                            </label>
                            <input type="text" value={profileCopyright} onChange={e => setProfileCopyright(e.target.value)} placeholder="z.B. Florian Reisinger" className="input input-bordered w-full"/>
                        </div>
                        <div>
                            <button type="submit" disabled={savingProfile} className="btn btn-primary">
                                {savingProfile ? <span className="loading loading-spinner"></span> : 'Profil speichern'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>

            {/* Watermark Card */}
            {user?.is_admin && (
            <div className="card bg-base-200 border border-base-300">
                <div className="card-body">
                    <h2 className="card-title text-2xl mb-4">Wasserzeichen für Gäste</h2>

                    {!watermark?.has_svg && (
                        <div className="alert alert-warning shadow-sm mb-6">
                            <span className="iconify mdi--alert text-xl"></span>
                            <span>Es wurde noch kein SVG-Wasserzeichen hochgeladen. Gäste laden Bilder in öffentlichen Galerien derzeit in Originalqualität ohne Wasserzeichen herunter.</span>
                        </div>
                    )}
                    {watermark?.has_svg && (
                        <div className="alert alert-success shadow-sm mb-6">
                            <span className="iconify mdi--check-circle text-xl"></span>
                            <span>SVG Wasserzeichen ist aktiv.</span>
                        </div>
                    )}

                    <form onSubmit={handleSaveWatermark} className="space-y-6">
                        <div className="form-control w-full">
                            <label className="label"><span
                                className="label-text font-bold">Logo (nur .svg)</span></label>
                            <input type="file" accept=".svg" onChange={e => setFile(e.target.files?.[0] || null)}
                                   className="file-input file-input-bordered w-full"/>
                            <div className="label"><span className="label-text-alt opacity-70">Lade eine neue Datei hoch, um das aktuelle Logo zu ersetzen.</span>
                            </div>
                        </div>

                        <div className="grid grid-cols-1 md:grid-cols-3 gap-6">
                            <div className="form-control">
                                <label className="label"><span
                                    className="label-text font-bold">Größe (Skalierung)</span></label>
                                <input type="range" min="0.05" max="0.5" step="0.01" value={scale}
                                       onChange={e => setScale(parseFloat(e.target.value))}
                                       className="range range-primary"/>
                                <div className="text-center text-sm mt-2">{Math.round(scale * 100)}% der Bildbreite
                                </div>
                            </div>

                            <div className="form-control">
                                <label className="label"><span className="label-text font-bold">Deckkraft</span></label>
                                <input type="range" min="0.1" max="1.0" step="0.05" value={opacity}
                                       onChange={e => setOpacity(parseFloat(e.target.value))}
                                       className="range range-primary"/>
                                <div className="text-center text-sm mt-2">{Math.round(opacity * 100)}%</div>
                            </div>

                            <div className="form-control">
                                <label className="label"><span className="label-text font-bold">Position</span></label>
                                <select value={position} onChange={e => setPosition(e.target.value)}
                                        className="select select-bordered w-full">
                                    <option value="bottom-right">Unten Rechts</option>
                                    <option value="bottom-left">Unten Links</option>
                                    <option value="top-right">Oben Rechts</option>
                                    <option value="top-left">Oben Links</option>
                                    <option value="center">Zentriert</option>
                                </select>
                            </div>
                        </div>

                        <div className="mt-6">
                            <button type="submit" disabled={savingWatermark} className="btn btn-primary">
                                {savingWatermark ?
                                    <span className="loading loading-spinner"></span> : 'Speichern & Cache leeren'}
                            </button>
                        </div>
                    </form>
                </div>
            </div>
            )}
        </div>
    );
}