import { useEffect } from 'react';
import { Gallery, GalleryMetadataOpts } from '../../../logic/useGalleries';
import IptcMetadataEditor, { IptcData } from '../../components/IptcMetadataEditor';
import { useUI } from '../../components/UIContext';
import { useForm, useWatch } from 'react-hook-form';

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
    const { showToast } = useUI();

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
            showToast('success', 'Metadaten-Vorgaben gespeichert');
            onClose();
        } catch {
            showToast('error', 'Fehler beim Speichern');
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

    if (!isOpen) return null;

    return (
        <dialog className="modal modal-open z-[60]">
            <div className="modal-box max-w-2xl relative">
                <button type="button" className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                
                <h3 className="font-bold text-xl mb-6 flex items-center gap-2">
                    <span className="iconify mdi--tag-multiple text-primary"></span> Metadaten-Vorgaben
                </h3>
                <p className="opacity-70 mb-6 text-sm">Für Galerie: <strong>{gallery.name}</strong></p>

                <form onSubmit={handleSubmit(onSubmit)}>
                    <div className="form-control mb-4">
                        <label className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box w-full">
                            <input type="checkbox" {...register('allow_client_metadata_edit')} className="checkbox checkbox-primary"/>
                            <div>
                                <span className="label-text font-bold block">Kunden dürfen Metadaten bearbeiten</span>
                                <span className="label-text-alt opacity-70 whitespace-normal break-words leading-tight inline-block mt-1">Erlaubt Kunden mit der Rolle "Metadaten bearbeiten" das Ändern von IPTC-Daten in dieser Galerie.</span>
                            </div>
                        </label>
                    </div>

                    <div className="form-control mb-4">
                        <label className="cursor-pointer label justify-start gap-4 bg-base-200 p-3 rounded-box w-full">
                            <input type="checkbox" {...register('apply_metadata_to_photos')} className="checkbox checkbox-primary"/>
                            <div>
                                <span className="label-text font-bold block">Standard-Metadaten beim Upload anwenden</span>
                                <span className="label-text-alt opacity-70 whitespace-normal break-words leading-tight inline-block mt-1">Überschreibt leere Felder bei neu hochgeladenen Bildern mit den untenstehenden Werten.</span>
                            </div>
                        </label>
                    </div>

                    {watchApplyMeta && (
                        <div className="mb-6 pt-4 border-t border-base-300">
                            <IptcMetadataEditor data={currentIptc} onChange={handleIptcChange} showArtist={false} />
                        </div>
                    )}
                    
                    <div className="modal-action">
                        <button type="button" className="btn btn-ghost" onClick={onClose}>Abbrechen</button>
                        <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                            {isSubmitting ? <span className="loading loading-spinner"></span> : 'Speichern'}
                        </button>
                    </div>
                </form>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </dialog>
    );
}