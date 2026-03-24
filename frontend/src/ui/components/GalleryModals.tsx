import { useState } from 'react';
import {Gallery, GalleryGroup, FlatGroup} from '../../logic/useGalleries';
import IptcMetadataEditor, { IptcData } from './IptcMetadataEditor';
import { useUI } from './UIContext';

interface GalleryModalsProps {
    availableGroups: FlatGroup[];
    isGroupModalOpen: boolean;
    setGroupModalOpen: (open: boolean) => void;
    isGalleryModalOpen: boolean;
    setGalleryModalOpen: (open: boolean) => void;

    editingGroup?: GalleryGroup | null;
    editingGallery?: Gallery | null;

    onCreateGroup: (name: string, slug: string, isPublic: boolean | null, parentId?: number | null) => Promise<void>;
    onCreateGallery: (name: string, slug: string, type: 'selection' | 'delivery', isLive: boolean, isPublic: boolean, parentId?: number | null, pw?: string, exp?: string, metadataOpts?: any) => Promise<void>;
    onUpdateGroup: (id: number, name: string, slug: string, isPublic: boolean | null, parentId?: number | null) => Promise<void>;
    onUpdateGallery: (id: number, name: string, slug: string, type: 'selection' | 'delivery', isLive: boolean, isPublic: boolean, parentId?: number | null, pw?: string, exp?: string, metadataOpts?: any) => Promise<void>;
    onDeleteGroup: (id: number) => Promise<void>;
    onDeleteGallery: (id: number) => Promise<void>;
}

