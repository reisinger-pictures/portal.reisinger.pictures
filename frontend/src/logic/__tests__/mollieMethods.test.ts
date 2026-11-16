import {describe, expect, it} from 'vitest';
import {mollieMethodOptions} from '../mollieMethods';

describe('mollieMethodOptions', () => {
    it('preserves the server order and maps known ids to German labels', () => {
        const options = mollieMethodOptions(['paybybank', 'eps', 'creditcard']);

        expect(options.map(option => option.id)).toEqual(['paybybank', 'eps', 'creditcard']);
        expect(options.map(option => option.label)).toEqual(['Pay by Bank', 'EPS-Überweisung', 'Kreditkarte']);
    });

    it('ignores unknown ids instead of rendering them', () => {
        const options = mollieMethodOptions(['mystery-method', 'eps', 'klarna']);

        expect(options.map(option => option.id)).toEqual(['eps']);
    });

    it('drops repeated ids', () => {
        const options = mollieMethodOptions(['eps', 'eps', 'creditcard']);

        expect(options.map(option => option.id)).toEqual(['eps', 'creditcard']);
    });

    it('returns no options for an empty list', () => {
        expect(mollieMethodOptions([])).toEqual([]);
    });
});
