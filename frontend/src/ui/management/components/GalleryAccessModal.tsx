import { t } from "@lingui/core/macro";
import { Trans, Plural } from "@lingui/react/macro";
import {useState} from 'react';
import useSWR from 'swr';
import {apiMutate, fetcher} from '../../../api';
import {useUI} from '../../components/UIContext';
import ModalShell from '../../components/ModalShell';

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
        // Migrated onto the shell's bounded layout. It needed the region the old
        // comment asked for — one *between* the header and the body — which is
        // what `bodyHead` is: the gallery line, the search field and the count
        // stay pinned while the list scrolls under them. The bound is named now
        // (`height="80vh"`), so the shell emits one `max-h-80vh` instead of
        // 80vh stacking against its own 90vh default.
        <ModalShell
            title={<Trans>Nutzer-Zugriff verwalten</Trans>}
            icon="mdi--account-key"
            onClose={onClose}
            boxClassName="max-w-2xl"
            height="80vh"
            scrollableBody
            bodyClassName="border border-base-300 rounded-box p-2"
            bodyHead={
                <>
                    <p className="text-sm opacity-70 mb-4"><Trans>Galerie:</Trans> <strong>{galleryName}</strong></p>

                    <input
                        type="text"
                        placeholder={t`Nutzer suchen...`}
                        className="input input-bordered w-full mb-2"
                        value={search}
                        onChange={e => setSearch(e.target.value)}
                    />

                    {/* The list below is the shell's scroll region, so its bottom
                        edge falls wherever the remaining space ends — mid-row.
                        With more users than fit, the last visible row was sliced
                        through the middle of its glyphs, which reads as a
                        rendering accident instead of as "there is more below".
                        Two affordances, neither of which touches the data: the
                        count states how many entries the list holds, and the
                        fade covers the cut-off row instead of guillotining it.
                        `sticky bottom-0` rather than an absolutely positioned
                        overlay, so the fade stays pinned to the bottom of the
                        scrollport at any offset and comes to rest in flow once
                        the end of the list is reached. */}
                    <p className="text-sm opacity-70 mb-2">
                        <Plural value={filteredUsers?.length ?? 0} one="# Nutzer" other="# Nutzer" />
                    </p>
                </>
            }
        >
                {isLoading ? (
                    <div className="flex justify-center p-8"><span className="loading loading-spinner"></span></div>
                ) : (
                    <div className="flex flex-col gap-1">
                        {filteredUsers?.map(u => {
                            const hasAccess = u.galleries.some(g => g.id === galleryId);
                            return (
                                // Name and action share one line, the email sits
                                // below it: an action centred against the whole
                                // name/email block lands between the two lines
                                // instead of on the name's line. This mirrors the
                                // label/action row in ManagementFtpInbox.
                                <div key={u.id}
                                     className="p-2 hover:bg-base-200 rounded">
                                    <div className="flex items-center justify-between gap-3">
                                        <div className="font-bold min-w-0 break-words">{u.name}</div>
                                        <button
                                            className={`btn btn-sm w-28 shrink-0 ${hasAccess ? 'btn-error btn-outline' : 'btn-primary'}`}
                                            onClick={() => toggleAccess(u.id, hasAccess)}
                                            disabled={processingId === u.id}
                                        >
                                            {processingId === u.id ? <span
                                                className="loading loading-spinner"></span> : (hasAccess ? <Trans>Entfernen</Trans> : <Trans>Hinzufügen</Trans>)}
                                        </button>
                                    </div>
                                    <div className="text-sm opacity-70 break-words mt-1">{u.email}</div>
                                </div>
                            );
                        })}
                    </div>
                )}
                <div aria-hidden="true"
                     className="sticky bottom-0 h-8 pointer-events-none rounded-b-box bg-linear-to-t from-base-100 to-transparent"></div>
        </ModalShell>
    );
}