import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import {useState} from 'react';
import useSWR from 'swr';
import {apiMutate, fetcher, GenerateInviteResponse, InviteData} from '../../../api';
import {useUI} from '../../components/UIContext';

export interface InviteModalProps {
    galleryId: string;
    galleryType: string;
    onClose: () => void;
}

export default function InviteModal({galleryId, galleryType, onClose}: InviteModalProps) {
    const [name, setName] = useState('');
    const [canEditMeta, setCanEditMeta] = useState(false);
    const [loading, setLoading] = useState(false);
    const [newLink, setNewLink] = useState('');
    const [linkType, setLinkType] = useState<'mass' | 'personal'>('mass');
    const {showToast, confirm} = useUI();

    const {data: invites, isLoading, mutate} = useSWR<InviteData[]>(`/api/management/galleries/${galleryId}/invites`, fetcher);
    if (isLoading) return <div className="flex justify-center p-8"><span className="loading loading-spinner loading-lg"></span></div>;

    const handleGenerate = async () => {
        setLoading(true);
        try {
            const data = await apiMutate<GenerateInviteResponse>(`/api/management/galleries/${galleryId}/invites`, 'POST', {
                name,
                can_edit_metadata: canEditMeta
            });
            if (data.success) {
                setNewLink(data.link);
                setName('');
                mutate(); // Liste aktualisieren
            }
        } catch {
            showToast('error', t`Netzwerkfehler`);
        }
        setLoading(false);
    };

    const handleUpdate = async (id: string, newName: string) => {
        await apiMutate(`/api/management/invites/${id}`, 'PUT', {name: newName});
        mutate();
    };

    const handleDelete = async (id: string) => {
        if (!(await confirm({
            title: t`Einladung widerrufen?`,
            message: t`Einladung wirklich widerrufen? Der Link funktioniert dann nicht mehr.`,
            confirmText: t`Widerrufen`,
            confirmColor: 'error'
        }))) return;
        await apiMutate(`/api/management/invites/${id}`, 'DELETE');
        mutate();
    };

    const copyLink = (token: string | undefined = undefined) => {
        const url = token ? (window.location.origin + '/invite/' + token) : newLink;
        navigator.clipboard.writeText(url);
        showToast('success', t`Kopiert!`);
    };

    return (
        <div className="modal modal-open">
            <div className="modal-box relative max-w-2xl">
                <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-6 flex items-center gap-2">
                    <span className="iconify mdi--link-variant text-info"></span> <Trans>Einladungen verwalten</Trans>
                </h3>

                <div className="flex flex-col gap-8">
                    {/* Linke Spalte: Neu generieren */}
                    <div>
                        <h4 className="font-bold mb-2"><Trans>Neuen Link generieren</Trans></h4>
                        <div className="form-control mb-3">
                            <label
                                className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box w-full border border-base-300">
                                <input type="radio" name="linkType" className="radio-primary radio"
                                       checked={linkType === 'mass'} onChange={() => {
                                    setLinkType('mass');
                                    setName('');
                                }}/>
                                <div>
                                    <span className="label-text font-bold block"><Trans>Massen-Link (Gruppen)</Trans></span>
                                    <span className="label-text-alt opacity-70 block mt-1"><Trans>Gäste geben E-Mail & Name selbst ein.<br/>Wichtig, um Bewertungen von mehreren Personen sauber zu trennen.</Trans></span>
                                </div>
                            </label>
                        </div>
                        <div className="form-control mb-3">
                            <label
                                className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box w-full border border-base-300">
                                <input type="radio" name="linkType" className="radio-primary radio"
                                       checked={linkType === 'personal'} onChange={() => setLinkType('personal')}/>
                                <div>
                                    <span className="label-text font-bold block"><Trans>Persönlicher Link (Einzelperson)</Trans></span>
                                    <span className="label-text-alt opacity-70 block mt-1"><Trans>Der Gast wird direkt ohne Anmeldung durchgewunken.</Trans></span>
                                </div>
                            </label>
                        </div>
                        {linkType === 'personal' && (
                            <div className="form-control pl-8 border-l-2 border-primary ml-2 mb-2">
                                <label className="label py-1"><span
                                    className="label-text font-bold">Name des Gastes</span></label>
                                <input type="text" value={name} onChange={e => setName(e.target.value)}
                                        placeholder={t`z.B. Oma Erna`}
                                       className="input input-bordered w-full"/>
                            </div>
                        )}

                        {galleryType === 'delivery' && (
                            <div className="form-control mb-3">
                                <label
                                    className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box border border-base-300 hover:bg-base-300/50 transition-colors">
                                    <input type="checkbox" className="checkbox-primary checkbox shrink-0" checked={canEditMeta}
                                           onChange={e => setCanEditMeta(e.target.checked)}/>
                                    <div>
                                        <span
                                            className="label-text font-bold block">Gast darf Metadaten bearbeiten</span>
                                        <span className="label-text-alt opacity-70 block mt-1">Ermöglicht dem Empfänger dieses Links das Ändern von IPTC Titeln und Beschreibungen.</span>
                                    </div>
                                </label>
                            </div>
                        )}

                        {newLink ? (
                            <div className="mt-4 p-3 bg-success/10 border border-success/20 rounded-box">
                                <p className="text-sm font-bold text-success mb-2"><Trans>Erfolgreich generiert!</Trans></p>
                                <div className="flex gap-2">
                                    <input type="text" readOnly value={newLink}
                                           className="input input-xs input-bordered w-full"/>
                                    <button className="btn btn-xs btn-success text-white"
                                            onClick={() => copyLink()}><Trans>Kopieren</Trans>
                                    </button>
                                </div>
                            </div>
                        ) : (
                            <button className="btn btn-primary btn-sm w-full mt-4" onClick={handleGenerate}
                                    disabled={loading}>
                                {loading ? <span className="loading loading-spinner"></span> : <Trans>Generieren</Trans>}
                            </button>
                        )}
                    </div>

                    {/* Rechte Spalte: Vorhandene verwalten */}
                    <div>
                        <h4 className="font-bold mb-2"><Trans>Bestehende Links</Trans></h4>
                        <div className="overflow-y-auto max-h-80 rounded-box border border-base-300">
                            <table className="table table-xs table-pin-rows table-pin-cols w-full">
                                <thead className="bg-base-200">
                                <tr>
                                    <th><Trans>Name</Trans></th>
                                    <th className="text-right"><Trans>Aktion</Trans></th>
                                </tr>
                                </thead>
                                <tbody>
                                {invites?.map(inv => (
                                    <tr key={inv.id}>
                                        <td className="p-1">
                                            <input
                                                type="text"
                                                className="input input-xs input-ghost w-full focus:bg-base-200"
                                                defaultValue={inv.name || ''}
                                                placeholder={t`Anonym`}
                                                onBlur={e => {
                                                    if (e.target.value !== inv.name) handleUpdate(inv.id, e.target.value);
                                                }}
                                            />
                                        </td>
                                        <td className="text-right p-1 whitespace-nowrap">
                                            <button className="btn btn-xs btn-ghost btn-square text-info"
                                                    title={t`Link kopieren`} onClick={() => copyLink(inv.token)}>
                                                <span className="iconify mdi--content-copy text-base"></span>
                                            </button>
                                            <button className="btn btn-xs btn-ghost btn-square text-error"
                                                    title={t`Widerrufen`} onClick={() => handleDelete(inv.id)}>
                                                <span className="iconify mdi--trash-can text-base"></span>
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                                {(!invites || invites.length === 0) && (
                                    <tr>
                                        <td colSpan={2} className="text-center opacity-50 py-4"><Trans>Noch keine Einladungen</Trans>
                                        </td>
                                    </tr>
                                )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div className="modal-action col-span-full mt-6">
                    <button className="btn" onClick={onClose}><Trans>Schließen</Trans></button>
                </div>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}
