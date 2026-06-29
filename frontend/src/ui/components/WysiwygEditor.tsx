import { useEditor, EditorContent, Editor } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { Table } from '@tiptap/extension-table';
import { TableRow } from '@tiptap/extension-table-row';
import { TableCell } from '@tiptap/extension-table-cell';
import { TableHeader } from '@tiptap/extension-table-header';
import { useEffect, useRef, useState, useCallback } from 'react';
import useSWR from 'swr';
import { fetcher } from '../../api';
import { usePermissions } from '../../logic/usePermissions';
import { TextSnippet } from '../../api';



interface Props {
    value: string;
    onChange: (val: string) => void;
    hideSnippets?: boolean;
}

export interface SlashStateRect {
    left: number;
    right: number;
    top: number;
    bottom: number;
}

export interface SlashState {
    active: boolean;
    query: string;
    range: { from: number; to: number };
    rect: SlashStateRect | null;
}

export default function WysiwygEditor({ value, onChange, hideSnippets }: Props) {
    const {isSuperAdmin} = usePermissions();
    const { data: snippets, isLoading } = useSWR<TextSnippet[]>(isSuperAdmin ? '/api/management/text-snippets' : null, fetcher);
    const snippetsRef = useRef<TextSnippet[]>([]);

    const [slashState, setSlashState] = useState<SlashState>({ active: false, query: '', range: { from: 0, to: 0 }, rect: null });
    const [selectedIndex, setSelectedIndex] = useState(0);

    const editorRef = useRef<Editor | null>(null);

    const slashStateRef = useRef(slashState);
    const selectedIndexRef = useRef(selectedIndex);

    useEffect(() => {
        if (snippets) snippetsRef.current = snippets;
    }, [snippets]);

    const filteredSnippets = (snippets || []).filter(s => 
        s.shortcut && s.shortcut.toLowerCase().startsWith(slashState.query.toLowerCase())
    );

    const filteredSnippetsRef = useRef(filteredSnippets);

    useEffect(() => {
        slashStateRef.current = slashState;
        selectedIndexRef.current = selectedIndex;
        filteredSnippetsRef.current = filteredSnippets;
    });

    const closeSlashMenu = useCallback(() => {
        setSlashState(s => ({ ...s, active: false }));
        setSelectedIndex(0);
    }, []);

    const applySnippet = useCallback((snippet: TextSnippet) => {
        const editorInstance = editorRef.current;
        if (!editorInstance) return;
        const range = slashStateRef.current.range;
        
        let content = snippet.content_html.trim();
        // Wenn das Snippet aus exakt einem <p>-Block besteht, entfernen wir die Tags für echtes Inline-Einfügen
        if (content.startsWith('<p>') && content.endsWith('</p>') && (content.match(/<p>/g) || []).length === 1) {
            content = content.substring(3, content.length - 4);
        }

        editorInstance.chain()
            .focus()
            .deleteRange(range)
            .insertContent(content)
            .run();
        closeSlashMenu();
    }, [closeSlashMenu]);

    const updateSlashMenu = useCallback((editorInstance: Editor) => {
        const { selection } = editorInstance.state;
        const { $from, empty } = selection;
        if (!empty) {
            closeSlashMenu();
            return;
        }

        const textBefore = $from.parent.textBetween(Math.max(0, $from.parentOffset - 50), $from.parentOffset, null, '\ufffc');
        const match = textBefore.match(/(?:^|\s)\/([a-zA-Z0-9_-]*)$/);

        if (match) {
            const query = match[1];
            const from = $from.pos - query.length - 1;
            const to = $from.pos;
            const coords = editorInstance.view.coordsAtPos(to);
            
            setSlashState({
                active: true,
                query,
                range: { from, to },
                rect: coords
            });
            setSelectedIndex(0);
        } else {
            closeSlashMenu();
        }
    }, [closeSlashMenu]);

    const editor = useEditor({
        extensions: [
            StarterKit,
            Table.configure({ resizable: true }),
            TableRow,
            TableHeader,
            TableCell,
        ],
        content: value,
        onUpdate: ({ editor }) => {
            onChange(editor.getHTML());
            updateSlashMenu(editor);
        },
        onSelectionUpdate: ({ editor }) => {
            updateSlashMenu(editor);
        },
        editorProps: { 
            attributes: { class: 'prose prose-sm max-w-none focus:outline-none min-h-[12rem] p-4 text-base-content/90' },
            // 🔥 WICHTIG: Tastatur-Events HIER abfangen, bevor Tiptap sie schluckt
            handleKeyDown: (_view, event) => {
                if (!slashStateRef.current.active) return false;
                
                const currentSnippets = filteredSnippetsRef.current;
                
                if (event.key === 'ArrowDown') {
                    event.preventDefault();
                    setSelectedIndex(prev => currentSnippets.length > 0 ? (prev + 1) % currentSnippets.length : 0);
                    return true;
                } else if (event.key === 'ArrowUp') {
                    event.preventDefault();
                    setSelectedIndex(prev => currentSnippets.length > 0 ? (prev - 1 + currentSnippets.length) % currentSnippets.length : 0);
                    return true;
                } else if (event.key === 'Enter' || event.key === 'Tab') {
                    const snippet = currentSnippets[selectedIndexRef.current];
                    if (snippet) {
                        event.preventDefault();
                        event.stopPropagation();
                        applySnippet(snippet);
                        return true;
                    }
                    closeSlashMenu();
                    return true;
                } else if (event.key === 'Escape') {
                    event.preventDefault();
                    closeSlashMenu();
                    return true;
                }
                
                return false;
            }
        }
    });

    useEffect(() => {
        editorRef.current = editor;
    });

    useEffect(() => {
        if (editor && value !== editor.getHTML()) {
            editor.commands.setContent(value);
        }
    }, [value, editor]);

    if (isLoading) return <div className="flex justify-center p-8"><span className="loading loading-spinner loading-lg"></span></div>;
    if (!editor) return null;

    return (
        <div className="border border-base-300 rounded-box overflow-visible bg-base-100 flex flex-col focus-within:border-primary transition-colors shadow-inner relative z-10">
            <div className="bg-base-200 border-b border-base-300 p-2 flex flex-wrap gap-2 items-center rounded-t-box">
                {isSuperAdmin && !hideSnippets && (
                    <select 
                        className="select select-sm select-bordered bg-primary/10 text-primary font-bold border-primary/30 mr-1" 
                        onChange={(e) => {
                            editor.chain().focus().insertContent(e.target.value).insertContent(' ').run();
                            e.target.value = '';
                        }}
                        defaultValue=""
                    >
                        <option value="" disabled className="bg-base-100 text-base-content">Baustein...</option>
                        {snippets?.map(s => <option key={s.id} value={s.content_html} className="bg-base-100 text-base-content">{s.title}{s.shortcut ? ` (/${s.shortcut})` : ''}</option>)}
                    </select>
                )}

                <button type="button" onClick={() => editor.chain().focus().toggleBold().run()} className={`btn btn-sm ${editor.isActive('bold') ? 'btn-neutral' : 'btn-ghost'}`}><span className="iconify mdi--format-bold text-lg"></span></button>
                <button type="button" onClick={() => editor.chain().focus().toggleItalic().run()} className={`btn btn-sm ${editor.isActive('italic') ? 'btn-neutral' : 'btn-ghost'}`}><span className="iconify mdi--format-italic text-lg"></span></button>
                <div className="divider divider-horizontal mx-0 w-1"></div>
                <button type="button" onClick={() => editor.chain().focus().toggleBulletList().run()} className={`btn btn-sm ${editor.isActive('bulletList') ? 'btn-neutral' : 'btn-ghost'}`}><span className="iconify mdi--format-list-bulleted text-lg"></span></button>
                <div className="divider divider-horizontal mx-0 w-1"></div>
                <button type="button" onClick={() => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()} className="btn btn-sm btn-ghost"><span className="iconify mdi--table-plus text-lg"></span></button>
                
                {editor.isActive('table') && (
                    <div className="join ml-1">
                        <button type="button" onClick={() => editor.chain().focus().addColumnAfter().run()} className="btn btn-sm join-item btn-ghost text-primary border-primary/20"><span className="iconify mdi--table-column-plus-after text-lg"></span></button>
                        <button type="button" onClick={() => editor.chain().focus().addRowAfter().run()} className="btn btn-sm join-item btn-ghost text-primary border-primary/20"><span className="iconify mdi--table-row-plus-after text-lg"></span></button>
                        <button type="button" onClick={() => editor.chain().focus().deleteTable().run()} className="btn btn-sm join-item btn-ghost text-error border-error/20"><span className="iconify mdi--table-remove text-lg"></span></button>
                    </div>
                )}

                <div className="flex-1"></div>

                <button type="button" onClick={() => editor.chain().focus().clearNodes().unsetAllMarks().run()} className="btn btn-sm btn-ghost text-error/50 hover:text-error" title="Formatierung entfernen">
                    <span className="iconify mdi--format-clear text-lg"></span>
                </button>
            </div>
            
            <EditorContent editor={editor} />
            
            <div className="bg-base-200 border-t border-base-300 p-1 flex justify-end items-center rounded-b-box text-sm opacity-60">
                 <span className={editor.getHTML().length > 90000 ? 'text-error font-bold' : ''}>
                     {editor.getHTML().length.toLocaleString('de-DE')} / 100.000 Zeichen (HTML)
                 </span>
            </div>

            {slashState.active && slashState.rect && (
                <ul 
                    className="menu bg-base-100 shadow-2xl rounded-box border border-base-300 w-64 fixed z-[9999]"
                    style={{ 
                        top: slashState.rect.bottom + 5, 
                        left: slashState.rect.left 
                    }}
                >
                    <li className="menu-title text-sm opacity-70 px-4 py-2">Textbaustein einfügen</li>
                    {filteredSnippets.length > 0 ? filteredSnippets.map((s, idx) => (
                        <li key={s.id}>
                            <a 
                                className={`${idx === selectedIndex ? 'bg-base-200 font-bold text-primary' : ''}`}
                                onClick={() => applySnippet(s)}
                                onMouseEnter={() => setSelectedIndex(idx)}
                            >
                                <span className="truncate flex-1">{s.title}</span>
                                {s.shortcut && <span className="opacity-50 text-sm font-mono ml-2 shrink-0">/{s.shortcut}</span>}
                            </a>
                        </li>
                    )) : (
                        <li className="px-4 py-2 text-sm opacity-50">Keine Bausteine gefunden</li>
                    )}
                </ul>
            )}
        </div>
    );
}
