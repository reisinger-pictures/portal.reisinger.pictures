import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useEffect } from 'react';
import { useForm, useWatch } from 'react-hook-form';
import { zodResolver } from '@hookform/resolvers/zod';
import { z } from 'zod';
import { TextSnippet } from '../../../api';
import WysiwygEditor from '../../components/WysiwygEditor';
import ModalDialogShell from '../../components/ModalDialogShell';

const createSnippetSchema = () => z.object({
    title: z.string().min(1, t`Titel ist erforderlich`),
    shortcut: z.string().min(1, t`Kürzel ist erforderlich`).regex(/^[a-z0-9_-]+$/, t`Nur Kleinbuchstaben, Zahlen, - und _`),
    content_html: z.string().min(1, t`Inhalt ist erforderlich`)
});

type SnippetFormValues = z.infer<ReturnType<typeof createSnippetSchema>>;

interface Props {
    isOpen: boolean;
    onClose: () => void;
    editingSnippet?: TextSnippet | null;
    onSave: (data: Partial<TextSnippet>) => Promise<void>;
}

// The prop is "the record this dialog edits", `null` meaning create a new one.
// It is renamed on destructuring to the record's own name because `editing` is
// already a word in this file with a different job — see the `editing={false}`
// on ModalDialogShell below. Naming the record keeps the two apart.
export default function TextSnippetModal({ isOpen, onClose, editingSnippet: snippet, onSave }: Props) {
    "use no memo";
    const snippetSchema = createSnippetSchema();
    const { register, handleSubmit, reset, setValue, control, formState: { errors, isSubmitting } } = useForm<SnippetFormValues>({
        resolver: zodResolver(snippetSchema)
    });

    useEffect(() => {
        if (isOpen) {
            reset({
                title: snippet?.title || '',
                shortcut: snippet?.shortcut || '',
                content_html: snippet?.content_html || ''
            });
        }
    }, [isOpen, snippet, reset]);

    const watchContentHtml = useWatch({ control, name: 'content_html' });

    const onSubmit = async (data: SnippetFormValues) => {
        try {
            await onSave(data);
            onClose();
        } catch {
            // The parent reports the API error; keep the entered values and modal open.
            return;
        }
    };

    if (!isOpen) return null;

    return (
        <ModalDialogShell
            title={snippet ? <Trans>Textbaustein bearbeiten</Trans> : <Trans>Neuen Textbaustein anlegen</Trans>}
            icon="mdi--text-box-multiple"
            onClose={onClose}
            // The shell's `editing` asks one question: "does this dialog have a
            // delete action for an existing record?" A text snippet has none —
            // deleting one is a list action — so the answer is a literal `false`.
            // It stays a literal because the value that *would* mean "this is an
            // edit session" is now called `snippet`, and the two readings of
            // "editing" can no longer be mistaken for each other here.
            editing={false}
            isSubmitting={isSubmitting}
            onSubmit={handleSubmit(onSubmit)}
            noValidate
            // `h-80vh` is this dialog's own height, unchanged: the shell adds
            // `max-h-90vh flex flex-col` alongside it and 80vh is the tighter
            // of the two, so the box is exactly as tall as it always was.
            boxClassName="max-w-4xl h-80vh"
            // The only bounded form layout this dialog had was hand-rolled:
            // a fixed-height box, a `flex-1` form and a `flex-1` region for
            // the editor. `scrollableBody` is that layout, expressed through
            // the shared shell, and it is what keeps the submit row reachable
            // instead of below the fold. The editor keeps its own bounded
            // scroll region (`min-h-48 max-h-160 resize-y`, inside
            // WysiwygEditor), so the two do not nest scroll the same content.
            scrollableBody
        >
                <div className="grid grid-cols-1 md:grid-cols-2 gap-4 mb-4">
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

                <div className="form-control mb-4">
                    <label className="label"><span className="label-text font-bold">Inhalt (HTML)</span></label>
                    <input type="hidden" required />
                    <WysiwygEditor value={watchContentHtml || ''} onChange={val => setValue('content_html', val)} />
                    {errors.content_html && <span className="text-error text-xs mt-1">{errors.content_html.message}</span>}
                </div>
        </ModalDialogShell>
    );
}