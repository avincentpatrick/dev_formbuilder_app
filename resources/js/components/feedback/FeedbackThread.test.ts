import { mount } from '@vue/test-utils';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import { reactive } from 'vue';
import type { FeedbackComment } from '@/components/feedback/types';

/**
 * A report's thread and its comment box (M179, `R-129c44c3`, `D151`).
 *
 *  · EVERY MESSAGE NAMES WHO WROTE IT, as the server words it — a member, the Support team, a Former member.
 *  · THE BOX IS THE SERVER'S. `canComment` comes from the policy; without it there is no box at all.
 *  · A PICTURE IS NAMED, NEVER SHOWN (ADR-0015 §D9).
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

const FeedbackThread = (await import('./FeedbackThread.vue')).default;

function comment(overrides: Partial<FeedbackComment> = {}): FeedbackComment {
    return {
        id: 'c1',
        author: 'Tess Tester',
        author_kind: 'member',
        action: null,
        body: 'Looks bigger to me.',
        created_at: '2026-10-20T01:00:00+00:00',
        has_screenshot: false,
        ...overrides,
    };
}

beforeEach(() => {
    mocks.posts.length = 0;
});

describe('feedback thread', () => {
    it('names who wrote each message and what a tester\'s move said', () => {
        const wrapper = mount(FeedbackThread, {
            props: {
                reportId: 'r1',
                canComment: false,
                comments: [
                    comment({ id: 'c1', author: 'Support team', author_kind: 'support', body: 'Which tablet?' }),
                    comment({ id: 'c2', author: 'Former member', body: 'Me too.' }),
                    comment({ id: 'c3', action: 'reopened', body: 'Still cut off.', has_screenshot: true }),
                ],
            },
        });

        const items = wrapper.findAll('li');
        expect(items.map((li) => li.find('.fbt__author').text())).toEqual(['Support team', 'Former member', 'Tess Tester']);
        expect(items[2].text()).toContain('said it is still a problem');
        expect(items[2].text()).toContain('Screenshot attached');
        expect(wrapper.find('img').exists()).toBe(false);
        expect(wrapper.find('form').exists()).toBe(false);
        wrapper.unmount();
    });

    it('posts a comment to its report, and refuses an empty one', async () => {
        const wrapper = mount(FeedbackThread, { props: { reportId: 'r9', canComment: true, comments: [] } });

        await wrapper.find('form').trigger('submit');
        expect(mocks.posts).toEqual([]);
        expect(wrapper.text()).toContain('Write a comment first.');

        await wrapper.find('textarea').setValue('It happens on the tablet too.');
        await wrapper.find('form').trigger('submit');

        expect(mocks.posts).toHaveLength(1);
        expect(mocks.posts[0].url).toBe('/feedback/r9/comments');
        expect(mocks.posts[0].data.body).toBe('It happens on the tablet too.');
        wrapper.unmount();
    });
});
