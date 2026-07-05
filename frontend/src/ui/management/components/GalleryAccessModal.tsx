import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import {useState} from 'react';
import useSWR from 'swr';
import {apiMutate, fetcher} from '../../../api';
import {useUI} from '../../components/UIContext';

interface GalleryBase {
    id: string;
}

interface UserAccess {
    id: string;
    name: string;
    email: string;
    is_super_admin?: boolean;
    galleries: GalleryBase[];
}

export interface GalleryAccessModalProps {
    galleryId: string;
    galleryName: string;
    isOpen: boolean;
    onClose: () => void;
}

export default function GalleryAccessModal({galleryId, galleryName, isOpen, onClose}: GalleryAccessModalProps) {
    const {data: response, isLoading, mutate} = useSWR<{data: UserAccess[]} | UserAccess[]>('/api/management/users', fetcher);
    const {showToast} = useUI();
    const [search, setSearch] = useState('');
    const [processingId, setProcessingId] = useState<string | null>(null);

    const users = Array.isArray(response) ? response : response?.data;

    if (!isOpen) return null;
    if (isLoading) return <div className="flex justify-center p-8"><span className="loading loading-spinner loading-lg"></span></div>;

    // Wir filtern Super-Admins raus, da die ohnehin alles sehen.
    const filteredUsers = users?.filter(u =>
        !u.is_super_admin &&
        (u.name.toLowerCase().includes(search.toLowerCase()) || u.email.toLowerCase().includes(search.toLowerCase()))
    );

    const toggleAccess = async (userId: string, hasAccess: boolean) => {
        setProcessingId(userId);
        try {
            await apiMutate(`/api/management/galleries/${galleryId}/sync-access`, 'POST', {
                user_id: userId, action: hasAccess ? 'detach' : 'attach'
            });
            showToast('success', hasAccess ? t`Zugriff entzogen` : t`Zugriff erteilt`);
            mutate();
        } catch (e: unknown) {
            showToast('error', e instanceof Error ? e.message : t`Fehler beim Speichern`);
        } finally {
            setProcessingId(null);
        }
    };

    return (
        <div className="modal modal-open">
            <div className="modal-box max-w-2xl relative flex flex-col max-h-80vh">
                <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-2 flex items-center gap-2">
                    <span className="iconify mdi--account-key text-primary"></span> <Trans>Nutzer-Zugriff verwalten</Trans>
                </h3>
                <p className="text-sm opacity-70 mb-4"><Trans>Galerie:</Trans> <strong>{galleryName}</strong></p>

                <input
                    type="text"
                    placeholder={t`Nutzer suchen...`}
                    className="input input-bordered w-full mb-4 shrink-0"
                    value={search}
                    onChange={e => setSearch(e.target.value)}
                />

                <div className="flex-1 overflow-y-auto border border-base-300 rounded-box p-2">
                    {isLoading ? (
                        <div className="flex justify-center p-8"><span className="loading loading-spinner"></span></div>
                    ) : (
                        <div className="flex flex-col gap-1">
                            {filteredUsers?.map(u => {
                                const hasAccess = u.galleries.some(g => g.id === galleryId);
                                return (
                                    <div key={u.id}
                                         className="flex items-center justify-between p-2 hover:bg-base-200 rounded">
                                        <div>
                                            <div className="font-bold">{u.name}</div>
                                            <div className="text-sm opacity-70">{u.email}</div>
                                        </div>
                                        <button
                                            className={`btn btn-sm w-28 ${hasAccess ? 'btn-error btn-outline' : 'btn-primary'}`}
                                            onClick={() => toggleAccess(u.id, hasAccess)}
                                            disabled={processingId === u.id}
                                        >
                                            {processingId === u.id ? <span
                                                className="loading loading-spinner"></span> : (hasAccess ? <Trans>Entfernen</Trans> : <Trans>Hinzufügen</Trans>)}
                                        </button>
                                    </div>
                                );
                            })}
                        </div>
                    )}
                </div>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}