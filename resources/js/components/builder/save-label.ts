import type { SaveState } from './types';

/**
 * The builder toolbar's status line (`.builder__save`, a polite live region).
 *
 * ── A MODULE FOR FOUR STRINGS, AND THAT IS DELIBERATE ────────────────────────────────────────────────────
 * `builder-layout.test.ts`'s own docblock records why `Builder.vue` cannot be mounted in Vitest: it drags
 * the store, the CSRF fetch sidecar and ~20 children into a new Inertia mock. So a mapping written inline in
 * the SFC is testable only as SOURCE TEXT, which can assert that a string appears but cannot assert that the
 * failed state does not return the success string. Extracted, it is a pure function with a real unit test —
 * the same shape as `analytics/bucket-label.ts` and `audit/event-variant.ts`.
 */
export function saveLabel(state: SaveState): string {
    if (state === 'saving') return 'Saving…';

    // ⚠️ 'Not saved' RATHER THAN NOTHING, AND THE REASON IS NOT COSMETIC. ConfigPanel's alert lives inside
    // that panel's `v-else`, so it is rendered only when a field or section is SELECTED — and selection goes
    // null on exactly the failure-adjacent paths (a delete that succeeds clears it, and an empty form starts
    // with nothing selected). In that state this string is the only report of the failure anywhere in the
    // client, so an empty span would be strictly worse than the lie it replaces. An empty span would also
    // collapse and reflow a toolbar row that already wraps at 375px, at the moment the author needs it still.
    //
    // ⚠️ AND IT DOES NOT REPEAT THE ERROR TEXT. The alert is the assertive channel and already carries the
    // message; this polite region's job is to stop claiming success, not to say it twice. Same vocabulary as
    // `Pages/submissions/Encode.vue`'s autosave label, which settled this exact double-announcement question
    // on a page with the same two-channel shape.
    if (state === 'failed') return 'Not saved';

    // `idle` and `saved` alike. At `idle` this is TRUE rather than merely tolerated: the page is hydrated
    // from the draft and the store seeds its baselines from those same rows, so local IS server at first
    // paint — "all changes saved" is a true statement about a set of zero changes. (`Encode.vue` renders
    // nothing at idle for the OPPOSITE reason: that page may have no draft row at all, so a saved claim there
    // would name a row that does not exist. Different fact, different answer — do not copy its null here.)
    return 'All changes saved';
}
