import { useState } from 'react';
import { Link } from 'react-router-dom';
import { useBrand } from '../../logic/useBrand';
import SearchBarWithSuggestions from './SearchBarWithSuggestions';

export interface GlobalSearchHeaderProps {
    onMenuClick: () => void;
}

export default function GlobalSearchHeader({ onMenuClick }: GlobalSearchHeaderProps) {
    const { logoSrc, portalName } = useBrand();
    const [isSearchFocused, setIsSearchFocused] = useState(false);

    return (
        <header role="banner" className="p-4 md:p-6 bg-base-100 border-b border-base-300 sticky top-0 z-30 flex items-center gap-3">
            <button type="button" className={`btn btn-square btn-ghost md:hidden shrink-0 ${isSearchFocused ? 'hidden' : ''}`} onClick={onMenuClick}>
                <span className="iconify mdi--menu text-2xl"></span>
            </button>
            <Link to="/" className={`md:hidden flex items-center gap-2 shrink-0 mr-1 ${isSearchFocused ? 'hidden' : ''}`}>
                <img src={logoSrc} alt="Logo" className="w-8 h-8 rounded shadow-sm bg-base-100" />
                <span className="font-bold text-sm truncate max-w-28 sm:max-w-48">{portalName}</span>
            </Link>

            <SearchBarWithSuggestions onFocusChange={setIsSearchFocused} />
        </header>
    );
}
