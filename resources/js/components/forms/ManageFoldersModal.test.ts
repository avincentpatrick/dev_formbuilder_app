import { mount, type VueWrapper } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, reactive } from 'vue';

/**
 * M131 (`R-9e634897`, `D79`) — "Manage folders". The dialog follows the rights `D79` split: anyone who may
 * create forms gets the create row, and only Owners and Admins (`can.manage`) get Rename and Delete. The
 * delete confirmation must say the forms move to Unfiled and that none is deleted, because that is the fact
 * an author must not have to guess.
 */

const mocks = vi.hoisted(() => ({ post: vi.fn(), patch: vi.fn(), destroy: vi.fn() }));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) =>
        reactive({
            ...initial,
            errors: {} as Record<string, string>,
            processing: false,
            reset() {},
            clearErrors() {},
            post: mocks.post,
            patch: mocks.patch,
        }),
    router: { delete: mocks.destroy },
}));

const ManageFoldersModal = (await import('./ManageFoldersModal.vue')).default;

const OPTIONS = [
    { id: 'folder-a', name: 'Clinics', count: 3 },
    { id: 'folder-b', name: 'Archive', count: 1 },
];

function mountModal(can: { create: boolean; manage: boolean }): VueWrapper {
    return mount(ManageFoldersModal, {
        props: { open: true, folders: { options: OPTIONS, unfiled_count: 0, can } },
        global: { stubs: { teleport: true } },
    });
}

function button(wrapper: VueWrapper, text: string) {
    return wrapper.findAll('button').find((b) => b.text() === text);
}

beforeEach(() => {
    mocks.post.mockReset();
    mocks.patch.mockReset();
    mocks.destroy.mockReset();
});

describe('ManageFoldersModal', () => {
    it('gives a Form Editor the create row and no Rename or Delete', () => {
        const wrapper = mountModal({ create: true, manage: false });

        expect(wrapper.text()).toContain('New folder');
        expect(wrapper.findAll('[data-folder-entry]')).toHaveLength(2);
        expect(wrapper.text()).toContain('3 forms');
        expect(wrapper.text()).toContain('1 form');
        expect(button(wrapper, 'Rename Clinics')).toBeUndefined();
        expect(button(wrapper, 'Delete Clinics')).toBeUndefined();
    });

    it('gives an Owner or Admin Rename and Delete on every folder, each named for a screen reader', () => {
        const wrapper = mountModal({ create: true, manage: true });

        for (const name of ['Clinics', 'Archive']) {
            expect(button(wrapper, `Rename ${name}`), name).toBeDefined();
            expect(button(wrapper, `Delete ${name}`), name).toBeDefined();
        }
    });

    it('creates a folder through the store route', async () => {
        const wrapper = mountModal({ create: true, manage: false });

        await wrapper.get('input').setValue('Rural');
        await wrapper.get('form').trigger('submit');

        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(mocks.post.mock.calls[0][0]).toBe('/form-folders');
    });

    it('renames a folder in place', async () => {
        const wrapper = mountModal({ create: false, manage: true });

        await button(wrapper, 'Rename Clinics')!.trigger('click');
        const input = wrapper.get('[data-folder-entry] input');
        expect((input.element as HTMLInputElement).value).toBe('Clinics');

        await input.setValue('Rural clinics');
        // "Save name" is the form's submit button; happy-dom does not submit a form from a button click, so
        // the form's own submit is what the test drives (a real browser does both).
        expect(button(wrapper, 'Save name')!.attributes('type')).toBe('submit');
        await wrapper.get('[data-folder-entry] form').trigger('submit');
        await nextTick();

        expect(mocks.patch.mock.calls[0][0]).toBe('/form-folders/folder-a');
    });

    it('confirms a delete by saying the forms move to Unfiled and none is deleted, then deletes', async () => {
        const wrapper = mountModal({ create: false, manage: true });

        await button(wrapper, 'Delete Archive')!.trigger('click');

        expect(mocks.destroy).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('Its forms move to Unfiled. No form is deleted.');

        await button(wrapper, 'Delete folder')!.trigger('click');

        expect(mocks.destroy.mock.calls[0][0]).toBe('/form-folders/folder-b');
    });
});
