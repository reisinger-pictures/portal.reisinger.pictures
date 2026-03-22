import React from 'react';

export interface IptcData {
    title?: string;
    description?: string;
    artist?: string;
    headline?: string;
    keywords?: string;
    location?: string;
    city?: string;
    state?: string;
    country?: string;
    iso_country?: string;
}

interface Props {
    data: IptcData;
    onChange: (data: IptcData) => void;
    showArtist?: boolean;
    disabled?: boolean;
}

export default function IptcMetadataEditor({ data, onChange, showArtist = true, disabled = false }: Props) {
    const handleChange = (field: keyof IptcData, value: string) => {
        onChange({ ...data, [field]: value });
    };

    return (
        <div className="space-y-4 bg-base-200 p-4 rounded-box border border-base-300">
            <h4 className="font-bold text-lg mb-2 flex items-center gap-2">
                <span className="iconify mdi--tag-multiple text-primary"></span> IPTC Metadaten
            </h4>
            
            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">Titel (Object Name)</span></label>
                    <input type="text" value={data.title || ''} onChange={e => handleChange('title', e.target.value)} disabled={disabled} className="input input-sm input-bordered" />
                </div>
                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">Überschrift (Headline)</span></label>
                    <input type="text" value={data.headline || ''} onChange={e => handleChange('headline', e.target.value)} disabled={disabled} className="input input-sm input-bordered" />
                </div>
            </div>

            <div className="form-control">
                <label className="label"><span className="label-text font-bold">Beschreibung (Caption)</span></label>
                <textarea value={data.description || ''} onChange={e => handleChange('description', e.target.value)} disabled={disabled} className="textarea textarea-bordered textarea-sm h-20"></textarea>
            </div>

            <div className="form-control">
                <label className="label"><span className="label-text font-bold">Schlagwörter (Keywords - Komma-getrennt)</span></label>
                <input type="text" value={data.keywords || ''} onChange={e => handleChange('keywords', e.target.value)} disabled={disabled} className="input input-sm input-bordered" />
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">Ort (Sub-location)</span></label>
                    <input type="text" value={data.location || ''} onChange={e => handleChange('location', e.target.value)} disabled={disabled} className="input input-sm input-bordered" />
                </div>
                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">Stadt (City)</span></label>
                    <input type="text" value={data.city || ''} onChange={e => handleChange('city', e.target.value)} disabled={disabled} className="input input-sm input-bordered" />
                </div>
                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">Bundesland/Kanton (State)</span></label>
                    <input type="text" value={data.state || ''} onChange={e => handleChange('state', e.target.value)} disabled={disabled} className="input input-sm input-bordered" />
                </div>
                <div className="form-control flex-row gap-2">
                    <div className="flex-1">
                        <label className="label"><span className="label-text font-bold">Land</span></label>
                        <input type="text" value={data.country || ''} onChange={e => handleChange('country', e.target.value)} disabled={disabled} className="input input-sm input-bordered w-full" />
                    </div>
                    <div className="w-24">
                        <label className="label"><span className="label-text font-bold">ISO</span></label>
                        <input type="text" maxLength={2} placeholder="DE" value={data.iso_country || ''} onChange={e => handleChange('iso_country', e.target.value.toUpperCase())} disabled={disabled} className="input input-sm input-bordered w-full" />
                    </div>
                </div>
            </div>

            {showArtist && (
                <div className="form-control mt-4 pt-4 border-t border-base-300">
                    <label className="label">
                        <span className="label-text font-bold text-warning">Urheber / Copyright (Artist)</span>
                        <span className="label-text-alt opacity-70">Wird beim Download ins Bild geschrieben</span>
                    </label>
                    <input type="text" value={data.artist || ''} onChange={e => handleChange('artist', e.target.value)} disabled={disabled} className="input input-sm input-bordered" />
                </div>
            )}
        </div>
    );
}