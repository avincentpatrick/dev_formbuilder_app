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

    it('renders NOTHING when there is nothing to list', () => {
        // ⚠️ THIS CASE ASSERTED AN EMPTY STATE UNTIL THE REVIEW, and dropping it is the fix rather than a
        // regression. `SyncStatus` only mounts this component when there ARE rows, so an empty `items` can
        // only mean "your one submission is the one you are currently reviewing" — where "Nothing waiting"
        // is false, and lands as an <h3> above the page's <h1> on a surface that renders on every screen.
        // The summary line in SyncStatus states the genuinely-empty case in one sentence instead.
        const wrapper = render([]);

        expect(wrapper.findAll('.outbox__item')).toHaveLength(0);
        expect(wrapper.text()).not.toContain('Nothing waiting');
        wrapper.unmount();
    });
});

describe('SubmissionOutbox — review targeting and focus (I10d review fixes)', () => {
    it('emits the uuid of the row whose Review was clicked, not the first one', async () => {
        // The bug this pins: SyncStatus used to bind `@review="reviewConflicts?.()"` and drop the payload,
        // so the resolver opened `nextConflict()` — the OLDEST conflict — while the list renders NEWEST
        // first. With two conflicts the respondent reviewed and resubmitted a submission they had not
        // selected, and the one they did select stayed parked.
        const wrapper = render([
            outboxRow({ client_submission_uuid: 'newer', status: 'conflict' }),
            outboxRow({ client_submission_uuid: 'older', status: 'conflict' }),
        ]);

        await wrapper
            .findAll('.outbox__item')[1]
            .findAll('button')
            .find((b) => b.text() === 'Review')
            ?.trigger('click');

        expect(wrapper.emitted('review')).toEqual([['older']]);
        wrapper.unmount();
    });

    it('moves focus to the SAFE control when the confirm opens', async () => {
        // The first implementation used a template ref on an MdsButton inside a v-for, which Vue assigns as
        // an ARRAY of component instances — no `focus()` anywhere. The assertion was on the emitted event,
        // so nothing caught it. Assert `document.activeElement`, which is the actual claim.
        const wrapper = mount(SubmissionOutbox, {
            attachTo: document.body,
            props: {
                rows: [outboxRow({ client_submission_uuid: 'a', status: 'needs_attention' })],
                syncingUuids: new Set<string>(),
                slug: 'clinic-intake',
                reviewingUuid: null,
            },
        });

        await wrapper.findAll('button').find((b) => b.text() === 'Discard')?.trigger('click');
        await new Promise((resolve) => setTimeout(resolve, 0));

        // ⚠️ IDENTIFY THE ELEMENT, DO NOT SEARCH ITS TEXT. The first version asserted
        // `document.activeElement?.textContent).toContain('Keep it')` and SURVIVED the mutation that deletes
        // the focus call outright — because the focused Discard button unmounts when the confirm swaps in,
        // so activeElement falls back to <body>, whose textContent contains the whole component including
        // the words "Keep it". A containment check against the document is not an assertion about focus.
        const active = document.activeElement as HTMLElement | null;
        expect(active?.tagName).toBe('BUTTON');
        expect(active?.textContent?.trim()).toBe('Keep it');
        expect(active?.hasAttribute('data-outbox-keep')).toBe(true);
        wrapper.unmount();
    });

    it('cancels the confirm on Escape', async () => {
        const wrapper = render([outboxRow({ client_submission_uuid: 'a', status: 'needs_attention' })]);

        await wrapper.findAll('button').find((b) => b.text() === 'Discard')?.trigger('click');
        expect(wrapper.text()).toContain('Discard this response?');

        await wrapper.get('.outbox').trigger('keydown', { key: 'Escape' });

        expect(wrapper.text()).not.toContain('Discard this response?');
        expect(wrapper.emitted('discard')).toBeUndefined();
        wrapper.unmount();
    });

    it('offers Retry on a row that is in the backoff, which is where §7.3 specified it', async () => {
        // §7.3's "Retry now" is defined as BYPASSING the exponential backoff, and the backoff state is
        // `pending` with attempts — so offering it only on needs_attention put the action everywhere except
        // the place the spec named.
        const wrapper = render([outboxRow({ client_submission_uuid: 'a', status: 'pending', attempts: 3 })]);

        expect(wrapper.text()).toContain('Retrying');
        expect(wrapper.findAll('button').some((b) => b.text() === 'Retry now')).toBe(true);
        wrapper.unmount();
    });

    it('renders nothing at all when the only row is the one under review', async () => {
        // `SyncStatus` gates on `rows.length > 0`, but `items` additionally filters the reviewed row — so an
        // empty-state here would say "Nothing waiting" while the respondent is actively resolving their only
        // submission, in an <h3> sitting above the page's <h1>.
        const wrapper = render([outboxRow({ client_submission_uuid: 'a', status: 'conflict' })], { reviewing: 'a' });

        expect(wrapper.text()).not.toContain('Nothing waiting');
        expect(wrapper.findAll('.outbox__item')).toHaveLength(0);
        wrapper.unmount();
    });
});
