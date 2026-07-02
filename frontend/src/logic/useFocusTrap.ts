import { useEffect, useRef } from 'react';

const FOCUSABLE_SELECTOR = 'a[href], button:not([disabled]), textarea:not([disabled]), input:not([disabled]), select:not([disabled]), [tabindex]:not([tabindex="-1"])';

export function useFocusTrap<T extends HTMLElement = HTMLDivElement>(isActive: boolean) {
    const containerRef = useRef<T | null>(null);
    const previousFocusRef = useRef<HTMLElement | null>(null);

    useEffect(() => {
        if (!isActive) {
            if (previousFocusRef.current) {
                previousFocusRef.current.focus();
                previousFocusRef.current = null;
            }
            return;
        }

        previousFocusRef.current = document.activeElement as HTMLElement;

        const container = containerRef.current;
        if (!container) return;

        const focusableElements = container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR);
        const firstFocusable = focusableElements[0];

        if (firstFocusable) {
            firstFocusable.focus();
        }

        const handleKeyDown = (e: KeyboardEvent) => {
            if (e.key !== 'Tab') return;
            const currentFocusableElements = container.querySelectorAll<HTMLElement>(FOCUSABLE_SELECTOR);
            const first = currentFocusableElements[0];
            const last = currentFocusableElements[currentFocusableElements.length - 1];

            if (e.shiftKey) {
                if (document.activeElement === first) {
                    e.preventDefault();
                    last?.focus();
                }
            } else {
                if (document.activeElement === last) {
                    e.preventDefault();
                    first?.focus();
                }
            }
        };

        document.addEventListener('keydown', handleKeyDown);
        return () => {
            document.removeEventListener('keydown', handleKeyDown);
            if (previousFocusRef.current) {
                previousFocusRef.current.focus();
                previousFocusRef.current = null;
            }
        };
    }, [isActive]);

    return containerRef;
}
