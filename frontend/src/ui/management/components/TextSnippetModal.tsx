import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useEffect } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { TextSnippet } from '../../../api';
import WysiwygEditor from '../../components/WysiwygEditor';

const snippetSchema = z.object({
    title: z.string().min(1, t`Titel ist erforderlich`),
    shortcut: z.string().min(1, t`Kürzel ist erforderlich`).regex(/^[a-z0-9_-]+$/, t`Nur Kleinbuchstaben, Zahlen, - und _`),
    content_html: z.string().min(1, t`Inhalt ist erforderlich`)
});

type SnippetFormValues = z.infer<typeof snippetSchema>;

interface Props {
    isOpen: boolean;
    onClose: () => void;
    editingSnippet?: TextSnippet | null;
    onSave: (data: Partial<TextSnippet>) => Promise<void>;
}

export default function TextSnippetModal({ isOpen, onClose, editingSnippet, onSave }: Props) {
    "use no memo";
    const { register, handleSubmit, reset, setValue, control, formState: { errors, isSubmitting } } = useForm<SnippetFormValues>({
        resolver: zodResolver(snippetSchema)
    });

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

    const onSubmit = async (data: SnippetFormValues) => {
        await onSave(data);
        onClose();
    };

    if (!isOpen) return null;

    return (
        <div className="modal modal-open">
            <div className="modal-box max-w-4xl relative flex flex-col h-80vh">
                <button type="button" className="btn btn-circle btn-ghost absolute right-2 top-2" onClick={onClose}>✕</button>
                <h3 className="font-bold text-xl mb-6 flex items-center gap-2 shrink-0">
                    <span className="iconify mdi--text-box-multiple text-primary"></span>
                    {editingSnippet ? <Trans>Textbaustein bearbeiten</Trans> : <Trans>Neuen Textbaustein anlegen</Trans>}
                </h3>

                <form onSubmit={handleSubmit(onSubmit)} className="flex flex-col flex-1 overflow-hidden" noValidate>
                    <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4 shrink-0">
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Titel (Intern)</span></label>
                            <input required type="text" {...register('title')} className={`input input-bordered ${errors.title ? 'input-error' : ''}`} />
                            {errors.title && <span className="text-error text-xs mt-1">{errors.title.message}</span>}
                        </div>
                        <div className="form-control">
                            <label className="label"><span className="label-text font-bold">Kürzel (Shortcut)</span></label>
                            <div className="join w-full">
                                <span className="btn no-animation join-item bg-base-300 border-base-300 font-mono opacity-70">/</span>
                                <input type="text" required {...register('shortcut')} className={`input input-bordered join-item w-full font-mono lowercase ${errors.shortcut ? 'input-error' : ''}`} />
                            </div>
                            {errors.shortcut && <span className="text-error text-xs mt-1">{errors.shortcut.message}</span>}
                        </div>
                    </div>

                    <div className="form-control flex-1 overflow-hidden mb-4 flex flex-col">
                        <label className="label shrink-0"><span className="label-text font-bold">Inhalt (HTML)</span></label>
                        <input type="hidden" required />
                        <div className="flex-1 overflow-y-auto">
                            <WysiwygEditor value={watchContentHtml || ''} onChange={val => setValue('content_html', val)} />
                        </div>
                        {errors.content_html && <span className="text-error text-xs mt-1">{errors.content_html.message}</span>}
                    </div>

                    <div className="modal-action shrink-0 mt-2">
                            <button type="button" className="btn btn-ghost" onClick={onClose}><Trans>Abbrechen</Trans></button>
                            <button type="submit" className="btn btn-primary" disabled={isSubmitting}>
                                {isSubmitting ? <span className="loading loading-spinner"></span> : <Trans>Speichern</Trans>}
                        </button>
                    </div>
                </form>
            </div>
            <div className="modal-backdrop" onClick={onClose}></div>
        </div>
    );
}