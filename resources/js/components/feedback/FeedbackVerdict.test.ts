import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';

/**
 * It works / Still a problem (M179, `R-129c44c3`, `D151`).
 *
 * What carries the weight:
 *  · THE BUTTONS ARE THE SERVER'S. A row's `actions` come from the member graph and the policy; no action, no
 *    button — this component never decides who may confirm.
 *  · STILL A PROBLEM NEEDS WORDS. An empty reason is refused here, before anything is posted; the server
 *    refuses it too, but a tester should not have to wait for a round trip to learn they must say what is wrong.
 */

const mocks = vi.hoisted(() => ({ posts: [] as Array<{ url: string; data: Record<string, unknown> }> }));

vi.mock('@inertiajs/vue3', () => ({
    useForm: (initial: Record<string, unknown>) => {
        const form = reactive({
            ...initial,
            errors: {} as Record<string, string>,
            processing: false,
            post(url: string) {
                const { errors: _e, processing: _p, post: _post, reset: _r, setError: _s, ...data } = form as Record<string, unknown>;
                mocks.posts.push({ url, data });
            },
            reset() {
                Object.assign(form, initial);
            },
            setError(key: string, message: string) {
                form.errors[key] = message;
            },
        });

        return form;
    },
}));

const FeedbackVerdict = (await import('./FeedbackVerdict.vue')).default;

beforeEach(() => {
    mocks.posts.length = 0;
});

describe('feedback verdict', () => {
    it('offers exactly the moves the server offers, and nothing when it offers none', () => {
        const both = mount(FeedbackVerdict, {
            props: { reportId: 'r1', actions: [{ verb: 'confirm', label: 'It works' }, { verb: 'reopen', label: 'Still a problem' }] },
        });
        expect(both.find('[data-verdict="confirm"]').text()).toBe('It works');
        expect(both.find('[data-verdict="reopen"]').text()).toBe('Still a problem');
        both.unmount();

        const reopenOnly = mount(FeedbackVerdict, { props: { reportId: 'r2', actions: [{ verb: 'reopen', label: 'Still a problem' }] } });
        expect(reopenOnly.find('[data-verdict="confirm"]').exists()).toBe(false);
        reopenOnly.unmount();

        const none = mount(FeedbackVerdict, { props: { reportId: 'r3', actions: [] } });
        expect(none.find('[data-feedback-verdict]').exists()).toBe(false);
        none.unmount();
    });

    it('posts It works at once, to the report it belongs to', async () => {
        const wrapper = mount(FeedbackVerdict, { props: { reportId: 'r1', actions: [{ verb: 'confirm', label: 'It works' }] } });

        await wrapper.find('[data-verdict="confirm"]').trigger('click');

        expect(mocks.posts).toEqual([{ url: '/feedback/r1/confirm', data: {} }]);
        wrapper.unmount();
    });

    it('refuses Still a problem without a reason, and posts it with one', async () => {
        const wrapper = mount(FeedbackVerdict, { props: { reportId: 'r1', actions: [{ verb: 'reopen', label: 'Still a problem' }] } });

        await wrapper.find('[data-verdict="reopen"]').trigger('click');
        await wrapper.find('form').trigger('submit');

        expect(mocks.posts).toEqual([]);
        expect(wrapper.text()).toContain('Say what is still a problem');

        await wrapper.find('textarea').setValue('It still cuts the last line.');
        await wrapper.find('form').trigger('submit');

        expect(mocks.posts).toHaveLength(1);
        expect(mocks.posts[0].url).toBe('/feedback/r1/reopen');
        expect(mocks.posts[0].data.body).toBe('It still cuts the last line.');
        wrapper.unmount();
    });
});
