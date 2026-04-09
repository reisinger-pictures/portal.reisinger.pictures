import { useState } from 'react';
import { Link } from 'react-router-dom';
import { Gallery, GalleryGroup, GalleryTreeResponse } from '../../logic/useGalleries';

// Type Guards für sauberes Casting
function isGallery(node: Gallery | GalleryGroup): node is Gallery {
    return 'type' in node;
}

interface Props {
    tree?: GalleryTreeResponse | null;
    onOpenGroupModal: (groupId?: string) => void;
    onOpenGalleryModal: (groupId?: string) => void;
    onEditGroup: (g: GalleryGroup) => void;
    onEditGallery: (g: Gallery) => void;
    onOpenPhotographerTeam: (node: Gallery | GalleryGroup) => void;
}

const TreeNode = ({
                      node,
                      onEditGroup,
                      onEditGallery,
    onOpenPhotographerTeam,
    onOpenGroupModal,
                      onOpenGalleryModal,
                      expandSignal
                  }: {
    node: Gallery | GalleryGroup,
    onEditGroup: (g: GalleryGroup) => void,
    onEditGallery: (g: Gallery) => void,
    onOpenPhotographerTeam: (node: Gallery | GalleryGroup) => void,
    onOpenGroupModal: (id: string) => void,
    onOpenGalleryModal: (id: string) => void,
    expandSignal: number
}) => {
    const [isOpen, setIsOpen] = useState(false);
    const [localSignal, setLocalSignal] = useState(0);
    const effectiveSignal = expandSignal + localSignal;
    const [prevSignal, setPrevSignal] = useState(effectiveSignal);

    if (effectiveSignal !== prevSignal) {
        setPrevSignal(effectiveSignal);
        if (effectiveSignal > prevSignal) setIsOpen(true);
        if (effectiveSignal < prevSignal) setIsOpen(false);
    }

    // Falls es eine Gallery ist (Type Guard greift hier)
    if (isGallery(node)) {
        const isExpired = node.expires_at && new Date(node.expires_at) < new Date();
        return (
            <div className="flex justify-between items-center w-full py-2 px-4 hover:bg-base-200 transition-colors border-b border-base-300/50 last:border-0">
                <Link to={'/' + node.full_path} className={`flex-1 flex items-center gap-3 ${isExpired ? 'line-through opacity-50' : ''}`}>
                    <span className="iconify mdi--image-multiple-outline text-2xl text-base-content/50"></span>
                    <span className="font-medium">{node.name}</span>
                    <span className="badge badge-sm badge-ghost">{node.type === 'selection' ? 'Auswahl' : 'Delivery'}</span>
                </Link>
                <button onClick={() => onEditGallery(node)} className="btn btn-ghost btn-sm btn-circle tooltip" data-tip="Bearbeiten">
                    <span className="iconify mdi--pencil text-lg"></span>
                </button>
            </div>
        );
    }

    // Ab hier weiß TypeScript, dass "node" eine GalleryGroup sein muss
    const hasInhalt = ((node.children?.length ?? 0) > 0) || ((node.galleries?.length ?? 0) > 0);

    return (
        <details open={isOpen} onToggle={e => setIsOpen(e.currentTarget.open)} className="group border border-base-300 bg-base-100 rounded-box mb-2 shadow-sm">
            <summary className="flex justify-between items-center w-full py-3 px-4 cursor-pointer bg-base-200/50 hover:bg-base-200 transition-colors list-none [&::-webkit-details-marker]:hidden">
                <div className="flex items-center gap-3 flex-1">
                    <span className="iconify mdi--chevron-right text-xl transition-transform group-open:rotate-90"></span>
                    <span className="iconify mdi--folder text-2xl text-primary"></span>
                    <span className="font-bold text-lg">{node.name}</span>
                </div>
                <div className="flex items-center gap-1">
                    <button onClick={(e) => { e.preventDefault(); e.stopPropagation(); onOpenGalleryModal(node.id); }} className="btn btn-ghost btn-xs tooltip" data-tip="Galerie hier erstellen">
                        <span className="iconify mdi--image-plus text-base text-primary"></span>
                    </button>
                    <button onClick={(e) => { e.preventDefault(); e.stopPropagation(); onOpenGroupModal(node.id); }} className="btn btn-ghost btn-xs tooltip" data-tip="Unterordner hier erstellen">
                        <span className="iconify mdi--folder-plus text-base text-secondary"></span>
                    </button>
                    {hasInhalt && (
                        <div className="join mr-4">
                            <button
                                onClick={(e) => { e.preventDefault(); e.stopPropagation(); setLocalSignal(s => s > 0 ? s + 1 : 1); }}
                                className="btn btn-ghost btn-xs join-item tooltip"
                                data-tip="Unterordner ausklappen"
                            >
                                <span className="iconify mdi--expand-all text-base opacity-70"></span>
                            </button>
                            <button
                                onClick={(e) => { e.preventDefault(); e.stopPropagation(); setLocalSignal(s => s < 0 ? s - 1 : -1); }}
                                className="btn btn-ghost btn-xs join-item tooltip"
                                data-tip="Unterordner einklappen"
                            >
                                <span className="iconify mdi--collapse-all text-base opacity-70"></span>
                            </button>
                        </div>
                    )}
                    <button onClick={(e) => { e.preventDefault(); e.stopPropagation(); onEditGroup(node); }} className="btn btn-ghost btn-sm btn-circle tooltip" data-tip="Ordner bearbeiten">
                        <span className="iconify mdi--pencil text-lg"></span>
                    </button>
                </div>
            </summary>
            <div className="p-2 pl-4 md:pl-8 border-t border-base-300 bg-base-100/50">
                {node.children?.map((c: GalleryGroup) => (
                    <TreeNode key={'g-'+c.id} node={c} onEditGroup={onEditGroup} onEditGallery={onEditGallery} onOpenPhotographerTeam={onOpenPhotographerTeam} onOpenGroupModal={onOpenGroupModal} onOpenGalleryModal={onOpenGalleryModal} expandSignal={effectiveSignal} />
                ))}
                {node.galleries?.map((g: Gallery) => (
                    <TreeNode key={'gal-'+g.id} node={g} onEditGroup={onEditGroup} onEditGallery={onEditGallery} onOpenPhotographerTeam={onOpenPhotographerTeam} onOpenGroupModal={onOpenGroupModal} onOpenGalleryModal={onOpenGalleryModal} expandSignal={effectiveSignal} />
                ))}
                {(!node.children?.length && !node.galleries?.length) && <div className="p-4 text-sm opacity-50 italic">Ordner ist leer.</div>}
            </div>
        </details>
    );
};

