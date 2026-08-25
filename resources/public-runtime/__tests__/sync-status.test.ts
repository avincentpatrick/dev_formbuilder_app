import { ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import SyncStatus from '../components/SyncStatus.vue';
import { ConflictReviewKey, SyncOutboxKey } from '../composables/context';
import { fakeSync, outboxRow } from './fixtures';

/**
 * The app-level sync surface. Two of these cases were REWRITTEN by I10d rather than added, and both
 * inversions are deliberate — see each one.
 */
describe('SyncStatus', () => {
    it('shows a Review button for a conflict on THIS form and invokes the injected action', async () => {
        const review = vi.fn();
        const wrapper = mount(SyncStatus, {
            global: {
                provide: {
                    [SyncOutboxKey as symbol]: fakeSync({ conflict: 1, conflictHere: 1 }),
                    [ConflictReviewKey as symbol]: review,
                },
            },
        });

        // Anchored on a testid, not on a structural class: I10d rebuilt this surface on MdsButton, so the
        // old `.sync-status__row--conflict button` selector describes markup that no longer exists.
        const button = wrapper.get('[data-testid="review-conflicts"]');
        expect(button.text()).toBe('Review');

        await button.trigger('click');
        expect(review).toHaveBeenCalledOnce();
        wrapper.unmount();
    });

    it('HIDES the Review CTA when every conflict belongs to a different form', () => {
        // A live bug before I10d, not a new risk: the count was cross-form while the resolver is slug-scoped,
        // so this state rendered "1 response needs review" above a button that silently did nothing.
        const wrapper = mount(SyncStatus, {
            global: {
                provide: {
                    [SyncOutboxKey as symbol]: fakeSync({ conflict: 1, conflictHere: 0 }),
                    [ConflictReviewKey as symbol]: vi.fn(),
                },
            },
        });

        expect(wrapper.find('[data-testid="review-conflicts"]').exists()).toBe(false);
        // ...and the count is explained rather than left as a number nobody can act on.
        expect(wrapper.text()).toContain('needs review on another form');
        wrapper.unmount();
    });

    it('renders the Sync now action even when the queue is EMPTY', () => {
        // ⚠️ THIS CASE IS THE INVERSE OF WHAT IT USED TO ASSERT ("renders nothing when the queue is empty"),
        // and the inversion is the point. `docs/offline-first-sync-design.md:103` requires a manual "Sync
        // now" that is ALWAYS VISIBLE, because it is the documented fallback on platforms with weak
        // Background Sync — i.e. exactly the platforms where a row can be stuck while the queue looks idle.
        // The old assertion locked in the behaviour that doc forbids. Do not "restore" it.
        const wrapper = mount(SyncStatus, {
            global: { provide: { [SyncOutboxKey as symbol]: fakeSync(), [ConflictReviewKey as symbol]: vi.fn() } },
        });

        expect(wrapper.find('.sync-status').exists()).toBe(true);
        expect(wrapper.text()).toContain('Sync now');
        // A first-time visitor has sent NOTHING, and this surface now renders on their very first screen —
        // so "Everything on this device has been sent" would be a false claim. The two empty cases are
        // distinguished (found by the adversarial review; the earlier assertion pinned the false one).
        expect(wrapper.text()).toContain('Nothing is waiting to be sent from this device.');
        // The BAR is always on; the LIST is not. The doc requires the action, not a "Nothing waiting" panel
        // above every screen of a first online visit.
        expect(wrapper.find('.outbox').exists()).toBe(false);
        wrapper.unmount();
    });

    it('does not use an assertive live region for the failed count', () => {
        // Tolerable while this lived inside one fill session; on a surface that now mounts on EVERY screen an
        // assertive region would interrupt the respondent on every page load and phase transition for as long
        // as one failed row exists. The scoped polite region carries progress instead.
        const wrapper = mount(SyncStatus, {
            global: {
                provide: {
                    [SyncOutboxKey as symbol]: fakeSync({ needsAttention: 2 }),
                    [ConflictReviewKey as symbol]: vi.fn(),
                },
            },
        });

        expect(wrapper.findAll('[role="alert"]')).toHaveLength(0);
        expect(wrapper.find('[role="status"][aria-live="polite"]').exists()).toBe(true);
        wrapper.unmount();
    });

    it('announces sync progress politely through one scoped region', () => {
        const sync = fakeSync();
        sync.lastAnnouncement.value = 'Response sent — reference MER-ABC123';

        const wrapper = mount(SyncStatus, {
            global: { provide: { [SyncOutboxKey as symbol]: sync, [ConflictReviewKey as symbol]: vi.fn() } },
        });

        // ONE region, not one per row: N live regions compete and a screen reader serialises them badly.
        const regions = wrapper.findAll('[aria-live]');
        expect(regions).toHaveLength(1);
        expect(regions[0].text()).toContain('MER-ABC123');
        wrapper.unmount();
    });

    it('FORWARDS the clicked row’s uuid to the review handler', async () => {
        // The bug: `@review="reviewConflicts?.()"` dropped the payload, so the resolver fell back to the
        // OLDEST conflict while the list renders newest-first. The child's emit was tested; the parent's
        // BINDING was not, and the mutation that re-drops the uuid survived until this case existed.
        const review = vi.fn();
        const sync = fakeSync({ conflict: 2, conflictHere: 2 });
        sync.rows.value = [
            outboxRow({ client_submission_uuid: 'newer', status: 'conflict' }),
            outboxRow({ client_submission_uuid: 'older', status: 'conflict' }),
        ];

        const wrapper = mount(SyncStatus, {
            global: { provide: { [SyncOutboxKey as symbol]: sync, [ConflictReviewKey as symbol]: review } },
        });

        await wrapper
            .findAll('.outbox__item')[1]
            .findAll('button')
            .find((b) => b.text() === 'Review')
            ?.trigger('click');

        expect(review).toHaveBeenCalledWith('older');
        wrapper.unmount();
    });

    it('renders the per-submission list, not just aggregate counts', () => {
        const sync = fakeSync({ pending: 1 });
        sync.rows.value = [outboxRow({ client_submission_uuid: 'u1', status: 'pending' })];

        const wrapper = mount(SyncStatus, {
            global: { provide: { [SyncOutboxKey as symbol]: sync, [ConflictReviewKey as symbol]: vi.fn() } },
        });

        // "2 responses waiting to sync" never told anyone whether THEIRS was one of them.
        expect(wrapper.text()).toContain('My submissions on this device');
        expect(wrapper.text()).toContain('Saved on this device');
        wrapper.unmount();
    });
});

/*
|--------------------------------------------------------------------------
| Increment M15 — what the SECOND respondent at a shared device is told.
|--------------------------------------------------------------------------
*/

describe('SyncStatus — respondent scope (Increment M15)', () => {
    it('tells the next respondent a NUMBER and nothing that identifies anybody', () => {
        // The whole containment, in one assertion set. Not the queue tag, not the server reference, not
        // which form, not when — every one of which this surface renders for the current visit's own rows
        // and every one of which identifies a stranger on shared hardware.
        const wrapper = mount(SyncStatus, {
            global: {
                provide: {
                    [SyncOutboxKey as symbol]: fakeSync({ earlierUnsent: 2 }),
                    [ConflictReviewKey as symbol]: vi.fn(),
                },
            },
        });

        const text = wrapper.text();

        expect(text).toContain('2 responses from earlier sessions on this device are still waiting to send.');
        expect(text).not.toContain('MER-');
        expect(text).not.toContain('clinic-intake');
        expect(wrapper.find('.outbox__ref').exists()).toBe(false);
        // And no action against them: no per-row Discard, no Review, no rows at all.
        expect(wrapper.find('.outbox').exists()).toBe(false);
        expect(wrapper.find('[data-testid="review-conflicts"]').exists()).toBe(false);

        wrapper.unmount();
    });

    it('says it in the singular for one, because "1 responses" is how a surface stops being trusted', () => {
        const wrapper = mount(SyncStatus, {
            global: {
                provide: {
                    [SyncOutboxKey as symbol]: fakeSync({ earlierUnsent: 1 }),
                    [ConflictReviewKey as symbol]: vi.fn(),
                },
            },
        });

        expect(wrapper.text()).toContain('One response from an earlier session on this device is still waiting to send.');

        wrapper.unmount();
    });

    it('never claims "nothing is waiting" directly above a line saying something is', () => {
        // Found by looking at the running page, not by a suite: both sentences were individually defensible
        // and together they contradicted each other, because the first is about THIS respondent and the
        // second about the device. Nothing in happy-dom or in the e2e asserts this string.
        const wrapper = mount(SyncStatus, {
            global: {
                provide: {
                    [SyncOutboxKey as symbol]: fakeSync({ earlierUnsent: 1 }),
                    [ConflictReviewKey as symbol]: vi.fn(),
                },
            },
        });

        const text = wrapper.text();

        expect(text).toContain('Nothing of yours is waiting to be sent.');
        expect(text).not.toContain('Nothing is waiting to be sent from this device.');

        wrapper.unmount();
    });

    it('stays SILENT about earlier sessions when there are none — the ordinary case', () => {
        const wrapper = mount(SyncStatus, {
            global: {
                provide: {
                    [SyncOutboxKey as symbol]: fakeSync(),
                    [ConflictReviewKey as symbol]: vi.fn(),
                },
            },
        });

        expect(wrapper.find('.sync-status__earlier').exists()).toBe(false);
        // The empty-state sentence I10d wrote is untouched: a first-time visitor still reads it.
        expect(wrapper.text()).toContain('Nothing is waiting to be sent from this device.');

        wrapper.unmount();
    });

    it('keeps "Sync now" and "Retry all" reachable, so a stranded queue still drains', () => {
        // Silence would be its own defect: "Sync now" is always visible and would have nothing to explain,
        // and an operator checking a device at the end of a field day needs to know something is queued.
        // Retrying SENDS — it discloses nothing and destroys nothing — so it stays device-wide.
        const sync = fakeSync({ needsAttention: 0, earlierUnsent: 1 });
        sync.needsAttention.value = 1;

        const wrapper = mount(SyncStatus, {
            global: {
                provide: {
                    [SyncOutboxKey as symbol]: sync,
                    [ConflictReviewKey as symbol]: vi.fn(),
                },
            },
        });

        const labels = wrapper.findAll('button').map((b) => b.text());

        expect(labels).toContain('Sync now');
        expect(labels).toContain('Retry all');
        // But no badge, because the badges count THIS visit and this visit has nothing failed.
        expect(wrapper.text()).not.toContain('1 failed');

        wrapper.unmount();
    });

    it('counts the badges and the summary from THIS visit, not from the device', () => {
        const sync = fakeSync({ pending: 1, needsAttention: 1, earlierUnsent: 5 });
        // The device-wide refs say something larger; only `mine` may reach the respondent.
        sync.pending.value = 6;
        sync.needsAttention.value = 3;

        const wrapper = mount(SyncStatus, {
            global: {
                provide: {
                    [SyncOutboxKey as symbol]: sync,
                    [ConflictReviewKey as symbol]: vi.fn(),
                },
            },
        });

        const text = wrapper.text();

        expect(text).toContain('1 queued');
        expect(text).toContain('1 failed');
        expect(text).toContain('2 responses on this device have not been sent yet.');
        expect(text).not.toContain('6 queued');

        wrapper.unmount();
    });

    it('does not report a stranger conflict as "needs review on another form"', () => {
        // The subtraction behind that sentence was device-wide minus this-form. With rows belonging to
        // visits, that difference counts a STRANGER's conflict and sends this respondent looking for a row
        // that is not theirs and that they will never find. It is now this visit minus this form.
        const sync = fakeSync({ conflictHere: 0 });
        sync.conflict.value = 1; // someone else's, device-wide

        const wrapper = mount(SyncStatus, {
            global: {
                provide: {
                    [SyncOutboxKey as symbol]: sync,
                    [ConflictReviewKey as symbol]: vi.fn(),
                },
            },
        });

        expect(wrapper.text()).not.toContain('needs review on another form');

        wrapper.unmount();
    });

    it('still keeps exactly ONE live region, which the e2e asserts by whole text', () => {
        const wrapper = mount(SyncStatus, {
            global: {
                provide: {
                    [SyncOutboxKey as symbol]: fakeSync({ earlierUnsent: 3 }),
                    [ConflictReviewKey as symbol]: vi.fn(),
                },
            },
        });

        expect(wrapper.findAll('[aria-live]')).toHaveLength(1);

        wrapper.unmount();
    });
});
