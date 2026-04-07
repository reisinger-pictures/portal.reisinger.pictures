import { Gallery, GalleryGroup, FlatGroup } from '../../logic/useGalleries';
import GalleryGroupModal from './GalleryGroupModal';
import GalleryModal from './GalleryModal';

interface GalleryModalsProps {
    availableGroups: FlatGroup[];
    isGroupModalOpen: boolean;
    setGroupModalOpen: (open: boolean) => void;
    isGalleryModalOpen: boolean;
    setGalleryModalOpen: (open: boolean) => void;

    editingGroup?: GalleryGroup | null;
    editingGallery?: Gallery | null;
    defaultGroupId?: string | null;

    onCreateGroup: (name: string, slug: string, isPublic: boolean | null, parentId?: string | null, extraOpts?: Record<string, unknown>) => Promise<void>;
    onCreateGallery: (name: string, slug: string, type: 'selection' | 'delivery', isLive: boolean, isPublic: boolean, parentId?: string | null, pw?: string, exp?: string, metadataOpts?: Record<string, unknown>) => Promise<void>;
    onUpdateGroup: (id: string, name: string, slug: string, isPublic: boolean | null, parentId?: string | null, extraOpts?: Record<string, unknown>) => Promise<void>;
    onUpdateGallery: (id: string, name: string, slug: string, type: 'selection' | 'delivery', isLive: boolean, isPublic: boolean, parentId?: string | null, pw?: string, exp?: string, metadataOpts?: Record<string, unknown>) => Promise<void>;
    onDeleteGroup: (id: string) => Promise<void>;
    onDeleteGallery: (id: string) => Promise<void>;
}

export default function GalleryModals(props: GalleryModalsProps) {
    return (
        <>
            <GalleryGroupModal
                isOpen={props.isGroupModalOpen}
                onClose={() => props.setGroupModalOpen(false)}
                availableGroups={props.availableGroups}
                defaultParentId={props.defaultGroupId}
                editingGroup={props.editingGroup}
                onCreate={props.onCreateGroup}
                onUpdate={props.onUpdateGroup}
                onDelete={props.onDeleteGroup}
            />
            <GalleryModal
                isOpen={props.isGalleryModalOpen}
                onClose={() => props.setGalleryModalOpen(false)}
                onOpenGroupModal={() => { props.setGalleryModalOpen(false); props.setGroupModalOpen(true); }}
                defaultGroupId={props.defaultGroupId}
                availableGroups={props.availableGroups}
                editingGallery={props.editingGallery}
                onCreate={props.onCreateGallery}
                onUpdate={props.onUpdateGallery}
                onDelete={props.onDeleteGallery}
            />
        </>
    );
}
