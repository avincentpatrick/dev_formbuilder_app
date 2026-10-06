import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

/**
 * M129 — the form hub's Settings tab. It holds no section of its own: every one is the component the builder's
 * modal wraps, plus the hub-only Scope. What this page owns is the frame — the strip marked current, exactly one
 * navigation named with the form's title, and the sections offered by what the server sent.
 */

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    useForm: (initial: Record<string, unknown>) =>
        reactive({ ...initial, errors: {}, processing: false, reset() {}, clearErrors() {}, transform() { return this; }, patch: () => {} }),
    router: { patch: () => {} },
    usePage: () => ({ props: { entitlements: null } }),
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', props: ['title'], template: '<header><h1>{{ title }}</h1><slot name="breadcrumbs" /></header>' },
}));

vi.mock('@/composables/useEntitlements', () => ({ useEntitlements: () => ({ feature: () => true }) }));

const Settings = (await import('./Settings.vue')).default;

const FORM = {
    id: 'form-1',
    title: 'Clinic Visit',
    description: null,
    save_and_resume: false,
    single_page_mode: false,
    opens_at: null,
    closes_at: null,
    timezone: 'UTC',
    max_responses: null,
    confirmation_message: null,
    confirmation_message_translations: {},
    redirect_kind: 'none',
    redirect_form_id: null,
    redirect_url: null,
    redirect_targets: [],
    redirect_delay_seconds: 20,
    theme_preset: null,
    theme_presets: [],
    default_locale: 'en',
    supported_locales: ['en'],
};

const SHARE = {
    public_slug: null,
    allow_guest_submissions: false,
    bot_challenge: 'off',
    guest_rate_limit_per_minute: null,
    suggested_slug: 'clinic-visit',
    is_published: true,
    public_host: 'acme.example.test',
    public_url: null,
};

function props(overrides: Record<string, unknown> = {}): Record<string, unknown> {
    return {
        form: FORM,
        share: SHARE,
        timezones: ['UTC'],
        ocr_scanning: { enabled: false, eligible: true, reason: null },
        // M132: the hub always sends the draft's reference files, an empty list included.
        reference_files: [],
        automations: { can_webhook: false, max: 10, items: [] },
        // M133: sent to a reader who can read the responses (`D87`).
        data_sharing: { enabled: false, field_keys: null, published: true, questions: [], used_by: [], used_by_others: 0 },
        scope: { current_node_id: null, options: [] },
        tabs: [
            { key: 'overview', label: 'Overview', href: '/forms/form-1', icon: 'forms' },
            { key: 'settings', label: 'Settings', href: '/forms/form-1/settings', icon: 'sliders' },
        ],
        crumbs: [{ label: 'Forms', href: '/forms' }, { label: 'Clinic Visit', href: '/forms/form-1' }, { label: 'Settings' }],
        ...overrides,
    };
}

function railLabels(wrapper: ReturnType<typeof mount>): string[] {
    return wrapper.findAll('.form-settings__rail-button').map((button) => button.text());
}

describe('forms/Settings', () => {
    it('marks Settings current in the strip, and names exactly one navigation with the form\'s title', () => {
        const wrapper = mount(Settings, { props: props() as never });
        const nav = wrapper.findComponent({ name: 'TabNav' });

        expect(nav.props('current')).toBe('settings');
        expect(nav.props('ariaLabel')).toBe('Clinic Visit');
        expect(wrapper.findAll('nav').filter((el) => el.attributes('aria-label') === 'Clinic Visit')).toHaveLength(1);
        expect(wrapper.find('h1').text()).toBe('Settings');
    });

    it('offers the builder\'s sections plus Scope, with Scanning where the server sent it', () => {
        const wrapper = mount(Settings, { props: props() as never });

        expect(railLabels(wrapper)).toEqual([
            'Details', 'Pages', 'Theme', 'Reference files', 'Choice lists', 'Share', 'Scanning', 'Schedule', 'Thank-you message',
            'Save and finish later', 'Automations', 'Data sharing', 'Scope',
        ]);
    });

    it('leaves out Scanning and Scope when the server sends neither', () => {
        const wrapper = mount(Settings, { props: props({ ocr_scanning: null, scope: null }) as never });

        expect(railLabels(wrapper)).not.toContain('Scanning');
        expect(railLabels(wrapper)).not.toContain('Scope');
        expect(railLabels(wrapper)).toContain('Details');
    });
});
