export type EaTabId = 'buchungen' | 'wareneingang' | 'belege' | 'anlagen' | 'monat'
    | 'auswertungen' | 'reisen' | 'steuer' | 'ust' | 'antraege' | 'signatur' | 'einstellungen';

export const EA_TABS: EaTabId[] = [
    'buchungen', 'wareneingang', 'belege', 'anlagen', 'monat', 'auswertungen',
    'reisen', 'steuer', 'ust', 'antraege', 'signatur', 'einstellungen',
];

/**
 * Normalisiert die drei URL-Parameter auf den tatsächlich gerenderten Zustand.
 *
 * `liveBereich` ist der Wert der **aktuellen** Browser-Adresse, nicht der des
 * Render-Snapshots, aus dem React Routers `setSearchParams` seinen `previous`
 * nimmt. Ein Tab-Klick kann zwischen Rendern und Normalisierungs-Effekt liegen;
 * ohne den live-Wert würde ein veralteter Snapshot die Auswahl zurücksetzen
 * (lokal reproduziert: der Anlagen-Tab blieb auf „Buchungen" stehen).
 * Ein gültiger live-Wert bleibt erhalten, nur ein fehlender oder ungültiger
 * fällt auf `buchungen` zurück. In Tests ohne Browser-Router (MemoryRouter)
 * ist `liveBereich` leer und `previous` maßgeblich.
 */
export function normalizedEaSearch(
    previous: URLSearchParams,
    year: number,
    month: number,
    liveBereich: string | null,
): URLSearchParams {
    const next = new URLSearchParams(previous);
    const live = liveBereich !== null && EA_TABS.some((candidate) => candidate === liveBereich)
        ? liveBereich
        : null;
    const fromPrevious = next.get('bereich');
    const bereich = live
        ?? (fromPrevious !== null && EA_TABS.some((candidate) => candidate === fromPrevious)
            ? fromPrevious
            : null)
        ?? 'buchungen';
    next.set('bereich', bereich);
    next.set('jahr', String(year));
    next.set('monat', String(month));
    return next;
}
