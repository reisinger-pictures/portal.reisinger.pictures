import { useState } from 'react';
import {Link, useLocation, useNavigate} from 'react-router-dom';
import {flattenGroups, Gallery, GalleryGroup, useProtectedGalleries} from '../../logic/useGalleries';
import {useAuth} from '../../logic/useAuth';
import {useSearch} from '../../logic/useSearch';
import Sidebar from '../components/Sidebar';
import GalleryModals from '../components/GalleryModals';
import ManagementUserView from './ManagementUserView';
import ManagementSettingsView from './ManagementSettingsView';
import ManagementFtpInbox from './ManagementFtpInbox';
import ManagementStatsView from './ManagementStatsView';
import ManagementMailTemplatesView from './ManagementMailTemplatesView';
import ErrorBoundary from '../components/ErrorBoundary';
import AdminWatermarkBanner from './components/AdminWatermarkBanner';

export default function ManagementDashboard() {
    const navigate = useNavigate();
    const location = useLocation();
    const pathView = location.pathname.replace('/', '');
    const currentView = pathView || 'structure';

    const {
        tree,
        isLoading,
        isError,
        createGroup,
        createGallery,
        updateGroup,
        updateGallery,
        deleteGroup,
        deleteGallery
    } = useProtectedGalleries();
    const [searchQuery, setSearchQuery] = useState('');
    const {results: searchResults} = useSearch(searchQuery, false, true); // Leere Query überspringen
    const {user} = useAuth();
    const {results: personalFeed, isLoading: feedLoading} = useSearch('', true);

    const [isSidebarOpen, setIsSidebarOpen] = useState(false);
    const [isGroupModalOpen, setGroupModalOpen] = useState(false);
    const [isGalleryModalOpen, setGalleryModalOpen] = useState(false);

    // Neu: State für das aktuell zu bearbeitende Element
    const [editingGroup, setEditingGroup] = useState<GalleryGroup | null>(null);
    const [editingGallery, setEditingGallery] = useState<Gallery | null>(null);

    const safeGroups = Array.isArray(tree?.groups) ? tree.groups : [];

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (searchQuery.trim().length >= 2) {
            navigate(`/search?q=${encodeURIComponent(searchQuery.trim())}`);
            setSearchQuery('');
        }
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
                    className={`fixed inset-y-0 left-0 z-50 w-80 transform transition-transform duration-300 ease-in-out md:relative md:translate-x-0 ${isSidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}>
                    <ErrorBoundary
                        fallback={<div className="w-80 p-4 text-error border-r border-base-300">Fehler beim Laden der
                            Sidebar.</div>}>
                        <Sidebar
                            tree={tree} isLoading={isLoading} isError={isError}
                            onOpenGalleryModal={() => {
                                setEditingGallery(null);
                                setGalleryModalOpen(true);
                            }}
                            onOpenGroupModal={() => {
                                setEditingGroup(null);
                                setGroupModalOpen(true);
                            }}
                            onEditGroup={(g) => {
                                setEditingGroup(g);
                                setGroupModalOpen(true);
                            }}
                            onEditGallery={(g) => {
                                setEditingGallery(g);
                                setGalleryModalOpen(true);
                            }}
                            currentView={currentView}
                            onCloseMobile={() => setIsSidebarOpen(false)}
                        />
                    </ErrorBoundary>
                </div>

                <main className="flex-1 overflow-y-auto flex flex-col w-full relative">
                    <header
                        className="p-4 md:p-6 bg-base-100 border-b border-base-300 sticky top-0 z-30 flex items-center gap-4">
                        <button className="btn btn-square btn-ghost md:hidden" onClick={() => setIsSidebarOpen(true)}>
                            <span className="iconify mdi--menu text-2xl"></span>
                        </button>

                        <form onSubmit={handleSearchSubmit} className="relative flex-1 w-full max-w-full md:max-w-4xl">
                            <div className="join w-full shadow-sm">
                                <input
                                    type="text"
                                    placeholder="Suche in allen Galerien..."
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
                                                                 onClick={() => setSearchQuery('')}>📁 {g.name}</Link>
                                            </li>
                                        ))}
                                        {searchResults.photos.map(p => (
                                            <li key={p.id}><Link to={'/photos/' + p.id}
                                                                 onClick={() => setSearchQuery('')}>
                                                <span
                                                    className="iconify mdi--image-outline opacity-70"></span> {p.filename}
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

                    <ErrorBoundary>
                        {currentView === 'users' && <ManagementUserView/>}
                        {currentView === 'settings' && <ManagementSettingsView/>}
                        {currentView === 'stats' && <ManagementStatsView/>}
                        {currentView === 'mail-templates' && <ManagementMailTemplatesView/>}
                        {currentView === 'structure' && (
                            <div className="p-6 md:p-10">
                                {user?.is_photographer && <ManagementFtpInbox/>}
                                <h1 className="text-3xl md:text-4xl font-bold mb-4">Galerie Struktur</h1>
                                <p className="opacity-70 text-lg mb-8">Erstelle eine Galerie oder eine Meta-Galerie.
                                    Wähle links eine Galerie aus der Struktur aus, um sie zu bearbeiten.</p>

                                {user?.is_photographer && (
                                    <div className="mt-12 border-t border-base-300 pt-8">
                                        <h2 className="text-2xl font-bold mb-6 flex items-center gap-2">
                                            <span className="iconify mdi--history text-primary"></span> Deine neuesten
                                            Uploads & Galerien
                                        </h2>
                                        {feedLoading ? (
                                            <span className="loading loading-spinner text-primary"></span>
                                        ) : (
                                            <div className="space-y-8">
                                                {personalFeed?.galleries && personalFeed.galleries.length > 0 && (
                                                    <div className="grid grid-cols-1 md:grid-cols-3 gap-4">
                                                        {personalFeed.galleries.slice(0, 3).map(g => (
                                                            <Link key={g.id} to={'/' + g.full_path}
                                                                  className="card bg-base-100 shadow-sm hover:shadow-xl transition-all border border-base-300">
                                                                <div
                                                                    className="card-body p-4 flex flex-row items-center">
                                                                    <div className="text-2xl mr-2"></div>
                                                                    <h3 className="card-title text-base text-primary truncate flex-1">{g.name}</h3>
                                                                </div>
                                                            </Link>
                                                        ))}
                                                    </div>
                                                )}
                                                {personalFeed?.photos && personalFeed.photos.length > 0 && (
                                                    <div
                                                        className="grid grid-cols-3 md:grid-cols-6 lg:grid-cols-8 gap-2">
                                                        {personalFeed.photos.slice(0, 16).map(p => (
                                                            <Link key={p.id} to={'/photos/' + p.id}
                                                                  className="block relative aspect-square bg-base-300 rounded overflow-hidden group shadow-sm hover:shadow-md">
                                                                <img src={p.thumb_url}
                                                                     className="object-cover w-full h-full group-hover:scale-105 transition-transform"
                                                                     loading="lazy"/>
                                                            </Link>
                                                        ))}
                                                    </div>
                                                )}
                                                {(!personalFeed?.galleries?.length && !personalFeed?.photos?.length) && (
                                                    <p className="opacity-50">Du hast noch keine eigenen Galerien oder
                                                        Bilder.</p>
                                                )}
                                            </div>
                                        )}
                                    </div>
                                )}
                            </div>
                        )}
                    </ErrorBoundary>
                </main>

                <ErrorBoundary>
                    <GalleryModals
                        availableGroups={flattenGroups(safeGroups)}
                        isGroupModalOpen={isGroupModalOpen} setGroupModalOpen={setGroupModalOpen}
                        isGalleryModalOpen={isGalleryModalOpen} setGalleryModalOpen={setGalleryModalOpen}
                        editingGroup={editingGroup} editingGallery={editingGallery}
                        onCreateGroup={createGroup} onCreateGallery={createGallery}
                        onUpdateGroup={updateGroup} onUpdateGallery={updateGallery}
                        onDeleteGroup={deleteGroup} onDeleteGallery={deleteGallery}
                    />
                </ErrorBoundary>


            </div>
        </div>
    );
}
