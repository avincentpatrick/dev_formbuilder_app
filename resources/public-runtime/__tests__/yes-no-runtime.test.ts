import { describe, expect, it } from 'vitest';
import { createFormRuntime } from '../composables/useFormRuntime';
import { field, schemaResponse } from './fixtures';

/**
 * Increment M124 (R-9f296f7e) — a yes/no answer means the same thing in the browser as on the server.
 *
 * The Yes/No control emits the strings `'yes'`/`'no'` and the store keeps them, while a resumed draft comes back
 * from the server as booleans. Before M124 the engine read whichever form it was handed by value, so a bare
 * `${consent}` answered `'no'` was TRUE here (a non-empty string) and false on the server, and the same question
 * read differently before and after a resume. Stage 3 now reads every yes/no answer through the server's own
 * table, so both forms evaluate as the boolean the server stores — while the store itself keeps the control's
 * vocabulary, which is what the control needs to show the choice.
 */
function gated(relevant: string) {
    return schemaResponse({
        fields: [
            field({ key: 'consent', field_type: 'yes_no', sequence: 0 }),
            field({ key: 'details', relevant_expression: relevant, sequence: 1 }),
        ],
    });
}

describe('createFormRuntime — a yes/no answer reads as the server reads it (M124)', () => {
    it('hides a question gated on a bare reference when the control answers no', () => {
        const rt = createFormRuntime(gated('${consent}'));
        rt.setAnswer('consent', 'no');
        rt.setAnswer('details', 'kept on the device, never submitted');

        expect(rt.fieldRelevance.value.details).toBe(false);
        expect(rt.effectiveAnswers.value).toEqual({ consent: false });
    });

    it('reads a fresh "yes" and a resumed true the same way', () => {
        const fresh = createFormRuntime(gated("${consent} = 'yes'"));
        fresh.setAnswer('consent', 'yes');
        const resumed = createFormRuntime(gated("${consent} = 'yes'"), { initialAnswers: { consent: true } });

        expect(fresh.fieldRelevance.value.details).toBe(true);
        expect(resumed.fieldRelevance.value.details).toBe(true);
    });

    it('submits the boolean the server stores, and keeps the control vocabulary in the store', () => {
        const rt = createFormRuntime(gated("${consent} = 'yes'"));
        rt.setAnswer('consent', 'yes');

        expect(rt.answers.consent).toBe('yes');
        expect(rt.effectiveAnswers.value.consent).toBe(true);
    });
});
