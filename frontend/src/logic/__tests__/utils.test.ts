import {describe, it, expect, vi} from 'vitest';
import {
    formatMoney,
    formatDateToDE,
    formatLocaleDate,
    flattenGroups,
    generateId,
    debounce,
    isEmpty,
    safeJsonParse,
    GalleryGroup,
    calcAge,
    moveArrayItemUp,
    moveArrayItemDown,
    toSlug,
} from '../utils';

describe('formatMoney', () => {
    it('formats cents to euro with 2 decimals', () => {
        expect(formatMoney(0)).toBe('0.00 €');
        expect(formatMoney(1)).toBe('0.01 €');
        expect(formatMoney(100)).toBe('1.00 €');
        expect(formatMoney(1234)).toBe('12.34 €');
    });

    it('handles negative amounts', () => {
        expect(formatMoney(-500)).toBe('-5.00 €');
        expect(formatMoney(-1)).toBe('-0.01 €');
    });

    it('formats large and half-cent values', () => {
        expect(formatMoney(999999)).toBe('9999.99 €');
        expect(formatMoney(155)).toBe('1.55 €');
        expect(formatMoney(105)).toBe('1.05 €');
    });

    it('returns --- € for NaN and non-finite values', () => {
        expect(formatMoney(NaN)).toBe('--- €');
        expect(formatMoney(Infinity)).toBe('--- €');
        expect(formatMoney(-Infinity)).toBe('--- €');
    });
});

describe('formatDateToDE', () => {
    it('returns empty string for empty input', () => {
        expect(formatDateToDE('')).toBe('');
    });

    it('formats a clean ISO date (YYYY-MM-DD) to DD.MM.YYYY', () => {
        expect(formatDateToDE('2024-06-22')).toBe('22.06.2024');
        expect(formatDateToDE('2023-01-05')).toBe('05.01.2023');
    });

    it('returns the original string when fewer than 3 dash-parts', () => {
        expect(formatDateToDE('2024-06')).toBe('2024-06');
    });

    it('returns the original string for non-date input', () => {
        expect(formatDateToDE('abc')).toBe('abc');
    });

    // REVIEW (aktueller Bug): Split erfolgt nur auf '-' und nimmt 3 Teile an.
    // Ein ISO-Datum mit Zeitanteil erzeugt daher fehlerhaft "TagZeit.Monat.Jahr".
    it('handles ISO datetime by extracting the date part', () => {
        expect(formatDateToDE('2024-06-22T12:00:00Z')).toBe('22.06.2024');
    });
});

describe('formatLocaleDate', () => {
    it('formats a Date to de-AT DD.MM.YYYY', () => {
        expect(formatLocaleDate(new Date(2024, 5, 22))).toBe('22.06.2024');
        expect(formatLocaleDate(new Date(2023, 0, 5))).toBe('05.01.2023');
    });

    it('result matches the DD.MM.YYYY pattern', () => {
        expect(formatLocaleDate(new Date(2024, 5, 22))).toMatch(/^\d{2}\.\d{2}\.\d{4}$/);
    });

    it('does not throw for an invalid date', () => {
        expect(typeof formatLocaleDate(new Date('invalid'))).toBe('string');
    });
});

describe('flattenGroups', () => {
    it('returns an empty array for no groups', () => {
        expect(flattenGroups([])).toEqual([]);
    });

    it('flattens a flat list with depth 0 and null is_public default', () => {
        const groups: GalleryGroup[] = [{id: '1', name: 'A', parent_id: null}];
        expect(flattenGroups(groups)).toEqual([{id: '1', name: 'A', depth: 0, is_public: null}]);
    });

    it('preserves is_public true / false / null', () => {
        const groups: GalleryGroup[] = [
            {id: '1', name: 'T', parent_id: null, is_public: true},
            {id: '2', name: 'F', parent_id: null, is_public: false},
            {id: '3', name: 'N', parent_id: null, is_public: null},
            {id: '4', name: 'U', parent_id: null}, // undefined → null
        ];
        const flat = flattenGroups(groups);
        expect(flat.map(g => g.is_public)).toEqual([true, false, null, null]);
    });

    it('flattens a 3-level nested tree with increasing depth', () => {
        const groups: GalleryGroup[] = [{
            id: '1', name: 'root', parent_id: null,
            children: [{id: '2', name: 'child', parent_id: '1', children: [
                {id: '3', name: 'grand', parent_id: '2'},
            ]}],
        }];
        const flat = flattenGroups(groups);
        expect(flat.map(g => g.id)).toEqual(['1', '2', '3']);
        expect(flat.map(g => g.depth)).toEqual([0, 1, 2]);
    });

    it('handles siblings at the same depth', () => {
        const groups: GalleryGroup[] = [
            {id: '1', name: 'a', parent_id: null},
            {id: '2', name: 'b', parent_id: null},
        ];
        const flat = flattenGroups(groups);
        expect(flat.map(g => g.depth)).toEqual([0, 0]);
    });
});

