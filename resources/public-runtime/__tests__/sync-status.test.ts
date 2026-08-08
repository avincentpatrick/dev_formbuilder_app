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
