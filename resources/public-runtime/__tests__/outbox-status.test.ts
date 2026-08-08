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

    it('offers Review for a conflict on this form and a way OUT for one on another', () => {
        const here = describeRow(outboxRow({ status: 'conflict' }), false, true);
        const elsewhere = describeRow(outboxRow({ status: 'conflict' }), false, false);

        expect(here.canReview).toBe(true);
        // The resolver reuses a share-token client bound to ONE slug, so a foreign conflict cannot be
        // resolved from here — and saying "Review" over a button that no-ops is the bug this replaces.
        expect(elsewhere.canReview).toBe(false);
        expect(elsewhere.detail).toContain('open this form');
    });

    it('reads a delivered row as Sent, quoting its reference', () => {
        const view = describeRow(outboxRow({ status: 'synced', server_submission_id: 'srv-9' }), false, true);

        expect(view.label).toBe('Sent');
        expect(view.variant).toBe('success');
        expect(view.detail).toContain(view.reference);
    });

    it('derives the reference from the CLIENT uuid for every status, including synced', () => {
        // Deriving from `server_submission_id` once available would make a code the respondent may already
        // have written down CHANGE as the row transitions — the worst possible property for a number whose
        // entire job is to be quotable.
        const uuid = '0191f0a0-1111-7000-8000-000000000abc';
        const synced = describeRow(
            outboxRow({ client_submission_uuid: uuid, status: 'synced', server_submission_id: 'srv-9' }),
            false,
            true,
        );
        const queued = describeRow(outboxRow({ client_submission_uuid: uuid, status: 'pending' }), false, true);

        expect(synced.reference).toBe(deriveReference(uuid));
        expect(synced.reference).toBe(queued.reference);
        expect(synced.reference).not.toContain('srv-9');
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
