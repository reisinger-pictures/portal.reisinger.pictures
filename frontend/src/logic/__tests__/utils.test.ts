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
    it('_review: mishandles ISO datetime by splitting only on dash', () => {
        expect(formatDateToDE('2024-06-22T12:00:00Z')).toBe('22T12:00:00Z.06.2024');
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
    it('treats RegExp, Map and Set instances as empty (no enumerable own keys)', () => {
        expect(isEmpty(/regex/)).toBe(true);
        expect(isEmpty(new Map())).toBe(true);
        expect(isEmpty(new Set())).toBe(true);
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
