import React, {useEffect, useId, useRef, useState, useTransition} from 'react';
import useSWR from 'swr';
import {fetcher} from '../../api';

export interface AutocompleteOption<T> {
    id: string;
    title: string;
    subtitle?: string;
    raw: T;
}

interface Props<T> {
    id?: string;
    ariaLabel?: string;
    label?: string;
    value: string;
    onChange: (val: string) => void;
    onSelect: (item: T) => void;
    endpoint: string;
    mapResponse: (data: T[]) => AutocompleteOption<T>[];
    placeholder?: string;
    disabled?: boolean;
    className?: string;
    required?: boolean;
}

export default function AutocompleteInput<T>({
    id,
    ariaLabel,
    label,
    value,
    onChange,
    onSelect,
    endpoint,
    mapResponse,
    placeholder,
    disabled,
    className,
    required
}: Props<T>) {
    const generatedId = useId();
    const inputId = id ?? generatedId;
    const listboxId = `${inputId}-listbox`;
    const getOptionId = (index: number) => `${inputId}-option-${index}`;
    const [, startTransition] = useTransition();
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
        const timer = setTimeout(() => {
            startTransition(() => {
                setDebouncedQuery(query);
            });
        }, 300);
        return () => clearTimeout(timer);
    }, [query, startTransition]);

    const fetchUrl = debouncedQuery.length >= 1 ? endpoint + encodeURIComponent(debouncedQuery) : null;
    // isValidating ist bei SWR true, solange ein Request (auch im Hintergrund) läuft
    const {data, isValidating} = useSWR<T[]>(fetchUrl, fetcher, {keepPreviousData: true});
    const options = data ? mapResponse(data) : [];
    const [previousOptionCount, setPreviousOptionCount] = useState(options.length);
    if (options.length !== previousOptionCount) {
        setPreviousOptionCount(options.length);
        setActiveIndex(-1);
    }
    const hasOpenListbox = isOpen && !disabled && options.length > 0;
    const activeOptionId = hasOpenListbox && activeIndex >= 0 && activeIndex < options.length
        ? getOptionId(activeIndex)
        : undefined;

    const closeSuggestions = () => {
        setIsOpen(false);
        setActiveIndex(-1);
    };

    const handleKeyDown = (e: React.KeyboardEvent<HTMLInputElement>) => {
        if (e.key === 'Tab') {
            closeSuggestions();
            return;
        }
        if (e.key === 'Escape') {
            e.preventDefault();
            closeSuggestions();
            return;
        }
        if (!hasOpenListbox) return;
        if (e.key === 'ArrowDown') {
            e.preventDefault();
            setActiveIndex(prev => (prev < options.length - 1 ? prev + 1 : prev));
        } else if (e.key === 'ArrowUp') {
            e.preventDefault();
            setActiveIndex(prev => (prev > 0 ? prev - 1 : 0));
        } else if (e.key === 'Enter') {
            e.preventDefault();
            if (activeIndex >= 0 && activeIndex < options.length) {
                closeSuggestions();
                onSelect(options[activeIndex].raw);
            }
        }
    };

    useEffect(() => {
        const handleClickOutside = (event: MouseEvent) => {
            if (wrapperRef.current && !wrapperRef.current.contains(event.target as Node)) {
                setIsOpen(false);
                setActiveIndex(-1);
            }
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
            {label && <label className="label" htmlFor={inputId}><span className="label-text font-bold">{label}</span></label>}
            <div className="relative w-full">
                <input
                    id={inputId}
                    type="text"
                    role="combobox"
                    aria-label={ariaLabel}
                    aria-expanded={hasOpenListbox}
                    aria-autocomplete="list"
                    aria-activedescendant={activeOptionId}
                    aria-controls={hasOpenListbox ? listboxId : undefined}
                    required={required}
                    value={query}
                    onChange={e => {
                        setQuery(e.target.value);
                        onChange(e.target.value);
                        setIsOpen(true);
                        setActiveIndex(-1);
                    }}
                    onFocus={() => {
                        setIsOpen(true);
                        setActiveIndex(-1);
                    }}
                    onBlur={closeSuggestions}
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
            {hasOpenListbox && (
                <ul id={listboxId} role="listbox" className="absolute z-50 top-full left-0 w-full min-w-72 mt-1 bg-base-100 shadow-2xl rounded-box border border-base-300 max-h-60 overflow-y-auto">
                    {options.map((opt, idx) => (
                        <li
                            key={opt.id}
                            id={getOptionId(idx)}
                            role="option"
                            aria-selected={activeIndex === idx}
                            className={`px-4 py-2 cursor-pointer flex flex-col border-b border-base-200/50 last:border-0 ${activeIndex === idx ? 'bg-base-200' : 'hover:bg-base-200'}`}
                            onMouseDown={event => event.preventDefault()}
                            onClick={() => {
                                closeSuggestions();
                                onSelect(opt.raw);
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
