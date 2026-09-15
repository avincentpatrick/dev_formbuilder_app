import { mount, flushPromises, type DOMWrapper, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';

/**
 * M96 — the Airtable rule editor: Submission ID, and opening a rule that was already saved.
 *
 * Until M96 this editor had no spec, and three things were wrong with it:
 *   • a new rule never bound Submission ID, so a retried delivery could add the same record twice;
 *   • opening a saved rule passed the table NAME and threw its stored mapping away, so the rule opened with
 *     nothing bound and could not be saved again, and a renamed table read as gone;
 *   • choosing a different base on a saved rule applied the old rule's bindings by position to the new table.
 *
 * ⚠️ EVERY DESTINATION FIXTURE CARRIES `sheet_id`. `publish()` emits null without one, so a fixture that left
 * it out would stay red after the fix for a reason that has nothing to do with the behaviour under test.
 */

const mocks = vi.hoisted(() => ({
    fetchChannels: vi.fn(),
    fetchMappableColumns: vi.fn(),
    inspectDestination: vi.fn(),
}));

vi.mock('./integrationsClient', () => mocks);

const AirtableRuleFields = (await import('./AirtableRuleFields.vue')).default;

type Change = {
    spreadsheet_id: string;
    spreadsheet_title: string;
    sheet_id: string;
    sheet_name: string;
    columns: { header: string; field_key: string | null }[];
} | null;

const BASE = 'appACME0000000001';
const OTHER_BASE = 'appOTHER000000002';

/** A table as the inspect sidecar returns it. `fingerprint` is the server's digest of `header_row`. */
function table(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        spreadsheet_id: BASE,
        title: 'Client Intake CRM',
        url: `https://airtable.com/${BASE}/tblRESPONSES00001`,
        tabs: ['Responses', 'Archive'],
        sheet_name: 'Responses',
        header_row: ['Full name', 'Submission ID'],
        sheet_id: 'tblRESPONSES00001',
        fingerprint: 'fp-responses',
        header_types: ['singleLineText', 'singleLineText'],
        ...overrides,
    };
}

/** A saved Airtable rule as ConnectionPresenter projects it. The caption name is stale on purpose. */
function savedRule(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        id: 'rule-1',
        connection_id: 'conn-1',
        name: 'Submissions → Client Intake CRM',
        event_types: ['submission.created'],
        form_id: null,
        form_title: null,
        form_url: null,
        channel_id: null,
        channel_name: null,
        spreadsheet_id: BASE,
        sheet_name: 'Responses (old name)',
        sheet_id: 'tblRESPONSES00001',
        spreadsheet_url: null,
        mapping: {
            fingerprint: 'fp-responses',
            columns: [
                { header: 'Full name', field_key: 'full_name' },
                { header: 'Submission ID', field_key: null },
            ],
        },
        destination_label: 'Client Intake CRM · Responses',
        paused_reason: null,
        status: 'active',
        consecutive_failure_count: 0,
        last_success_at: null,
        last_failure_at: null,
        created_at: '2026-09-01T00:00:00Z',
        ...overrides,
    };
}

function render(rule: Record<string, unknown> | null): VueWrapper {
    return mount(AirtableRuleFields, {
        props: { connectionId: 'conn-1', formId: '', formTitle: null, rule: rule as never, errors: {} },
    });
}

function lastChange(wrapper: VueWrapper): Change {
    const events = wrapper.emitted('change') ?? [];

    return (events.at(-1)?.[0] ?? null) as Change;
}

/** The base picker is the first select; the table picker and one select per field follow it. */
function baseSelect(wrapper: VueWrapper): DOMWrapper<HTMLSelectElement> {
    return wrapper.findAll('select')[0] as DOMWrapper<HTMLSelectElement>;
}

async function chooseBase(wrapper: VueWrapper, id: string): Promise<void> {
    await baseSelect(wrapper).setValue(id);
    await flushPromises();
}

