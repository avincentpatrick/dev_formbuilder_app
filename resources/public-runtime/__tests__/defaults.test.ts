/**
 * M180 (`R-20784770`, `D148`, `D154`) — a question's Default value is filled in when the form opens. `buildDefaults()`
 * works each one out ONCE (a formula over the clock and the answers seeded before it), shaped for the question's
 * control; a question that takes no default (a note, a calculation, a hidden question's fixed value, a file, a grid)
 * gets none.
 */

import { describe, expect, it } from 'vitest';

import { buildDefaults } from '../lib/defaults';
import { field, schemaResponse, section } from './fixtures';

const NOW = '2026-10-20T09:30:00+08:00';

describe('buildDefaults', () => {
    it('fills a typed default, shaped for the control', () => {
        const schema = schemaResponse({
            fields: [
                field({ key: 'name', default_value: 'Ana' }),
                field({ key: 'count', field_type: 'integer', default_value: '3' }),
                field({ key: 'rate', field_type: 'decimal', default_value: '2.5' }),
                field({ key: 'colour', field_type: 'single_select', default_value: 'red', config: { options: [{ value: 'red', label: 'Red' }, { value: 'blue', label: 'Blue' }] } }),
                field({ key: 'hobbies', field_type: 'multi_select', default_value: 'chess reading golf', config: { options: [{ value: 'chess', label: 'Chess' }, { value: 'reading', label: 'Reading' }] } }),
                field({ key: 'consent', field_type: 'yes_no', default_value: 'Yes' }),
                field({ key: 'start', field_type: 'date', default_value: '2026-01-15' }),
                field({ key: 'at', field_type: 'time', default_value: '08:30' }),
                field({ key: 'when', field_type: 'datetime', default_value: '2026-01-15T08:30' }),
            ],
        });

        expect(buildDefaults(schema, NOW).top).toEqual({
            name: 'Ana', count: 3, rate: 2.5, colour: 'red', hobbies: ['chess', 'reading'], consent: 'yes',
            start: '2026-01-15', at: '08:30', when: '2026-01-15T08:30',
        });
    });

    it('leaves out a default that does not fit its question', () => {
        const schema = schemaResponse({
            fields: [
                field({ key: 'count', field_type: 'integer', default_value: 'many' }),
                field({ key: 'colour', field_type: 'single_select', default_value: 'green', config: { options: [{ value: 'red', label: 'Red' }] } }),
                field({ key: 'start', field_type: 'date', default_value: 'tomorrow' }),
                field({ key: 'consent', field_type: 'yes_no', default_value: 'perhaps' }),
            ],
        });

        expect(buildDefaults(schema, NOW).top).toEqual({});
    });

    it('works a formula default out once, from the clock and the defaults before it', () => {
        const schema = schemaResponse({
            fields: [
                field({ key: 'visit', field_type: 'date', default_value: 'today()', default_value_is_expression: true }),
                field({ key: 'next', field_type: 'date', default_value: "date_add(${visit}, 7, 'days')", default_value_is_expression: true }),
                field({ key: 'early', field_type: 'date', default_value: "date_add(${later}, 1, 'days')", default_value_is_expression: true }),
                field({ key: 'later', field_type: 'date', default_value: '2026-12-01' }),
            ],
        });

        // `early` names a LATER question, which is not filled yet when it is worked out — so it is blank.
        expect(buildDefaults(schema, NOW).top).toEqual({ visit: '2026-10-20', next: '2026-10-27', later: '2026-12-01' });
    });

    it('leaves a formula default out with no clock, or when it does not parse', () => {
        const schema = schemaResponse({
            fields: [
                field({ key: 'visit', field_type: 'date', default_value: 'today()', default_value_is_expression: true }),
                field({ key: 'broken', default_value: 'concat(', default_value_is_expression: true }),
            ],
        });

        expect(buildDefaults(schema, null).top).toEqual({});
    });

    it('gives no default to a question that takes none, and leaves a hidden question\'s fixed value to the prefill', () => {
        const schema = schemaResponse({
            fields: [
                field({ key: 'intro', field_type: 'note', default_value: 'x' }),
                field({ key: 'total', field_type: 'calculated', default_value: '5' }),
                field({ key: 'ref', field_type: 'hidden', default_value: 'fixed', config: { prefill_source: 'fixed' } }),
                field({ key: 'photo', field_type: 'image_capture', default_value: 'x' }),
                field({ key: 'grid', field_type: 'matrix', default_value: 'x' }),
            ],
        });

        expect(buildDefaults(schema, NOW).top).toEqual({});
    });

    it('keeps a repeat member\'s default for each new entry, apart from the form\'s own', () => {
        const schema = schemaResponse({
            sections: [section({ key: 'roster', is_repeatable: true })],
            fields: [
                field({ key: 'town', default_value: 'Pasig' }),
                field({ key: 'relation', section_key: 'roster', default_value: 'child' }),
            ],
        });

        expect(buildDefaults(schema, NOW)).toEqual({ top: { town: 'Pasig' }, members: { roster: { relation: 'child' } } });
    });
});
