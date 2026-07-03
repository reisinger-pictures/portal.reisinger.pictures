/**
 * Format cents to Euro string
 */
export function formatMoney(cents: number): string {
    if (!Number.isFinite(cents)) return '--- €';
    return (cents / 100).toFixed(2) + ' €';
}

/**
 * Gallery and Group related types
 */
export interface GalleryGroup {
    id: string;
    name: string;
    parent_id: string | null;
    slug?: string;
    is_public?: boolean | null;
    is_free_download?: boolean | null;
    is_editorial_only?: boolean | null;
    is_hidden?: boolean | null;
    restricted_photographers?: boolean | null;
    effective_restricted_photographers?: boolean;
    effective_is_free_download?: boolean;
    children?: GalleryGroup[];
    galleries?: { id: string }[];
}

export interface FlatGroup {
    id: string;
    name: string;
    depth: number;
    is_public: boolean | null;
}

/**
 * Flatten nested gallery groups into a flat array with depth indicator
 */
export function flattenGroups(groups: GalleryGroup[], depth = 0): FlatGroup[] {
    let flat: FlatGroup[] = [];
    for (const g of groups) {
        flat.push({id: g.id, name: g.name, depth, is_public: g.is_public ?? null});
        if (g.children) flat = flat.concat(flattenGroups(g.children, depth + 1));
    }
    return flat;
}

/**
 * Format ISO date string to German date format (DD.MM.YYYY)
 */
export function formatDateToDE(iso: string): string {
    if (!iso) return '';
    const datePart = iso.slice(0, 10);
    const parts = datePart.split('-');
    if (parts.length === 3) return `${parts[2]}.${parts[1]}.${parts[0]}`;
    return iso;
}

/**
 * Format date to German locale string
 */
export function formatLocaleDate(date: Date): string {
    return date.toLocaleDateString('de-AT', {day: '2-digit', month: '2-digit', year: 'numeric'});
}

/**
 * Generate a unique ID for temporary elements
 */
export function generateId(): string {
    return `${Date.now()}-${Math.random().toString(36).substr(2, 9)}`;
}

/**
 * Debounce function to limit execution frequency
 */
export function debounce<T extends (...args: unknown[]) => unknown>(
    func: T,
    wait: number
): (...args: Parameters<T>) => void {
    let timeout: ReturnType<typeof setTimeout> | null = null;
    return (...args: Parameters<T>) => {
        if (timeout) clearTimeout(timeout);
        timeout = setTimeout(() => func(...args), wait);
    };
}

/**
 * Check if value is empty (null, undefined, empty string, empty array)
 */
export function isEmpty(value: unknown): boolean {
    if (value == null) return true;
    if (Array.isArray(value)) return value.length === 0;
    if (typeof value === 'string') return value.trim().length === 0;
    if (value instanceof Map || value instanceof Set) return value.size === 0;
    if (typeof value === 'object') return Object.keys(value).length === 0;
    return false;
}

/**
 * Safe JSON parse with fallback
 */
export function safeJsonParse<T>(json: string, fallback: T): T {
    try {
        return JSON.parse(json);
    } catch {
        return fallback;
    }
}