beforeEach(() => {
    mocks.fetchChannels.mockReset();
    mocks.fetchMappableColumns.mockReset();
    mocks.inspectDestination.mockReset();

    mocks.fetchChannels.mockResolvedValue({
        channels: [
            { id: BASE, label: 'Client Intake CRM', available: true, unavailable_reason: null },
            { id: OTHER_BASE, label: 'Sales pipeline', available: true, unavailable_reason: null },
        ],
        truncated: false,
        error: null,
    });
    mocks.fetchMappableColumns.mockResolvedValue({
        columns: [
            { key: 'full_name', label: 'Full name', group: 'Form fields' },
            { key: '__submission_id', label: 'Submission ID', group: 'Submission details' },
        ],
        scoped: true,
    });
});

describe('AirtableRuleFields — a new rule', () => {
    it('arrives with Submission ID bound when the table has that field', async () => {
        mocks.inspectDestination.mockResolvedValue({ destination: table(), error: null });

        const wrapper = render(null);
        await flushPromises();
        await chooseBase(wrapper, BASE);

        const change = lastChange(wrapper);
        expect(change, 'a map with Submission ID bound is saveable, so it must be published').not.toBeNull();
        expect(change!.columns).toEqual([
            { header: 'Full name', field_key: null },
            { header: 'Submission ID', field_key: '__submission_id' },
        ]);
        expect(wrapper.text()).toContain('Lets us spot a row we already added, so a retried delivery does not add it twice.');
        wrapper.unmount();
    });

    it('explains how to add the field when the table has none', async () => {
        mocks.inspectDestination.mockResolvedValue({
            destination: table({ header_row: ['Full name', 'Notes'], header_types: ['singleLineText', 'multilineText'] }),
            error: null,
        });

        const wrapper = render(null);
        await flushPromises();
        await chooseBase(wrapper, BASE);

        expect(wrapper.text()).toContain(
            'Add a single line text field called “Submission ID” to this table in Airtable, then press Check again.',
        );
        // A new rule has nothing to pause.
        expect(wrapper.text()).not.toContain('The rule pauses until you save it');
        wrapper.unmount();
    });

    it('warns when Submission ID is bound to a field type Airtable may change', async () => {
        mocks.inspectDestination.mockResolvedValue({
            destination: table({ header_types: ['singleLineText', 'number'] }),
            error: null,
        });

        const wrapper = render(null);
        await flushPromises();
        await chooseBase(wrapper, BASE);

        expect(wrapper.text()).toContain('Airtable may change or refuse the submission id in a field of this type');
        wrapper.unmount();
    });

    it('re-reads the same table on Check again and keeps the bindings by field name', async () => {
        mocks.inspectDestination
            .mockResolvedValueOnce({
                destination: table({ header_row: ['Full name', 'Notes'], header_types: ['singleLineText', 'multilineText'], fingerprint: 'fp-before' }),
                error: null,
            })
            .mockResolvedValueOnce({
                destination: table({
                    header_row: ['Full name', 'Notes', 'Submission ID'],
                    header_types: ['singleLineText', 'multilineText', 'singleLineText'],
                    fingerprint: 'fp-after',
                }),
                error: null,
            });

        const wrapper = render(null);
        await flushPromises();
        await chooseBase(wrapper, BASE);

        // Selects: base, table, then one per field. Point "Full name" at the full_name answer.
        await wrapper.findAll('select')[2].setValue('full_name');

        const recheck = wrapper.findAll('button').find((candidate) => candidate.text().trim() === 'Check again');
        expect(recheck, 'a Check again control should sit beside the table picker').toBeTruthy();
        await recheck!.trigger('click');
        await flushPromises();

        expect(mocks.inspectDestination).toHaveBeenLastCalledWith('conn-1', BASE, 'tblRESPONSES00001');
        expect(lastChange(wrapper)!.columns).toEqual([
            { header: 'Full name', field_key: 'full_name' },
            { header: 'Notes', field_key: null },
            { header: 'Submission ID', field_key: '__submission_id' },
        ]);
        wrapper.unmount();
    });
});

