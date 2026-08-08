import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

/**
 * Increment I9a — the submission detail page's action gate.
 *
 * ONE THING IS UNDER TEST HERE AND IT IS A CLASS OF BUG, NOT A STATUS. `SubmissionReviewService` refuses any
 * transition whose source status it does not NAME (a positive `$from` list); this page used to offer Archive
 * for anything it did not EXCLUDE (`s !== 'archived' && s !== 'draft'`). The two agreed only by coincidence,
 * and every new `SubmissionStatus` broke the coincidence in the same direction: a button the server refuses.
 *
 * `screened_out` is the case that made it matter rather than merely untidy — `archived` consumes a
 * `max_responses` slot and `screened_out` deliberately does not, so the transition the old gate offered would
 * have retroactively overfilled a paid cap. The sweep below asserts the client's offered set EQUALS the
 * server's accepted set, so a future status has to be added to both on purpose.
 */

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', template: '<a><slot /></a>' },
    router: { visit: vi.fn(), patch: vi.fn() },
    useForm: () => ({ reason: '', remarks: '', processing: false, errors: {}, patch: vi.fn(), reset: vi.fn() }),
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', template: '<header><slot name="actions" /></header>' },
}));

// Imported AFTER the mocks so the component resolves them.
const Show = (await import('./Show.vue')).default;

/** Every value `SubmissionStatus` can hold, kept in the enum's own order. */
const ALL_STATUSES = [
    'draft',
    'submitted',
    'screened_out',
    'under_review',
    'approved',
    'returned',
    'archived',
] as const;

/**
 * `SubmissionReviewService::archive()`'s `$from` list, HAND-TRANSCRIBED — and the limitation is stated rather
 * than glossed, because it bounds what this file can prove. There is no generated TypeScript mirror of
 * `SubmissionStatus` in this repo, so a client test cannot read the server's list; both constants here are
 * literals a person maintains. Someone changing the Vue gate could edit them in the same commit and silence
 * the failure.
 *
 * What makes the pair still worth having is that the PHP half does NOT have that weakness:
 * `ScreenedOutTest`'s "archives exactly the four statuses that consumed a slot" sweep drives the real service
 * over `SubmissionStatus::cases()`, so the server's accepted set is pinned against the enum itself. This file
 * pins the client against a transcription of that set. Two locks, one of them soft — not one lock across both.
 */
const SERVER_ARCHIVABLE = ['submitted', 'under_review', 'approved', 'returned'];

function mountAt(status: string, canReview = true) {
    return mount(Show, {
        props: {
            submission: {
                id: 's1',
                form_id: 'f1',
                form_title: 'Survey',
                version_number: 1,
                status,
                status_label: status,
                source: 'guest',
                source_label: 'Guest',
                respondent: 'Anonymous',
                locale: null,
                submitted_at: null,
                finalized_at: null,
                review: { validator: null, validated_at: null, returned_reason: null, remarks: null },
            },
            blocks: [],
            can: { review: canReview },
            pdf: null,
        },
        global: {
            stubs: {
                MdsBadge: true,
                MdsCard: { template: '<div><slot name="header" /><slot /></div>' },
                MdsFormField: { template: '<div><slot /></div>' },
                MdsModal: { template: '<div><slot /></div>' },
                MdsTextarea: true,
            },
        },
    });
}

/** The action-bar button labels currently offered, in DOM order. */
function offeredActions(status: string): string[] {
    return mountAt(status)
        .findAll('header button')
        .map((b) => b.text().trim());
}

describe('Show.vue — the archive gate (I9a)', () => {
    it('offers Archive for exactly the statuses the server will accept', () => {
        const offered = ALL_STATUSES.filter((s) => offeredActions(s).includes('Archive'));

        expect([...offered]).toEqual(SERVER_ARCHIVABLE);
    });

    it('never offers Archive on a screened-out submission', () => {
        // Stated on its own as well as inside the sweep, because this is the case with a consequence: the
        // transition would convert a non-capacity-consuming row into a consuming one.
        expect(offeredActions('screened_out')).not.toContain('Archive');
    });

    it('still offers the ordinary review actions, so the gate is not simply off', () => {
        // The anti-vacuity half. A component that rendered no buttons at all would satisfy both cases above.
        expect(offeredActions('submitted')).toEqual(['Mark under review', 'Return', 'Approve', 'Archive']);
        expect(offeredActions('under_review')).toEqual(['Return', 'Approve', 'Archive']);
    });

    it('offers nothing at all to a viewer who cannot review', () => {
        expect(mountAt('submitted', false).findAll('header button')).toHaveLength(0);
    });
});
