import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useBrand } from '../../logic/useBrand';
import HighlightText from './HighlightText';
import { useSearch } from '../../logic/useSearch';

export interface GlobalSearchHeaderProps {
    onMenuClick: () => void;
}

export default function GlobalSearchHeader({ onMenuClick }: GlobalSearchHeaderProps) {
    const { logoSrc, portalName } = useBrand();
    const [searchParams] = useSearchParams();
    const qParam = searchParams.get('q') || '';
    const [searchQuery, setSearchQuery] = useState(qParam);
    const [prevQParam, setPrevQParam] = useState(qParam);
    
    
    if (qParam !== prevQParam) {
        setPrevQParam(qParam);
        setSearchQuery(qParam);
    }

    const [isSearchFocused, setIsSearchFocused] = useState(false);
    const { results: searchResults } = useSearch(searchQuery, false, true);
    const navigate = useNavigate();

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (searchQuery.trim().length >= 1) {
            navigate(`/search?q=${encodeURIComponent(searchQuery.trim())}`);
            (document.activeElement as HTMLElement)?.blur();
        }
    };

    return (
        <header className="p-4 md:p-6 bg-base-100 border-b border-base-300 sticky top-0 z-30 flex items-center gap-3">
            <button className="btn btn-square btn-ghost md:hidden shrink-0" onClick={onMenuClick}>
                <span className="iconify mdi--menu text-2xl"></span>
            </button>
            <Link to="/" className="md:hidden flex items-center gap-2 shrink-0 mr-1">
                <img src={logoSrc} alt="Logo" className="w-8 h-8 rounded shadow-sm bg-base-100" />
                <span className="font-bold text-sm truncate max-w-[110px] sm:max-w-[200px]">{portalName}</span>
            </Link>
            
            <form onSubmit={handleSearchSubmit} className="relative flex-1 w-full max-w-full">
                <div className="join w-full shadow-sm">
                    <input
                        type="text"
                        placeholder="Suche in allen Galerien..."
                        className="input input-bordered join-item w-full"
                        value={searchQuery}
                        onChange={(e) => setSearchQuery(e.target.value)}
                        onFocus={() => setIsSearchFocused(true)}
                        onBlur={() => setTimeout(() => setIsSearchFocused(false), 200)}
                    />
                    <button type="submit" className="btn btn-primary join-item">
                        <span className="iconify mdi--magnify text-xl"></span>
                    </button>
                </div>
                {isSearchFocused && searchQuery.length >= 1 && (
                    <div className="fixed top-[72px] left-2 right-2 md:absolute md:top-14 md:left-0 md:right-auto md:w-full bg-base-100 shadow-2xl rounded-box border border-base-300 z-[100] max-h-[60vh] overflow-y-auto">
                        <ul className="menu p-2">
                            <li>
                                <Link to={`/search?q=${encodeURIComponent(searchQuery.trim())}`}  className="text-primary font-bold">
                                    <span className="iconify mdi--magnify text-lg mr-1"></span> Suche nach "{searchQuery}"
                                </Link>
                            </li>
                            <div className="divider my-0"></div>
                            {searchResults ? (
                                <>
                                    {searchResults.galleries.map(g => (
                                        <li key={g.id}><Link to={'/' + g.full_path} >📁 <HighlightText text={g.name} highlight={searchQuery} /></Link></li>
                                    ))}
                                    {searchResults.photos.map(p => (
                                        <li key={p.id}>
                                            <Link to={'/photos/' + p.id} className="py-2">
                                                <div className="flex items-center gap-3">
                                                    <img src={p.thumb_url} className="w-10 h-10 object-cover rounded shadow-sm shrink-0" alt="" />
                                                    <span className="truncate leading-tight flex-1"><HighlightText text={p.title || 'Foto'} highlight={searchQuery} /></span>
                                                </div>
                                            </Link>
                                        </li>
                                    ))}
                                    {searchResults.galleries.length === 0 && searchResults.photos.length === 0 && (
                                        <li className="disabled"><span className="opacity-50">Keine direkten Treffer</span></li>
                                    )}
                                </>
                            ) : (
                                <li className="disabled"><span className="opacity-50">Sucht...</span></li>
                            )}
                        </ul>
                    </div>
                )}
            </form>

            
        </header>
    );
}
