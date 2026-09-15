import { mount, flushPromises, type DOMWrapper, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi, beforeEach } from 'vitest';

/**
 * Increment M23 — the double-provision guard. First component spec in this directory.
 *
 * ⛔ WHY THIS ONE BUTTON EARNS A SPEC WHEN NO OTHER `:loading` BUTTON DOES. Its click reaches a raw `fetch`
 * with an IRREVERSIBLE EXTERNAL side effect: `createDestination()` provisions a real spreadsheet in the
 * tenant's Google Drive. A duplicate is a file Meridian will never write to and only the tenant can delete
 * — and because both responses assign `destination`, the rule binds to the SECOND sheet and the FIRST is
 * the orphan. Everything else in the tree is either an Inertia submit or a GET.
 *
 * ⚠️ THE PASSTHROUGH STUB IS LOAD-BEARING, AND THAT IS THE WHOLE REASON THERE ARE TWO SPECS FOR ONE CLICK.
 * This increment guards it in two places: here (a `busy` early-return) and inside `MdsButton`
 * (`stopImmediatePropagation`). Mounted against the REAL button, the design-system guard alone satisfies
 * the assertion below — so reverting the local guard would leave this file GREEN and the mutation signal
 * would be measuring the other fix entirely. The stub takes the design-system guard out of the picture so
 * this file tests the guard it is named for; `Button.test.ts` tests the other one against the same click.
 */

const mocks = vi.hoisted(() => ({
    createDestination: vi.fn(),
    fetchMappableColumns: vi.fn(),
    inspectDestination: vi.fn(),
}));

vi.mock('./integrationsClient', () => mocks);

const SheetsRuleFields = (await import('./SheetsRuleFields.vue')).default;

/** A bare button carrying the consumer's listeners — i.e. MdsButton with its own guard removed. */
const ButtonPassthrough = {
    name: 'Button',
    inheritAttrs: false,
    template: '<button v-bind="$attrs"><slot /></button>',
};

/**
 * ⛔ THE STUB KEY IS `Button`, NOT `MdsButton`, AND GETTING IT WRONG FAILS SILENTLY. Vue Test Utils matches
 * a stub against the component's own inferred name, which for a `<script setup>` SFC is its FILENAME —
 * `Button.vue` gives `Button`. `MdsButton` is only the barrel's export alias, so keying on it matches
 * nothing at all: the real button renders, its guard absorbs the extra clicks, and the spec passes with the
 * local guard reverted. MEASURED rather than deduced — the same three-click case reports **1** call under
 * the `MdsButton` key and **2** under `Button`. This assertion is what stops that returning unnoticed.
 */
function expectStubApplied(button: DOMWrapper<Element>): void {
    expect(
        button.classes(),
        'the passthrough stub must be in place, or this file is measuring MdsButton’s guard instead',
    ).not.toContain('mds-button');
}

function render(connectionId: string | null = 'conn-1'): VueWrapper {
    return mount(SheetsRuleFields, {
        props: { connectionId, formId: '', formTitle: 'Q3 Intake', rule: null, errors: {} },
        global: { stubs: { Button: ButtonPassthrough } },
    });
}

/** Located by label rather than position — this editor renders more than one button. */
function createButton(wrapper: VueWrapper): DOMWrapper<Element> {
    const button = wrapper.findAll('button').find((candidate) => candidate.text().includes('Create'));
    expect(button, 'the Create button should be rendered in create mode').toBeTruthy();
    expectStubApplied(button!);

    return button!;
}

beforeEach(() => {
    vi.clearAllMocks();
    // The immediate watcher calls this on mount; without a resolved payload the component never settles.
    mocks.fetchMappableColumns.mockResolvedValue({ columns: [], scoped: false, error: null });
});

