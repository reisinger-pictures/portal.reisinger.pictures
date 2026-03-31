import { useState } from 'react';
import {Link, useNavigate} from 'react-router-dom';
import {useAuth} from '../../logic/useAuth';
import {useSearch} from '../../logic/useSearch';
import {Gallery} from '../../logic/useGalleries';
import Sidebar from '../components/Sidebar';
import HighlightText from '../components/HighlightText';

export default function ClientDashboard() {
    const {user} = useAuth();
    const navigate = useNavigate();
    const [isSidebarOpen, setIsSidebarOpen] = useState(false);
    const [searchQuery, setSearchQuery] = useState('');
    const {results: searchResults} = useSearch(searchQuery, false, true); // Leere Query überspringen

    if (!user) return null;

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (searchQuery.trim().length >= 2) {
            navigate(`/search?q=${encodeURIComponent(searchQuery.trim())}`);
            setSearchQuery('');
        }
    };

    return (
        <div className="flex h-screen bg-base-100 overflow-hidden relative">
            {isSidebarOpen && (
                <div className="fixed inset-0 bg-black/50 z-40 md:hidden transition-opacity"
                     onClick={() => setIsSidebarOpen(false)}></div>
            )}

            <div
                className={`fixed inset-y-0 left-0 z-50 w-full md:w-72 2xl:w-80 transform transition-transform duration-300 ease-in-out md:relative md:translate-x-0 ${isSidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}>
                <Sidebar onCloseMobile={() => setIsSidebarOpen(false)}/>
            </div>

            <main className="flex-1 overflow-y-auto flex flex-col w-full relative bg-base-200">
                <header className="p-4 border-b border-base-300 bg-base-100 sticky top-0 z-30 flex items-center gap-3 ">
                    <button className="btn btn-square btn-ghost md:hidden shrink-0" onClick={() => setIsSidebarOpen(true)}>
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
                                placeholder="Bilder durchsuchen..."
                                className="input input-bordered join-item w-full"
                                value={searchQuery}
                                onChange={(e) => setSearchQuery(e.target.value)}
                            />
                            <button type="submit" className="btn btn-primary join-item">
                                <span className="iconify mdi--magnify text-xl"></span>
                            </button>
                        </div>

                        {searchQuery.length >= 2 && searchResults && (
                            <div
                                className="absolute top-14 left-0 w-full bg-base-100 shadow-2xl rounded-box border border-base-300 z-50 max-h-[60vh] overflow-y-auto">
                                <ul className="menu p-2">
                                    <li>
                                        <Link to={`/search?q=${encodeURIComponent(searchQuery.trim())}`}
                                              onClick={() => setSearchQuery('')} className="text-primary font-bold">
                                            <span className="iconify mdi--magnify text-lg mr-1"></span> Suche nach
                                            "{searchQuery}"
                                        </Link>
                                    </li>
                                    <div className="divider my-0"></div>
                                    {searchResults.galleries.map(g => (
                                        <li key={g.id}><Link to={'/' + g.full_path}
                                                             onClick={() => setSearchQuery('')}>📁 <HighlightText text={g.name} highlight={searchQuery} /></Link></li>
                                    ))}
                                    {searchResults.photos.map(p => (
                                        <li key={p.id}><Link to={'/photos/' + p.id} onClick={() => setSearchQuery('')}>
                                            <span className="iconify mdi--image-outline opacity-70"></span> <HighlightText text={p.filename} highlight={searchQuery} />
                                        </Link></li>
                                    ))}
                                    {searchResults.galleries.length === 0 && searchResults.photos.length === 0 && (
                                        <li className="disabled"><span
                                            className="opacity-50">Keine direkten Treffer</span></li>
                                    )}
                                </ul>
                            </div>
                        )}
                    </form>
                </header>

                <div className="container mx-auto p-8 relative z-0">
                    <h1 className="text-3xl font-bold mb-8">Willkommen zurück, {user.name}!</h1>
                    {(user.my_galleries?.length ?? 0) > 0 ? (
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            {user.my_galleries?.map((g: Gallery) => (
                                <div key={g.id}
                                     className="card bg-base-100 shadow-xl cursor-pointer hover:shadow-2xl transition-shadow border border-base-300"
                                     onClick={() => navigate('/' + g.full_path)}>
                                    <div className="card-body">
                                        <h2 className="card-title text-primary">{g.name}</h2>
                                        <div className="card-actions justify-end mt-4">
                                            <button className="btn btn-primary btn-sm">Öffnen</button>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="alert shadow-lg bg-base-100 border border-base-300">
                            <span>Aktuell sind keine Galerien für dich freigeschaltet.</span>
                        </div>
                    )}
                </div>
            </main>
        </div>
    );
}
