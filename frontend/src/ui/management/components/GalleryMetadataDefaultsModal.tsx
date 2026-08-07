import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useEffect, useState } from 'react';
import { Gallery, GalleryMetadataOpts } from '../../../logic/useGalleries';
import IptcMetadataEditor from '../../components/IptcMetadataEditor';
import { IptcData } from '../../../logic/usePhoto';
import { useUI } from '../../components/UIContext';
import { useForm, useWatch } from 'react-hook-form';
import AIGalleryDefaultsModal from './AIGalleryDefaultsModal';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    gallery: Gallery;
    onUpdate: (id: string, name: string, slug: string, type: 'selection' | 'delivery', isLive: boolean, isPublic: boolean, parentId?: string | null, pw?: string, exp?: string, metadataOpts?: GalleryMetadataOpts) => Promise<void>;
}

export interface MetadataDefaultsFormValues {
    allow_client_metadata_edit: boolean;
    apply_metadata_to_photos: boolean;
    title: string;
    description: string;
    keywords: string;
    location: string;
    city: string;
    state: string;
    country: string;
    iso_country: string;
}

export default function GalleryMetadataDefaultsModal({ isOpen, onClose, gallery, onUpdate }: Props) {
    "use no memo";
    const { showToast } = useUI();
    const [isAiModalOpen, setIsAiModalOpen] = useState(false);

    const { register, handleSubmit, reset, setValue, control, formState: { isSubmitting } } = useForm({
        defaultValues: {
            allow_client_metadata_edit: false, apply_metadata_to_photos: false,
            title: '', description: '', keywords: '', location: '', city: '', state: '', country: '', iso_country: ''
        }
    });

    useEffect(() => {
        if (isOpen && gallery) {
            reset({
                allow_client_metadata_edit: gallery.allow_client_metadata_edit || false,
                apply_metadata_to_photos: gallery.apply_metadata_to_photos || false,
                title: gallery.default_title || '',
                description: gallery.default_description || '',
                keywords: gallery.default_keywords || '',
                location: gallery.default_location || '',
                city: gallery.default_city || '',
                state: gallery.default_state || '',
                country: gallery.default_country || '',
                iso_country: gallery.default_iso_country || ''
            });
        }
    }, [isOpen, gallery, reset]);

    const watchApplyMeta = useWatch({ control, name: 'apply_metadata_to_photos' });

    const onSubmit = async (data: MetadataDefaultsFormValues) => {
        const metaOpts = {
            allow_client_metadata_edit: data.allow_client_metadata_edit,
            apply_metadata_to_photos: data.apply_metadata_to_photos,
            default_title: data.title, default_description: data.description, default_keywords: data.keywords,
            default_location: data.location, default_city: data.city, default_state: data.state,
            default_country: data.country, default_iso_country: data.iso_country
        };

        try {
            await onUpdate(
                gallery.id, gallery.name, gallery.slug, gallery.type, gallery.is_live, gallery.is_public, 
                gallery.gallery_group_id, undefined, gallery.expires_at ? gallery.expires_at.split('T')[0] : undefined, 
                metaOpts
            );
            showToast('success', t`Metadaten-Vorgaben gespeichert`);
            onClose();
        } catch {
            showToast('error', t`Fehler beim Speichern`);
        }
    };

    const formValues = useWatch({ control }) as IptcData;
    const currentIptc: IptcData = {
        title: formValues.title, description: formValues.description, keywords: formValues.keywords,
        location: formValues.location, city: formValues.city, state: formValues.state,
        country: formValues.country, iso_country: formValues.iso_country
    };
    
    const handleIptcChange = (newData: IptcData) => {
        Object.entries(newData).forEach(([key, val]) => setValue(key as Parameters<typeof setValue>[0], val));
    };

    const handleAiApply = (data: Partial<IptcData>) => {
        Object.entries(data).forEach(([key, val]) => {
            if (val) setValue(key as Parameters<typeof setValue>[0], val);
        });
    };

    if (!isOpen) return null;

    return (
        <dialog className="modal modal-open">
            <div className="modal-box max-w-2xl relative">
                <button type="button" className="btn btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                
                <h3 className="font-bold text-xl mb-6 flex items-center gap-2">
                    <span className="iconify mdi--tag-multiple text-primary"></span> <Trans>Metadaten-Vorgaben</Trans>
                </h3>
                <p className="opacity-70 mb-6 text-sm"><Trans>Für Galerie:</Trans> <strong>{gallery.name}</strong></p>

                <form onSubmit={handleSubmit(onSubmit)}>
                    <div className="form-control mb-4">
                        <label className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box w-full hover:bg-base-300/50 transition-colors">
                            <input type="checkbox" {...register('allow_client_metadata_edit')} className="checkbox checkbox-primary shrink-0"/>
                            <div>
                                <span className="label-text font-bold block"><Trans>Kunden dürfen Metadaten bearbeiten</Trans></span>
                                <span className="label-text-alt opacity-70 whitespace-normal break-words leading-tight inline-block mt-1"><Trans>Erlaubt Kunden mit der Rolle "Metadaten bearbeiten" das Ändern von IPTC-Daten in dieser Galerie.</Trans></span>
                            </div>
                        </label>
                    </div>

                    <div className="form-control mb-4">
                        <label className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box w-full hover:bg-base-300/50 transition-colors">
                            <input type="checkbox" {...register('apply_metadata_to_photos')} className="checkbox checkbox-primary shrink-0"/>
                            <div>
                                <span className="label-text font-bold block"><Trans>Standard-Metadaten beim Upload anwenden</Trans></span>
                                <span className="label-text-alt opacity-70 whitespace-normal break-words leading-tight inline-block mt-1"><Trans>Überschreibt leere Felder bei neu hochgeladenen Bildern mit den untenstehenden Werten.</Trans></span>
                            </div>
                        </label>
                    </div>

                    {watchApplyMeta && (
                        <div className="mb-6 pt-4 border-t border-base-300">
                            <IptcMetadataEditor data={currentIptc} onChange={handleIptcChange} showArtist={false} />
                        </div>
                    )}
                    
                    <div className="mt-6 flex flex-col sm:flex-row justify-end gap-3 w-full">
                        <button type="button" className="btn btn-ghost w-full sm:w-auto" onClick={onClose}><Trans>Abbrechen</Trans></button>
                        <button type="button" className="btn btn-outline btn-primary w-full sm:w-auto" onClick={() => setIsAiModalOpen(true)}>
                            <span className="iconify mdi--auto-fix"></span> <Trans>KI generieren</Trans>
                        </button>
                        <button type="submit" className="btn btn-primary w-full sm:w-auto" disabled={isSubmitting}>
                            {isSubmitting ? <span className="loading loading-spinner"></span> : <Trans>Speichern</Trans>}
                        </button>
                    </div>
                </form>

                <AIGalleryDefaultsModal isOpen={isAiModalOpen} onClose={() => setIsAiModalOpen(false)} onApply={handleAiApply} />
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </dialog>
    );
}