import { useState, useEffect } from 'react';
import Sidebar from './Sidebar';
import {flattenGroups, Gallery, GalleryGroup, useProtectedGalleries} from '../../logic/useGalleries';
import { Link, useNavigate, useSearchParams } from 'react-router-dom';
import { useSearch } from '../../logic/useSearch';
import GalleryModals from './GalleryModals';
import AdminWatermarkBanner from '../management/components/AdminWatermarkBanner';

export default function PageLayout({children, currentView, hideMobileHeader}: {
    children: React.ReactNode,
    currentView?: string,
    hideMobileHeader?: boolean
}) {
    const [isSidebarOpen, setIsSidebarOpen] = useState(false);
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
        const [searchParams] = useSearchParams();
    const [searchQuery, setSearchQuery] = useState(searchParams.get('q') || '');
    const [isSearchFocused, setIsSearchFocused] = useState(false);

    useEffect(() => {
        setSearchQuery(searchParams.get('q') || '');
    }, [searchParams]);
    const {results: searchResults} = useSearch(searchQuery, false, true);
    const navigate = useNavigate();

    const handleSearchSubmit = (e: React.FormEvent) => {
        e.preventDefault();
        if (searchQuery.trim().length >= 2) {
            navigate(`/search?q=${encodeURIComponent(searchQuery.trim())}`);
            (document.activeElement as HTMLElement)?.blur();
        }
    };

    const [isGroupModalOpen, setGroupModalOpen] = useState(false);
    const [isGalleryModalOpen, setGalleryModalOpen] = useState(false);
    const [editingGroup, setEditingGroup] = useState<GalleryGroup | null>(null);
    const [editingGallery, setEditingGallery] = useState<Gallery | null>(null);
    const safeGroups = Array.isArray(tree?.groups) ? tree.groups : [];

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
                    <Sidebar
                        tree={tree} isLoading={isLoading} isError={isError}
                        currentView={currentView}
                        onCloseMobile={() => setIsSidebarOpen(false)}
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
                    />
                </div>

                <main className="flex-1 overflow-y-auto flex flex-col w-full relative bg-base-200">
                    <header className="p-4 md:p-6 bg-base-100 border-b border-base-300 sticky top-0 z-30 flex items-center gap-4">
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
                                            <li key={g.id}><Link to={'/' + g.full_path} >📁 {g.name}</Link></li>
                                        ))}
                                        {searchResults.photos.map(p => (
                                            <li key={p.id}><Link to={'/photos/' + p.id} >
                                                <span className="iconify mdi--image-outline opacity-70"></span> {p.filename}
                                            </Link></li>
                                        ))}
                                        {searchResults.galleries.length === 0 && searchResults.photos.length === 0 && (
                                            <li className="disabled"><span className="opacity-50">Keine direkten Treffer</span></li>
                                        )}
                                    </ul>
                                </div>
                            )}
                        </form>
                    </header>
                    {children}
                </main>
                <GalleryModals
                    availableGroups={flattenGroups(safeGroups)}
                    isGroupModalOpen={isGroupModalOpen} setGroupModalOpen={setGroupModalOpen}
                    isGalleryModalOpen={isGalleryModalOpen} setGalleryModalOpen={setGalleryModalOpen}
                    editingGroup={editingGroup} editingGallery={editingGallery}
                    onCreateGroup={createGroup} onCreateGallery={createGallery}
                    onUpdateGroup={updateGroup} onUpdateGallery={updateGallery}
                    onDeleteGroup={deleteGroup} onDeleteGallery={deleteGallery}
                />
            </div>
        </div>
    );
}