describe('SheetsRuleFields — creating a spreadsheet is not repeatable by double-click', () => {
    it('provisions exactly one sheet however many times Create is pressed mid-request', async () => {
        // ⭐ THE CASE. Mutation: drop `|| busy.value` from create()'s early return and this reddens with 2.
        // The `:disabled` expression cannot cover it — that reads `destination !== null`, and `destination`
        // is not assigned until the response lands, so it is false for the entire flight.
        let land: (value: unknown) => void = () => {};
        mocks.createDestination.mockReturnValue(
            new Promise((resolve) => {
                land = resolve;
            }),
        );

        const wrapper = render();
        await flushPromises();

        const button = createButton(wrapper);
        await button.trigger('click');
        await button.trigger('click');
        await button.trigger('click');

        expect(mocks.createDestination).toHaveBeenCalledTimes(1);

        land({ destination: null, error: null });
        await flushPromises();
        wrapper.unmount();
    });

    it('releases the guard when the response lands, rather than wedging the editor busy forever', async () => {
        // The control for the case above: a guard that never clears is indistinguishable from a working one
        // in that test and catastrophic in production. Here the request completes on the ERROR arm — the one
        // easiest to leave `busy` set on — and Create has to work again afterwards.
        mocks.createDestination.mockResolvedValueOnce({ destination: null, error: 'Google said no.' });

        const wrapper = render();
        await flushPromises();

        await createButton(wrapper).trigger('click');
        await flushPromises();

        expect(mocks.createDestination).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('Google said no.');

        mocks.createDestination.mockResolvedValueOnce({ destination: null, error: null });
        await createButton(wrapper).trigger('click');
        await flushPromises();

        expect(mocks.createDestination).toHaveBeenCalledTimes(2);
        wrapper.unmount();
    });

    it('still does nothing at all without a connection, which is the guard that was already there', async () => {
        // Pinned so the added `busy` clause cannot be written in a way that drops the original condition.
        const wrapper = render(null);
        await flushPromises();

        await createButton(wrapper).trigger('click');
        await flushPromises();

        expect(mocks.createDestination).not.toHaveBeenCalled();
        wrapper.unmount();
    });
});

// ── M96: Submission ID, and opening a rule that was already saved ─────────────────────────────────────────

type Change = { spreadsheet_id: string; sheet_name: string; columns: { header: string; field_key: string | null }[] } | null;

/** A pasted sheet as the inspect sidecar returns it. `fingerprint` is the server's digest of `header_row`. */
function sheet(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        spreadsheet_id: 'SHEET_A',
        title: 'Q3 Intake',
        url: 'https://docs.google.com/spreadsheets/d/SHEET_A/edit',
        tabs: ['Responses'],
        sheet_name: 'Responses',
        header_row: ['Full name', 'Submission ID'],
        sheet_id: null,
        fingerprint: 'fp-a',
        header_types: null,
        ...overrides,
    };
}

/** A saved Sheets rule as ConnectionPresenter projects it. */
function savedRule(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id: 'rule-1',
        connection_id: 'conn-1',
        name: 'Submissions → Q3 Intake',
        event_types: ['submission.created'],
        form_id: null,
        form_title: null,
        form_url: null,
        channel_id: null,
        channel_name: null,
        spreadsheet_id: 'SHEET_A',
        sheet_name: 'Responses',
        sheet_id: null,
        spreadsheet_url: null,
        mapping: {
            fingerprint: 'fp-a',
            columns: [
                { header: 'Full name', field_key: 'full_name' },
                { header: 'Submission ID', field_key: '__submission_id' },
            ],
        },
        destination_label: 'Q3 Intake · Responses',
        paused_reason: null,
        status: 'active',
        consecutive_failure_count: 0,
        last_success_at: null,
        last_failure_at: null,
        created_at: '2026-09-01T00:00:00Z',
        ...overrides,
    };
}

function renderRule(rule: Record<string, unknown>): VueWrapper {
    return mount(SheetsRuleFields, {
        props: { connectionId: 'conn-1', formId: '', formTitle: 'Q3 Intake', rule: rule as never, errors: {} },
        global: { stubs: { Button: ButtonPassthrough } },
    });
}

function lastChange(wrapper: VueWrapper): Change {
    const events = wrapper.emitted('change') ?? [];

    return (events.at(-1)?.[0] ?? null) as Change;
}

function checkButton(wrapper: VueWrapper): DOMWrapper<Element> {
    const button = wrapper.findAll('button').find((candidate) => candidate.text().trim() === 'Check');
    expect(button, 'the Check button should be rendered in existing-sheet mode').toBeTruthy();

    return button!;
}

/** Switch a new rule to "Use one I already have", paste a reference and press Check. */
async function pasteAndCheck(wrapper: VueWrapper, reference: string): Promise<void> {
    await wrapper.find('input[type="radio"][value="existing"]').setValue(true);
    await wrapper.find('input[placeholder^="https://docs.google.com"]').setValue(reference);
    await checkButton(wrapper).trigger('click');
    await flushPromises();
}

