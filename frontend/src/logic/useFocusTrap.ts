import { type RefObject, useEffect, useRef } from 'react';

const FOCUSABLE_SELECTOR = [
    'a[href]',
    'button:not([disabled])',
    'textarea:not([disabled])',
    'input:not([disabled])',
    'select:not([disabled])',
    '[contenteditable="true"]',
    '[tabindex]:not([tabindex="-1"])',
].join(',');

export interface FocusTrapOptions<T extends HTMLElement = HTMLElement> {
    onEscape?: () => void;
    containerRef?: RefObject<T | null>;
}

interface FocusTrapEntry {
    previousFocus: HTMLElement | null;
    addedTabIndex: boolean;
}

// Nested dialogs register in mount order. Only the top entry may handle
// document-level focus events, otherwise an outer trap fights its inner trap.
const trapStacks = new WeakMap<Document, FocusTrapEntry[]>();
const pendingFocusRestorations = new WeakMap<Document, HTMLElement[]>();

function getFocusableElements(container: HTMLElement): HTMLElement[] {
    return Array.from(container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR))
        .filter((element) => element.getAttribute('aria-hidden') !== 'true');
}

function isTopTrap(stack: FocusTrapEntry[], entry: FocusTrapEntry): boolean {
    return stack[stack.length - 1] === entry;
}

function removeTrapEntry(stack: FocusTrapEntry[], entry: FocusTrapEntry): void {
    const entryIndex = stack.indexOf(entry);
    if (entryIndex !== -1) {
        stack.splice(entryIndex, 1);
    }
}

function rememberPendingFocus(ownerDocument: Document, focus: HTMLElement | null): void {
    if (!focus?.isConnected) return;
    const pending = pendingFocusRestorations.get(ownerDocument) ?? [];
    pending.push(focus);
    pendingFocusRestorations.set(ownerDocument, pending);
}

function findPendingFocus(ownerDocument: Document): HTMLElement | null {
    const pending = pendingFocusRestorations.get(ownerDocument);
    if (!pending) return null;

    for (let index = pending.length - 1; index >= 0; index -= 1) {
        if (pending[index].isConnected) return pending[index];
    }
    return null;
}

export function useFocusTrap<T extends HTMLElement = HTMLDivElement>(
    isActive: boolean,
    options?: FocusTrapOptions<T>,
) {
    const containerRef = useRef<T | null>(null);
    const onEscapeRef = useRef<(() => void) | undefined>(options?.onEscape);

    useEffect(() => {
        onEscapeRef.current = options?.onEscape;
    }, [options?.onEscape]);

    useEffect(() => {
        if (!isActive) return;

        const container = (options?.containerRef ?? containerRef).current;
        if (!container) return;

        const ownerDocument = container.ownerDocument;
        const stack = trapStacks.get(ownerDocument) ?? [];
        if (!trapStacks.has(ownerDocument)) {
            trapStacks.set(ownerDocument, stack);
        }

        const activeElement = ownerDocument.activeElement;
        const previousFocus = activeElement instanceof HTMLElement ? activeElement : null;
        const entry: FocusTrapEntry = {
            previousFocus,
            addedTabIndex: false,
        };
        stack.push(entry);

        const focusFirstElement = () => {
            const focusableElements = getFocusableElements(container);
            if (focusableElements.length === 0) {
                if (!container.hasAttribute('tabindex')) {
                    container.setAttribute('tabindex', '-1');
                    entry.addedTabIndex = true;
                }
                container.focus();
                return;
            }
            focusableElements[0].focus();
        };

        focusFirstElement();

        const handleKeyDown = (event: KeyboardEvent) => {
            if (!isTopTrap(stack, entry)) return;

            if (event.key === 'Escape') {
                if (onEscapeRef.current) {
                    event.preventDefault();
                    onEscapeRef.current();
                }
                return;
            }
            if (event.key !== 'Tab') return;

            const focusableElements = getFocusableElements(container);
            if (focusableElements.length === 0) {
                event.preventDefault();
                container.focus();
                return;
            }

            const first = focusableElements[0];
            const last = focusableElements[focusableElements.length - 1];
            const focusedElement = ownerDocument.activeElement;
            const focusIsOutside = !(focusedElement instanceof Node) || !container.contains(focusedElement);

            if (event.shiftKey) {
                if (focusedElement === first || focusedElement === container || focusIsOutside) {
                    event.preventDefault();
                    last.focus();
                }
            } else if (focusedElement === last || focusedElement === container || focusIsOutside) {
                event.preventDefault();
                first.focus();
            }
        };

        const handleFocusIn = (event: FocusEvent) => {
            if (!isTopTrap(stack, entry)) return;

            const target = event.target;
            if (target instanceof Node && container.contains(target)) return;

            focusFirstElement();
        };

        ownerDocument.addEventListener('keydown', handleKeyDown);
        ownerDocument.addEventListener('focusin', handleFocusIn);

        return () => {
            ownerDocument.removeEventListener('keydown', handleKeyDown);
            ownerDocument.removeEventListener('focusin', handleFocusIn);

            const wasTopTrap = isTopTrap(stack, entry);
            if (!wasTopTrap) {
                // If an outer trap is removed before its nested trap, keep its
                // trigger as a fallback for when the nested trap is restored.
                rememberPendingFocus(ownerDocument, previousFocus);
            }
            removeTrapEntry(stack, entry);

            if (entry.addedTabIndex) {
                container.removeAttribute('tabindex');
            }

            if (wasTopTrap) {
                const focusTarget = previousFocus?.isConnected
                    ? previousFocus
                    : findPendingFocus(ownerDocument)
                        ?? (stack.length > 0 ? stack[stack.length - 1].previousFocus : null);
                if (focusTarget?.isConnected) {
                    focusTarget.focus();
                }
            }

            if (stack.length === 0) {
                trapStacks.delete(ownerDocument);
                pendingFocusRestorations.delete(ownerDocument);
            }
        };
    }, [isActive, options?.containerRef]);

    return containerRef;
}