describe('generateId', () => {
    it('matches the expected id pattern', () => {
        expect(generateId()).toMatch(/^\d+-[a-z0-9]{9}$/);
    });

    it('produces unique ids across many calls', () => {
        const ids = new Set(Array.from({length: 1000}, () => generateId()));
        expect(ids.size).toBe(1000);
    });
});

describe('debounce', () => {
    it('invokes once after the wait, with only the latest arguments', () => {
        vi.useFakeTimers();
        try {
            const spy = vi.fn((...args: unknown[]) => args.length);
            const debounced = debounce(spy, 100);

            debounced('a');
            debounced('b');
            debounced('c');

            expect(spy).not.toHaveBeenCalled();

            vi.advanceTimersByTime(99);
            expect(spy).not.toHaveBeenCalled();

            vi.advanceTimersByTime(1);
            expect(spy).toHaveBeenCalledTimes(1);
            expect(spy).toHaveBeenCalledWith('c');
        } finally {
            vi.useRealTimers();
        }
    });
});

describe('isEmpty', () => {
    it('treats null and undefined as empty', () => {
        expect(isEmpty(null)).toBe(true);
        expect(isEmpty(undefined)).toBe(true);
    });

    it('treats empty arrays and strings as empty', () => {
        expect(isEmpty([])).toBe(true);
        expect(isEmpty('')).toBe(true);
        expect(isEmpty('   ')).toBe(true); // trimmed
    });

    it('treats empty objects as empty', () => {
        expect(isEmpty({})).toBe(true);
    });

    it('does not treat falsy primitives 0 and false as empty', () => {
        expect(isEmpty(0)).toBe(false);
        expect(isEmpty(false)).toBe(false);
    });

    it('treats non-empty collections as non-empty', () => {
        expect(isEmpty([1])).toBe(false);
        expect(isEmpty('a')).toBe(false);
        expect(isEmpty({a: 1})).toBe(false);
    });

    // REVIEW-freundlich: RegExp/Map/Set sind Objekte ohne aufzählbare Eigen-Keys → gelten als "leer".
    it('treats RegExp as empty, and Map/Set based on size', () => {
        expect(isEmpty(/regex/)).toBe(true);
        expect(isEmpty(new Map())).toBe(true);
        expect(isEmpty(new Set())).toBe(true);
        expect(isEmpty(new Map([['a', 1]]))).toBe(false);
        expect(isEmpty(new Set([1]))).toBe(false);
    });
});

describe('safeJsonParse', () => {
    it('parses valid JSON', () => {
        expect(safeJsonParse('{"a":1}', null)).toEqual({a: 1});
    });

    it('returns the fallback for invalid JSON', () => {
        expect(safeJsonParse('not json', 'fb')).toBe('fb');
    });

    it('returns the fallback for an empty string', () => {
        expect(safeJsonParse('', 'fb')).toBe('fb');
    });

    it('parses primitives, arrays and null', () => {
        expect(safeJsonParse('"hello"', null)).toBe('hello');
        expect(safeJsonParse('[1,2]', null)).toEqual([1, 2]);
        expect(safeJsonParse('null', 'fb')).toBeNull();
    });

    it('preserves the fallback type', () => {
        expect(safeJsonParse('x', 42)).toBe(42);
        expect(safeJsonParse('x', {default: true})).toEqual({default: true});
    });
});

describe('calcAge', () => {
    it('born 2010-12-31, reference 2026-01-01 → 15 (birthday not yet occurred)', () => {
        expect(calcAge(new Date('2010-12-31'), new Date('2026-01-01'))).toBe(15);
    });

    it('born 2000-06-15, reference 2025-06-15 → 25 (exact birthday)', () => {
        expect(calcAge(new Date('2000-06-15'), new Date('2025-06-15'))).toBe(25);
    });

    it('born 2000-06-15, reference 2025-06-14 → 24 (day before birthday)', () => {
        expect(calcAge(new Date('2000-06-15'), new Date('2025-06-14'))).toBe(24);
    });

    it('born 1990-03-01, reference 2025-02-28 → 34 (month before birthday)', () => {
        expect(calcAge(new Date('1990-03-01'), new Date('2025-02-28'))).toBe(34);
    });

    it('born 2000-01-01, reference 2000-01-01 → 0 (same day)', () => {
        expect(calcAge(new Date('2000-01-01'), new Date('2000-01-01'))).toBe(0);
    });

    it('born 2000-01-01, reference 2000-12-31 → 0 (first year, not yet birthday)', () => {
        expect(calcAge(new Date('2000-01-01'), new Date('2000-12-31'))).toBe(0);
    });
});

