import { mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { ConnectionWithRules, ProviderCard, RuleRow } from '@/components/integrations/types';

/**
 * The Integrations page (H15b, extended by H16b).
 *
 * ⚠️ THIS FILE IS THE ONLY COMPONENT-LEVEL GATE THIS SURFACE CAN EVER HAVE, and it did not exist until now.
 * Storybook globs `packages/design-system/src/**` only, so an app page gets no story and no `checkA11y` scan;
 * the sole automated signal was the Playwright axe run, which measures accessibility rather than behaviour.
 *
 * The load-bearing assertion here is the 7-DAY NOTICE. Google expires a refresh token after seven days while
 * its OAuth app is in `testing`, so the connection dies weekly and the tenant sees a red "Reconnect needed"
 * badge with no cause. The reasonable conclusion from that is that our token handling is broken. Nothing
 * else in the stack can catch the notice going missing: it is a sentence, and no test asserted one here
 * before this file.
 */

const mocks = vi.hoisted(() => ({
    pageProps: {
        auth: { user: { name: 'Demo Owner' }, can: { manageIntegrations: true } },
        entitlements: { features: { native_connectors: true } },
    },
    visit: vi.fn(),
    del: vi.fn(),
}));

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { visit: mocks.visit, delete: mocks.del },
    usePage: () => ({ props: mocks.pageProps }),
    useForm: () => ({ errors: {}, transform: () => ({ post: vi.fn(), patch: vi.fn() }), clearErrors: vi.fn() }),
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', template: '<header><slot name="actions" /></header>' },
}));

// The rule modal opens a provider round trip on mount; this page's tests have nothing to say about it.
vi.mock('@/components/integrations/RuleFormModal.vue', () => ({
    default: { name: 'RuleFormModal', props: ['open', 'connectionId', 'provider', 'forms', 'eventTypes', 'rule'], template: '<div />' },
}));

const Index = (await import('./Index.vue')).default;

const SEVEN_DAY_NOTICE =
    'Google hasn’t published this app yet, so it treats every connection as a test and expires the sign-in after 7 days. Someone will need to reconnect weekly until then. That’s Google’s policy for apps in testing, not a fault in Meridian.';

function provider(overrides: Partial<ProviderCard> = {}): ProviderCard {
    return {
        key: 'google_sheets',
        label: 'Google Sheets',
        description: 'Append every new submission as a row.',
        scopes: ['https://www.googleapis.com/auth/drive.file'],
        configured: true,
        connect_url: '/integrations/google_sheets/connect',
        connected: true,
        notice: SEVEN_DAY_NOTICE,
        ...overrides,
    };
}

function rule(overrides: Partial<RuleRow> = {}): RuleRow {
    return {
        id: 'rule-1',
        connection_id: 'conn-1',
        name: 'New submissions → sheet',
        event_types: ['submission.created'],
        form_id: null,
        form_title: null,
        form_url: null,
        channel_id: null,
        channel_name: null,
        spreadsheet_id: 'SHEET_ID_0000000000000001',
        sheet_name: 'Responses',
        spreadsheet_url: 'https://docs.google.com/spreadsheets/d/SHEET_ID_0000000000000001/edit',
        mapping: { fingerprint: 'abc', columns: [{ header: 'full name', field_key: 'full_name' }] },
        destination_label: 'Q3 Intake · Responses',
        paused_reason: null,
        status: 'active',
        consecutive_failure_count: 0,
        last_success_at: null,
        last_failure_at: null,
        created_at: '2026-08-01T09:00:00+00:00',
        ...overrides,
    };
}

function connection(overrides: Partial<ConnectionWithRules> = {}): ConnectionWithRules {
    return {
        id: 'conn-1',
        provider: 'google_sheets',
        provider_label: 'Google Sheets',
        external_account_id: 'google-drive',
        external_account_label: 'Google Sheets',
        scopes: ['https://www.googleapis.com/auth/drive.file'],
        status: 'active',
        disconnected: false,
        token_expires_at: null,
        last_refreshed_at: null,
        last_error: null,
        last_error_at: null,
        connected_by_name: 'Demo Owner',
        created_at: '2026-08-01T09:00:00+00:00',
        can: { update: true, delete: true },
        rules: [rule()],
        ...overrides,
    };
}

