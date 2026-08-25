import { describe, expect, it } from 'vitest';
import { conflictCopy } from '../lib/conflict-notice';

/**
 * Increment M14 — the module that stops the review banner and the outbox list telling a respondent two
 * different stories about one row.
 *
 * Its subject is a contradiction that was LIVE before this increment: `App.vue`'s `resolveNotice` said a
 * content conflict was "a copy already saved", while `outbox-status.ts` said the same row was "the form
 * changed after this was saved" — a sentence about a different event, on the surface the respondent reaches
 * first. Two copies of one decision, in two files, disagreeing.
 */
describe('conflictCopy', () => {
    it('keeps the form-drift wording byte-identical, because an e2e that cannot run here asserts it', () => {
        // ⛔ THE FROZEN STRING. `tests/e2e/public-runtime-offline.spec.ts:247` asserts `/this form was
        // updated/i` against this exact sentence, and that suite cannot run on the development host — so a
        // reword is discovered at merge, which is the 81-failure shape this project has already paid for
        // once. Adding a cause to the mapper is safe; editing this arm is not.
        expect(conflictCopy('form_updated').resolveNotice).toBe(
            'This form was updated after this response was saved. Your answers were kept where possible — please review and resubmit.',
        );
        expect(conflictCopy('form_updated').listDetail).toBe('We found a conflict — the form changed after this was saved.');
    });

    it('gives each 409 cause its own sentence on both surfaces', () => {
        // The four causes a parked row can carry. Deleting any one arm of the mapper reddens exactly this
        // case, and the per-cause assertions below say which arm went.
        expect(conflictCopy('draft_conflict').resolveNotice).toContain('another device');
        expect(conflictCopy('draft_conflict').listDetail).toContain('another device');

        expect(conflictCopy('submission_conflict').resolveNotice).toContain('already saved');
        expect(conflictCopy('submission_conflict').listDetail).toContain('already saved');

        expect(conflictCopy('submission_uuid_claimed').resolveNotice).toContain('could not be matched');
        expect(conflictCopy('submission_uuid_claimed').listDetail).toContain('could not be matched');

        expect(conflictCopy('submission_version_superseded').resolveNotice).toContain('This form was updated');
    });

    it('never tells a respondent the form changed when it did not', () => {
        // ⚠️ THIS IS THE HARM THE BACKLOG ROW NAMES, PINNED AS ITS OWN CASE RATHER THAN AS AN ASSERTION
        // INSIDE THE ONE ABOVE. The defect was not "the copy is vague" — it was that the client asserted a
        // republish for a refusal the server raised about a second device. A future arm added with the drift
        // sentence copy-pasted into it would pass the per-cause case above by containing the right substring
        // and still reintroduce exactly this.
        for (const code of ['draft_conflict', 'submission_conflict', 'submission_uuid_claimed']) {
            const copy = conflictCopy(code);
            expect(copy.resolveNotice, code).not.toContain('This form was updated');
            expect(copy.listDetail, code).not.toContain('the form changed');
        }
    });

    it('falls back to the drift copy for null and for a code this build has never heard of', () => {
        // Both inputs are real rather than defensive: a row parked by a build older than G8c stores no
        // `conflict_code` at all (`outbox.ts` defaults it to null, and `fixtures.ts` mirrors that), and a
        // future server cause reaches an older client as an unknown string. The fallback matches the
        // classifier's own tail, which keeps an unrecognised 409 on `refresh`.
        expect(conflictCopy(null)).toEqual(conflictCopy('form_updated'));
        expect(conflictCopy('a_cause_from_a_later_release')).toEqual(conflictCopy('form_updated'));
    });
});
