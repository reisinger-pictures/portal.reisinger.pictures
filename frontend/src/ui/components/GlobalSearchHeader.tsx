import { t } from "@lingui/core/macro";
import { useState } from 'react';
import MobileBrandLink from './MobileBrandLink';
import SearchBarWithSuggestions from './SearchBarWithSuggestions';

export interface GlobalSearchHeaderProps {
    onMenuClick: () => void;
    isSidebarOpen?: boolean;
    sidebarId?: string;
}

export default function GlobalSearchHeader({ onMenuClick, isSidebarOpen = false, sidebarId = 'dashboard-sidebar' }: GlobalSearchHeaderProps) {
    const [isSearchFocused, setIsSearchFocused] = useState(false);

    return (
        <header role="banner" className="p-4 md:p-6 bg-base-100 border-b border-base-300 sticky top-0 z-30 flex items-center gap-3">
            <button
                type="button"
                className={`btn btn-square btn-ghost md:hidden shrink-0 ${isSearchFocused ? 'hidden' : ''}`}
                aria-label={t`Menü öffnen`}
                aria-expanded={isSidebarOpen}
                aria-controls={sidebarId}
                onClick={onMenuClick}
            >
                <span className="iconify mdi--menu text-2xl" aria-hidden="true"></span>
            </button>
            <MobileBrandLink hidden={isSearchFocused} />

            <SearchBarWithSuggestions onFocusChange={setIsSearchFocused} />
        </header>
    );
}
