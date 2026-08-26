/**
 * The delivery-rule modal's event narrowing (Increment M23).
 *
 * ⛔ WHAT THE DEFECT ACTUALLY WAS, because the backlog row calls it "silently sends the unfiltered set" and
 * that is the milder of the two outcomes. For a tabular grant the modal renders only `submission.*`
 * checkboxes, so a rule carrying a legacy `form.*` event — one written before the server-side guard existed
 * — had no box to untick. Saving sent it anyway, the server rejected it under the DOTTED key
 * `event_types.{index}`, and this modal renders only the BARE `event_types` key. The tenant got a 422 with
 * no visible cause, on a modal that stays open, and could no longer rename the rule, rescope it, or repair
 * its destination. A dead end, not merely a dirty payload.
 *
 * ⚠️ THE FIX IS TWO FILTERS AND THEY NEED SEPARATE CASES, WHICH IS THE WHOLE DESIGN OF THIS FILE. The seed
 * filter alone makes every "what is sent" assertion pass, so a test suite that only submits would leave the
 * transform filter with zero coverage and report a green mutation run for a line it never exercised. The
 * isolating case writes the model DIRECTLY, past the seed, and is marked below.
 */

import { mount, type VueWrapper } from '@vue/test-utils';
import { reactive } from 'vue';
import { describe, expect, it, vi, beforeEach } from 'vitest';

import type { Option, RuleRow } from './types';

// `reactive` is load-bearing rather than tidiness — Inertia's real useForm returns a reactive object, and a
// plain one freezes every computed in the component at its first value. `transform` + `patch`/`post` are
// captured so a case can assert the WIRE payload rather than merely that a request happened; ShareModal's
// spec established this shape for the same reason.
const sent = vi.hoisted(() => ({ payload: null as Record<string, unknown> | null, url: '' }));
/** A handle on the live form object, so a case can assert the MODEL and not only what was sent. */
const seen = vi.hoisted(() => ({ form: null as Record<string, unknown> | null }));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) => {
        const form: Record<string, unknown> = reactive({
            ...initial,
            errors: {} as Record<string, string>,
            processing: false,
            clearErrors: () => {},
            transform(this: Record<string, unknown>, fn: (data: Record<string, unknown>) => Record<string, unknown>) {
                this.__transform = fn;

                return this;
            },
            patch(this: Record<string, unknown>, url: string) {
                const fn = this.__transform as ((d: Record<string, unknown>) => Record<string, unknown>) | undefined;
                sent.payload = fn ? fn(this) : { ...this };
                sent.url = url;
            },
            post(this: Record<string, unknown>, url: string) {
                const fn = this.__transform as ((d: Record<string, unknown>) => Record<string, unknown>) | undefined;
                sent.payload = fn ? fn(this) : { ...this };
                sent.url = url;
            },
        });
        seen.form = form;

        return form;
    },
}));

// The channel picker fires on open for a CHANNEL grant; the tabular children each run their own provider
// round trips on mount and this spec has nothing to say about them.
vi.mock('./integrationsClient', () => ({
    fetchChannels: vi.fn().mockResolvedValue({ channels: [], truncated: false, error: null }),
}));
vi.mock('./SheetsRuleFields.vue', () => ({
    default: { name: 'SheetsRuleFields', props: ['connectionId', 'formId', 'formTitle', 'rule', 'errors'], template: '<div />' },
}));
vi.mock('./AirtableRuleFields.vue', () => ({
    default: { name: 'AirtableRuleFields', props: ['connectionId', 'formId', 'formTitle', 'rule', 'errors'], template: '<div />' },
}));

const RuleFormModal = (await import('./RuleFormModal.vue')).default;

const EVENT_TYPES: Option[] = [
    { value: 'submission.created', label: 'Submission created' },
    { value: 'form.published', label: 'Form published' },
];

/** A rule written before the server-side event guard existed: it carries one deliverable event and one not. */
function legacyRule(eventTypes: string[] = ['submission.created', 'form.published']): RuleRow {
    return {
        id: 'rule-1',
        connection_id: 'conn-1',
        name: 'Legacy',
        event_types: eventTypes,
        form_id: null,
        form_title: null,
        form_url: null,
        channel_id: null,
        channel_name: null,
        spreadsheet_id: null,
        sheet_name: null,
        spreadsheet_url: null,
        mapping: null,
        destination_label: null,
        paused_reason: null,
        status: 'active',
        consecutive_failure_count: 0,
        last_success_at: null,
        last_failure_at: null,
        created_at: '2026-01-01T00:00:00Z',
    };
}

