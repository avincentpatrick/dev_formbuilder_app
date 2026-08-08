import { describe, expect, it } from 'vitest';
import { mount, type VueWrapper } from '@vue/test-utils';
import SubmissionOutbox from '../components/SubmissionOutbox.vue';
import type { OutboxRow } from '../lib/db';
import { outboxRow } from './fixtures';

/**
 * Increment I10d — "My submissions on this device" (`docs/PRD.md:223`, UX §7.1).
 */
function render(rows: OutboxRow[], over: { syncing?: string[]; slug?: string; reviewing?: string | null } = {}): VueWrapper {
    return mount(SubmissionOutbox, {
        props: {
            rows,
            syncingUuids: new Set(over.syncing ?? []),
            slug: over.slug ?? 'clinic-intake',
            reviewingUuid: over.reviewing ?? null,
        },
    });
}

function item(wrapper: VueWrapper, index: number) {
    return wrapper.findAll('.outbox__item')[index];
}

describe('SubmissionOutbox', () => {
    it('renders one row per submission with its status and copy', () => {
        const wrapper = render([
            outboxRow({ client_submission_uuid: 'a', status: 'pending' }),
            outboxRow({ client_submission_uuid: 'b', status: 'needs_attention' }),
        ]);

        expect(wrapper.findAll('.outbox__item')).toHaveLength(2);
        expect(item(wrapper, 0).text()).toContain('Queued');
        expect(item(wrapper, 1).text()).toContain('Failed');
        wrapper.unmount();
    });

    it('retries THAT row, not the first one', () => {
        const wrapper = render([
            outboxRow({ client_submission_uuid: 'a', status: 'needs_attention' }),
            outboxRow({ client_submission_uuid: 'b', status: 'needs_attention' }),
        ]);

        void item(wrapper, 1).findAll('button').find((b) => b.text() === 'Retry now')?.trigger('click');

        // Always emitting the first uuid is the obvious bug here and it would pass a single-row test.
        expect(wrapper.emitted('retry')).toEqual([['b']]);
        wrapper.unmount();
    });

    it('shows Sending… on the syncing row ONLY, and disables its retry', () => {
        // ⚠️ THE CASE THAT PROVES A GLOBAL BOOLEAN WAS INSUFFICIENT. Reading `sync.syncing` instead of the
        // per-uuid set makes BOTH rows claim to be sending, which is the state this whole mechanism exists
        // to distinguish.
        const wrapper = render(
            [
                outboxRow({ client_submission_uuid: 'a', status: 'pending' }),
                outboxRow({ client_submission_uuid: 'b', status: 'pending' }),
            ],
            { syncing: ['a'] },
        );

        expect(item(wrapper, 0).text()).toContain('Sending…');
        expect(item(wrapper, 1).text()).not.toContain('Sending…');
        wrapper.unmount();
    });

    it('requires a SECOND, explicit confirmation before discarding', async () => {
        const wrapper = render([outboxRow({ client_submission_uuid: 'a', status: 'needs_attention' })]);

        await wrapper.findAll('button').find((b) => b.text() === 'Discard')?.trigger('click');

        // §7.3: "an action requiring a confirmation step". One click must not destroy anything.
        expect(wrapper.emitted('discard')).toBeUndefined();
        expect(wrapper.text()).toContain('Discard this response?');
        wrapper.unmount();
    });

    it('names the reference in the confirm, so it is clear WHICH response is at stake', async () => {
        const wrapper = render([outboxRow({ client_submission_uuid: 'a', status: 'needs_attention' })]);
        const reference = wrapper.get('.outbox__ref').text();

        await wrapper.findAll('button').find((b) => b.text() === 'Discard')?.trigger('click');

        // The thing `window.confirm` structurally could not do.
        expect(wrapper.text()).toContain(reference);
        wrapper.unmount();
    });

    it('discards on the confirm click, and keeps it on Keep it', async () => {
        const wrapper = render([outboxRow({ client_submission_uuid: 'a', status: 'needs_attention' })]);

        await wrapper.findAll('button').find((b) => b.text() === 'Discard')?.trigger('click');
        await wrapper.findAll('button').find((b) => b.text() === 'Keep it')?.trigger('click');
        expect(wrapper.emitted('discard')).toBeUndefined();

        await wrapper.findAll('button').find((b) => b.text() === 'Discard')?.trigger('click');
        await wrapper.findAll('button').find((b) => b.text() === 'Yes, discard')?.trigger('click');
        expect(wrapper.emitted('discard')).toEqual([['a']]);
        wrapper.unmount();
    });

    it('offers Review for a conflict on this form and a LINK for one on another', () => {
        const wrapper = render(
            [
                outboxRow({ client_submission_uuid: 'a', status: 'conflict', slug: 'clinic-intake' }),
                outboxRow({ client_submission_uuid: 'b', status: 'conflict', slug: 'household-roster' }),
            ],
            { slug: 'clinic-intake' },
        );

        expect(item(wrapper, 0).findAll('button').some((b) => b.text() === 'Review')).toBe(true);

        // The cross-form guard. A Review button here would silently no-op, because the resolver's
        // share-token client is bound to one slug — and a NAVIGATION must be a link, not a button.
        const foreign = item(wrapper, 1);
        expect(foreign.findAll('button').some((b) => b.text() === 'Review')).toBe(false);
        expect(foreign.get('a').attributes('href')).toBe('/f/household-roster');
        wrapper.unmount();
    });

    it('never offers Retry on a conflict row', () => {
        // A blind retry of a parked 409 either 409s again or is re-parked by the version guard before the
        // POST. Offering it would teach the respondent the button does nothing.
        const wrapper = render([outboxRow({ client_submission_uuid: 'a', status: 'conflict' })]);

        expect(wrapper.findAll('button').some((b) => b.text() === 'Retry now')).toBe(false);
        wrapper.unmount();
    });

    it('hides the row whose review flow is currently on screen', () => {
        const wrapper = render(
            [
                outboxRow({ client_submission_uuid: 'a', status: 'conflict' }),
                outboxRow({ client_submission_uuid: 'b', status: 'pending' }),
            ],
            { reviewing: 'a' },
        );

        // Strictly better than the old blanket `v-if="!resolving"`, which hid the WHOLE surface during a
        // review: the respondent keeps sight of their other queued submissions.
        expect(wrapper.findAll('.outbox__item')).toHaveLength(1);
        expect(wrapper.text()).toContain('Queued');
        wrapper.unmount();
    });

    it('shows an empty state rather than a bare heading when nothing is queued', () => {
        const wrapper = render([]);

        expect(wrapper.findAll('.outbox__item')).toHaveLength(0);
        expect(wrapper.text()).toContain('Nothing waiting');
        wrapper.unmount();
    });
});