export default function ManagementStructureView({ tree, onOpenGroupModal, onOpenGalleryModal, onEditGroup, onEditGallery, onOpenPhotographerTeam }: Props) {
    const [expandSignal, setExpandSignal] = useState(0);
    const safeGroups = Array.isArray(tree?.groups) ? [...tree.groups].sort((a,b)=>a.name.localeCompare(b.name)) : [];
    const safeRootGalleries = Array.isArray(tree?.root_galleries) ? [...tree.root_galleries].sort((a,b)=>a.name.localeCompare(b.name)) : [];

    return (
        <div className="p-6 md:p-10 max-w-6xl mx-auto w-full">
            <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                <div>
                    <h1 className="text-4xl font-bold mb-2">Galerien</h1>
                    <p className="opacity-70 text-lg">Verwalte deine Ordner und Galerien.</p>
                </div>
                <div className="join shadow-sm">
                    <button onClick={() => onOpenGalleryModal()} className="btn btn-primary join-item"><span className="iconify mdi--image-plus"></span> Neue Galerie</button>
                    <button onClick={() => onOpenGroupModal()} className="btn btn-outline join-item"><span className="iconify mdi--folder-plus"></span> Neuer Ordner</button>
                </div>
            </div>

            <div className="flex justify-end gap-2 mb-4">
                <button onClick={() => setExpandSignal(s => s > 0 ? s + 1 : 1)} className="btn btn-sm btn-ghost"><span className="iconify mdi--expand-all"></span> Alle ausklappen</button>
                <button onClick={() => setExpandSignal(s => s < 0 ? s - 1 : -1)} className="btn btn-sm btn-ghost"><span className="iconify mdi--collapse-all"></span> Alle einklappen</button>
            </div>

            <div className="space-y-2">
                {safeGroups.map((g: GalleryGroup) => (
                    <TreeNode key={'grp-'+g.id} node={g} onEditGroup={onEditGroup} onEditGallery={onEditGallery} onOpenPhotographerTeam={onOpenPhotographerTeam} onOpenGroupModal={onOpenGroupModal} onOpenGalleryModal={onOpenGalleryModal} expandSignal={expandSignal} />
                ))}
                {safeRootGalleries.length > 0 && (
                    <div className="border border-base-300 bg-base-100 rounded-box shadow-sm mt-6">
                        <div className="bg-base-200/50 py-2 px-4 font-bold border-b border-base-300 opacity-70">Hauptverzeichnis (Ohne Ordner)</div>
                        <div className="p-2">
                            {safeRootGalleries.map((g: Gallery) => (
                                <TreeNode key={'rgal-'+g.id} node={g} onEditGroup={onEditGroup} onEditGallery={onEditGallery} onOpenPhotographerTeam={onOpenPhotographerTeam} onOpenGroupModal={onOpenGroupModal} onOpenGalleryModal={onOpenGalleryModal} expandSignal={expandSignal} />
                            ))}
                        </div>
                    </div>
                )}
            </div>
        </div>
    );
}