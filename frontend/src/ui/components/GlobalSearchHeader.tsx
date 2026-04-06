import { useState } from 'react';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import HighlightText from './HighlightText';
import { useSearch } from '../../logic/useSearch';
import { useCart } from '../../logic/CartContext';

export default function GlobalSearchHeader({ onMenuClick }: { onMenuClick: () => void }) {
    const [searchParams] = useSearchParams();
    const qParam = searchParams.get('q') || '';
    const [searchQuery, setSearchQuery] = useState(qParam);
    const [prevQParam, setPrevQParam] = useState(qParam);
    
    const { itemCount } = useCart();

    if (qParam !== prevQParam) {
        setPrevQParam(qParam);
        setSearchQuery(qParam);
    }

    const [isSearchFocused, setIsSearchFocused] = useState(false);
    const { results: searchResults } = useSearch(searchQuery, false, true);
    const navigate = useNavigate();

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (searchQuery.trim().length >= 2) {
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
                <img src="/android-chrome-192x192.png" alt="Logo" className="w-8 h-8 rounded shadow-sm bg-base-100" />
                <span className="font-bold text-sm truncate max-w-[110px] sm:max-w-[200px]">Reisinger Portal</span>
            </Link>
            
            <form onSubmit={handleSearchSubmit} className="relative flex-1 w-full max-w-full md:max-w-4xl">
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
                {isSearchFocused && searchQuery.length >= 2 && searchResults && (
                    <div className="absolute top-14 left-0 w-full bg-base-100 shadow-2xl rounded-box border border-base-300 z-50 max-h-[60vh] overflow-y-auto">
                        <ul className="menu p-2">
                            <li>
                                <Link to={`/search?q=${encodeURIComponent(searchQuery.trim())}`}  className="text-primary font-bold">
                                    <span className="iconify mdi--magnify text-lg mr-1"></span> Suche nach "{searchQuery}"
                                </Link>
                            </li>
                            <div className="divider my-0"></div>
                            {searchResults.galleries.map(g => (
                                <li key={g.id}><Link to={'/' + g.full_path} >📁 <HighlightText text={g.name} highlight={searchQuery} /></Link></li>
                            ))}
                            {searchResults.photos.map(p => (
                                <li key={p.id}><Link to={'/photos/' + p.id} >
                                    <span className="iconify mdi--image-outline opacity-70"></span> <HighlightText text={p.filename} highlight={searchQuery} />
                                </Link></li>
                            ))}
                            {searchResults.galleries.length === 0 && searchResults.photos.length === 0 && (
                                <li className="disabled"><span className="opacity-50">Keine direkten Treffer</span></li>
                            )}
                        </ul>
                    </div>
                )}
            </form>

            <button 
                className="btn btn-ghost btn-circle relative ml-auto shrink-0" 
                onClick={() => navigate('/cart')}
                title="Warenkorb öffnen"
            >
                <span className="iconify mdi--cart text-2xl"></span>
                {itemCount > 0 && (
                    <div className="badge badge-primary badge-sm absolute top-1 right-0 border-base-100 border-2">{itemCount}</div>
                )}
            </button>
        </header>
    );
}
