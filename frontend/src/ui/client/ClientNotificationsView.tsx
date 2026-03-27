import useSWR from 'swr';
import { fetcher, apiMutate } from '../../api';
import PageLayout from '../components/PageLayout';

interface PrefItem {
    id: string;
    name: string;
    type: 'gallery' | 'group';
    gallery_type?: 'selection' | 'delivery';
    wants_notifications: boolean;
}

interface PreferencesData {
    galleries: PrefItem[];
    groups: PrefItem[];
}

export default function ClientNotificationsView() {
    const { data, error, isLoading, mutate } = useSWR<PreferencesData>('/api/notifications/preferences', fetcher);

    const toggleOptIn = async (id: string, type: 'gallery' | 'group', currentValue: boolean) => {
        const endpoint = type === 'gallery' ? `/api/galleries/${id}/opt-in` : `/api/gallery-groups/${id}/opt-in`;
        
        // Optimistic UI Update
        const newData = { ...data } as PreferencesData;
        if (type === 'gallery') {
            const idx = newData.galleries.findIndex(g => g.id === id);
            if(idx > -1) newData.galleries[idx].wants_notifications = !currentValue;
        } else {
            const idx = newData.groups.findIndex(g => g.id === id);
            if(idx > -1) newData.groups[idx].wants_notifications = !currentValue;
        }
        mutate(newData, false);

        try {
            await apiMutate(endpoint, 'POST', { wants_notifications: !currentValue });
            mutate(); // Real re-fetch
        } catch (e) {
            alert('Fehler beim Speichern der Einstellung.');
            mutate(); // Rollback on error
        }
    };

    if (isLoading) return <PageLayout><div className="flex h-full items-center justify-center"><span className="loading loading-spinner loading-lg"></span></div></PageLayout>;
    if (error || !data) return <PageLayout><div className="p-8 text-center text-error">Daten konnten nicht geladen werden.</div></PageLayout>;

    const hasNoAccess = data.galleries.length === 0 && data.groups.length === 0;

    return (
        <PageLayout currentView="notifications">
            <div className="container mx-auto p-4 md:p-8 max-w-4xl">
                <h1 className="text-3xl font-bold mb-2 flex items-center gap-2">
                    <span className="iconify mdi--bell-ring text-primary"></span> Benachrichtigungen
                </h1>
                <p className="opacity-70 mb-8">Verwalte hier, für welche Galerien und Ordner du E-Mail-Updates erhalten möchtest.</p>

                {hasNoAccess ? (
                    <div className="alert shadow-sm bg-base-100 border border-base-300">
                        <span className="iconify mdi--information text-xl"></span>
                        <span>Du bist aktuell keinen speziellen Ordnern oder privaten Galerien zugewiesen.</span>
                    </div>
                ) : (
                    <div className="space-y-8">
                        {/* Meta-Galerien (Gruppen) */}
                        {data.groups.length > 0 && (
                            <div>
                                <h2 className="text-xl font-bold mb-4 flex items-center gap-2 border-b border-base-300 pb-2">
                                    <span className="iconify mdi--folder-multiple text-secondary"></span> Abonnierte Ordner (Meta-Galerien)
                                </h2>
                                <div className="bg-base-100 rounded-box border border-base-300 shadow-sm overflow-hidden">
                                    {data.groups.map(group => (
                                        <div key={group.id} className="flex justify-between items-center p-4 border-b border-base-300 last:border-b-0 hover:bg-base-200/50 transition-colors">
                                            <div>
                                                <div className="font-bold text-lg">{group.name}</div>
                                                <div className="text-xs opacity-70">Benachrichtigt bei neuen Galerien in diesem Ordner.</div>
                                            </div>
                                            <input type="checkbox" className="toggle toggle-primary" checked={group.wants_notifications} onChange={() => toggleOptIn(group.id, 'group', group.wants_notifications)} />
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}

                        {/* Einzel-Galerien */}
                        {data.galleries.length > 0 && (
                            <div>
                                <h2 className="text-xl font-bold mb-4 flex items-center gap-2 border-b border-base-300 pb-2">
                                    <span className="iconify mdi--image-multiple text-primary"></span> Abonnierte Einzel-Galerien
                                </h2>
                                <div className="bg-base-100 rounded-box border border-base-300 shadow-sm overflow-hidden">
                                    {data.galleries.map(gallery => (
                                        <div key={gallery.id} className="flex justify-between items-center p-4 border-b border-base-300 last:border-b-0 hover:bg-base-200/50 transition-colors">
                                            <div>
                                                <div className="font-bold text-lg flex items-center gap-2">
                                                    {gallery.name}
                                                    <span className="badge badge-sm badge-ghost">{gallery.gallery_type === 'selection' ? 'Auswahl' : 'Delivery'}</span>
                                                </div>
                                                <div className="text-xs opacity-70">Benachrichtigt bei neuen Fotos in dieser Galerie.</div>
                                            </div>
                                            <input type="checkbox" className="toggle toggle-primary" checked={gallery.wants_notifications} onChange={() => toggleOptIn(gallery.id, 'gallery', gallery.wants_notifications)} />
                                        </div>
                                    ))}
                                </div>
                            </div>
                        )}
                    </div>
                )}
            </div>
        </PageLayout>
    );
}