// The 2026-09-26 test audit found moveArrayItemUp/Down and toSlug untested while
// the surrounding helpers had 37 tests. Both matter more than their size
// suggests: the move functions reorder contract line items and discount tiers,
// where an off-by-one is a money bug rather than a cosmetic one.

describe('moveArrayItemUp', () => {
    const items = [{ id: 'a' }, { id: 'b' }, { id: 'c' }];

    it('swaps the item with its predecessor', () => {
        expect(moveArrayItemUp(items, 2)).toEqual([{ id: 'a' }, { id: 'c' }, { id: 'b' }]);
    });

    it('returns the same array for the first index, so no reorder is a no-op', () => {
        expect(moveArrayItemUp(items, 0)).toBe(items);
    });

    it('returns the same array for an out-of-range index in both directions', () => {
        expect(moveArrayItemUp(items, items.length)).toBe(items);
        expect(moveArrayItemUp(items, -1)).toBe(items);
    });

    it('never mutates the input', () => {
        const original = [...items];
        moveArrayItemUp(items, 1);
        expect(items).toEqual(original);
    });

    it('preserves element identity, not just deep equality', () => {
        // Reordering a list of records must not clone them: identity is what
        // React keys and downstream dirty-tracking rely on.
        const moved = moveArrayItemUp(items, 2);
        expect(moved[1]).toBe(items[2]);
        expect(moved[2]).toBe(items[1]);
    });

    it('handles a single-element list', () => {
        const single = [{ id: 'only' }];
        expect(moveArrayItemUp(single, 0)).toBe(single);
    });

    it('handles an empty list', () => {
        const empty: { id: string }[] = [];
        expect(moveArrayItemUp(empty, 0)).toBe(empty);
    });
});

describe('moveArrayItemDown', () => {
    const items = [{ id: 'a' }, { id: 'b' }, { id: 'c' }];

    it('swaps the item with its successor', () => {
        expect(moveArrayItemDown(items, 0)).toEqual([{ id: 'b' }, { id: 'a' }, { id: 'c' }]);
    });

    it('returns the same array for the last index', () => {
        expect(moveArrayItemDown(items, items.length - 1)).toBe(items);
    });

    it('returns the same array for an out-of-range index in both directions', () => {
        expect(moveArrayItemDown(items, -1)).toBe(items);
        expect(moveArrayItemDown(items, items.length)).toBe(items);
    });

    it('never mutates the input', () => {
        const original = [...items];
        moveArrayItemDown(items, 1);
        expect(items).toEqual(original);
    });

    it('round-trips with moveArrayItemUp', () => {
        // The ordering guarantee the UI relies on: a step down followed by a
        // step up returns the original order.
        const down = moveArrayItemDown(items, 0);
        expect(moveArrayItemUp(down, 1)).toEqual(items);
    });
});

describe('toSlug', () => {
    it('transliterates German umlauts and eszett', () => {
        expect(toSlug('Müller & Sohn')).toBe('mueller-sohn');
        expect(toSlug('Ärzte')).toBe('aerzte');
        expect(toSlug('Ötzi')).toBe('oetzi');
        expect(toSlug('Übermut')).toBe('uebermut');
        expect(toSlug('Straße')).toBe('strasse');
    });

    it('collapses runs of non-alphanumerics into single hyphens', () => {
        expect(toSlug('a--b')).toBe('a-b');
        expect(toSlug('Gallery   Name')).toBe('gallery-name');
    });

    it('strips leading hyphens', () => {
        expect(toSlug('  Leading')).toBe('leading');
    });

    it('keeps a trailing hyphen, which is current behaviour and possibly a defect', () => {
        // Pinned rather than fixed. The regex only anchors at the start
        // (/^-+/), so 'Trailing  ' yields 'trailing-'. toSlug feeds gallery
        // slugs, so changing it could move existing gallery URLs. Whether a
        // trailing hyphen should be stripped is a product decision, not a
        // drive-by cleanup — and the backend runs makeUnique() over the result
        // anyway. If this is ever changed, this test is the tripwire.
        expect(toSlug('Trailing  ')).toBe('trailing-');
    });

    it('returns an empty string for empty input', () => {
        expect(toSlug('')).toBe('');
    });

    it('returns an empty string when nothing survives transliteration', () => {
        expect(toSlug('///')).toBe('');
    });
});
