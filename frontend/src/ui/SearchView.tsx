import ResponsiveImage from './components/ResponsiveImage';
import { useEffect, useState } from 'react';
import {Link, useNavigate, useSearchParams} from 'react-router-dom';
import {useSearch} from '../logic/useSearch';
import Sidebar from './components/Sidebar';
import AdminWatermarkBanner from './management/components/AdminWatermarkBanner';

export default function SearchView() {
    const [searchParams] = useSearchParams();
    const query = searchParams.get('q') || '';
    const [localQuery, setLocalQuery] = useState(query);
    const {results, isLoading, isError} = useSearch(query);
    const navigate = useNavigate();
    const [isSidebarOpen, setIsSidebarOpen] = useState(false);

    useEffect(() => {
        setLocalQuery(query);
    }, [query]);

    const handleSearch = (e: React.FormEvent) => {
        e.preventDefault();
        navigate(`/search?q=${encodeURIComponent(localQuery.trim())}`);
    };

    return (
        <div className="flex flex-col h-screen">
            <AdminWatermarkBanner/>
            <div className="flex flex-1 bg-base-100 overflow-hidden relative">
                {isSidebarOpen && (
                    <div className="fixed inset-0 bg-black/50 z-40 md:hidden transition-opacity"
                         onClick={() => setIsSidebarOpen(false)}></div>
                )}

                <div
                    className={`fixed inset-y-0 left-0 z-50 w-full md:w-72 2xl:w-80 transform transition-transform duration-300 ease-in-out md:relative md:translate-x-0 ${isSidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}>
                    <Sidebar currentView="search" onCloseMobile={() => setIsSidebarOpen(false)}/>
                </div>

                <main className="flex-1 overflow-y-auto flex flex-col w-full relative bg-base-200">
                    <header className="p-4 border-b border-base-300 bg-base-100 flex items-center gap-3 md:hidden">
                        <button className="btn btn-square btn-ghost shrink-0" onClick={() => setIsSidebarOpen(true)}>
                            <span className="iconify mdi--menu text-2xl"></span>
                        </button>
                        <Link to="/" className="flex items-center gap-2 shrink-0">
                            <img src="/android-chrome-192x192.png" alt="Logo" className="w-8 h-8 rounded shadow-sm bg-base-100" />
                            <span className="font-bold text-base truncate">Reisinger Foto Portal</span>
                        </Link>
                    </header>

                    <div className="container mx-auto max-w-7xl p-4 md:p-8">
                        <form onSubmit={handleSearch} className="mb-8 max-w-2xl mx-auto">
                            <div className="join w-full shadow-sm">
                                <input
                                    type="text"
                                    placeholder="Galerien und Bilder suchen..."
                                    className="input input-bordered join-item w-full bg-base-100"
                                    value={localQuery}
                                    onChange={e => setLocalQuery(e.target.value)}
                                    autoFocus
                                />
                                <button type="submit" className="btn btn-primary join-item">
                                    <span className="iconify mdi--magnify text-xl"></span> Suchen
                                </button>
                            </div>
                        </form>

                        <h1 className="text-2xl md:text-3xl font-bold mb-8 text-center">
                            {query ? <>Suchergebnisse für <span
                                className="text-primary">"{query}"</span></> : 'Neueste Entdeckungen'}
                        </h1>

                        {isLoading && <div className="flex justify-center p-10"><span
                            className="loading loading-spinner loading-lg text-primary"></span></div>}
                        {isError && <div className="alert alert-error">Fehler beim Laden der Ergebnisse.</div>}

                        {!isLoading && !isError && results && (
                            <div className="space-y-12">
                                <section>
                                    <h2 className="text-xl font-bold mb-4 flex items-center gap-2 border-b border-base-300 pb-2">
                                        <span className="iconify mdi--folder-multiple text-primary"></span> Galerien
                                        ({results.galleries.length})
                                    </h2>
                                    {results.galleries.length > 0 ? (
                                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                            {results.galleries.map(g => (
                                                <div key={g.id}
                                                     className="card bg-base-100 shadow-xl cursor-pointer hover:shadow-2xl border border-base-300 transition-all hover:-translate-y-1"
                                                     onClick={() => navigate('/' + g.full_path)}>
                                                    <div className="card-body p-4 flex flex-row items-center">
                                                        <div className="text-2xl mr-2"></div>
                                                        <h3 className="card-title text-base text-primary truncate flex-1">{g.name}</h3>
                                                        <span
                                                            className="iconify mdi--chevron-right text-xl opacity-50"></span>
                                                    </div>
                                                </div>
                                            ))}
                                        </div>
                                    ) : <p className="opacity-50">Keine passenden Galerien gefunden.</p>}
                                </section>

                                <section>
                                    <h2 className="text-xl font-bold mb-4 flex items-center gap-2 border-b border-base-300 pb-2">
                                        <span className="iconify mdi--image-multiple text-secondary"></span> Fotos
                                        ({results.photos.length})
                                    </h2>
                                    {results.photos.length > 0 ? (
                                        <div
                                            className="grid grid-cols-2 sm:grid-cols-3 md:grid-cols-4 lg:grid-cols-5 xl:grid-cols-6 gap-4">
                                            {results.photos.map(p => (
                                                <Link key={p.id} to={`/photos/${p.id}`}
                                                      className="block relative aspect-square bg-base-300 rounded overflow-hidden group shadow-md hover:shadow-xl transition-shadow">
                                                    <ResponsiveImage src={p.thumb_url} srcSet={p.srcset} alt={p.filename} containerClassName="absolute inset-0 w-full h-full" className="object-cover w-full h-full group-hover:scale-105 transition-transform" />
                                                    <div
                                                        className="absolute inset-x-0 bottom-0 bg-gradient-to-t from-black/80 to-transparent p-3 opacity-100 text-white text-xs truncate">
                                                        {p.filename}
                                                    </div>
                                                </Link>
                                            ))}
                                        </div>
                                    ) : <p className="opacity-50">Keine passenden Fotos gefunden.</p>}
                                </section>
                            </div>
                        )}
                    </div>
                </main>
            </div>
        </div>
    );
}
