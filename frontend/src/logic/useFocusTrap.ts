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
    container: HTMLElement;
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

/**
 * Whether a container is the innermost active focus trap in its document.
 *
 * Dialogs can nest — AIGalleryDefaultsModal opens inside another dialog — and a
 * key event on the inner one bubbles up to the outer handler. Without this
 * check, Escape in the inner dialog closes both, because the outer dialog sees
 * the same event and cannot tell it was already handled.
 *
 * With no trap registered at all this returns true, so a dialog that is not
 * itself trapped still responds to Escape rather than silently doing nothing.
 */
export function isTopmostTrapContainer(container: HTMLElement | null): boolean {
    if (!container) {
        return true;
    }

    const stack = trapStacks.get(container.ownerDocument);
    if (!stack || stack.length <= 1) {
        return true;
    }

    // Registration order is not nesting order. React runs effects child-first,
    // so a nested dialog registers BEFORE its parent, and a "last registered
    // wins" check hands Escape to the outer dialog and leaves the inner one
    // inert. Document order is what expresses nesting, because a nested dialog
    // is rendered inside its parent's subtree. Sibling dialogs that were
    // opened one after another are appended to the body, so the later one also
    // follows the earlier one and the same check stays correct for them.
    const isFollowedByAnotherTrap = stack.some(
        (entry) =>
            entry.container !== container &&
            (container.compareDocumentPosition(entry.container) &
                Node.DOCUMENT_POSITION_FOLLOWING) !==
                0,
    );

    return !isFollowedByAnotherTrap;
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
            container,
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
