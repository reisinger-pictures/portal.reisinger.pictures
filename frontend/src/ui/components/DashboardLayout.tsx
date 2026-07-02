import { useState, type ReactNode } from 'react';
import Sidebar from './Sidebar';
import { flattenGroups } from '../../logic/utils';
import { Gallery, GalleryGroup, useProtectedGalleries } from '../../logic/useGalleries';
import GalleryModals from './GalleryModals';
import { DashboardContext, type DashboardContextValue } from './DashboardContext';

export interface DashboardLayoutProps {
    children: ReactNode;
    currentView?: string;
    header?: (props: { onMenuClick: () => void }) => ReactNode;
    mainClassName?: string;
    sidebarWrapper?: (children: ReactNode) => ReactNode;
}

export default function DashboardLayout({ children, currentView, header, mainClassName = '', sidebarWrapper }: DashboardLayoutProps) {
    const [isSidebarOpen, setIsSidebarOpen] = useState(false);

    const galleryData = useProtectedGalleries();
    const { tree, isLoading, isError, mutate, createGroup, createGallery, updateGroup, updateGallery, deleteGroup, deleteGallery } = galleryData;
    const [isGroupModalOpen, setGroupModalOpen] = useState(false);
    const [isGalleryModalOpen, setGalleryModalOpen] = useState(false);
    const [editingGroup, setEditingGroup] = useState<GalleryGroup | null>(null);
    const [editingGallery, setEditingGallery] = useState<Gallery | null>(null);
    const [prefillGroupId, setPrefillGroupId] = useState<string | null>(null);
    const safeGroups = Array.isArray(tree?.groups) ? tree.groups : [];

    const onOpenGalleryModal = (groupId?: string) => {
        setEditingGallery(null);
        setPrefillGroupId(groupId || null);
        setGalleryModalOpen(true);
    };
    const onOpenGroupModal = (groupId?: string) => {
        setEditingGroup(null);
        setPrefillGroupId(groupId || null);
        setGroupModalOpen(true);
    };
    const onEditGroup = (g: GalleryGroup) => {
        setEditingGroup(g);
        setGroupModalOpen(true);
    };
    const onEditGallery = (g: Gallery) => {
        setEditingGallery(g);
        setGalleryModalOpen(true);
    };

    const sidebar = (
        <Sidebar
            tree={tree} isLoading={isLoading} isError={isError}
            currentView={currentView}
            onCloseMobile={() => setIsSidebarOpen(false)}
            onOpenGalleryModal={onOpenGalleryModal}
            onOpenGroupModal={onOpenGroupModal}
            onEditGroup={onEditGroup}
            onEditGallery={onEditGallery}
        />
    );

    const ctxValue: DashboardContextValue = {
        tree, isLoading, isError, mutate,
        onOpenGalleryModal, onOpenGroupModal, onEditGroup, onEditGallery
    };

    return (
        <DashboardContext.Provider value={ctxValue}>
            <div className="flex flex-col h-screen">
                <div className="flex flex-1 bg-base-100 overflow-hidden relative">
                    {isSidebarOpen && (
                        <div className="fixed inset-0 bg-black/50 z-40 md:hidden transition-opacity"
                             onClick={() => setIsSidebarOpen(false)} />
                    )}

                    <div className={`fixed inset-y-0 left-0 z-50 w-full md:w-72 2xl:w-80 transform transition-transform duration-300 ease-in-out md:relative md:translate-x-0 ${isSidebarOpen ? 'translate-x-0' : '-translate-x-full'}`}>
                        {sidebarWrapper ? sidebarWrapper(sidebar) : sidebar}
                    </div>

                    <main className={`flex-1 overflow-y-auto flex flex-col w-full relative ${mainClassName}`}>
                        {header && header({ onMenuClick: () => setIsSidebarOpen(true) })}
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
        </DashboardContext.Provider>
    );
}
