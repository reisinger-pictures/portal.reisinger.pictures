import { useState } from 'react';
import useSWR from 'swr';
import { fetcher, apiMutate } from '../../api';
import { useUI } from '../components/UIContext';
import ErrorMessage from '../components/ErrorMessage';
import TextSnippetModal from './components/TextSnippetModal';
import { TextSnippet } from '../../api';
import EmptyState from '../components/EmptyState';



export default function ManagementTextSnippetsView() {
    const { data: snippets, error, isLoading, mutate } = useSWR<TextSnippet[]>('/api/management/text-snippets', fetcher);
    const { showToast, confirm } = useUI();
    const [isModalOpen, setIsModalOpen] = useState(false);
    const [editingSnippet, setEditingSnippet] = useState<TextSnippet | null>(null);

    const handleSave = async (data: Partial<TextSnippet>) => {
        try {
            if (editingSnippet) {
                await apiMutate(`/api/management/text-snippets/${editingSnippet.id}`, 'PUT', data);
                showToast('success', 'Baustein aktualisiert');
            } else {
                await apiMutate('/api/management/text-snippets', 'POST', data);
                showToast('success', 'Baustein angelegt');
            }
            mutate();
        } catch (e: unknown) {
            showToast('error', e instanceof Error ? e.message : 'Fehler beim Speichern');
        }
    };

    const openEdit = (s: TextSnippet) => {
        setEditingSnippet(s);
        setIsModalOpen(true);
    };

    const handleDelete = async (id: string) => {
        if (!(await confirm({ title: 'Textbaustein löschen?', message: 'Möchtest du diesen Baustein wirklich löschen?', confirmColor: 'error' }))) return;
        try {
            await apiMutate(`/api/management/text-snippets/${id}`, 'DELETE');
            mutate();
            showToast('success', 'Baustein gelöscht');
        } catch {
            showToast('error', 'Fehler beim Löschen');
        }
    };

    if (isLoading) return <div className="p-10 flex justify-center"><span className="loading loading-spinner loading-lg"></span></div>;
    if (error) return <div className="p-10"><ErrorMessage message="Fehler beim Laden der Textbausteine." /></div>;

    return (
        <div className="p-6 md:p-10 max-w-5xl mx-auto w-full">
            <div className="flex flex-col md:flex-row justify-between items-start md:items-center mb-8 gap-4">
                <div>
                    <h1 className="text-4xl font-bold mb-2">Textbausteine</h1>
                    <p className="opacity-70">Verwalte Vorlagen für Verträge und Sonderkonditionen.</p>
                </div>
                <button className="btn btn-primary" onClick={() => { setEditingSnippet(null); setIsModalOpen(true); }}>+ Neuer Baustein</button>
            </div>

            <div className="grid grid-cols-1 md:grid-cols-2 gap-6">
                {snippets?.map(s => (
                    <div key={s.id} className="card bg-base-100 border border-base-300 shadow-sm">
                        <div className="card-body p-5">
                            <div className="flex justify-between items-start">
                                <h2 className="card-title text-primary">{s.title}</h2>
                                <div className="flex gap-1">
                                    <button className="btn btn-ghost btn-xs btn-square" title="Bearbeiten" onClick={() => openEdit(s)}><span className="iconify mdi--pencil text-base"></span></button>
                                    <button className="btn btn-ghost btn-xs btn-square text-error" onClick={() => handleDelete(s.id)} title="Löschen"><span className="iconify mdi--trash-can text-base"></span></button>
                                </div>
                            </div>
                            {s.shortcut && <code className="text-sm bg-base-200 p-1 rounded w-fit mt-1">/{s.shortcut}</code>}
                            <div className="mt-4 p-3 bg-base-200/50 rounded-box border border-base-300 opacity-80 text-sm line-clamp-3 prose prose-sm max-w-none" dangerouslySetInnerHTML={{ __html: s.content_html }}></div>
                        </div>
                    </div>
                ))}
                {snippets?.length === 0 && (
                    <EmptyState title="Keine Textbausteine vorhanden." className="col-span-full py-12 border-dashed" />
                )}
            </div>
            <TextSnippetModal isOpen={isModalOpen} onClose={() => setIsModalOpen(false)} editingSnippet={editingSnippet} onSave={handleSave} />
        </div>
    );
}