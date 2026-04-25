import { useState } from 'react';
import useSWR from 'swr';
import { fetcher, apiMutate } from '../../../api';
import { useUI } from '../../components/UIContext';
import { Gallery, GalleryGroup } from '../../../logic/useGalleries';
import { UserDetailed } from '../../../logic/useUsers';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    item: Gallery | GalleryGroup | null;
    isGroup: boolean;
    onUpdateState: () => void;
}

export default function PhotographerTeamModal({ isOpen, onClose, item, isGroup, onUpdateState }: Props) {
    const { data: users, isLoading, mutate } = useSWR<UserDetailed[]>('/api/management/users', fetcher);
    const { showToast } = useUI();
    const [status, setStatus] = useState<'null' | 'true' | 'false'>('null');
    const [isSaving, setIsSaving] = useState(false);

    const [prevItemId, setPrevItemId] = useState<string | null>(null);

    if (item && item.id !== prevItemId) {
        setPrevItemId(item.id);
        setStatus(item.restricted_photographers === null ? 'null' : (item.restricted_photographers ? 'true' : 'false'));
    } else if (!item && prevItemId !== null) {
        setPrevItemId(null);
    }

    if (!isOpen || !item) return null;

    const photographers = users?.filter(u => u.is_photographer && !u.is_super_admin) || [];
    const isEffectivelyRestricted = status === 'true' || (status === 'null' && item.effective_restricted_photographers);

    const handleStatusChange = async (newStatus: 'null' | 'true' | 'false') => {
        setStatus(newStatus);
        setIsSaving(true);
        const val = newStatus === 'null' ? null : newStatus === 'true';
        const endpoint = isGroup ? `/api/management/gallery-groups/${item.id}` : `/api/management/galleries/${item.id}`;
        try {
            await apiMutate(endpoint, 'PUT', { name: item.name, restricted_photographers: val });
            onUpdateState();
            showToast('success', 'Status gespeichert');
        } catch (e: unknown) {
            showToast('error', e instanceof Error ? e.message : 'Fehler beim Speichern');
        }
        setIsSaving(false);
    };

    const toggleAccess = async (userId: string, hasAccess: boolean) => {
        const endpoint = isGroup ? `/api/management/gallery-groups/${item.id}/sync-photographers` : `/api/management/galleries/${item.id}/sync-photographers`;
        try {
            await apiMutate(endpoint, 'POST', { user_id: userId, action: hasAccess ? 'detach' : 'attach' });
            mutate();
            showToast('success', hasAccess ? 'Zugriff entfernt' : 'Zugriff erteilt');
        } catch(e: unknown) {
            showToast('error', e instanceof Error ? e.message : 'Fehler beim Speichern');
        }
    };

    return (
        <div className="modal modal-open z-[80]">
            <div className="modal-box max-w-2xl relative flex flex-col max-h-[80vh]">
                <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-2 flex items-center gap-2">
                    <span className="iconify mdi--camera-account text-primary"></span> Fotografen-Team
                </h3>
                <p className="text-sm opacity-70 mb-4">{isGroup ? 'Ordner' : 'Galerie'}: <strong>{item.name}</strong></p>

                <div className="form-control w-full mb-6">
                    <label className="label"><span className="label-text font-bold">Zugriffs-Status (Fotografen)</span></label>
                    <select className="select select-bordered w-full" value={status} onChange={e => handleStatusChange(e.target.value as 'null' | 'true' | 'false')} disabled={isSaving}>
                        <option value="null">Erben (Aktuell: {item.effective_restricted_photographers ? 'Restriktiv' : 'Offen'})</option>
                        <option value="false">Offen (Jeder Fotograf hat Zugriff)</option>
                        <option value="true">Restriktiv (Nur zugewiesene Fotografen)</option>
                    </select>
                </div>

                {isEffectivelyRestricted ? (
                    <div className="flex-1 overflow-y-auto border border-base-300 rounded-box p-2">
                        {isLoading ? (
                            <div className="flex justify-center p-8"><span className="loading loading-spinner"></span></div>
                        ) : (
                            <div className="flex flex-col gap-1">
                                {photographers.map(u => {
                                    const hasAccess = isGroup 
                                        ? u.photographer_gallery_groups?.some(g => g.id === item.id)
                                        : u.photographer_galleries?.some(g => g.id === item.id);
                                    return (
                                        <div key={u.id} className="flex items-center justify-between p-2 hover:bg-base-200 rounded">
                                            <div>
                                                <div className="font-bold">{u.name}</div>
                                                <div className="text-sm opacity-70">{u.email}</div>
                                            </div>
                                            <button 
                                                className={`btn btn-sm w-28 ${hasAccess ? 'btn-error btn-outline' : 'btn-primary'}`}
                                                onClick={() => toggleAccess(u.id, !!hasAccess)}
                                            >
                                                {hasAccess ? 'Entfernen' : 'Hinzufügen'}
                                            </button>
                                        </div>
                                    );
                                })}
                            </div>
                        )}
                    </div>
                ) : (
                    <div className="alert alert-info shadow-sm mt-4">
                        <span className="iconify mdi--information text-xl"></span>
                        <span>Dieser Bereich ist aktuell für <strong>alle Fotografen</strong> freigegeben. Du musst keine expliziten Zuweisungen vornehmen.</span>
                    </div>
                )}
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}
