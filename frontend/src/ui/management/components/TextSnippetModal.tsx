import { useEffect } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { TextSnippet } from '../ManagementTextSnippetsView';
import WysiwygEditor from '../../components/WysiwygEditor';

interface Props {
    isOpen: boolean;
    onClose: () => void;
    editingSnippet?: TextSnippet | null;
    onSave: (data: Partial<TextSnippet>) => Promise<void>;
}

export default function TextSnippetModal({ isOpen, onClose, editingSnippet, onSave }: Props) {
    const { register, handleSubmit, reset, setValue, control, formState: { isSubmitting } } = useForm<Partial<TextSnippet>>();

    useEffect(() => {
        if (isOpen) {
            reset({
                title: editingSnippet?.title || '',
                shortcut: editingSnippet?.shortcut || '',
                content_html: editingSnippet?.content_html || ''
            });
        }
    }, [isOpen, editingSnippet, reset]);

    const watchContentHtml = useWatch({ control, name: 'content_html' });

    const onSubmit = async (data: Partial<TextSnippet>) => {
        await onSave(data);
        onClose();
    };

    if (!isOpen) return null;

    return (
        <div className="modal modal-open z-50">
            <div className="modal-box max-w-4xl relative flex flex-col h-[80vh]">
                <button type="button" className="btn btn-sm btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-6 flex items-center gap-2 shrink-0">
                    <span className="iconify mdi--text-box-multiple text-primary"></span>
                    {editingSnippet ? 'Textbaustein bearbeiten' : 'Neuen Textbaustein anlegen'}
                </h3>

                <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col flex-1 overflow-hidden">
                    <div className="flex flex-col md:flex-row gap-4 mb-4 shrink-0">
                        <div className="form-control flex-1">
                            <label className="label"><span className="label-text font-bold">Titel (Intern) *</span></label>
                            <input required type="text" {...register('title')} className="input input-sm input-bordered" placeholder="z.B. AGB Angebot" />
                        </div>
                        <div className="form-control w-full md:w-1/3">
                            <label className="label">
                                <span className="label-text font-bold">Kürzel (Shortcut)</span>
                            </label>
                            <div className="join w-full">
                                <span className="btn btn-sm no-animation join-item bg-base-300 border-base-300 font-mono opacity-70 cursor-default">/</span>
                                <input type="text" {...register('shortcut')} className="input input-sm input-bordered join-item w-full font-mono lowercase" placeholder="agb" />
                            </div>
                        </div>
                    </div>

                    <div className="form-control flex-1 overflow-hidden mb-4 flex flex-col">
                        <label className="label shrink-0"><span className="label-text font-bold">Inhalt (HTML)</span></label>
                        <div className="flex-1 overflow-y-auto">
                            <WysiwygEditor value={watchContentHtml || ''} onChange={val => setValue('content_html', val)} hideSnippets={true} />
                        </div>
                    </div>

                    <div className="modal-action shrink-0 mt-2">
                        <button type="button" className="btn btn-ghost" onClick={onClose}>Abbrechen</button>
                        <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                            {isSubmitting ? <span className="loading loading-spinner"></span> : 'Speichern'}
                        </button>
                    </div>
                </form>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}
