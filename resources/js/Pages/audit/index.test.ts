import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi, beforeEach } from 'vitest';
import type { AuditChange, AuditPageProps, AuditRow } from '@/components/audit/types';

/**
 * The audit log page (Increment I2, PRD Feature #12).
 *
 * The load-bearing assertions here are the ones about REDACTION and about the EXPORT QUERY, and neither is
 * covered by any other gate:
 *
 *  • audit-compliance-logging-spec §2.1 says an erasure's "before" value was never stored — so a renderer
 *    that presents `old` as a real prior value is advertising something the ledger deliberately does not
 *    have. That is a compliance defect no axe scan or PHP test can see, because the props are correct and
 *    only the presentation lies.
 *  • "I exported what I was looking at" is a guarantee, and both `submissions/Inbox.vue` and
 *    `analytics/Index.vue` build their export query SEPARATELY from their page query — which is exactly
 *    where that guarantee dies. The whole-string assertion below is the only thing holding it here.
 */

const mocks = vi.hoisted(() => ({
    pageProps: {
        auth: { user: { name: 'Demo Owner' }, can: { viewAuditLog: true } },
        entitlements: { features: {} },
    },
    get: vi.fn(),
    visit: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: mocks.get, visit: mocks.visit },
    usePage: () => ({ props: mocks.pageProps }),
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', template: '<header><slot name="actions" /></header>' },
}));

// Imported AFTER the mocks so the component resolves them.
const AuditIndex = (await import('./Index.vue')).default;

function change(overrides: Partial<AuditChange> = {}): AuditChange {
    return { key: 'title', old: 'Old', new: 'New', redacted: false, ...overrides };
}

function row(overrides: Partial<AuditRow> = {}): AuditRow {
    return {
        id: '0192e2e0-0000-7000-8000-000000000001',
        created_at: '2026-08-01T09:30:00+00:00',
        event: 'updated',
        event_label: 'Updated',
        target: {
            type: 'form',
            type_label: 'Form',
            id: '0192e2e0-0000-7000-8000-0000000000ff',
            label: 'Clinic Intake',
            url: '/forms',
        },
        actor: 'Demo Owner',
        acting_as: null,
        is_system: false,
        ip_address: '203.0.113.4',
        changes: [change()],
        redacted_fields: [],
        ...overrides,
    };
}

function props(overrides: Partial<AuditPageProps> = {}): AuditPageProps {
    return {
        data: [row()],
        meta: { current_page: 1, last_page: 1, total: 1, per_page: 25 },
        filters: {
            events: [
                { value: 'created', label: 'Created' },
                { value: 'deleted', label: 'Deleted' },
                { value: 'permission_changed', label: 'Permission changed' },
            ],
            auditable_types: [{ value: 'form', label: 'Form' }],
            actors: [{ value: 'u1', label: 'Demo Owner' }],
            applied: { auditable_type: null, event: null, user_id: null, from: null, to: null },
        },
        empty_reason: null,
        can: { export: true },
        ...overrides,
    };
}

function render(overrides: Partial<AuditPageProps> = {}): VueWrapper {
    // `teleport: true` or MdsModal's panel renders outside the wrapper and is invisible to find().
    return mount(AuditIndex, { props: props(overrides), global: { stubs: { teleport: true } } });
}

beforeEach(() => {
    mocks.get.mockClear();
    mocks.visit.mockClear();
});

describe('audit log — redaction', () => {
    it('never prints the placeholder as a value, and never claims the "before" was real', async () => {
        const wrapper = render({
            data: [
                row({
                    changes: [
                        change({ key: 'remarks', old: '[REDACTED]', new: '[REDACTED]', redacted: true }),
                        change({ key: 'status', old: 'submitted', new: 'approved' }),
                    ],
                    redacted_fields: ['remarks'],
                }),
            ],
        });

        await wrapper.find('button[aria-label="View changes"]').trigger('click');
        const text = wrapper.text();

        // A sentinel printed in a value column reads as if it WERE the value; a badge reads as an absence.
        expect(text).not.toContain('[REDACTED]');
        expect(text).toContain('Redacted');
        // §2.1's whole content, stated to the user rather than only in a code comment.
        expect(text).toContain('including the “before” value');
        // Whole-field replacements, and on a redacted row there is no change to mark up at all.
        expect(wrapper.findAll('del')).toHaveLength(0);
        expect(wrapper.findAll('ins')).toHaveLength(0);
        // The unredacted key in the same diff still shows both real sides.
        expect(text).toContain('submitted');
        expect(text).toContain('approved');

        wrapper.unmount();
    });

    it('marks a row as redacted in the list without opening it', () => {
        const wrapper = render({ data: [row({ redacted_fields: ['remarks'] })] });

        expect(wrapper.text()).toContain('Redacted');
        wrapper.unmount();
    });
});

