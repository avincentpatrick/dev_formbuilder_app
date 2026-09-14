import { mount, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

/**
 * The webhook endpoint page — `/webhooks/{endpoint}` (Increment H14) — and its delivery log.
 *
 * ⚠️ THIS PAGE HAD NO VITEST FILE UNTIL M96, AND WHAT THIS FILE PINS FIRST IS AN ABSENCE.
 * The delivery log is OFFSET-paginated on the server (`WebhookEndpointPresenter::show()`, newest first, fixed)
 * and `MdsDataTable` sorts only the rows it was handed. A sortable `Created` header therefore reordered ONE
 * page and announced, through `aria-sort`, an order over the whole log that did not exist. The user's
 * decision of 2026-08-18 drops the sort on this table and on the submissions inbox, following
 * `audit/Index.vue`: no sortable column, and one line of prose stating the fixed order instead.
 *
 * ⚠️ A FLOOR BEFORE EVERY ABSENCE. Zero sort buttons is also what a table that never rendered looks like, so
 * each case first proves the header, a delivery row and the pagination are really on the page.
 *
 * ── THE HARNESS ─────────────────────────────────────────────────────────────────────────────────────────
 * Built on `integrations/index.test.ts`'s pattern. Two things this page needs that one does not: `usePage()`
 * must return a `flash` object, because both one-shot watchers run `immediate` at setup and read
 * `flash.newSecret` and `flash.testResult`; and the three webhook modals are replaced, because this file has
 * nothing to say about them and each carries its own form state.
 */

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), post: vi.fn(), patch: vi.fn(), delete: vi.fn(), visit: vi.fn() },
    // An empty flash is the state every ordinary page load is in.
    usePage: () => ({ props: { flash: {} } }),
    useForm: () => ({ errors: {}, transform: () => ({ post: vi.fn(), patch: vi.fn() }), clearErrors: vi.fn() }),
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: {
        name: 'PageHeader',
        props: ['title'],
        template: '<header><h1>{{ title }}</h1><slot name="breadcrumbs" /><slot name="actions" /></header>',
    },
}));

vi.mock('@/components/webhooks/WebhookFormModal.vue', () => ({
    default: { name: 'WebhookFormModal', props: ['open', 'forms', 'eventTypes', 'endpoint'], template: '<div />' },
}));

vi.mock('@/components/webhooks/SecretRevealModal.vue', () => ({
    default: { name: 'SecretRevealModal', props: ['open', 'secret', 'name'], template: '<div />' },
}));

vi.mock('@/components/webhooks/TestResultModal.vue', () => ({
    default: { name: 'TestResultModal', props: ['open', 'result'], template: '<div />' },
}));

const Show = (await import('./Show.vue')).default;

const ENDPOINT_ID = '018f0000-0000-7000-8000-0000000000cc';
const ORDER_LINE = 'Newest first.';

type Props = Record<string, unknown>;

function delivery(overrides: Props = {}): Props {
    return {
        id: '018f0000-0000-7000-8000-0000000000d1',
        webhook_endpoint_id: ENDPOINT_ID,
        event_id: '018f0000-0000-7000-8000-0000000000e1',
        event_type: 'submission.created',
        status: 'succeeded',
        attempt_count: 1,
        max_attempts: 5,
        next_retry_at: null,
        last_attempted_at: '2026-08-10T14:30:05+00:00',
        response_status_code: 200,
        response_body_excerpt: 'ok',
        response_time_ms: 120,
        created_at: '2026-08-10T14:30:00+00:00',
        updated_at: '2026-08-10T14:30:05+00:00',
        ...overrides,
    };
}

function showProps(overrides: Props = {}): Props {
    return {
        crumbs: [{ label: 'Webhooks', href: '/webhooks' }, { label: 'Order feed' }],
        endpoint: {
            id: ENDPOINT_ID,
            name: 'Order feed',
            url: 'https://receiver.example.test/hook',
            status: 'active',
            event_types: ['submission.created'],
            form_id: null,
            form_title: null,
            form_url: null,
            secret_masked: 'masked-abcd',
            disabled_reason: null,
            consecutive_failure_count: 0,
            last_success_at: '2026-08-10T14:30:05+00:00',
            last_failure_at: null,
            created_at: '2026-08-01T09:00:00+00:00',
            signing_algorithm: 'hmac-sha256',
            secret_previous_expires_at: null,
            updated_at: '2026-08-01T09:00:00+00:00',
        },
        // A SECOND PAGE EXISTS. With one page the rows handed over are the whole log and a client sort is
        // honest; the defect is only reachable once the log is longer than a page, so the fixture says so.
        deliveries: { data: [delivery()], meta: { current_page: 1, last_page: 2, total: 26, per_page: 25 } },
        forms: [],
        eventTypes: [{ value: 'submission.created', label: 'Submission created' }],
        can: { update: true, delete: true },
        ...overrides,
    };
}

function render(props: Props = showProps()): VueWrapper {
    return mount(Show, { props, global: { stubs: { teleport: true } } });
}

/** Every surface `MdsDataTable` renders for a sortable column: `aria-sort`, the header button, the chip bar. */
function sortAffordances(wrapper: VueWrapper): { ariaSort: number; headerButtons: number; sortBar: boolean } {
    return {
        ariaSort: wrapper.findAll('th[aria-sort]').length,
        headerButtons: wrapper.findAll('th button').length,
        sortBar: wrapper.find('[role="group"][aria-label^="Sort"]').exists(),
    };
}

describe('webhook endpoint page — the delivery log is server-paginated', () => {
    it('offers no sort on the delivery log', () => {
        const wrapper = render();

        // The floor: the header the sort used to live on, a real delivery row, and a pager with a next page.
        expect(wrapper.findAll('th').map((th) => th.text())).toEqual(expect.arrayContaining(['Event', 'Created']));
        expect(wrapper.text()).toContain('submission.created');
        expect(wrapper.find('nav[aria-label="Pagination"]').text()).toContain('of 2');

        expect(sortAffordances(wrapper)).toEqual({ ariaSort: 0, headerButtons: 0, sortBar: false });

        wrapper.unmount();
    });

    it('states the log’s fixed order in one line, before the table', () => {
        const wrapper = render();

        const lines = wrapper.findAll('p').filter((p) => p.text() === ORDER_LINE);
        expect(lines, 'exactly one order line').toHaveLength(1);

        // Placement, not just presence: a sentence in the page header's actions slot would pass a text check.
        const position = lines[0]!.element.compareDocumentPosition(wrapper.get('table').element);
        expect(position & Node.DOCUMENT_POSITION_FOLLOWING, 'the order line must come BEFORE the table').not.toBe(0);

        wrapper.unmount();
    });

    it('keeps the order line when the log is empty', () => {
        // The order is a fact about the log, not about its rows: an endpoint with no deliveries yet still
        // renders the heading and the table's empty state, and the sentence must not depend on data.
        const wrapper = render(
            showProps({ deliveries: { data: [], meta: { current_page: 1, last_page: 1, total: 0, per_page: 25 } } }),
        );

        expect(wrapper.text()).toContain('No deliveries yet');
        expect(wrapper.findAll('p').filter((p) => p.text() === ORDER_LINE)).toHaveLength(1);

        wrapper.unmount();
    });
});
