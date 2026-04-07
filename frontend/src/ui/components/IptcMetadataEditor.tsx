import React, { useState, useEffect, useRef } from 'react';
import { useLocations, LocationResult } from '../../logic/useLocations';

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
    children?: React.ReactNode;
}

// Interne Komponente für das Smart Assistance Dropdown
const AutocompleteInput = ({ value, onChange, onSelect, type, placeholder, disabled, label, className }: {
    value: string, onChange: (val: string) => void, onSelect: (loc: LocationResult) => void, type: 'city' | 'country', placeholder?: string, disabled?: boolean, label: string, className?: string
}) => {
    const [query, setQuery] = useState(value || '');
    const [debouncedQuery, setDebouncedQuery] = useState('');
    const [isOpen, setIsOpen] = useState(false);
    const [activeIndex, setActiveIndex] = useState(-1);
    const wrapperRef = useRef<HTMLDivElement>(null);

    const [prevValue, setPrevValue] = useState(value || '');

    if ((value || '') !== prevValue) {
        setPrevValue(value || '');
        setQuery(value || '');
    }

    // Debounce the fetch query
    useEffect(() => {
        const timer = setTimeout(() => setDebouncedQuery(query), 300);
        return () => clearTimeout(timer);
    }, [query]);

    const { locations, isLoading } = useLocations(debouncedQuery, type);

    useEffect(() => {
        setActiveIndex(-1);
    }, [locations]);

    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (!isOpen || locations.length === 0) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActiveIndex(prev => (prev < locations.length - 1 ? prev + 1 : prev));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActiveIndex(prev => (prev > 0 ? prev - 1 : 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIndex >= 0 && activeIndex < locations.length) {
                onSelect(locations[activeIndex]);
                setIsOpen(false);
            }
        } else if (e.key === 'Escape') {
            setIsOpen(false);
        }
    };

    useEffect(() => {
        const handleClickOutside = (event: MouseEvent) => {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target as Node)) {
                setIsOpen(false);
            }
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    return (
        <div className="relative flex-1 w-full form-control" ref={wrapperRef}>
            <label className="label"><span className="label-text font-bold">{label}</span></label>
            <input
                type="text"
                value={query}
                onChange={e => {
                    setQuery(e.target.value);
                    onChange(e.target.value);
                    setIsOpen(true);
                }}
                onFocus={() => setIsOpen(true)}
                onKeyDown={handleKeyDown}
                disabled={disabled}
                placeholder={placeholder}
                className={className || "input input-sm input-bordered w-full"}
            />
            {isOpen && !disabled && locations.length > 0 && (
                <ul className="absolute z-50 top-full left-0 w-full mt-1 bg-base-100 shadow-2xl rounded-box border border-base-300 max-h-60 overflow-y-auto">
                    {locations.map((loc, idx) => (
                        <li
                            key={loc.id}
                            className={`px-4 py-2 cursor-pointer flex flex-col border-b border-base-200/50 last:border-0 ${activeIndex === idx ? 'bg-base-200' : 'hover:bg-base-200'}`}
                            onClick={() => {
                                onSelect(loc);
                                setIsOpen(false);
                            }}
                            onMouseEnter={() => setActiveIndex(idx)}
                        >
                            <span className="font-bold text-sm text-primary">{loc.name}</span>
                            {loc.state && <span className="text-xs opacity-70">{loc.state}, {loc.country}</span>}
                        </li>
                    ))}
                </ul>
            )}
            {isOpen && isLoading && debouncedQuery.length >= 2 && locations.length === 0 && (
                <div className="absolute z-50 top-full left-0 w-full mt-1 bg-base-100 shadow-xl rounded-box border border-base-300 p-2 text-center text-xs opacity-50">Sucht...</div>
            )}
        </div>
    );
};

const ReadOnlyField = ({ label, value }: { label: string, value?: string }) => (
    <div className="mb-4">
        <span className="text-xs font-bold opacity-70 block mb-1">{label}</span>
        <div className="text-sm">{value || <span className="opacity-40 italic">Keine Angabe</span>}</div>
    </div>
);

