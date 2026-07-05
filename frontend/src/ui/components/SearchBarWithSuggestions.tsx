import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useState, useEffect, useRef } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import HighlightText from './HighlightText';
import { useSearch } from '../../logic/useSearch';

export interface SearchBarWithSuggestionsProps {
    placeholder?: string;
    minCharsForSuggestions?: number;
    autoFocus?: boolean;
    clearOnSubmit?: boolean;
    onFocusChange?: (focused: boolean) => void;
}

export default function SearchBarWithSuggestions({
    placeholder = t`Suche in allen Galerien...`,
    minCharsForSuggestions = 1,
    autoFocus = false,
    clearOnSubmit = false,
    onFocusChange,
}: SearchBarWithSuggestionsProps) {
    const [searchParams] = useSearchParams();
    const qParam = searchParams.get('q') || '';
    const [searchQuery, setSearchQuery] = useState(qParam);
    const [debouncedQuery, setDebouncedQuery] = useState(qParam);
    const [isSearchFocused, setIsSearchFocused] = useState(false);
    const { results: searchResults } = useSearch(debouncedQuery, false, true);
    const navigate = useNavigate();
    const formRef = useRef<HTMLFormElement>(null);

    useEffect(() => {
        const timer = setTimeout(() => setDebouncedQuery(searchQuery), 200);
        return () => clearTimeout(timer);
    }, [searchQuery]);

    const handleSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (searchQuery.trim().length >= 1) {
            navigate(`/search?q=${encodeURIComponent(searchQuery.trim())}`);
            if (clearOnSubmit) {
                setSearchQuery('');
            }
            (document.activeElement as HTMLElement)?.blur();
        }
    };

    const handleFocus = () => {
        setIsSearchFocused(true);
        onFocusChange?.(true);
    };

    const handleBlur = () => {
        requestAnimationFrame(() => {
            if (!formRef.current?.contains(document.activeElement)) {
                setIsSearchFocused(false);
                onFocusChange?.(false);
            }
        });
    };

    const clearQuery = () => {
        if (clearOnSubmit) {
            setSearchQuery('');
        }
    };

    return (
        <form ref={formRef} onSubmit={handleSubmit} className="relative flex-1 w-full max-w-full">
            <div className="join w-full shadow-sm">
                <input
                    type="text"
                    placeholder={placeholder}
                    className="input input-bordered join-item w-full bg-base-100"
                    value={searchQuery}
                    onChange={(e) => setSearchQuery(e.target.value)}
                    onFocus={handleFocus}
                    onBlur={handleBlur}
                    autoFocus={autoFocus}
                />
                <button type="submit" className="btn btn-primary join-item">
                    <span className="iconify mdi--magnify text-xl"></span>
                </button>
            </div>
            {isSearchFocused && searchQuery.length >= minCharsForSuggestions && (
                <div className="fixed top-16 left-2 right-2 md:absolute md:top-14 md:left-0 md:right-auto md:w-full bg-base-100 shadow-2xl rounded-box border border-base-300 z-50 max-h-96 overflow-y-auto">
                    <ul className="menu p-2">
                        <li>
                            <Link to={`/search?q=${encodeURIComponent(searchQuery.trim())}`} onClick={clearQuery} className="text-primary font-bold">
                                <span className="iconify mdi--magnify text-lg mr-1"></span> <Trans>Suche nach &quot;{searchQuery}&quot;</Trans>
                            </Link>
                        </li>
                        <div className="divider my-0"></div>
                        {searchResults ? (
                            <>
                                {searchResults.galleries.map(g => (
                                    <li key={g.id}><Link to={'/' + g.full_path} onClick={clearQuery}><span className="iconify mdi--folder-outline opacity-70"></span> <HighlightText text={g.name} highlight={searchQuery} /></Link></li>
                                ))}
                                {searchResults.photos.map(p => (
                                    <li key={p.id}>
                                        <Link to={'/photos/' + p.id} onClick={clearQuery}>
                                            <div className="flex items-center gap-3">
                                                <img src={p.thumb_url} className="w-10 h-10 object-cover rounded shadow-sm shrink-0" alt="" />
                                                <span className="truncate leading-tight flex-1"><HighlightText text={p.title || t`Foto`} highlight={searchQuery} /></span>
                                            </div>
                                        </Link>
                                    </li>
                                ))}
                                {searchResults.galleries.length === 0 && searchResults.photos.length === 0 && (
                                    <li className="disabled"><span className="opacity-50"><Trans>Keine direkten Treffer</Trans></span></li>
                                )}
                            </>
                        ) : (
                            <li className="disabled"><span className="opacity-50"><Trans>Sucht...</Trans></span></li>
                        )}
                    </ul>
                </div>
            )}
        </form>
    );
}