function render(overrides: Record<string, unknown> = {}): VueWrapper {
    return mount(Index, {
        props: {
            providers: [provider()],
            connections: [connection()],
            summary: { rules: { active: 1, total: 1 }, deliveries: { used: 0, limit: null } },
            forms: [],
            eventTypes: [{ value: 'submission.created', label: 'Submission created' }],
            can: { create: true },
            ...overrides,
        },
        global: { stubs: { teleport: true } },
    });
}

beforeEach(() => {
    mocks.visit.mockClear();
    mocks.del.mockClear();
});

describe('the 7-day expiry notice (H16b)', () => {
    it('states the cause on the provider card, naming Google rather than us', () => {
        const wrapper = render();
        const text = wrapper.text();

        expect(text).toContain('7 days');
        expect(text).toContain('Google’s policy');
        // The half that matters most: without it the sentence explains a limitation and still leaves the
        // reader thinking it might be ours.
        expect(text).toContain('not a fault in Meridian');
        wrapper.unmount();
    });

    it('repeats it beside a dead connection, where the bare badge would otherwise stand alone', () => {
        // `statusVariant('refresh_failed')` renders "Reconnect needed" in danger red. THIS is the screen a
        // tenant lands on when the weekly expiry happens, so this is where the cause has to be.
        const wrapper = render({ connections: [connection({ status: 'refresh_failed' })] });
        const text = wrapper.text();

        expect(text).toContain('Reconnect needed');
        expect(text).toContain('7 days');
        wrapper.unmount();
    });

    it('disappears entirely once the app is published, with no placeholder left behind', () => {
        // Publishing flips one env var. A notice that degraded to "" or "n/a" would be a permanent artefact
        // of a temporary state — the failure mode the config-driven design exists to avoid.
        const wrapper = render({ providers: [provider({ notice: null })] });

        expect(wrapper.text()).not.toContain('7 days');
        wrapper.unmount();
    });

    it('says nothing about Google on a provider that has no caveat', () => {
        const wrapper = render({
            providers: [provider({ key: 'slack', label: 'Slack', notice: null })],
            connections: [connection({ provider: 'slack', status: 'refresh_failed' })],
        });

        expect(wrapper.text()).toContain('Reconnect needed');
        expect(wrapper.text()).not.toContain('7 days');
        wrapper.unmount();
    });
});

describe('the destination column', () => {
    it('prints the SERVER’s resolved label, so the cell never branches on the provider', () => {
        const wrapper = render();

        expect(wrapper.text()).toContain('Q3 Intake · Responses');
        // The heading is provider-neutral too: this page draws one table per connected workspace, and a
        // per-provider heading would give two tables on one page different columns.
        expect(wrapper.text()).toContain('Destination');
        expect(wrapper.text()).not.toContain('Channel');
        wrapper.unmount();
    });

    it('falls back to the Slack pair for a rule stored before destination_label existed', () => {
        const wrapper = render({
            connections: [
                connection({
                    provider: 'slack',
                    rules: [rule({ destination_label: null, channel_name: 'ops', spreadsheet_id: null })],
                }),
            ],
        });

        expect(wrapper.text()).toContain('#ops');
        wrapper.unmount();
    });

    it('renders an em dash rather than inventing copy for a rule with no destination', () => {
        const wrapper = render({
            connections: [connection({ rules: [rule({ destination_label: null, channel_name: null, channel_id: null })] })],
        });

        expect(wrapper.text()).toContain('—');
        expect(wrapper.text()).not.toContain('Not set');
        wrapper.unmount();
    });
});

describe('opening the rule modal', () => {
    it('hands the modal the PROVIDER, not just the connection id', async () => {
        // The modal renders an entirely different destination editor per provider. Passing only the id would
        // give a Google connection Slack's channel picker — which would call a sidecar that cannot enumerate.
        const wrapper = render();
        const button = wrapper.findAll('button').find((b) => b.text().includes('Add rule'));

        await button?.trigger('click');

        expect(wrapper.findComponent({ name: 'RuleFormModal' }).props('provider')).toBe('google_sheets');
        wrapper.unmount();
    });
});
