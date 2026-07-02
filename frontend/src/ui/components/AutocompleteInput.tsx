import React, {useEffect, useRef, useState} from 'react';
import useSWR from 'swr';
import {fetcher} from '../../api';

export interface AutocompleteOption<T> {
    id: string;
    title: string;
    subtitle?: string;
    raw: T;
}

interface Props<T> {
    label?: string;
    value: string;
    onChange: (val: string) => void;
    onSelect: (item: T) => void;
    endpoint: string;
    mapResponse: (data: T[]) => AutocompleteOption<T>[];
    placeholder?: string;
    disabled?: boolean;
    className?: string;
}

export default function AutocompleteInput<T>({
                                                 label,
                                                 value,
                                                 onChange,
                                                 onSelect,
                                                 endpoint,
                                                 mapResponse,
                                                 placeholder,
                                                 disabled,
                                                 className
                                             }: Props<T>) {
    const [query, setQuery] = useState(() => value || '');
    const [debouncedQuery, setDebouncedQuery] = useState('');
    const [isOpen, setIsOpen] = useState(false);
    const [activeIndex, setActiveIndex] = useState(-1);
    const wrapperRef = useRef<HTMLDivElement>(null);
    const [prevValue, setPrevValue] = useState(value);

    if (value !== prevValue) {
        setPrevValue(value);
        setQuery(value || '');
    }

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedQuery(query), 300);
        return () => clearTimeout(timer);
    }, [query]);

    const fetchUrl = debouncedQuery.length >= 1 ? endpoint + encodeURIComponent(debouncedQuery) : null;
    // isValidating ist bei SWR true, solange ein Request (auch im Hintergrund) läuft
    const {data, isValidating} = useSWR<T[]>(fetchUrl, fetcher, {keepPreviousData: true});
    const options = data ? mapResponse(data) : [];

    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (!isOpen || options.length === 0) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActiveIndex(prev => (prev < options.length - 1 ? prev + 1 : prev));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActiveIndex(prev => (prev > 0 ? prev - 1 : 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIndex >= 0 && activeIndex < options.length) {
                onSelect(options[activeIndex].raw);
                setIsOpen(false);
            }
        } else if (e.key === 'Escape') {
            setIsOpen(false);
        }
    };

    useEffect(() => {
        const handleClickOutside = (event: MouseEvent) => {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target as Node)) setIsOpen(false);
        };
        document.addEventListener('mousedown', handleClickOutside);
        return () => document.removeEventListener('mousedown', handleClickOutside);
    }, []);

    // Ergänze Padding rechts (pr-8), damit der Text nicht in den Spinner läuft
    const inputClassName = className
        ? `${className} pr-8`
        : "input input-bordered w-full pr-8";

    return (
        <div className="relative flex-1 w-full form-control" ref={wrapperRef}>
            {label && <label className="label"><span className="label-text font-bold">{label}</span></label>}
            <div className="relative w-full">
                <input
                    type="text"
                    role="combobox"
                    aria-expanded={isOpen}
                    aria-autocomplete="list"
                    aria-activedescendant={activeIndex >= 0 ? `autocomplete-option-${activeIndex}` : undefined}
                    aria-controls="autocomplete-listbox"
                    value={query}
                    onChange={e => {
                        setQuery(e.target.value);
                        onChange(e.target.value);
                        setIsOpen(true);
                        setActiveIndex(-1);
                    }}
                    onFocus={() => setIsOpen(true)}
                    onKeyDown={handleKeyDown}
                    disabled={disabled}
                    placeholder={placeholder}
                    className={inputClassName}
                />
                {/* Neuer Loading-Spinner am rechten Rand des Inputs */}
                {isValidating && debouncedQuery.length >= 1 && (
                    <div className="absolute right-2 top-0 bottom-0 flex items-center pointer-events-none">
                        <span className="loading loading-spinner loading-xs opacity-50 text-primary"></span>
                    </div>
                )}
            </div>
            {isOpen && !disabled && options.length > 0 && (
                <ul id="autocomplete-listbox" role="listbox" className="absolute z-50 top-full left-0 w-full mt-1 bg-base-100 shadow-2xl rounded-box border border-base-300 max-h-60 overflow-y-auto">
                    {options.map((opt, idx) => (
                        <li
                            key={opt.id}
                            id={`autocomplete-option-${idx}`}
                            role="option"
                            aria-selected={activeIndex === idx}
                            className={`px-4 py-2 cursor-pointer flex flex-col border-b border-base-200/50 last:border-0 ${activeIndex === idx ? 'bg-base-200' : 'hover:bg-base-200'}`}
                            onClick={() => {
                                onSelect(opt.raw);
                                setIsOpen(false);
                            }}
                            onMouseEnter={() => setActiveIndex(idx)}
                        >
                            <span className="font-bold text-sm text-primary">{opt.title}</span>
                            {opt.subtitle && <span className="text-sm opacity-70">{opt.subtitle}</span>}
                        </li>
                    ))}
                </ul>
            )}
        </div>
    );
}