describe('audit log — labels and badges', () => {
    it('prints the SERVER\'s event label, never the raw enum value', () => {
        // Catches a reintroduced client-side label map — the exact drift DSR §3.8 exists to prevent, and
        // the reason `AuditEvent::label()` is the single source across badge, filter and CSV.
        const wrapper = render({
            data: [row({ event: 'permission_changed', event_label: 'Permission changed' })],
        });

        expect(wrapper.text()).toContain('Permission changed');
        expect(wrapper.text()).not.toContain('permission_changed');
        wrapper.unmount();
    });

    it('renders `archived` neutral, not the form-status amber', () => {
        // Belt and braces over event-variant.test.ts, at the integration seam where a swap back to
        // statusVariant() would actually happen.
        const wrapper = render({ data: [row({ event: 'archived', event_label: 'Archived' })] });
        const badge = wrapper.find('.mds-badge');

        expect(badge.classes().join(' ')).toContain('neutral');
        expect(badge.classes().join(' ')).not.toContain('warning');
        wrapper.unmount();
    });

    it('discloses a system-attributed row', () => {
        const wrapper = render({ data: [row({ actor: 'System', is_system: true })] });

        expect(wrapper.text()).toContain('System');
        wrapper.unmount();
    });

    it('carries the impersonation fact into the change modal (I11a)', async () => {
        // The modal is the surface built for "what exactly happened here", so it is the LAST place the fact
        // may go missing — and the first draft of this increment updated every other read surface and left
        // it out. Shared verbatim with the console page, which is why it is tested from this one.
        const wrapper = render({ data: [row({ actor: 'Demo Owner', acting_as: 'Platform operator' })] });

        await wrapper.get('button[aria-label="View changes"]').trigger('click');

        const text = wrapper.text();
        expect(text).toContain('Acting as');
        expect(text).toContain('Platform operator');
        wrapper.unmount();
    });

    it('marks a row an operator drove, without losing the effective actor (I11a)', () => {
        const wrapper = render({ data: [row({ actor: 'Demo Owner', acting_as: 'Platform operator' })] });

        // BOTH names, and the "via" prefix. Asserting only that "Platform operator" appears would pass just
        // as well if the marker had REPLACED the actor — which is the one rendering that would misreport
        // whose authority the action ran under. The word carries the relationship (WCAG 1.4.1); the colour
        // difference between the two lines is decoration and cannot be the only signal.
        expect(wrapper.text()).toContain('Demo Owner');
        expect(wrapper.text()).toContain('via Platform operator');
        wrapper.unmount();
    });

    it('renders no impersonation marker on an ordinary row', () => {
        const wrapper = render({ data: [row()] });

        // A class assertion, NOT `text()).not.toContain('via')`: a bare substring over the whole page would
        // redden on any future copy containing "trivial" or "deviation", and could not tell "no marker"
        // from "marker rendered with empty text".
        expect(wrapper.find('.audit__acting-as').exists()).toBe(false);
        wrapper.unmount();
    });
});

describe('audit log — the target cell', () => {
    it('links a resolved target', () => {
        const wrapper = render();

        expect(wrapper.find('a[href="/forms"]').text()).toContain('Clinic Intake');
        wrapper.unmount();
    });

    it('degrades to a short id without printing null or undefined', () => {
        // The day-one state: resource_grants are hard-deleted by design, and half the aliases have no page.
        const wrapper = render({
            data: [
                row({
                    target: {
                        type: 'resource_grants',
                        type_label: 'Access grant',
                        id: '0192e2e0-1111-7000-8000-0000000000aa',
                        label: null,
                        url: null,
                    },
                }),
            ],
        });

        const text = wrapper.text();
        expect(wrapper.find('a[href="/forms"]').exists()).toBe(false);
        expect(text).toContain('Access grant');
        expect(text).toContain('0192e2e0…');
        expect(text).not.toContain('null');
        expect(text).not.toContain('undefined');
        wrapper.unmount();
    });
});