function render(over: Record<string, unknown> = {}): VueWrapper {
    return mount(RuleFormModal, {
        props: {
            open: true,
            connectionId: 'conn-1',
            forms: [],
            eventTypes: EVENT_TYPES,
            rule: legacyRule(),
            provider: 'google_sheets',
            destinationKind: 'tabular',
            ...over,
        },
        // MdsModal teleports to <body>; without this the wrapper sees nothing at all.
        global: { stubs: { teleport: true } },
    });
}

beforeEach(() => {
    sent.payload = null;
    sent.url = '';
    seen.form = null;
});

describe('RuleFormModal — what is rendered is what is sent', () => {
    it('seeds the model through the same narrowing the checkboxes use', async () => {
        // ⭐ ISOLATES THE SEED FILTER. Mutation: put `form.event_types = [...seeded]` back and this reddens
        // — and it is the ONLY case that does, because every submit-based assertion is satisfied by the
        // seed filter alone.
        const wrapper = render();

        expect(seen.form!.event_types).toEqual(['submission.created']);

        const boxes = wrapper.findAll('input[type="checkbox"]');
        expect(boxes).toHaveLength(1);
        expect((boxes[0].element as HTMLInputElement).checked).toBe(true);

        wrapper.unmount();
    });

    it('sends only the events this destination can deliver', async () => {
        const wrapper = render();

        await wrapper.get('form').trigger('submit');

        expect(sent.payload!.event_types).toEqual(['submission.created']);
        expect(sent.url).toBe('/integrations/rules/rule-1');

        wrapper.unmount();
    });

    it('narrows on the way out too, for a model the seed never saw', async () => {
        // ⭐ ISOLATES THE TRANSFORM FILTER, and nothing else in this file can. With the seed filter present,
        // reverting `submit()` to send `data.event_types` whole leaves every other case green — the array
        // was already clean before it got there. So the model is written DIRECTLY here, past the seed,
        // reproducing the one case the seed cannot cover: a parent that flips `destinationKind` under an
        // already-open modal, which the `open`-keyed watch would not re-fire for.
        const wrapper = render();

        seen.form!.event_types = ['submission.created', 'form.published'];
        await wrapper.get('form').trigger('submit');

        expect(sent.payload!.event_types).toEqual(['submission.created']);

        wrapper.unmount();
    });

    it('says which event it is dropping, so the save is a stated change and not a silent one', async () => {
        // Filtering at seed time CHANGES STORED DATA on the next save for ANY reason — a tenant opening this
        // modal to rename a rule would otherwise lose an event without being told.
        const wrapper = render();

        expect(wrapper.text()).toContain('Form published');
        expect(wrapper.text()).toContain('can’t');

        wrapper.unmount();
    });

    it('handles the rule whose only event this destination cannot deliver', async () => {
        // ⚠️ THE FIXTURE THE OTHER CASES CANNOT REACH: every one above leaves a survivor, so none of them
        // exercises the empty result. Here the tenant would otherwise see an untouched-looking form and be
        // told "choose at least one event" — a legible sentence with the wrong cause. The hint is what
        // supplies the cause.
        const wrapper = render({ rule: legacyRule(['form.published']) });

        expect(seen.form!.event_types).toEqual([]);
        // The catalog still renders — `availableEvents` narrows the PROP list, not the rule's own — so what
        // the tenant sees is a fieldset of unticked boxes. That is precisely why the hint has to exist:
        // without it the form looks untouched and the server's "choose at least one event" reads as a
        // mistake the tenant made rather than a value the modal removed.
        const boxes = wrapper.findAll('input[type="checkbox"]');
        expect(boxes).toHaveLength(1);
        expect(boxes.filter((box) => (box.element as HTMLInputElement).checked)).toHaveLength(0);
        expect(wrapper.text()).toContain('Form published');

        await wrapper.get('form').trigger('submit');
        expect(sent.payload!.event_types).toEqual([]);

        wrapper.unmount();
    });

    it('leaves a channel grant completely alone, which is the over-filtering control', async () => {
        // The narrowing is tabular-only. A Slack rule keeps every event, ticked and sent, and gets no hint —
        // without this, a fix that filtered unconditionally would look correct.
        const wrapper = render({ destinationKind: 'channel', provider: 'slack', rule: legacyRule() });

        expect(wrapper.findAll('input[type="checkbox"]')).toHaveLength(2);
        expect(wrapper.text()).not.toContain('can’t');

        await wrapper.get('form').trigger('submit');
        expect(sent.payload!.event_types).toEqual(['submission.created', 'form.published']);

        wrapper.unmount();
    });
});
