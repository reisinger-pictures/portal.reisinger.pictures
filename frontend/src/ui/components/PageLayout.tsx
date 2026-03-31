import { useState } from 'react';
import Sidebar from './Sidebar';
import {flattenGroups, Gallery, GalleryGroup, useProtectedGalleries} from '../../logic/useGalleries';
import GalleryModals from './GalleryModals';
import AdminWatermarkBanner from '../management/components/AdminWatermarkBanner';
import GlobalSearchHeader from './GlobalSearchHeader';

export default function PageLayout({children, currentView}: {
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
    
    const [isGroupModalOpen, setGroupModalOpen] = useState(false);
    const [isGalleryModalOpen, setGalleryModalOpen] = useState(false);
    const [editingGroup, setEditingGroup] = useState<GalleryGroup | null>(null);
    const [editingGallery, setEditingGallery] = useState<Gallery | null>(null);
    const [prefillGroupId, setPrefillGroupId] = useState<string | null>(null);
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
                        onOpenGalleryModal={(groupId?: string) => {
                            setEditingGallery(null);
                            setPrefillGroupId(groupId || null);
                            setGalleryModalOpen(true);
                        }}
                        onOpenGroupModal={(groupId?: string) => {
                            setEditingGroup(null);
                            setPrefillGroupId(groupId || null);
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
                    <GlobalSearchHeader onMenuClick={() => setIsSidebarOpen(true)} />
                    {children}
                </main>
                <GalleryModals
                    availableGroups={flattenGroups(safeGroups)}
                    isGroupModalOpen={isGroupModalOpen} setGroupModalOpen={setGroupModalOpen}
                    isGalleryModalOpen={isGalleryModalOpen} setGalleryModalOpen={setGalleryModalOpen}
                    editingGroup={editingGroup} editingGallery={editingGallery}
                    defaultGroupId={prefillGroupId}
                    onCreateGroup={createGroup} onCreateGallery={createGallery}
                    onUpdateGroup={updateGroup} onUpdateGallery={updateGallery}
                    onDeleteGroup={deleteGroup} onDeleteGallery={deleteGallery}
                />
            </div>
        </div>
    );
}
