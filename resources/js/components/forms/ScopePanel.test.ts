import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

/**
 * M129 — the scope picker, now one body mounted by the forms list's dialog and the hub's Settings tab.
 * Breadcrumb labels, active nodes only, "No scope" as a real choice, and a save that keeps the page's state.
 */

const mocks = vi.hoisted(() => ({ patch: vi.fn(), transformed: null as null | Record<string, unknown> }));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) =>
        reactive({
            ...initial,
            errors: {} as Record<string, string>,
            processing: false,
            reset() {},
            clearErrors() {},
            transform(callback: (data: Record<string, unknown>) => Record<string, unknown>) {
                mocks.transformed = callback({ scope_node_id: (this as unknown as { scope_node_id: string }).scope_node_id });
                return this;
            },
            patch: mocks.patch,
        }),
}));

const ScopePanel = (await import('./ScopePanel.vue')).default;

const SCOPES = [
    { id: 'n1', name: 'Luzon', parent_id: null, is_active: true },
    { id: 'n2', name: 'NCR', parent_id: 'n1', is_active: true },
    { id: 'n3', name: 'Retired', parent_id: 'n1', is_active: false },
];

beforeEach(() => {
    mocks.patch.mockReset();
    mocks.transformed = null;
});

describe('ScopePanel', () => {
    it('offers "No scope" and every ACTIVE node as its breadcrumb path', () => {
        const wrapper = mount(ScopePanel, { props: { open: true, formId: 'form-1', currentNodeId: null, scopes: SCOPES } });
        const labels = wrapper.findAll('option').map((o) => o.text());

        expect(labels).toContain('No scope');
        expect(labels).toContain('Luzon / NCR');
        expect(labels.some((label) => label.includes('Retired'))).toBe(false);
    });

    it('saves "No scope" as null, keeps the page\'s state, and says when it has saved', async () => {
        const wrapper = mount(ScopePanel, { props: { open: true, formId: 'form-1', currentNodeId: null, scopes: SCOPES } });

        await wrapper.find('button').trigger('click');

        expect(mocks.transformed).toEqual({ scope_node_id: null });
        expect(mocks.patch).toHaveBeenCalledTimes(1);
        const [url, options] = mocks.patch.mock.calls[0] as [string, { preserveState: boolean; onSuccess: () => void }];
        expect(url).toBe('/forms/form-1/scope');
        expect(options.preserveState).toBe(true);

        options.onSuccess();
        expect(wrapper.emitted('saved')).toHaveLength(1);
    });
});
