import { mount } from '@vue/test-utils';
import { describe, expect, it, vi } from 'vitest';

/**
 * The webhook endpoints list — `/webhooks` (Increment H14). This page had no Vitest file until M182, which pins one
 * thing: the URL column clips a long address with an ellipsis (`.webhooks__url`, `max-width: min(22rem, 100%)`), so
 * the cell carries the whole address in a title (`R-9d3c2439`). The sweep in
 * `resources/js/__tests__/truncation-title.test.ts` holds that the attribute is bound; this proves it is bound to
 * the address itself. Built on `show.test.ts`'s harness, with the Zapier recipe replaced — it has nothing to say here.
 */

vi.mock('@inertiajs/vue3', () => ({
    Head: { name: 'Head', render: () => null },
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    router: { get: vi.fn(), visit: vi.fn() },
    usePage: () => ({ props: { flash: {} } }),
    useForm: () => ({ errors: {}, transform: () => ({ post: vi.fn(), patch: vi.fn() }), clearErrors: vi.fn() }),
}));

vi.mock('@/components/shell/PageHeader.vue', () => ({
    default: { name: 'PageHeader', props: ['title'], template: '<header><h1>{{ title }}</h1><slot name="actions" /></header>' },
}));

vi.mock('@/components/webhooks/WebhookFormModal.vue', () => ({
    default: { name: 'WebhookFormModal', props: ['open', 'forms', 'eventTypes', 'endpoint'], template: '<div />' },
}));

vi.mock('@/components/webhooks/ZapierRecipe.vue', () => ({
    default: { name: 'ZapierRecipe', template: '<div />' },
}));

const Index = (await import('./Index.vue')).default;

const LONG_URL = 'https://hooks.zapier.com/hooks/catch/1234567/abcdefg/clinic-intake-northern-district-responses';

describe('the URL column', () => {
    it('carries the whole address in a title, since the cell clips a long one with an ellipsis', () => {
        const wrapper = mount(Index, {
            props: {
                data: [
                    {
                        id: '018f0000-0000-7000-8000-0000000000aa',
                        name: 'Zapier intake',
                        url: LONG_URL,
                        status: 'active',
                        event_types: ['submission.created'],
                        form_id: null,
                        form_title: null,
                        form_url: null,
                        secret_masked: 'whsec_••••abcd',
                        disabled_reason: null,
                        consecutive_failure_count: 0,
                        last_success_at: null,
                        last_failure_at: null,
                        created_at: '2026-10-01T08:00:00Z',
                    },
                ],
                summary: { endpoints: { used: 1, limit: 5 }, deliveries: { used: 0, limit: 1000 } },
                forms: [],
                eventTypes: [],
                can: { create: true },
                filters: { applied: { q: null } },
                empty_reason: null,
            },
            global: { stubs: { teleport: true } },
        });

        // A floor before the attribute: the row really rendered its address.
        const cell = wrapper.get('.webhooks__url');
        expect(cell.text()).toBe(LONG_URL);
        expect(cell.attributes('title')).toBe(LONG_URL);
        wrapper.unmount();
    });
});
