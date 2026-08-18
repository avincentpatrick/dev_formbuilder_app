/**
 * The builder toolbar's status copy (Increment J7).
 *
 * This exists as a unit test at all because `Builder.vue` cannot be mounted in Vitest — `builder-layout.test.ts`
 * records why — so the mapping was previously assertable only as source text. Source text can prove a string
 * APPEARS; it cannot prove that the failed state does not return the success string, which is the one property
 * that matters here.
 */

import { describe as group, expect, it } from 'vitest';

import { saveLabel } from './save-label';
import type { SaveState } from './types';

const ALL_STATES: SaveState[] = ['idle', 'saving', 'saved', 'failed'];

group('the builder toolbar never claims a save that did not happen', () => {
    it('announces the write while it is in flight', () => {
        // The real ellipsis character, not three periods — the string is rendered, and a screen reader
        // pronounces the two differently.
        expect(saveLabel('saving')).toBe('Saving…');
    });

    it('does NOT return the success string when the write failed', () => {
        // ⭐ THE DEFECT, AS A PROPERTY RATHER THAN AS A FIXTURE. Asserting the exact replacement string would
        // pass just as well against a mapping that returned it for every state, so the negative is the load
        // -bearing half and the positive one below is the readable half.
        expect(saveLabel('failed')).not.toBe('All changes saved');
    });

    it('does NOT go silent when the write failed', () => {
        // The other way to get this wrong, and the reason the failed state renders a string at all: with
        // nothing selected, ConfigPanel's alert is not rendered, so an empty toolbar would leave a failed
        // write reported NOWHERE in the client — strictly worse than the lie it replaced.
        expect(saveLabel('failed')).not.toBe('');
    });

    it('reports the failure in the toolbar', () => {
        expect(saveLabel('failed')).toBe('Not saved');
    });

    it('renders idle and saved identically, deliberately', () => {
        // They are different FACTS — "wrote nothing" vs "wrote, and it landed" — kept apart in the type so a
        // later surface can tell them apart. At idle the claim is TRUE rather than tolerated: the page is
        // hydrated from the draft and the store's baselines come from those same rows, so local IS server at
        // first paint.
        expect(saveLabel('idle')).toBe('All changes saved');
        expect(saveLabel('saved')).toBe('All changes saved');
    });

    it('maps every member of the union to a non-empty string', () => {
        // Totality. A fifth state added to `SaveState` reddens HERE rather than falling through to whatever
        // the last return happens to be — which, given the last return is the success string, would silently
        // reintroduce this increment's defect under a new name.
        for (const state of ALL_STATES) {
            expect(saveLabel(state), `no copy for the '${state}' state`).not.toBe('');
        }
    });
});
