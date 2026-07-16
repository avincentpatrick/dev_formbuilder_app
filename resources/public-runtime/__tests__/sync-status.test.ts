import { ref } from 'vue';
import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import SyncStatus from '../components/SyncStatus.vue';
import { ConflictReviewKey, SyncOutboxKey } from '../composables/context';
import type { SyncOutbox } from '../composables/useSyncOutbox';

function fakeSync(over: Partial<Record<'pending' | 'needsAttention' | 'conflict', number>> = {}): SyncOutbox {
    return {
        pending: ref(over.pending ?? 0),
        needsAttention: ref(over.needsAttention ?? 0),
        conflict: ref(over.conflict ?? 0),
        syncing: ref(false),
        quotaWarning: ref<string | null>(null),
        refresh: vi.fn(async () => {}),
        syncNow: vi.fn(async () => {}),
        retryNeedsAttention: vi.fn(async () => {}),
        nextConflict: vi.fn(async () => null),
        discardConflict: vi.fn(async () => {}),
        registerBackgroundSync: vi.fn(),
        dispose: vi.fn(),
    };
}

describe('SyncStatus (Increment G8c conflict CTA)', () => {
    it('shows a Review button on conflicts and invokes the injected review action', async () => {
        const review = vi.fn();
        const wrapper = mount(SyncStatus, {
            global: { provide: { [SyncOutboxKey as symbol]: fakeSync({ conflict: 2 }), [ConflictReviewKey as symbol]: review } },
        });

        const button = wrapper.get('.sync-status__row--conflict button');
        expect(button.text()).toBe('Review');
        expect(wrapper.text()).toContain('2 responses need review');

        await button.trigger('click');
        expect(review).toHaveBeenCalledOnce();
    });

    it('renders nothing when the queue is empty', () => {
        const wrapper = mount(SyncStatus, {
            global: { provide: { [SyncOutboxKey as symbol]: fakeSync(), [ConflictReviewKey as symbol]: vi.fn() } },
        });
        expect(wrapper.find('.sync-status').exists()).toBe(false);
    });
});
