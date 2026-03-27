import { useState } from 'react';
import useSWR from 'swr';
import {apiMutate, fetcher} from '../../../api';
import { useUI } from '../../components/UIContext';

export default function InviteModal({galleryId, onClose}: { galleryId: string, onClose: () => void }) {
    const [name, setName] = useState('');
    const [loading, setLoading] = useState(false);
    const [newLink, setNewLink] = useState('');
    const [linkType, setLinkType] = useState<'mass' | 'personal'>('mass');
    const { showToast, confirm } = useUI();

    const {data: invites, mutate} = useSWR<{
        id: string,
        name: string,
        token: string
    }[]>(`/api/management/galleries/${galleryId}/invites`, fetcher);

    const handleGenerate = async () => {
        setLoading(true);
        try {
            const data = await apiMutate<{
                success: boolean,
                link: string
            }>(`/api/management/galleries/${galleryId}/invites`, 'POST', {name});
            if (data.success) {
                setNewLink(data.link);
                setName('');
                mutate(); // Liste aktualisieren
            }
        } catch {
            showToast('error', 'Netzwerkfehler');
        }
        setLoading(false);
    };

    const handleUpdate = async (id: string, newName: string) => {
        await apiMutate(`/api/management/invites/${id}`, 'PUT', {name: newName});
        mutate();
    };

    const handleDelete = async (id: string) => {
        if (!(await confirm({ title: 'Einladung widerrufen?', message: 'Einladung wirklich widerrufen? Der Link funktioniert dann nicht mehr.', confirmText: 'Widerrufen', confirmColor: 'error' }))) return;
        await apiMutate(`/api/management/invites/${id}`, 'DELETE');
        mutate();
    };

    const copyLink = (token: string | undefined = undefined) => {
        const url = token ? (window.location.origin + '/invite/' + token) : newLink;
        navigator.clipboard.writeText(url);
        showToast('success', 'Kopiert!');
    };

    return (
        <div className="modal modal-open">
            <div className="modal-box relative max-w-2xl">
                <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-6 flex items-center gap-2">
                    <span className="iconify mdi--link-variant text-info"></span> Einladungen verwalten
                </h3>

                <div className="flex flex-col gap-8">
                    {/* Linke Spalte: Neu generieren */}
                    <div>
                        <h4 className="font-bold mb-2">Neuen Link generieren</h4>
                        <div className="form-control mb-3">
                            <label className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box w-full border border-base-300">
                                <input type="radio" name="linkType" className="radio radio-primary radio-sm" checked={linkType === 'mass'} onChange={() => { setLinkType('mass'); setName(''); }} />
                                <div>
                                    <span className="label-text font-bold block">Massen-Link (Gruppen)</span>
                                    <span className="label-text-alt opacity-70 block mt-1">Gäste geben E-Mail & Name selbst ein.<br/>Wichtig, um Bewertungen von mehreren Personen sauber zu trennen.</span>
                                </div>
                            </label>
                        </div>
                        <div className="form-control mb-3">
                            <label className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box w-full border border-base-300">
                                <input type="radio" name="linkType" className="radio radio-primary radio-sm" checked={linkType === 'personal'} onChange={() => setLinkType('personal')} />
                                <div>
                                    <span className="label-text font-bold block">Persönlicher Link (Einzelperson)</span>
                                    <span className="label-text-alt opacity-70 block mt-1">Der Gast wird direkt ohne Anmeldung durchgewunken.</span>
                                </div>
                            </label>
                        </div>
                        {linkType === 'personal' && (
                            <div className="form-control pl-8 border-l-2 border-primary ml-2 mb-2">
                                <label className="label py-1"><span className="label-text font-bold">Name des Gastes</span></label>
                                <input type="text" value={name} onChange={e => setName(e.target.value)} placeholder="z.B. Oma Erna" className="input input-sm input-bordered w-full" />
                            </div>
                        )}

                        {newLink ? (
                            <div className="mt-4 p-3 bg-success/10 border border-success/20 rounded-box">
                                <p className="text-xs font-bold text-success mb-2">Erfolgreich generiert!</p>
                                <div className="flex gap-2">
                                    <input type="text" readOnly value={newLink}
                                           className="input input-xs input-bordered w-full"/>
                                    <button className="btn btn-xs btn-success text-white"
                                            onClick={() => copyLink()}>Kopieren
                                    </button>
                                </div>
                            </div>
                        ) : (
                            <button className="btn btn-primary btn-sm w-full mt-4" onClick={handleGenerate}
                                    disabled={loading}>
                                {loading ? <span className="loading loading-spinner"></span> : 'Generieren'}
                            </button>
                        )}
                    </div>

                    {/* Rechte Spalte: Vorhandene verwalten */}
                    <div>
                        <h4 className="font-bold mb-2">Bestehende Links</h4>
                        <div className="overflow-y-auto max-h-80 rounded-box border border-base-300">
                            <table className="table table-xs table-pin-rows table-pin-cols w-full">
                                <thead className="bg-base-200">
                                <tr>
                                    <th>Name</th>
                                    <th className="text-right">Aktion</th>
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
                                                placeholder="Anonym"
                                                onBlur={e => {
                                                    if (e.target.value !== inv.name) handleUpdate(inv.id, e.target.value);
                                                }}
                                            />
                                        </td>
                                        <td className="text-right p-1 whitespace-nowrap">
                                            <button className="btn btn-xs btn-ghost btn-square text-info"
                                                    title="Link kopieren" onClick={() => copyLink(inv.token)}>
                                                <span className="iconify mdi--content-copy text-base"></span>
                                            </button>
                                            <button className="btn btn-xs btn-ghost btn-square text-error"
                                                    title="Widerrufen" onClick={() => handleDelete(inv.id)}>
                                                <span className="iconify mdi--trash-can text-base"></span>
                                            </button>
                                        </td>
                                    </tr>
                                ))}
                                {(!invites || invites.length === 0) && (
                                    <tr>
                                        <td colSpan={2} className="text-center opacity-50 py-4">Noch keine Einladungen
                                        </td>
                                    </tr>
                                )}
                                </tbody>
                            </table>
                        </div>
                    </div>
                </div>

                <div className="modal-action mt-6">
                    <button className="btn" onClick={onClose}>Schließen</button>
                </div>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}
