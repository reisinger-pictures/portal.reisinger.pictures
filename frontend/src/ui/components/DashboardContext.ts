import { createContext, useContext } from 'react';
import { Gallery, GalleryGroup, GalleryTreeResponse } from '../../logic/useGalleries';

export interface DashboardContextValue {
    tree: GalleryTreeResponse | null | undefined;
    isLoading: boolean;
    isError: unknown;
    mutate: () => void;
    onOpenGalleryModal: (groupId?: string) => void;
    onOpenGroupModal: (groupId?: string) => void;
    onEditGroup: (g: GalleryGroup) => void;
    onEditGallery: (g: Gallery) => void;
}

export const DashboardContext = createContext<DashboardContextValue | null>(null);

export function useDashboard(): DashboardContextValue {
    const ctx = useContext(DashboardContext);
    if (!ctx) throw new Error('useDashboard must be used within DashboardLayout');
    return ctx;
}
