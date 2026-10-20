/**
 * M180 (`R-20784770`, `D148`, `D154`) — the runtime fills the defaults in when a form opens fresh, and only then. A
 * session that restores answers (a resume, a paper copy, a version drift, a conflict review, staff editing a response)
 * seeds nothing; a same-browser draft restored after the form opened drops a seeded default the respondent had cleared.
 */

import { describe, expect, it } from 'vitest';

import { createFormRuntime } from '../composables/useFormRuntime';
import { field, schemaResponse, section } from './fixtures';

const NOW = '2026-10-20T09:30:00+08:00';

function form() {
    return schemaResponse({
        sections: [section({ key: 'roster', is_repeatable: true, sequence: 1 })],
        fields: [
            field({ key: 'town', default_value: 'Pasig' }),
            field({ key: 'visit', field_type: 'date', default_value: 'today()', default_value_is_expression: true, sequence: 1 }),
            field({ key: 'relation', section_key: 'roster', default_value: 'child' }),
        ],
    });
}

describe('the runtime\'s defaults', () => {
    it('fills them in when asked to, and the respondent can change one', () => {
        const runtime = createFormRuntime(form(), { now: NOW, seedDefaults: true });

        expect(runtime.answers.town).toBe('Pasig');
        expect(runtime.answers.visit).toBe('2026-10-20');

        runtime.setAnswer('town', 'Marikina');
        expect(runtime.answers.town).toBe('Marikina');
    });

    it('fills none unless asked to', () => {
        const runtime = createFormRuntime(form(), { now: NOW });

        expect(runtime.answers.town).toBeUndefined();
    });

    it('lets restored answers win over a default', () => {
        const runtime = createFormRuntime(form(), { now: NOW, seedDefaults: true, initialAnswers: { town: 'Taguig' } });

        expect(runtime.answers.town).toBe('Taguig');
    });

    it('drops a seeded default that a draft restored afterwards does not hold, so a cleared one stays cleared', () => {
        const runtime = createFormRuntime(form(), { now: NOW, seedDefaults: true });

        runtime.restoreDraft({ visit: '2026-10-19' });

        expect(runtime.answers.town).toBeUndefined();
        expect(runtime.answers.visit).toBe('2026-10-19');
    });

    it('fills a repeat member\'s default into each new entry', () => {
        const runtime = createFormRuntime(form(), { now: NOW, seedDefaults: true });

        runtime.addInstance('roster');
        runtime.addInstance('roster');

        expect(runtime.instanceValue('roster', 0, 'relation')).toBe('child');
        expect(runtime.instanceValue('roster', 1, 'relation')).toBe('child');
    });

    it('leaves a new entry empty when not asked to fill defaults', () => {
        const runtime = createFormRuntime(form(), { now: NOW });

        runtime.addInstance('roster');

        expect(runtime.instanceValue('roster', 0, 'relation')).toBeNull();
    });
});