describe('audit log — empty states', () => {
    it('offers no escape hatch when the ledger is genuinely empty', () => {
        const wrapper = render({ data: [], empty_reason: 'no_rows', meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } });

        expect(wrapper.text()).toContain('appear here as they happen');
        // A "Clear filters" button on an unfiltered empty ledger offers to fix a problem that is not there.
        expect(wrapper.text()).not.toContain('Clear filters');
        wrapper.unmount();
    });

    it('offers to clear filters when they matched nothing, and clears them EXACTLY', () => {
        const wrapper = render({
            data: [],
            empty_reason: 'no_matches',
            meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 },
            filters: { ...props().filters, applied: { ...props().filters.applied, event: 'deleted' } },
        });

        expect(wrapper.text()).toContain('No matching activity');

        const button = wrapper.findAll('button').find((b) => b.text().includes('Clear filters'));
        expect(button).toBeDefined();
        button!.trigger('click');

        const [url, params] = mocks.get.mock.calls[0];
        expect(url).toBe('/audit-log');
        // Exact equality — a leftover `page: 1` or an empty-string filter would survive the "clear".
        expect(params).toEqual({});
        wrapper.unmount();
    });
});

describe('audit log — navigation', () => {
    it('omits empty filters from the query and REPLACES history', async () => {
        const wrapper = render();

        const select = wrapper.find('#audit-event');
        await select.setValue('deleted');

        const [url, params, options] = mocks.get.mock.calls[0];
        expect(url).toBe('/audit-log');
        // Exact — an accidental `auditable_type: ''` would reach the server as a filter for the empty type.
        expect(params).toEqual({ event: 'deleted' });
        expect(options.replace).toBe(true);
        expect(options.preserveState).toBe(true);
        wrapper.unmount();
    });

    it('preserves the filter when paging, and does NOT replace history', () => {
        // The asymmetry is deliberate and breaks silently: filtering is a correction to the current view,
        // paging is a step you should be able to walk back out of.
        const wrapper = render({
            meta: { current_page: 1, last_page: 3, total: 60, per_page: 25 },
            filters: { ...props().filters, applied: { ...props().filters.applied, event: 'deleted' } },
        });

        wrapper.findComponent({ name: 'Pagination' }).vm.$emit('update:page', 3);

        const [url, params, options] = mocks.get.mock.calls[0];
        expect(url).toBe('/audit-log');
        expect(params).toEqual({ event: 'deleted', page: 3 });
        expect(options.replace).toBeUndefined();
        wrapper.unmount();
    });

    it('re-seeds its controls from what the SERVER applied, not from what was typed', () => {
        // If the server ever clamps or defaults a filter, the control must move with it — otherwise the
        // page declares one thing and reports another.
        const wrapper = render({
            filters: {
                ...props().filters,
                applied: { ...props().filters.applied, from: '2026-01-01', event: 'deleted' },
            },
        });

        expect((wrapper.find('#audit-from').element as HTMLInputElement).value).toBe('2026-01-01');
        expect((wrapper.find('#audit-event').element as HTMLSelectElement).value).toBe('deleted');
        wrapper.unmount();
    });

    it('marks the table busy while a request is in flight', async () => {
        const wrapper = render();

        await wrapper.find('#audit-event').setValue('deleted');
        const [, , options] = mocks.get.mock.calls[0];

        options.onStart();
        await wrapper.vm.$nextTick();
        expect(wrapper.findComponent({ name: 'DataTable' }).props('loading')).toBe(true);

        options.onFinish();
        await wrapper.vm.$nextTick();
        expect(wrapper.findComponent({ name: 'DataTable' }).props('loading')).toBe(false);
        wrapper.unmount();
    });
});

describe('audit log — export', () => {
    it('hides both export buttons from a user who may not export', () => {
        const wrapper = render({ can: { export: false } });

        expect(wrapper.text()).not.toContain('Export CSV');
        expect(wrapper.text()).not.toContain('Export XLSX');
        wrapper.unmount();
    });

    it('exports EXACTLY what is on screen, asserted as one whole URL', async () => {
        // "I exported what I was looking at" is a compliance guarantee. Both Inbox.vue and
        // analytics/Index.vue build the export query separately from the page query, which is precisely
        // where it dies; this asserts the whole string rather than probing for one parameter.
        const wrapper = render({
            filters: {
                ...props().filters,
                applied: { ...props().filters.applied, event: 'deleted', from: '2026-01-01' },
            },
        });

        // Replace ONLY `window.location`, never the whole window: stubbing the global tears out the DOM
        // event constructors test-utils needs to dispatch the click.
        const original = window.location;
        const assigned: string[] = [];
        Object.defineProperty(window, 'location', {
            configurable: true,
            value: {
                get href() {
                    return '';
                },
                set href(value: string) {
                    assigned.push(value);
                },
            },
        });

        const button = wrapper.findAll('button').find((b) => b.text().includes('Export CSV'));
        await button!.trigger('click');

        expect(assigned).toEqual(['/audit-log/export?format=csv&event=deleted&from=2026-01-01']);

        Object.defineProperty(window, 'location', { configurable: true, value: original });
        wrapper.unmount();
    });
});
