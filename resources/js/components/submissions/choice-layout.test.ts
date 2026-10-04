import { describe, expect, it } from 'vitest';
import { choiceLayoutFor } from './choice-layout';

/**
 * M130 (`R-048a3286`) — reading a stored appearance the way ODK does, and ignoring what it does not know.
 */
describe('choiceLayoutFor', () => {
    it('maps the two offered layouts on both list types', () => {
        for (const type of ['single_select', 'multi_select']) {
            expect(choiceLayoutFor(type, 'columns-pack')).toBe('pack');
            expect(choiceLayoutFor(type, 'columns')).toBe('columns');
        }
    });

    it('defaults to one per line when nothing, or an empty string, is stored', () => {
        expect(choiceLayoutFor('single_select', null)).toBe('stacked');
        expect(choiceLayoutFor('single_select', undefined)).toBe('stacked');
        expect(choiceLayoutFor('multi_select', '')).toBe('stacked');
    });

    it('reads space-separated tokens, as ODK does, taking the first it knows', () => {
        expect(choiceLayoutFor('multi_select', 'no-buttons columns-pack')).toBe('pack');
        expect(choiceLayoutFor('single_select', '  COLUMNS  likert ')).toBe('columns');
    });

    it('ignores an imported appearance it does not know, including inherited object names', () => {
        for (const unknown of ['likert', 'minimal', 'quick', 'columns-4', 'toString', 'constructor', '__proto__']) {
            expect(choiceLayoutFor('single_select', unknown), unknown).toBe('stacked');
        }
    });

    it('gives a layout only to the two list types, whatever is stored', () => {
        for (const type of ['dropdown', 'likert_scale', 'short_text', 'yes_no', 'note']) {
            expect(choiceLayoutFor(type, 'columns'), type).toBe('stacked');
        }
    });
});
