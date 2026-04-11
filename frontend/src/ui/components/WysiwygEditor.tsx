import { useEditor, EditorContent } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { useEffect } from 'react';

interface Props {
    value: string;
    onChange: (val: string) => void;
}

export default function WysiwygEditor({ value, onChange }: Props) {
    const editor = useEditor({
        extensions: [StarterKit],
        content: value,
        onUpdate: ({ editor }) => {
            onChange(editor.getHTML());
        },
        editorProps: {
            attributes: {
                class: 'prose prose-sm max-w-none focus:outline-none min-h-[8rem] p-4 text-base-content/90',
            },
        },
    });

    useEffect(() => {
        if (editor && value !== editor.getHTML()) {
            editor.commands.setContent(value);
        }
    }, [value, editor]);

    if (!editor) return null;

    return (
        <div className="border border-base-300 rounded-box overflow-hidden bg-base-100 flex flex-col focus-within:border-primary transition-colors shadow-inner">
            {/* Toolbar */}
            <div className="bg-base-200 border-b border-base-300 p-1 flex flex-wrap gap-1 items-center">
                <button type="button" onClick={() => editor.chain().focus().toggleBold().run()} className={`btn btn-xs ${editor.isActive('bold') ? 'btn-neutral' : 'btn-ghost'}`} title="Fett"><span className="iconify mdi--format-bold text-base"></span></button>
                <button type="button" onClick={() => editor.chain().focus().toggleItalic().run()} className={`btn btn-xs ${editor.isActive('italic') ? 'btn-neutral' : 'btn-ghost'}`} title="Kursiv"><span className="iconify mdi--format-italic text-base"></span></button>
                
                <div className="divider divider-horizontal mx-0 w-1"></div>
                
                <button type="button" onClick={() => editor.chain().focus().toggleHeading({ level: 1 }).run()} className={`btn btn-xs ${editor.isActive('heading', { level: 1 }) ? 'btn-neutral' : 'btn-ghost'}`} title="Überschrift 1"><span className="iconify mdi--format-header-1 text-base"></span></button>
                <button type="button" onClick={() => editor.chain().focus().toggleHeading({ level: 2 }).run()} className={`btn btn-xs ${editor.isActive('heading', { level: 2 }) ? 'btn-neutral' : 'btn-ghost'}`} title="Überschrift 2"><span className="iconify mdi--format-header-2 text-base"></span></button>
                <button type="button" onClick={() => editor.chain().focus().toggleHeading({ level: 3 }).run()} className={`btn btn-xs ${editor.isActive('heading', { level: 3 }) ? 'btn-neutral' : 'btn-ghost'}`} title="Überschrift 3"><span className="iconify mdi--format-header-3 text-base"></span></button>

                <div className="divider divider-horizontal mx-0 w-1"></div>
                
                <button type="button" onClick={() => editor.chain().focus().toggleBulletList().run()} className={`btn btn-xs ${editor.isActive('bulletList') ? 'btn-neutral' : 'btn-ghost'}`} title="Aufzählung"><span className="iconify mdi--format-list-bulleted text-base"></span></button>
                <button type="button" onClick={() => editor.chain().focus().toggleOrderedList().run()} className={`btn btn-xs ${editor.isActive('orderedList') ? 'btn-neutral' : 'btn-ghost'}`} title="Nummerierte Liste"><span className="iconify mdi--format-list-numbered text-base"></span></button>

                <div className="divider divider-horizontal mx-0 w-1"></div>

                <button type="button" onClick={() => editor.chain().focus().clearNodes().unsetAllMarks().run()} className="btn btn-xs btn-ghost text-base-content/50 hover:text-error" title="Formatierung entfernen"><span className="iconify mdi--format-clear text-base"></span></button>
            </div>
            
            <div className="cursor-text" onClick={() => editor.commands.focus()}>
                <EditorContent editor={editor} />
            </div>
        </div>
    );
}
