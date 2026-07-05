import { useState } from 'react';
import {Link, useNavigate} from 'react-router-dom';
import { useBrand } from '../../logic/useBrand';
import {useAuth} from '../../logic/useAuth';
import {useSearch} from '../../logic/useSearch';
import {Gallery} from '../../logic/useGalleries';
import Sidebar from '../components/Sidebar';
import SearchBarWithSuggestions from '../components/SearchBarWithSuggestions';
import { Trans } from "@lingui/react/macro";

export default function ClientDashboard() {
    const { logoSrc, portalName } = useBrand();
    const {user} = useAuth();
    const navigate = useNavigate();
    const [isSidebarOpen, setIsSidebarOpen] = useState(false);
    const [isSearchFocused, setIsSearchFocused] = useState(false);
    const {results: discoveryFeed} = useSearch(''); // Öffentliche Galerien laden

    if (!user) return null;

    const userName = user.name;
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
                <header className="p-4 border-b border-base-300 bg-base-100 sticky top-0 z-30 flex items-center gap-3">
                    <button type="button" className={`btn btn-square btn-ghost md:hidden shrink-0 ${isSearchFocused ? 'hidden' : ''}`} onClick={() => setIsSidebarOpen(true)}>
                        <span className="iconify mdi--menu text-2xl"></span>
                    </button>
                    <Link to="/" className={`md:hidden flex items-center gap-2 shrink-0 mr-1 ${isSearchFocused ? 'hidden' : ''}`}>
                        <img src={logoSrc} alt="Logo" className="w-8 h-8 rounded shadow-sm bg-base-100" />
                        <span className="font-bold text-sm truncate max-w-28 sm:max-w-48">{portalName}</span>
                    </Link>

                    <SearchBarWithSuggestions clearOnSubmit onFocusChange={setIsSearchFocused} />
                </header>

                <div className="container mx-auto p-8 relative z-0">
                    <h1 className="text-3xl font-bold mb-8"><Trans>Willkommen zurück, {userName}!</Trans></h1>
                    {(user.my_galleries?.length ?? 0) > 0 ? (
                        <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                            {user.my_galleries?.map((g: Gallery) => (
                                <div key={g.id}
                                     className="card bg-base-100 shadow-xl cursor-pointer hover:shadow-2xl transition-shadow border border-base-300"
                                     onClick={() => navigate('/' + g.full_path)}>
                                    <div className="card-body">
                                        <h2 className="card-title text-primary">{g.name}</h2>
                                        <div className="card-actions justify-end mt-4">
                                            <button className="btn btn-primary btn-sm"><Trans>Öffnen</Trans></button>
                                        </div>
                                    </div>
                                </div>
                            ))}
                        </div>
                    ) : (
                        <div className="alert shadow-lg bg-base-100 border border-base-300">
                            <span><Trans>Aktuell sind keine privaten Galerien für dich freigeschaltet.</Trans></span>
                        </div>
                    )}

                    {/* --- NEU: Öffentliche Galerien anzeigen --- */}
                    {discoveryFeed?.galleries && discoveryFeed.galleries.length > 0 && (
                        <div className="mt-12 border-t border-base-300 pt-8">
                            <h2 className="text-2xl font-bold mb-6 flex items-center gap-2">
                                <span className="iconify mdi--earth text-primary"></span> <Trans>Öffentliche Entdeckungen</Trans>
                            </h2>
                            <div className="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6">
                                {discoveryFeed.galleries.map(g => (
                                    <div key={g.id} className="card bg-base-100 shadow-xl cursor-pointer hover:shadow-2xl transition-shadow border border-base-300" onClick={() => navigate('/' + g.full_path)}>
                                        <div className="card-body">
                                            <h2 className="card-title text-primary">{g.name}</h2>
                                            <div className="card-actions justify-end mt-4">
                                                <button className="btn btn-outline btn-sm"><Trans>Ansehen</Trans></button>
                                            </div>
                                        </div>
                                    </div>
                                ))}
                            </div>
                        </div>
                    )}
                </div>
            </main>
        </div>
    );
}
