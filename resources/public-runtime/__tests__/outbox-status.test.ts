import { describe, expect, it } from 'vitest';
import { describeRow } from '../lib/outbox-status';
import { deriveReference } from '../lib/reference-number';
import type { OutboxStatus } from '../lib/db';
import { outboxRow } from './fixtures';

/**
 * Increment I10d — the projection from one outbox row to what the respondent reads.
 *
 * Pure, so every state is reachable here in a line, which is the whole reason it is not inlined in the
 * component: five states × a syncing flag × a cross-form flag is not a matrix worth exploring through mounts.
 */
describe('describeRow', () => {
    it('reads a fresh pending row as Queued', () => {
        const view = describeRow(outboxRow({ status: 'pending', attempts: 0 }), false, true);

        expect(view.label).toBe('Queued');
        expect(view.variant).toBe('neutral');
        expect(view.detail).toContain('Saved on this device');
        expect(view.canRetry).toBe(false);
    });

    it('reads a pending row that has already failed once as Retrying, keeping the spec’s words', () => {
        const view = describeRow(outboxRow({ status: 'pending', attempts: 2 }), false, true);

        expect(view.label).toBe('Retrying');
        // §7.1's "we'll keep trying" is TRUE here — the engine really will.
        expect(view.detail).toContain('we’ll keep trying');
    });

    it('does not promise continued retries on a row nothing will retry', () => {
        // ⚠️ THE ONE DELIBERATE DEVIATION FROM §7.1's LITERAL COPY. The spec gives Failed "Couldn't send —
        // we'll keep trying", but outbox.ts is explicit that a needs_attention row is NEVER auto-retried: it
        // got there by a 422 or by exhausting five attempts. Printing the spec's sentence over an engine that
        // has stopped would tell the respondent something false and they would wait instead of acting.
        const view = describeRow(outboxRow({ status: 'needs_attention' }), false, true);

        expect(view.label).toBe('Failed');
        expect(view.detail).not.toContain('we’ll keep trying');
        expect(view.detail).toContain('Retry now');
        expect(view.canRetry).toBe(true);
    });

    it('shows Syncing only while an attempt is actually in flight', () => {
        const row = outboxRow({ status: 'pending' });

        expect(describeRow(row, true, true).label).toBe('Syncing');
        expect(describeRow(row, true, true).detail).toBe('Sending…');
        expect(describeRow(row, false, true).label).toBe('Queued');
    });

    it('names WHY a row conflicted, so the list cannot contradict the banner its Review button opens', () => {
        // ⚠️ Increment M14 — THE CONTRADICTION THIS CLOSES WAS LIVE, NOT HYPOTHETICAL. This branch hardcoded
        // "the form changed after this was saved" for every cause, while `App.vue`'s review banner — reached
        // by the Review button on this very row — told a content conflict something else entirely. Both now
        // read `lib/conflict-notice.ts`, so the next cause added reaches both surfaces or neither.
        const content = describeRow(outboxRow({ status: 'conflict', conflict_code: 'submission_conflict' }), false, true);
        const draft = describeRow(outboxRow({ status: 'conflict', conflict_code: 'draft_conflict' }), false, true);
        const drift = describeRow(outboxRow({ status: 'conflict', conflict_code: 'form_updated' }), false, true);

        expect(content.detail).toContain('already saved');
        expect(content.detail).not.toContain('the form changed');
        expect(draft.detail).toContain('another device');
        expect(draft.detail).not.toContain('the form changed');
        // The drift arm keeps its pre-M14 sentence exactly.
        expect(drift.detail).toBe('We found a conflict — the form changed after this was saved.');
    });

    it('still describes a conflict whose code is null, which the exhaustiveness sweep depends on', () => {
        // A row parked by a build older than G8c carries no `conflict_code`, and `fixtures.ts` defaults it to
        // null — so the sweep at the bottom of this file walks `status: 'conflict'` with a null code and
        // asserts a non-empty detail. A cause-branch with no default arm would redden THAT case rather than
        // this one, several screens away from the change; this states the requirement where it is created.
        const view = describeRow(outboxRow({ status: 'conflict', conflict_code: null }), false, true);

        expect(view.detail).toBe('We found a conflict — the form changed after this was saved.');
    });

    it('offers Review for a conflict on this form and a way OUT for one on another', () => {
        const here = describeRow(outboxRow({ status: 'conflict' }), false, true);
        const elsewhere = describeRow(outboxRow({ status: 'conflict' }), false, false);

        expect(here.canReview).toBe(true);
        // The resolver reuses a share-token client bound to ONE slug, so a foreign conflict cannot be
        // resolved from here — and saying "Review" over a button that no-ops is the bug this replaces.
        expect(elsewhere.canReview).toBe(false);
        expect(elsewhere.detail).toContain('open this form');
    });

    it('reads a delivered row as Sent, quoting the SERVER reference', () => {
        const view = describeRow(
            outboxRow({ status: 'synced', server_submission_id: 'srv-9', server_reference: '7K4M-2QXB' }),
            false,
            true,
        );

        expect(view.label).toBe('Sent');
        expect(view.variant).toBe('success');
        expect(view.reference).toBe('7K4M-2QXB');
        expect(view.detail).toBe('Sent — reference 7K4M-2QXB');
    });

    it('degrades to a bare Sent when a row predates the server reference', () => {
        // A row synced by a build older than J2e has no `server_reference`. Falling back to the device-local
        // tag would be exactly the unfindable code J2e removed, so it says less instead.
        const view = describeRow(
            outboxRow({ status: 'synced', server_submission_id: 'srv-9', server_reference: null }),
            false,
            true,
        );

        expect(view.reference).toBeNull();
        expect(view.detail).toBe('Sent');
    });

    it('keeps the queue tag device-local and stable, and never calls it a reference', () => {
        // ⚠️ REWRITTEN IN J2e, AND THE CASE IT REPLACES WAS THE DOCTRINE RATHER THAN AN ACCIDENT. It read
        // "derives the reference from the CLIENT uuid for every status, including synced", on the grounds
        // that switching to a server-side value would make a code the respondent may have written down
        // CHANGE. That was right under its premise — the premise being that the local code was presented AS
        // their reference. It no longer is: it is a queue tag, labelled as one, with copy saying a reference
        // is issued once the response is sent.
        //
        // So the tag keeps the old invariant (stable across the transition, never derived from anything
        // server-side) and the REFERENCE is a separate field that is simply absent until there is one.
        const uuid = '0191f0a0-1111-7000-8000-000000000abc';
        const synced = describeRow(
            outboxRow({
                client_submission_uuid: uuid,
                status: 'synced',
                server_submission_id: 'srv-9',
                server_reference: '7K4M-2QXB',
            }),
            false,
            true,
        );
        const queued = describeRow(outboxRow({ client_submission_uuid: uuid, status: 'pending' }), false, true);

        expect(queued.queueTag).toBe(deriveReference(uuid));
        expect(synced.queueTag).toBe(queued.queueTag);
        expect(synced.queueTag).not.toContain('srv-9');

        // And a queued row has no reference at all — the whole point of the split.
        expect(queued.reference).toBeNull();
        expect(queued.detail).not.toContain('reference');
    });

    it('describes EVERY OutboxStatus, so a new case cannot be added silently', () => {
        // The WebhookFanOutTest pattern: sweep the union rather than spot-check it. A sixth status added to
        // db.ts without a branch here would otherwise render a blank badge.
        const statuses: OutboxStatus[] = ['pending', 'synced', 'conflict', 'needs_attention'];

        for (const status of statuses) {
            const view = describeRow(outboxRow({ status }), false, true);
            expect(view.label, status).not.toBe('');
            expect(view.detail, status).not.toBe('');
        }
    });
});
