import { t } from "@lingui/core/macro";
import { Trans } from "@lingui/react/macro";
import { useEditor, EditorContent, Editor, useEditorState } from '@tiptap/react';
import StarterKit from '@tiptap/starter-kit';
import { Table } from '@tiptap/extension-table';
import { TableRow } from '@tiptap/extension-table-row';
import { TableCell } from '@tiptap/extension-table-cell';
import { TableHeader } from '@tiptap/extension-table-header';
import Link from '@tiptap/extension-link';
import Underline from '@tiptap/extension-underline';
import { useEffect, useRef, useState } from 'react';
import useSWR from 'swr';
import { fetcher } from '../../api';
import { usePermissions } from '../../logic/usePermissions';
import { TextSnippet } from '../../api';



interface Props {
    value: string;
    onChange: (val: string) => void;
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

// Static fallback used before the editor instance exists. The reactive toolbar
// state is produced by `useEditorState` below and never read via the stable
// `editor` identity during render (which the React Compiler would freeze).
interface ToolbarUiState {
    bold: boolean;
    italic: boolean;
    underline: boolean;
    link: boolean;
    bulletList: boolean;
    orderedList: boolean;
    table: boolean;
    heading: number;
}

const EMPTY_TOOLBAR_UI: ToolbarUiState = {
    bold: false,
    italic: false,
    underline: false,
    link: false,
    bulletList: false,
    orderedList: false,
    table: false,
    heading: 0,
};

export default function WysiwygEditor({ value, onChange }: Props) {
    const {isSuperAdmin} = usePermissions();
    const { data: snippets, isLoading } = useSWR<TextSnippet[]>(isSuperAdmin ? '/api/management/text-snippets' : null, fetcher);
    const snippetsRef = useRef<TextSnippet[]>([]);

    const [slashState, setSlashState] = useState<SlashState>({ active: false, query: '', range: { from: 0, to: 0 }, rect: null });
    const [selectedIndex, setSelectedIndex] = useState(0);
    const [linkPopoverOpen, setLinkPopoverOpen] = useState(false);
    const [linkHref, setLinkHref] = useState('');
    const [hasTextSelection, setHasTextSelection] = useState(false);

    const editorRef = useRef<Editor | null>(null);
    const lastTextSelectionRef = useRef<{ from: number; to: number } | null>(null);

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

    const closeSlashMenu = () => {
        setSlashState(s => ({ ...s, active: false }));
        setSelectedIndex(0);
    };

    const applySnippet = (snippet: TextSnippet) => {
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
    };

    const updateSlashMenu = (editorInstance: Editor) => {
        const { selection } = editorInstance.state;
        const { $from, empty } = selection;
        if (!empty) {
            lastTextSelectionRef.current = { from: selection.from, to: selection.to };
            setHasTextSelection(true);
            closeSlashMenu();
            return;
        }

        setHasTextSelection(false);
        lastTextSelectionRef.current = null;

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
    };

    const editor = useEditor({
        // Defer editor creation to a post-mount effect instead of during render.
        // With React 19 StrictMode (dev double-invoke) and the React Compiler (prod),
        // creating the editor synchronously inside `useEditor` caused the instance to be
        // destroyed and recreated, leaving a window where `editor.getHTML()` ran on an
        // editor whose `schema` was already nulled -> "Cannot read properties of null
        // (reading 'cached')" on a hard page reload.
        immediatelyRender: false,
        extensions: [
            StarterKit.configure({ heading: { levels: [1, 2, 3, 4] }, link: false, underline: false }),
            Link.configure({ openOnClick: false, autolink: true, linkOnPaste: true }),
            Underline,
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
            attributes: { class: 'prose prose-sm max-w-none focus:outline-none p-4 text-base-content/90' },
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

    // Reactive toolbar state. `useEditorState` subscribes to editor transactions and
    // re-renders with the current selection/mark state. Reading `editor.isActive(...)`
    // directly during render would be frozen by the React Compiler (stable `editor`
    // identity, mutating `editor.state`), so every toolbar flag is derived here.
    const editorUi = useEditorState({
        editor,
        selector: (snapshot) => {
            const ed = snapshot.editor;
            if (!ed) return EMPTY_TOOLBAR_UI;
            return {
                bold: ed.isActive('bold'),
                italic: ed.isActive('italic'),
                underline: ed.isActive('underline'),
                link: ed.isActive('link'),
                bulletList: ed.isActive('bulletList'),
                orderedList: ed.isActive('orderedList'),
                table: ed.isActive('table'),
                heading: [1, 2, 3, 4].find((level) => ed.isActive('heading', { level })) ?? 0,
            };
        },
    });
    const toolbarUi = editorUi ?? EMPTY_TOOLBAR_UI;

    useEffect(() => {
        editorRef.current = editor;
    });

    useEffect(() => {
        if (editor && !editor.isDestroyed && value !== editor.getHTML()) {
            editor.commands.setContent(value);
        }
    }, [value, editor]);

    if (isLoading) return <div className="flex justify-center p-8"><span className="loading loading-spinner loading-lg"></span></div>;
    if (!editor) return null;

    // Defensive: the editor can be mid-teardown (schema already nulled) between
    // renders; never read `getHTML()` on a destroyed instance.
    const safeHtmlLength = editor.isDestroyed ? 0 : editor.getHTML().length;

    return (
        <div className="border border-base-300 rounded-box overflow-visible bg-base-100 flex flex-col focus-within:border-primary transition-colors shadow-inner relative z-10">
            <div className="bg-base-200 border-b border-base-300 p-2 flex flex-wrap gap-2 items-center rounded-t-box">
                <select
                    className="select select-sm select-bordered w-32"
                    aria-label={t`Überschrift`}
                    title={t`Überschrift`}
                    value={toolbarUi.heading}
                    onChange={(event) => {
                        const level = Number(event.target.value);
                        if (level === 0) editor.chain().focus().setParagraph().run();
                        else editor.chain().focus().toggleHeading({ level: level as 1 | 2 | 3 | 4 }).run();
                    }}
                >
                    <option value={0}><Trans>Absatz</Trans></option>
                    <option value={1}><Trans>Überschrift 1</Trans></option>
                    <option value={2}><Trans>Überschrift 2</Trans></option>
                    <option value={3}><Trans>Überschrift 3</Trans></option>
                    <option value={4}><Trans>Überschrift 4</Trans></option>
                </select>
                <button type="button" onClick={() => editor.chain().focus().toggleBold().run()} className={`btn btn-sm ${toolbarUi.bold ? 'btn-neutral' : 'btn-ghost'}`} title={t`Fett`}><span className="iconify mdi--format-bold text-lg"></span></button>
                <button type="button" onClick={() => editor.chain().focus().toggleItalic().run()} className={`btn btn-sm ${toolbarUi.italic ? 'btn-neutral' : 'btn-ghost'}`} title={t`Kursiv`}><span className="iconify mdi--format-italic text-lg"></span></button>
                <button type="button" onClick={() => editor.chain().focus().toggleUnderline().run()} className={`btn btn-sm ${toolbarUi.underline ? 'btn-neutral' : 'btn-ghost'}`} title={t`Unterstrichen`} aria-label={t`Unterstrichen`}><span className="iconify mdi--format-underline text-lg"></span></button>
                <div className="relative">
                    <button type="button" disabled={!hasTextSelection && !toolbarUi.link} onClick={() => { setLinkHref(editor.getAttributes('link').href ?? ''); setLinkPopoverOpen(true); }} className={`btn btn-sm ${toolbarUi.link ? 'btn-neutral' : 'btn-ghost'}`} title={t`Link einfügen`} aria-label={t`Link einfügen`}><span className="iconify mdi--link-variant text-lg"></span></button>
                    {linkPopoverOpen && (
                        <div className="absolute left-0 top-full z-20 mt-2 flex w-80 flex-col gap-2 rounded-box border border-base-300 bg-base-100 p-3 shadow-xl">
                            <label className="input input-sm input-bordered flex items-center gap-2">
                                <span className="text-sm opacity-70"><Trans>URL</Trans></span>
                                <input aria-label={t`Link-Adresse`} value={linkHref} onChange={(event) => setLinkHref(event.target.value)} className="grow" placeholder="https://..." />
                            </label>
                            <div className="flex justify-end gap-2">
                                <button type="button" className="btn btn-sm btn-ghost" onClick={() => setLinkPopoverOpen(false)}><Trans>Abbrechen</Trans></button>
                                {toolbarUi.link && <button type="button" className="btn btn-sm btn-error btn-outline" onClick={() => { editor.chain().focus().unsetLink().run(); setLinkPopoverOpen(false); }}><Trans>Entfernen</Trans></button>}
                                <button type="button" className="btn btn-sm btn-primary" onClick={() => {
                                    const range = lastTextSelectionRef.current;
                                    if (range) editor.chain().focus().setTextSelection({ from: range.from, to: range.to }).run();
                                    if (linkHref.trim()) editor.chain().focus().setLink({ href: linkHref.trim(), target: '_blank', rel: 'noopener noreferrer' }).run();
                                    else editor.chain().focus().unsetLink().run();
                                    setLinkPopoverOpen(false);
                                }}><Trans>Anwenden</Trans></button>
                            </div>
                        </div>
                    )}
                </div>
                <div className="divider divider-horizontal mx-0 w-1"></div>
                <button type="button" onClick={() => editor.chain().focus().toggleBulletList().run()} className={`btn btn-sm ${toolbarUi.bulletList ? 'btn-neutral' : 'btn-ghost'}`}><span className="iconify mdi--format-list-bulleted text-lg"></span></button>
                <button type="button" onClick={() => editor.chain().focus().toggleOrderedList().run()} className={`btn btn-sm ${toolbarUi.orderedList ? 'btn-neutral' : 'btn-ghost'}`} title={t`Nummerierte Liste`} aria-label={t`Nummerierte Liste`}><span className="iconify mdi--format-list-numbered text-lg"></span></button>
                <div className="divider divider-horizontal mx-0 w-1"></div>
                <button type="button" onClick={() => editor.chain().focus().insertTable({ rows: 3, cols: 3, withHeaderRow: true }).run()} className="btn btn-sm btn-ghost" title={t`Tabelle einfügen`} aria-label={t`Tabelle einfügen`}><span className="iconify mdi--table-plus text-lg"></span></button>
                
                {toolbarUi.table && (
                    <div className="join ml-1">
                        <button type="button" onClick={() => editor.chain().focus().addColumnBefore().run()} className="btn btn-sm join-item btn-ghost text-primary border-primary/20" title={t`Spalte davor hinzufügen`}><span className="iconify mdi--table-column-plus-before text-lg"></span></button>
                        <button type="button" onClick={() => editor.chain().focus().addColumnAfter().run()} className="btn btn-sm join-item btn-ghost text-primary border-primary/20" title={t`Spalte danach hinzufügen`}><span className="iconify mdi--table-column-plus-after text-lg"></span></button>
                        <button type="button" onClick={() => editor.chain().focus().deleteColumn().run()} className="btn btn-sm join-item btn-ghost text-error border-error/20" title={t`Spalte löschen`}><span className="iconify mdi--table-column-remove text-lg"></span></button>
                        <button type="button" onClick={() => editor.chain().focus().addRowBefore().run()} className="btn btn-sm join-item btn-ghost text-primary border-primary/20" title={t`Zeile davor hinzufügen`}><span className="iconify mdi--table-row-plus-before text-lg"></span></button>
                        <button type="button" onClick={() => editor.chain().focus().addRowAfter().run()} className="btn btn-sm join-item btn-ghost text-primary border-primary/20" title={t`Zeile danach hinzufügen`}><span className="iconify mdi--table-row-plus-after text-lg"></span></button>
                        <button type="button" onClick={() => editor.chain().focus().deleteRow().run()} className="btn btn-sm join-item btn-ghost text-error border-error/20" title={t`Zeile löschen`}><span className="iconify mdi--table-row-remove text-lg"></span></button>
                        <button type="button" onClick={() => editor.chain().focus().deleteTable().run()} className="btn btn-sm join-item btn-ghost text-error border-error/20" title={t`Tabelle löschen`}><span className="iconify mdi--table-remove text-lg"></span></button>
                    </div>
                )}

                <div className="flex-1"></div>

                <button type="button" onClick={() => editor.chain().focus().clearNodes().unsetAllMarks().run()} className="btn btn-sm btn-ghost text-error/50 hover:text-error" title={t`Formatierung entfernen`}>
                    <span className="iconify mdi--format-clear text-lg"></span>
                </button>
            </div>
            
            <div data-testid="editor-scroll" className="min-h-48 max-h-160 overflow-y-auto resize-y">
                <EditorContent editor={editor} />
            </div>
            
            <div className="bg-base-200 border-t border-base-300 p-1 flex justify-end items-center rounded-b-box text-sm opacity-60">
                     <span className={safeHtmlLength > 90000 ? 'text-error font-bold' : ''}>
                         {safeHtmlLength.toLocaleString('de-DE')} <Trans>/ 100.000 Zeichen (HTML)</Trans>
                     </span>
            </div>

            {slashState.active && slashState.rect && (
                <ul 
                    className="menu bg-base-100 shadow-2xl rounded-box border border-base-300 w-64 fixed z-50"
                    style={{ top: slashState.rect.bottom + 5, left: slashState.rect.left }}
                >
                    <li className="menu-title text-sm opacity-70 px-4 py-2"><Trans>Textbaustein einfügen</Trans></li>
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
                        <li className="px-4 py-2 text-sm opacity-50"><Trans>Keine Bausteine gefunden</Trans></li>
                    )}
                </ul>
            )}
        </div>
    );
}
