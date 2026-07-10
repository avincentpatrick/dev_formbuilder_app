import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';
import RuntimeSession from '../components/RuntimeSession.vue';
import type { ApiClient } from '../lib/api-client';
import type { Bootstrap } from '../lib/types';
import { field, schemaResponse } from './fixtures';

const SUBMISSION_ID = '0192f1a2-b3c4-7d5e-8f90-1a2b3c4d5e6f';

function fakeClient(overrides: Partial<ApiClient> = {}): ApiClient {
    return {
        fetchSchema: vi.fn(async () => {
            throw new Error('unused in these tests');
        }),
        submit: vi.fn(async () => ({ id: SUBMISSION_ID, status: 'submitted', created: true })),
        remint: vi.fn(async () => ({ shareToken: 't2', expiresAt: '', form: { id: 'f', title: 'T' } })),
        token: () => 't1',
        ...overrides,
    };
}

const bootstrap: Bootstrap = {
    shareToken: 't1',
    expiresAt: '',
    formId: 'f',
    formTitle: 'T',
    slug: 's',
    defaultLocale: 'en',
};

beforeEach(() => {
    window.localStorage.clear();
});

describe('RuntimeSession (component wiring)', () => {
    it('renders fields via the shared FieldInput and submits effective answers', async () => {
        const schema = schemaResponse({
            fields: [field({ key: 'name', label: 'Full name', is_required: 'required' })],
        });
        const client = fakeClient();
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client } });

        expect(wrapper.text()).toContain('Full name');

        // Submit while empty → blocked + inline required error; the network is not called.
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(client.submit).not.toHaveBeenCalled();
        expect(wrapper.text()).toContain('This field is required.');

        // Fill + submit → client.submit is called with the answer, and 'submitted' fires with the id.
        await wrapper.find('input').setValue('Ada');
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(client.submit).toHaveBeenCalledWith(expect.objectContaining({ answers: { name: 'Ada' } }));
        expect(wrapper.emitted('submitted')?.[0]).toEqual([SUBMISSION_ID]);

        wrapper.unmount();
    });

    it('shows the language switcher only for a multi-locale form', () => {
        const single = mount(RuntimeSession, {
            props: {
                schema: schemaResponse({ fields: [field({ key: 'a' })], form: { supported_locales: ['en'] } }),
                bootstrap,
                client: fakeClient(),
            },
        });
        // Field 'a' is a text input, so a <select> would only be the switcher.
        expect(single.findAll('select')).toHaveLength(0);
        single.unmount();

        const multi = mount(RuntimeSession, {
            props: {
                schema: schemaResponse({ fields: [field({ key: 'a' })], form: { supported_locales: ['en', 'es'] } }),
                bootstrap,
                client: fakeClient(),
            },
        });
        expect(multi.findAll('select')).toHaveLength(1);
        multi.unmount();
    });

    it('maps a server 422 field error back onto the field', async () => {
        const { ApiError } = await import('../lib/error-normalizer');
        const client = fakeClient({
            submit: vi.fn(async () => {
                throw new ApiError({
                    httpStatus: 422,
                    code: 'submission_invalid',
                    message: 'invalid',
                    fieldErrors: { name: ['Server says no.'] },
                    kind: 'field',
                    retryAfterSeconds: null,
                });
            }),
        });
        const schema = schemaResponse({ fields: [field({ key: 'name', label: 'Full name' })] });
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client } });

        await wrapper.find('input').setValue('Ada');
        await wrapper.find('form').trigger('submit');
        await flushPromises();
        expect(wrapper.text()).toContain('Server says no.');
        wrapper.unmount();
    });
});