describe('SheetsRuleFields — Submission ID and saved rules (M96)', () => {
    beforeEach(() => {
        // `clearAllMocks` keeps queued once-values and implementations, which would leak between these cases.
        mocks.inspectDestination.mockReset();
        mocks.createDestination.mockReset();
    });

    it('arrives with Submission ID bound on a pasted sheet whose heading reads it', async () => {
        mocks.inspectDestination.mockResolvedValue({ destination: sheet({ header_row: ['Full name', '  submission   ID '] }), error: null });

        const wrapper = render();
        await flushPromises();
        await pasteAndCheck(wrapper, 'SHEET_A');

        const change = lastChange(wrapper);
        expect(change, 'a map with Submission ID bound is saveable, so it must be published').not.toBeNull();
        expect(change!.columns).toEqual([
            { header: 'Full name', field_key: null },
            { header: '  submission   ID ', field_key: '__submission_id' },
        ]);
        expect(wrapper.text()).toContain('Lets us spot a row we already added, so a retried delivery does not add it twice.');
        wrapper.unmount();
    });

    it('explains how to add the column when a pasted sheet has none', async () => {
        mocks.inspectDestination.mockResolvedValue({ destination: sheet({ header_row: ['Full name', 'Notes'] }), error: null });

        const wrapper = render();
        await flushPromises();
        await pasteAndCheck(wrapper, 'SHEET_A');

        expect(wrapper.text()).toContain('Add a column headed “Submission ID” at the end of this sheet, then press Check again.');
        wrapper.unmount();
    });

    it('re-inspects the tab an existing rule writes to, not the first tab', async () => {
        mocks.inspectDestination.mockResolvedValue({
            destination: sheet({ tabs: ['Responses', 'Tab 2'], sheet_name: 'Tab 2' }),
            error: null,
        });

        const wrapper = renderRule(savedRule({ sheet_name: 'Tab 2' }));
        await flushPromises();

        expect(mocks.inspectDestination).toHaveBeenCalledTimes(1);
        expect(mocks.inspectDestination).toHaveBeenCalledWith('conn-1', 'SHEET_A', 'Tab 2');
        expect(lastChange(wrapper)?.sheet_name).toBe('Tab 2');
        wrapper.unmount();
    });

    it('restores an existing rule exactly as stored, leaving an unbound Submission ID unbound', async () => {
        // The heading row DOES read "Submission ID", so a restore that pre-binds would bind it here.
        mocks.inspectDestination.mockResolvedValue({ destination: sheet(), error: null });

        const wrapper = renderRule(savedRule({
            mapping: {
                fingerprint: 'fp-a',
                columns: [
                    { header: 'Full name', field_key: 'full_name' },
                    { header: 'Submission ID', field_key: null },
                ],
            },
        }));
        await flushPromises();

        expect(lastChange(wrapper)!.columns).toEqual([
            { header: 'Full name', field_key: 'full_name' },
            { header: 'Submission ID', field_key: null },
        ]);
        expect(wrapper.text()).not.toContain('changed since the rule was saved');
        wrapper.unmount();
    });

    it('builds a fresh map when Check is pressed on an existing rule, never carrying the old bindings over', async () => {
        mocks.inspectDestination
            .mockResolvedValueOnce({ destination: sheet(), error: null })
            .mockResolvedValueOnce({
                destination: sheet({ spreadsheet_id: 'SHEET_B', header_row: ['Reviewer', 'Submission ID'], fingerprint: 'fp-b' }),
                error: null,
            });

        const wrapper = renderRule(savedRule());
        await flushPromises();

        await wrapper.find('input[placeholder^="https://docs.google.com"]').setValue('SHEET_B');
        await checkButton(wrapper).trigger('click');
        await flushPromises();

        expect(lastChange(wrapper)!.columns).toEqual([
            { header: 'Reviewer', field_key: null },
            { header: 'Submission ID', field_key: '__submission_id' },
        ]);
        wrapper.unmount();
    });

    it('binds every catalog column of a sheet it creates, Submission ID included', async () => {
        // The shared beforeEach answers an EMPTY catalog, under which this path binds nothing at all.
        mocks.fetchMappableColumns.mockResolvedValue({
            columns: [
                { key: 'full_name', label: 'Full name', group: 'Form fields' },
                { key: '__submission_id', label: 'Submission ID', group: 'Submission details' },
            ],
            scoped: true,
            error: null,
        });
        mocks.createDestination.mockResolvedValue({ destination: sheet(), error: null });

        const wrapper = render();
        await flushPromises();
        await createButton(wrapper).trigger('click');
        await flushPromises();

        expect(mocks.createDestination).toHaveBeenCalledWith('conn-1', 'Q3 Intake — responses', ['Full name', 'Submission ID']);
        expect(lastChange(wrapper)!.columns).toEqual([
            { header: 'Full name', field_key: 'full_name' },
            { header: 'Submission ID', field_key: '__submission_id' },
        ]);
        wrapper.unmount();
    });
});