export default function GalleryModals({
                                          availableGroups,
                                          isGroupModalOpen,
                                          setGroupModalOpen,
                                          isGalleryModalOpen,
                                          setGalleryModalOpen,
                                          editingGroup,
                                          editingGallery,
                                          onCreateGroup,
                                          onCreateGallery,
                                          onUpdateGroup,
                                          onUpdateGallery,
                                          onDeleteGroup,
                                          onDeleteGallery
                                      }: GalleryModalsProps) {
    const [returnToGallery, setReturnToGallery] = useState(false);
    const [processing, setProcessing] = useState(false);
    const { showToast, confirm } = useUI();

    const toSlug = (text: string) => text.toLowerCase().replace(/ä/g, 'ae').replace(/ö/g, 'oe').replace(/ü/g, 'ue').replace(/ß/g, 'ss').replace(/[^a-z0-9]+/g, '-').replace(/(^-|-$)/g, '');

    // Group State
    const [newGroupName, setNewGroupName] = useState('');
    const [newGroupSlug, setNewGroupSlug] = useState('');
    const [groupSlugEdited, setGroupSlugEdited] = useState(false);
    const [newGroupIsPublic, setNewGroupIsPublic] = useState<'null' | 'true' | 'false'>('null');
    const [groupParentId, setGroupParentId] = useState<number | ''>('');

    // Gallery State
    const [newGalleryName, setNewGalleryName] = useState('');
    const [newGallerySlug, setNewGallerySlug] = useState('');
    const [gallerySlugEdited, setGallerySlugEdited] = useState(false);
    const [newGalleryType, setNewGalleryType] = useState<'selection' | 'delivery'>('delivery');
    const [newGalleryIsPublic, setNewGalleryIsPublic] = useState(false);
    const [newGalleryIsLive, setNewGalleryIsLive] = useState(false);
    const [galleryParentId, setGalleryParentId] = useState<number | ''>('');
    const [newGalleryPassword, setNewGalleryPassword] = useState('');
    const [newGalleryExpiresAt, setNewGalleryExpiresAt] = useState('');
    const [allowClientMeta, setAllowClientMeta] = useState(false);
    const [applyMeta, setApplyMeta] = useState(false);
    const [iptcData, setIptcData] = useState<IptcData>({});

    // Sync Edit States via Render Phase Update
    const [prevGroupState, setPrevGroupState] = useState({
        open: false,
        group: undefined as GalleryGroup | null | undefined
    });
    if (isGroupModalOpen !== prevGroupState.open || editingGroup !== prevGroupState.group) {
        setPrevGroupState({open: isGroupModalOpen, group: editingGroup});
        if (isGroupModalOpen) {
            if (editingGroup) {
                setNewGroupName(editingGroup.name);
                setNewGroupSlug(editingGroup.slug || '');
                setGroupSlugEdited(true);
                setNewGroupIsPublic(editingGroup.is_public === null ? 'null' : (editingGroup.is_public ? 'true' : 'false'));
                setGroupParentId(editingGroup.parent_id || '');
            } else {
                setNewGroupName('');
                setNewGroupSlug('');
                setGroupSlugEdited(false);
                setNewGroupIsPublic('null');
                setGroupParentId('');
            }
        }
    }

    const [prevGalleryState, setPrevGalleryState] = useState({
        open: false,
        gallery: undefined as Gallery | null | undefined
    });
    if (isGalleryModalOpen !== prevGalleryState.open || editingGallery !== prevGalleryState.gallery) {
        setPrevGalleryState({open: isGalleryModalOpen, gallery: editingGallery});
        if (isGalleryModalOpen) {
            if (editingGallery) {
                setNewGalleryName(editingGallery.name);
                setNewGallerySlug(editingGallery.slug || '');
                setGallerySlugEdited(true);
                setNewGalleryType(editingGallery.type);
                setNewGalleryIsPublic(editingGallery.is_public);
                setNewGalleryIsLive(editingGallery.is_live);
                setGalleryParentId(editingGallery.gallery_group_id || '');
                setNewGalleryPassword(''); // pw is hidden, so empty
                setNewGalleryExpiresAt(editingGallery.expires_at ? editingGallery.expires_at.split('T')[0] : '');
                setAllowClientMeta(editingGallery.allow_client_metadata_edit || false);
                setApplyMeta(editingGallery.apply_metadata_to_photos || false);
                setIptcData({
                    title: editingGallery.default_title || '',
                    headline: editingGallery.default_headline || '',
                    description: editingGallery.default_description || '',
                    keywords: editingGallery.default_keywords || '',
                    location: editingGallery.default_location || '',
                    city: editingGallery.default_city || '',
                    state: editingGallery.default_state || '',
                    country: editingGallery.default_country || '',
                    iso_country: editingGallery.default_iso_country || ''
                });
            } else {
                setNewGalleryName('');
                setNewGallerySlug('');
                setGallerySlugEdited(false);
                setNewGalleryType('delivery');
                setNewGalleryIsPublic(false);
                setNewGalleryIsLive(false);
                setGalleryParentId('');
                setNewGalleryPassword('');
                setNewGalleryExpiresAt('');
                setAllowClientMeta(false);
                setApplyMeta(false);
                setIptcData({});
            }
        }
    }

    const handleGroupNameChange = (val: string) => {
        setNewGroupName(val);
        if (!groupSlugEdited) setNewGroupSlug(toSlug(val));
    };

    const handleGalleryNameChange = (val: string) => {
        setNewGalleryName(val);
        if (!gallerySlugEdited) setNewGallerySlug(toSlug(val));
    };

    const closeGroupModal = () => {
        setGroupModalOpen(false);
        if (returnToGallery) {
            setGalleryModalOpen(true);
            setReturnToGallery(false);
        }
    };

    const handleGroupSubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!newGroupName.trim()) return;
        setProcessing(true);
        const isPub = newGroupIsPublic === 'null' ? null : newGroupIsPublic === 'true';
        try {
            if (editingGroup) {
                await onUpdateGroup(editingGroup.id, newGroupName, newGroupSlug, isPub, groupParentId === '' ? null : Number(groupParentId));
            } else {
                await onCreateGroup(newGroupName, newGroupSlug, isPub, groupParentId === '' ? null : Number(groupParentId));
            }
            closeGroupModal();
        } catch {
            showToast('error', 'Fehler beim Speichern');
        }
        setProcessing(false);
    };

    const handleGallerySubmit = async (e: React.FormEvent) => {
        e.preventDefault();
        if (!newGalleryName.trim()) return;
        setProcessing(true);
        try {
            if (editingGallery) {
                const metaOpts = { allow_client_metadata_edit: allowClientMeta, apply_metadata_to_photos: applyMeta, default_title: iptcData.title, default_headline: iptcData.headline, default_description: iptcData.description, default_keywords: iptcData.keywords, default_location: iptcData.location, default_city: iptcData.city, default_state: iptcData.state, default_country: iptcData.country, default_iso_country: iptcData.iso_country };
                await onUpdateGallery(editingGallery.id, newGalleryName, newGallerySlug, newGalleryType, newGalleryIsLive, newGalleryIsPublic, galleryParentId === '' ? null : Number(galleryParentId), newGalleryPassword, newGalleryExpiresAt, metaOpts);
            } else {
                const metaOpts = { allow_client_metadata_edit: allowClientMeta, apply_metadata_to_photos: applyMeta, default_title: iptcData.title, default_headline: iptcData.headline, default_description: iptcData.description, default_keywords: iptcData.keywords, default_location: iptcData.location, default_city: iptcData.city, default_state: iptcData.state, default_country: iptcData.country, default_iso_country: iptcData.iso_country };
                await onCreateGallery(newGalleryName, newGallerySlug, newGalleryType, newGalleryIsLive, newGalleryIsPublic, galleryParentId === '' ? null : Number(galleryParentId), newGalleryPassword, newGalleryExpiresAt, metaOpts);
            }
            setGalleryModalOpen(false);
        } catch {
            showToast('error', 'Fehler beim Speichern');
        }
        setProcessing(false);
    };

    const handleTypeChange = (val: 'selection' | 'delivery') => {
        setNewGalleryType(val);
        if (val === 'selection') setNewGalleryIsLive(false);
    };

    const selectedParent = availableGroups.find(g => g.id === (galleryParentId === '' ? null : Number(galleryParentId)));
    let isVisibilityForced = selectedParent?.is_public !== undefined && selectedParent?.is_public !== null;
    let forcedVisibility = selectedParent?.is_public;

    if (newGalleryType === 'selection') {
        isVisibilityForced = true;
        forcedVisibility = false;
    }

    return (
        <>
            <dialog className={`modal ${isGroupModalOpen ? 'modal-open' : ''}`}>
                <div className="modal-box relative">
                    <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2"
                            onClick={closeGroupModal}>✕
                    </button>
                    <h3 className="font-bold text-lg mb-4">{editingGroup ? 'Meta-Galerie bearbeiten' : 'Neue Meta-Galerie erstellen'}</h3>
                    <form onSubmit={handleGroupSubmit}>
                        <div className="flex flex-col md:flex-row gap-4 mb-4">
                            <div className="form-control w-full md:w-1/2">
                                <label className="label"><span className="label-text font-bold">Name</span></label>
                                <input type="text" required value={newGroupName}
                                       onChange={e => handleGroupNameChange(e.target.value)}
                                       className="input input-bordered w-full"/>
                            </div>
                            <div className="form-control w-full md:w-1/2">
                                <label className="label"><span className="label-text font-bold">URL Slug</span></label>
                                <input type="text" value={newGroupSlug} onChange={e => {
                                    setNewGroupSlug(toSlug(e.target.value));
                                    setGroupSlugEdited(true);
                                }} className="input input-bordered w-full text-sm font-mono"/>
                            </div>
                        </div>

                        <div className="form-control w-full mb-4">
                            <label className="label"><span className="label-text font-bold">Sichtbarkeits-Vorgabe</span></label>
                            <select value={newGroupIsPublic}
                                    onChange={e => setNewGroupIsPublic(e.target.value as 'null' | 'true' | 'false')}
                                    className="select select-bordered w-full">
                                <option value="null">Keine Vorgabe (Unterordner entscheiden selbst)</option>
                                <option value="false">Privat erzwingen (Nur mit Link / Passwort)</option>
                                <option value="true">Öffentlich erzwingen (Für alle sichtbar)</option>
                            </select>
                        </div>

                        <div className="form-control w-full mb-6">
                            <label className="label"><span
                                className="label-text font-bold">Übergeordnete Meta-Galerie</span></label>
                            <select value={groupParentId}
                                    onChange={e => setGroupParentId(e.target.value ? Number(e.target.value) : '')}
                                    className="select select-bordered w-full">
                                <option value="">-- Keine --</option>
                                {availableGroups.filter(g => g.id !== editingGroup?.id).map(g => <option key={g.id}
                                                                                                         value={g.id}>{'- '.repeat(g.depth)}{g.name}</option>)}
                            </select>
                        </div>
                        
                        {newGalleryType === 'delivery' && (
                            <div className="mt-6 pt-6 border-t border-base-300">
                                <h4 className="font-bold text-lg mb-4">Metadaten & Berechtigungen</h4>
                                
                                <div className="form-control mb-4">
                                    <label className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box w-full">
                                        <input type="checkbox" checked={allowClientMeta}
                                               onChange={e => setAllowClientMeta(e.target.checked)}
                                               className="checkbox checkbox-primary"/>
                                        <div>
                                            <span className="label-text font-bold block">Kunden dürfen Metadaten bearbeiten</span>
                                            <span className="label-text-alt opacity-70 whitespace-normal break-words leading-tight inline-block mt-1">
                                                Erlaubt Kunden mit der Rolle "Metadaten bearbeiten" das Ändern von IPTC-Daten in dieser Galerie.
                                            </span>
                                        </div>
                                    </label>
                                </div>

                                <div className="form-control mb-4">
                                    <label className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box w-full">
                                        <input type="checkbox" checked={applyMeta}
                                               onChange={e => setApplyMeta(e.target.checked)}
                                               className="checkbox checkbox-primary"/>
                                        <div>
                                            <span className="label-text font-bold block">Standard-Metadaten beim Upload anwenden</span>
                                            <span className="label-text-alt opacity-70 whitespace-normal break-words leading-tight inline-block mt-1">
                                                Überschreibt leere Felder bei neu hochgeladenen Bildern mit den untenstehenden Werten.
                                            </span>
                                        </div>
                                    </label>
                                </div>

                                {applyMeta && (
                                    <div className="mb-6">
                                        <IptcMetadataEditor data={iptcData} onChange={setIptcData} showArtist={false} />
                                    </div>
                                )}
                            </div>
                        )}
                        
                        <div className="modal-action flex justify-between">
                            {editingGroup ? (
                                <button type="button" className="btn btn-outline btn-error" onClick={async () => {
                                    if (await confirm({ title: 'Meta-Galerie löschen?', message: 'ACHTUNG: Alle Unterordner werden dabei in die Root-Ebene verschoben!', confirmText: 'Löschen', confirmColor: 'error' })) {
                                        setProcessing(true);
                                        await onDeleteGroup(editingGroup.id);
                                        setProcessing(false);
                                        closeGroupModal();
                                    }
                                }}>Löschen</button>
                            ) : <div></div>}
                            <div>
                                <button type="button" className="btn btn-ghost mr-2"
                                        onClick={closeGroupModal}>Abbrechen
                                </button>
                                <button type="submit" className="btn btn-primary" disabled={processing}>{processing ?
                                    <span className="loading loading-spinner"></span> : 'Speichern'}</button>
                            </div>
                        </div>
                    </form>
                </div>
                <div className="modal-backdrop"></div>
            </dialog>

            <dialog className={`modal ${isGalleryModalOpen ? 'modal-open' : ''}`}>
                <div className="modal-box max-w-2xl relative">
                    <button className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2"
                            onClick={() => setGalleryModalOpen(false)}>✕
                    </button>
                    <div className="flex justify-between items-center mb-6 mr-8">
                        <h3 className="font-bold text-lg">{editingGallery ? 'Galerie bearbeiten' : 'Neue Galerie erstellen'}</h3>
                        {!editingGallery && (
                            <button type="button" className="btn btn-xs btn-outline" onClick={() => {
                                setGalleryModalOpen(false);
                                setReturnToGallery(true);
                                setGroupModalOpen(true);
                            }}>
                                Ordner / Meta-Galerie erstellen
                            </button>
                        )}
                    </div>

                    <form onSubmit={handleGallerySubmit}>
                        <div className="flex flex-col md:flex-row gap-4 mb-4">
                            <div className="form-control w-full md:w-1/2">
                                <label className="label"><span className="label-text font-bold">Name der Galerie</span></label>
                                <input type="text" required value={newGalleryName}
                                       onChange={e => handleGalleryNameChange(e.target.value)}
                                       className="input input-bordered w-full"/>
                            </div>
                            <div className="form-control w-full md:w-1/2">
                                <label className="label"><span className="label-text font-bold">URL Slug</span></label>
                                <input type="text" value={newGallerySlug} onChange={e => {
                                    setNewGallerySlug(toSlug(e.target.value));
                                    setGallerySlugEdited(true);
                                }} className="input input-bordered w-full text-sm font-mono"/>
                            </div>
                        </div>

                        <div className="flex flex-col md:flex-row gap-4 mb-4">
                            <div className="form-control w-full md:w-1/2">
                                <label className="label"><span
                                    className="label-text font-bold">Galerie-Typ</span></label>
                                <select required value={newGalleryType}
                                        onChange={e => handleTypeChange(e.target.value as 'selection' | 'delivery')}
                                        className="select select-bordered w-full">
                                    <option value="delivery">Delivery (Downloads)</option>
                                    <option value="selection">Auswahl (Ratings)</option>
                                </select>
                            </div>
                            <div className="form-control w-full md:w-1/2">
                                <label className="label"><span
                                    className="label-text font-bold">Sichtbarkeit</span></label>
                                <select
                                    required
                                    disabled={isVisibilityForced}
                                    value={isVisibilityForced ? (forcedVisibility ? 'true' : 'false') : (newGalleryIsPublic ? 'true' : 'false')}
                                    onChange={e => setNewGalleryIsPublic(e.target.value === 'true')}
                                    className="select select-bordered w-full"
                                >
                                    <option value="false">Privat (Nur mit Link / Passwort)</option>
                                    <option value="true">Öffentlich (Für alle sichtbar)</option>
                                </select>
                                {isVisibilityForced && (
                                    <label className="label pt-1 pb-0">
                                        <span className="label-text-alt text-warning leading-tight whitespace-normal break-words">
                                            {newGalleryType === 'selection' ? 'Bewertungs-Galerien sind zwingend privat.' : 'Wird durch Meta-Galerie erzwungen'}
                                        </span>
                                    </label>
                                )}
                            </div>
                        </div>

                        <div className="form-control w-full mb-4">
                            <label className="label"><span className="label-text font-bold">In welchem Ordner soll die Galerie liegen?</span></label>
                            <select value={galleryParentId}
                                    onChange={e => setGalleryParentId(e.target.value ? Number(e.target.value) : '')}
                                    className="select select-bordered w-full">
                                <option value="">-- Oberste Ebene (Root) --</option>
                                {availableGroups.map(g => <option key={g.id}
                                                                  value={g.id}>{'- '.repeat(g.depth)}{g.name}</option>)}
                            </select>
                        </div>

                        {newGalleryType === 'delivery' && (
                            <div className="form-control w-full mb-4">
                                <label className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box w-full">
                                    <input type="checkbox" checked={newGalleryIsLive}
                                           onChange={e => setNewGalleryIsLive(e.target.checked)}
                                           className="checkbox checkbox-primary"/>
                                    <div>
                                        <span className="label-text font-bold block">LIVE Galerie</span>
                                        <span
                                            className="label-text-alt opacity-70 whitespace-normal break-words leading-tight inline-block mt-1">Die Galerie aktualisiert sich für Besucher automatisch alle 10s.</span>
                                    </div>
                                </label>
                            </div>
                        )}

                        <div className="flex flex-col md:flex-row gap-4 mb-6">
                            <div className="form-control w-full md:w-1/2">
                                <label className="label">
                                    <span className="label-text font-bold">Passwort</span>
                                </label>
                                <input type="text" value={newGalleryPassword}
                                       onChange={e => setNewGalleryPassword(e.target.value)}
                                       className="input input-bordered w-full" 
                                       placeholder={editingGallery ? "Leer = Aktuelles Passwort behalten" : "Leer = Nur Magic Link"}/>
                                {editingGallery && (
                                    <label className="label pt-1 pb-0">
                                        <span className="label-text-alt opacity-70 whitespace-normal break-words leading-tight">Nur ausfüllen, um das bestehende Passwort zu überschreiben.</span>
                                    </label>
                                )}
                            </div>
                            <div className="form-control w-full md:w-1/2">
                                <label className="label"><span
                                    className="label-text font-bold">Ablaufdatum</span></label>
                                <input type="date" value={newGalleryExpiresAt}
                                       onChange={e => setNewGalleryExpiresAt(e.target.value)}
                                       className="input input-bordered w-full"/>
                            </div>
                        </div>

                        <div className="modal-action flex justify-between">
                            {editingGallery ? (
                                <button type="button" className="btn btn-outline btn-error" onClick={async () => {
                                    if (await confirm({ title: 'Galerie löschen?', message: 'Diese Galerie inklusive aller Bilder wirklich löschen? Dieser Schritt kann nicht rückgängig gemacht werden!', confirmText: 'Unwiderruflich löschen', confirmColor: 'error' })) {
                                        setProcessing(true);
                                        await onDeleteGallery(editingGallery.id);
                                        setProcessing(false);
                                        setGalleryModalOpen(false);
                                    }
                                }}>Löschen</button>
                            ) : <div></div>}
                            <div>
                                <button type="button" className="btn btn-ghost mr-2"
                                        onClick={() => setGalleryModalOpen(false)}>Abbrechen
                                </button>
                                <button type="submit" className="btn btn-primary" disabled={processing}>{processing ?
                                    <span className="loading loading-spinner"></span> : 'Speichern'}</button>
                            </div>
                        </div>
                    </form>
                </div>
                <div className="modal-backdrop"></div>
            </dialog>
        </>
    );
}