export default function IptcMetadataEditor({ data, onChange, showArtist = true, disabled = false, children }: Props) {
    const [keywordInput, setKeywordInput] = useState('');

    const handleChange = (field: keyof IptcData, value: string) => {
        onChange({ ...data, [field]: value });
    };

    const handleMultiChange = (updates: Partial<IptcData>) => {
        onChange({ ...data, ...updates });
    };

    const keywordsArray = (data.keywords || '').split(',').map(k => k.trim()).filter(k => k.length > 0);

    const addKeywords = (text: string) => {
        if (disabled) return;
        const newKeywords = text.split(/[,;\s\n]+/).map(k => k.trim()).filter(k => k.length > 0);

        const uniqueKeywords = new Set(keywordsArray);
        let added = false;

        newKeywords.forEach(kw => {
            if (!uniqueKeywords.has(kw)) {
                uniqueKeywords.add(kw);
                added = true;
            }
        });

        if (added) {
            handleChange('keywords', Array.from(uniqueKeywords).join(', '));
        }
    };

    const handleKeywordKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Enter' || e.key === ',') {
            e.preventDefault();
            addKeywords(keywordInput);
            setKeywordInput('');
        } else if (e.key === 'Backspace' && keywordInput === '' && keywordsArray.length > 0) {
            removeKeyword(keywordsArray[keywordsArray.length - 1]);
        }
    };

    const handlePasteKeywords = (e: React.ClipboardEvent<HTMLInputElement>) => {
        e.preventDefault();
        const text = e.clipboardData.getData('text');
        addKeywords(text);
        setKeywordInput('');
    };

    const removeKeyword = (kwToRemove: string) => {
        if (disabled) return;
        const newArray = keywordsArray.filter(k => k !== kwToRemove);
        handleChange('keywords', newArray.join(', '));
    };

    const clearAllKeywords = () => {
        if (disabled) return;
        handleChange('keywords', '');
    };

    if (disabled) {
        return (
            <div className="bg-base-200 p-6 rounded-box border border-base-300">
                <h4 className="font-bold text-lg mb-6 flex items-center gap-2">
                    <span className="iconify mdi--tag-multiple text-primary"></span> Bildinformationen
                </h4>
                <div className="grid grid-cols-1 md:grid-cols-2 gap-x-6">
                    <ReadOnlyField label="Titel" value={data.title} />
                    <div className="md:col-span-2">
                        <ReadOnlyField label="Beschreibung" value={data.description} />
                    </div>
                    <div className="md:col-span-2 mb-4">
                        <span className="text-xs font-bold opacity-70 block mb-2">Schlagwörter</span>
                        <div className="flex flex-wrap gap-2">
                            {keywordsArray.length > 0 ? keywordsArray.map((kw, i) => (
                                <span key={i} className="badge badge-neutral badge-sm">{kw}</span>
                            )) : <span className="opacity-40 italic text-sm">Keine Schlagwörter</span>}
                        </div>
                    </div>
                    <ReadOnlyField label="Ort" value={data.location} />
                    <ReadOnlyField label="Stadt" value={data.city} />
                    <ReadOnlyField label="Bundesland/Kanton" value={data.state} />
                    <ReadOnlyField label="Land" value={data.country ? `${data.country} (${data.iso_country || '-'}) ` : ''} />
                    {showArtist && (
                        <div className="md:col-span-2 mt-2 pt-4 border-t border-base-300">
                            <ReadOnlyField label="Urheber / Copyright" value={data.artist} />
                        </div>
                    )}
                </div>
            </div>
        );
    }

    return (
        <div className="space-y-4 bg-base-100 p-6 rounded-box border border-base-300 shadow-sm">
            <h4 className="font-bold text-lg mb-2 flex items-center gap-2">
                <span className="iconify mdi--tag-multiple text-primary"></span> IPTC Metadaten
            </h4>

            <div className="form-control w-full">
                <label className="label"><span className="label-text font-bold">Titel</span></label>
                <input type="text" value={data.title || ''} onChange={e => handleChange('title', e.target.value)} className="input input-sm input-bordered w-full" />
            </div>

            <div className="form-control">
                <label className="label"><span className="label-text font-bold">Beschreibung</span></label>
                <textarea value={data.description || ''} onChange={e => handleChange('description', e.target.value)} className="textarea textarea-bordered textarea-sm h-24 w-full"></textarea>
            </div>

            <div className="form-control">
                <label className="label">
                    <span className="label-text font-bold">Schlagwörter</span>
                </label>
                <div className="flex flex-wrap gap-1.5 items-center border border-base-300 bg-base-100 rounded-box p-1.5 focus-within:outline-1 focus-within:outline-offset-0 focus-within:outline-primary transition-all min-h-[2.5rem] relative pr-8 shadow-inner">
                    {keywordsArray.map((kw, i) => (
                        <div key={i} className="badge badge-neutral gap-1 pl-2 pr-1 h-7 text-xs">
                            {kw}
                            <button type="button" onClick={() => removeKeyword(kw)} className="btn btn-ghost btn-xs btn-circle h-4 w-4 min-h-0 opacity-70 hover:opacity-100 hover:bg-base-300/50">✕</button>
                        </div>
                    ))}
                    <input
                        type="text"
                        value={keywordInput}
                        onChange={e => setKeywordInput(e.target.value)}
                        onKeyDown={handleKeywordKeyDown}
                        onPaste={handlePasteKeywords}
                        placeholder={keywordsArray.length === 0 ? "Schlagworte eingeben (Komma/Enter)..." : ""}
                        className="input input-ghost input-xs focus:outline-none flex-1 min-w-[60px] h-7 px-1"
                    />
                    {keywordsArray.length > 0 && (
                        <button type="button" onClick={clearAllKeywords} className="btn btn-ghost btn-xs btn-circle absolute right-2 opacity-40 hover:opacity-100 text-error bg-base-200 hover:bg-error hover:text-white transition-colors" title="Alle löschen">
                            <span className="iconify mdi--close"></span>
                        </button>
                    )}
                </div>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-4">
                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">Ort</span></label>
                    <input type="text" value={data.location || ''} onChange={e => handleChange('location', e.target.value)} className="input input-sm input-bordered" />
                </div>

                <AutocompleteInput
                    label="Stadt"
                    type="city"
                    value={data.city || ''}
                    onChange={(val) => handleChange('city', val)}
                    onSelect={(loc) => handleMultiChange({
                        city: loc.name,
                        state: loc.state || data.state,
                        country: loc.country || data.country,
                        iso_country: loc.iso_country || data.iso_country
                    })}
                    disabled={disabled}
                />

                <div className="form-control">
                    <label className="label"><span className="label-text font-bold">Bundesland</span></label>
                    <input type="text" value={data.state || ''} onChange={e => handleChange('state', e.target.value)} className="input input-sm input-bordered" />
                </div>

                <div className="form-control flex-row gap-2">
                    <AutocompleteInput
                        label="Land"
                        type="country"
                        value={data.country || ''}
                        onChange={(val) => handleChange('country', val)}
                        onSelect={(loc) => handleMultiChange({
                            country: loc.name,
                            iso_country: loc.iso_country || data.iso_country
                        })}
                        disabled={disabled}
                        className="input input-sm input-bordered w-full"
                    />

                    <div className="w-24">
                        <label className="label"><span className="label-text font-bold">ISO</span></label>
                        <input type="text" maxLength={2} placeholder="DE" value={data.iso_country || ''} onChange={e => handleChange('iso_country', e.target.value.toUpperCase())} className="input input-sm input-bordered w-full" />
                    </div>
                </div>
            </div>

            {showArtist && (
                <div className="form-control md:col-span-2 mt-4 pt-4 border-t border-base-300">
                    <label className="label pb-1"><span className="label-text font-bold opacity-70">Urheber / Copyright</span></label>
                    <input type="text" value={data.artist || ''} disabled className="input input-sm input-bordered bg-base-200 text-base-content/60 cursor-not-allowed w-full" />
                </div>
            )}
            {children}
        </div>
    );
}