describe('AirtableRuleFields — opening a saved rule', () => {
    it('finds the table by its id and restores the stored mapping exactly, when the fields are unchanged', async () => {
        mocks.inspectDestination.mockResolvedValue({
            destination: table({ sheet_name: 'Responses', tabs: ['Responses', 'Archive'] }),
            error: null,
        });

        const wrapper = render(savedRule());
        await flushPromises();

        // By id: the stored caption is 'Responses (old name)', which the base no longer has.
        expect(mocks.inspectDestination).toHaveBeenCalledWith('conn-1', BASE, 'tblRESPONSES00001');
        expect(lastChange(wrapper)).toEqual({
            spreadsheet_id: BASE,
            spreadsheet_title: 'Client Intake CRM',
            sheet_id: 'tblRESPONSES00001',
            sheet_name: 'Responses',
            // Submission ID stays unbound as stored, though the table has that field: a restore never pre-binds.
            columns: [
                { header: 'Full name', field_key: 'full_name' },
                { header: 'Submission ID', field_key: null },
            ],
        });
        expect(wrapper.text()).not.toContain('changed since the rule was saved');
        wrapper.unmount();
    });

    it('does not carry stored bindings by position once the fields changed, and says so without a live region', async () => {
        mocks.inspectDestination.mockResolvedValue({
            destination: table({
                header_row: ['Submission ID', 'Notes', 'Full name'],
                header_types: ['singleLineText', 'multilineText', 'singleLineText'],
                fingerprint: 'fp-changed',
            }),
            error: null,
        });

        const wrapper = render(savedRule({
            mapping: {
                fingerprint: 'fp-responses',
                columns: [
                    { header: 'full name', field_key: 'full_name' },
                    { header: 'submission id', field_key: '__submission_id' },
                ],
            },
        }));
        await flushPromises();

        const change = lastChange(wrapper);
        expect(change).not.toBeNull();
        // By position, `full_name` would land on "Submission ID" and the id on "Notes".
        expect(change!.columns).toEqual([
            { header: 'Submission ID', field_key: '__submission_id' },
            { header: 'Notes', field_key: null },
            { header: 'Full name', field_key: 'full_name' },
        ]);

        const notice = wrapper.findAll('p').find((p) => p.text().includes('changed since the rule was saved'));
        expect(notice, 'the editor must say the fields changed').toBeTruthy();
        // The editor already owns one live status line; this notice is present from the first frame.
        expect(notice!.attributes('role')).toBeUndefined();
        expect(notice!.attributes('aria-live')).toBeUndefined();
        wrapper.unmount();
    });

    it('builds a fresh map when a different base is chosen, never applying the old rule’s bindings', async () => {
        mocks.inspectDestination
            .mockResolvedValueOnce({ destination: table(), error: null })
            .mockResolvedValueOnce({
                destination: table({
                    spreadsheet_id: OTHER_BASE,
                    title: 'Sales pipeline',
                    url: `https://airtable.com/${OTHER_BASE}/tblLEADS000000001`,
                    tabs: ['Leads'],
                    sheet_name: 'Leads',
                    sheet_id: 'tblLEADS000000001',
                    header_row: ['Company', 'Submission ID'],
                    fingerprint: 'fp-leads',
                }),
                error: null,
            });

        const wrapper = render(savedRule());
        await flushPromises();
        await chooseBase(wrapper, OTHER_BASE);

        expect(lastChange(wrapper)!.columns).toEqual([
            { header: 'Company', field_key: null },
            { header: 'Submission ID', field_key: '__submission_id' },
        ]);
        wrapper.unmount();
    });

    it('adds that the rule pauses until it is saved, when a saved rule’s table has no Submission ID field', async () => {
        mocks.inspectDestination.mockResolvedValue({
            destination: table({ header_row: ['Full name', 'Notes'], header_types: ['singleLineText', 'multilineText'], fingerprint: 'fp-two' }),
            error: null,
        });

        const wrapper = render(savedRule({
            mapping: {
                fingerprint: 'fp-two',
                columns: [
                    { header: 'Full name', field_key: 'full_name' },
                    { header: 'Notes', field_key: null },
                ],
            },
        }));
        await flushPromises();

        expect(wrapper.text()).toContain('The rule pauses until you save it with the new column.');
        wrapper.unmount();
    });
});
