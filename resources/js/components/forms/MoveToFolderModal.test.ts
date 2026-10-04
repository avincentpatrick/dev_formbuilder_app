import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

/**
 * M131 (`R-9e634897`, `D78`) — "Move to folder": the current folder preselected, Unfiled a real choice, and the
 * null the request expects when Unfiled is chosen.
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
                mocks.transformed = callback({ folder_id: (this as unknown as { folder_id: string }).folder_id });
                return this;
            },
            patch: mocks.patch,
        }),
}));

const MoveToFolderModal = (await import('./MoveToFolderModal.vue')).default;

const FOLDERS = [
    { id: 'folder-a', name: 'Clinics', count: 3 },
    { id: 'folder-b', name: 'Archive', count: 0 },
];

function mountModal(currentFolderId: string | null, folders = FOLDERS) {
    return mount(MoveToFolderModal, {
        props: { open: true, formId: 'form-1', formTitle: 'Intake', currentFolderId, folders },
        global: { stubs: { teleport: true } },
    });
}

beforeEach(() => {
    mocks.patch.mockReset();
    mocks.transformed = null;
});

describe('MoveToFolderModal', () => {
    it('offers Unfiled and every folder, with the current one selected', () => {
        const wrapper = mountModal('folder-b');
        const select = wrapper.get('select');

        expect(select.findAll('option').map((o) => o.text())).toEqual(['Unfiled', 'Clinics', 'Archive']);
        expect((select.element as HTMLSelectElement).value).toBe('folder-b');
    });

    it('files the form into the chosen folder and closes when the server accepts', async () => {
        const wrapper = mountModal(null);

        await wrapper.get('select').setValue('folder-a');
        await wrapper.findAll('button').find((b) => b.text() === 'Move')!.trigger('click');

        expect(mocks.transformed).toEqual({ folder_id: 'folder-a' });
        const [url, options] = mocks.patch.mock.calls[0] as [string, { onSuccess: () => void }];
        expect(url).toBe('/forms/form-1/folder');

        options.onSuccess();
        expect(wrapper.emitted('update:open')).toEqual([[false]]);
    });

    it('sends null, never an empty string, for Unfiled', async () => {
        const wrapper = mountModal('folder-a');

        await wrapper.get('select').setValue('');
        await wrapper.findAll('button').find((b) => b.text() === 'Move')!.trigger('click');

        expect(mocks.transformed).toEqual({ folder_id: null });
    });

    it('says where folders are made when the workspace has none', () => {
        const wrapper = mountModal(null, []);

        expect(wrapper.text()).toContain('This workspace has no folders yet');
    });
});
