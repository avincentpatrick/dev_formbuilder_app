import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import ConfirmationScreen from '../components/ConfirmationScreen.vue';
import { createApiClient, parseRedirect } from '../lib/api-client';

/**
 * M130 (`R-db169c29`, `D76`) — after the thank-you: the destination, a 20-second count the respondent can stop,
 * and every case in which nothing may count at all. Fake timers, so 20 seconds is a step rather than a wait.
 */
const NEXT = { url: 'https://health.example.org/next', label: 'health.example.org' };

const mounted: VueWrapper[] = [];

function screen(props: Record<string, unknown> = {}) {
    const navigate = vi.fn();
    const wrapper = mount(ConfirmationScreen, {
        props: { reference: '7K4M-2QXB', queueTag: null, message: 'Thanks — your response has been recorded.', redirect: NEXT, navigate, ...props },
        attachTo: document.body,
    });
    mounted.push(wrapper);

    return { wrapper, navigate };
}

async function tick(ms: number): Promise<void> {
    vi.advanceTimersByTime(ms);
    await nextTick();
}

function pageShow(persisted: boolean): void {
    const event = new Event('pageshow');
    Object.defineProperty(event, 'persisted', { value: persisted });
    window.dispatchEvent(event);
}

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    mounted.splice(0).forEach((wrapper) => wrapper.unmount());
    vi.useRealTimers();
});

describe('ConfirmationScreen — after the thank-you (M130, D76)', () => {
    it('shows the thank-you first, names the destination, and goes after 20 seconds', async () => {
        const { wrapper, navigate } = screen();
        await nextTick();

        expect(wrapper.find('h1').text()).toBe('Thanks — your response has been recorded.');
        expect(document.activeElement).toBe(wrapper.find('h1').element);
        expect(wrapper.find('[data-redirect]').text()).toContain('Next: health.example.org');
        expect(wrapper.text()).toContain('Continuing in 20 seconds.');
        // One polite announcement, not a ticking live region.
        expect(wrapper.find('[role="status"]').text()).toBe('Next: health.example.org, in 20 seconds. Choose Stay on this page to remain here.');

        await tick(19_000);
        expect(wrapper.text()).toContain('Continuing in 1 second.');
        expect(navigate).not.toHaveBeenCalled();

        await tick(1_000);
        expect(navigate).toHaveBeenCalledExactlyOnceWith(NEXT.url);
        expect(wrapper.emitted('leave')).toHaveLength(1);
    });

    it('stays when asked: the count stops for good, and focus moves to the link that remains', async () => {
        const { wrapper, navigate } = screen();
        await nextTick();

        await wrapper.findAll('button').find((b) => b.text() === 'Stay on this page')!.trigger('click');
        await nextTick();
        await nextTick();
        await tick(60_000);

        expect(navigate).not.toHaveBeenCalled();
        expect(wrapper.text()).not.toContain('Continuing in');
        expect(wrapper.find('[role="status"]').text()).toBe('You will stay on this page.');
        const link = wrapper.find('a');
        expect(link.attributes('href')).toBe(NEXT.url);
        expect(document.activeElement).toBe(link.element);
    });

    it('goes at once on Continue now, and the count never fires a second time', async () => {
        const { wrapper, navigate } = screen();
        await nextTick();

        await wrapper.find('a').trigger('click');
        await tick(60_000);

        expect(navigate).toHaveBeenCalledExactlyOnceWith(NEXT.url);
        expect(wrapper.emitted('leave')).toHaveLength(1);
    });

    it('never counts in a frame: the link opens the top window and the page does not navigate itself', async () => {
        const { wrapper, navigate } = screen({ framed: true });
        await nextTick();
        await tick(60_000);

        expect(navigate).not.toHaveBeenCalled();
        expect(wrapper.text()).not.toContain('Continuing in');
        expect(wrapper.find('[role="status"]').text()).toBe('Next: health.example.org. Choose Continue now when you are ready.');

        const link = wrapper.find('a');
        expect(link.attributes('target')).toBe('_top');
        await link.trigger('click');
        // The browser follows the link itself; the page only rotates the session first.
        expect(navigate).not.toHaveBeenCalled();
        expect(wrapper.emitted('leave')).toHaveLength(1);
    });

    it('never counts while responses wait to send, and starts once the device reports none', async () => {
        const { wrapper, navigate } = screen({ unsent: 1 });
        await nextTick();
        await tick(60_000);
        expect(navigate).not.toHaveBeenCalled();
        expect(wrapper.text()).not.toContain('Continuing in');

        // The outbox count refreshes a moment after an online submit settles.
        await wrapper.setProps({ unsent: 0 });
        expect(wrapper.text()).toContain('Continuing in 20 seconds.');
        await tick(20_000);
        expect(navigate).toHaveBeenCalledExactlyOnceWith(NEXT.url);
    });

    it('never moves a response that is only queued on the device', async () => {
        const { wrapper, navigate } = screen({ reference: null, queueTag: 'MER-4SAS76' });
        await nextTick();
        await tick(60_000);

        expect(navigate).not.toHaveBeenCalled();
        expect(wrapper.find('[data-redirect]').exists()).toBe(false);
        expect(wrapper.text()).toContain('MER-4SAS76');
    });

    it('stops the count when the respondent starts another response', async () => {
        const { wrapper, navigate } = screen();
        await nextTick();

        await wrapper.findAll('button').find((b) => b.text() === 'Submit another response')!.trigger('click');
        await tick(60_000);

        expect(wrapper.emitted('restart')).toHaveLength(1);
        expect(navigate).not.toHaveBeenCalled();
    });

    it('stops counting the moment the page is hidden', async () => {
        const { navigate } = screen();
        await nextTick();
        await tick(5_000);

        window.dispatchEvent(new Event('pagehide'));
        await tick(60_000);

        expect(navigate).not.toHaveBeenCalled();
    });

    it('keeps the link on a page restored by Back, and never counts again — even when the outbox settles', async () => {
        const { wrapper, navigate } = screen();
        await nextTick();

        window.dispatchEvent(new Event('pagehide'));
        pageShow(true);
        // What would otherwise restart it: the outbox count moving after the page comes back.
        await wrapper.setProps({ unsent: 1 });
        await wrapper.setProps({ unsent: 0 });
        await tick(60_000);

        expect(navigate).not.toHaveBeenCalled();
        expect(wrapper.text()).not.toContain('Continuing in');
        expect(wrapper.find('a').attributes('href')).toBe(NEXT.url);
    });

    it('shows nothing about a destination when there is none', async () => {
        const { wrapper, navigate } = screen({ redirect: null });
        await nextTick();
        await tick(60_000);

        expect(wrapper.find('[data-redirect]').exists()).toBe(false);
        expect(wrapper.find('[role="status"]').text()).toBe('');
        expect(navigate).not.toHaveBeenCalled();
    });
});

describe('parseRedirect — the client side of a navigation sink (M130)', () => {
    const origin = 'http://acme.localhost:8080';

    it('accepts an https destination, and an address on this page\'s own origin', () => {
        expect(parseRedirect({ url: 'https://health.example.org/next', label: 'Next' }, origin)).toEqual({ url: 'https://health.example.org/next', label: 'Next' });
        // A form destination is built from the app's URL, which is plain http on a local stack.
        expect(parseRedirect({ url: 'http://acme.localhost:8080/f/follow-up', label: 'Follow-up' }, origin)).toEqual({
            url: 'http://acme.localhost:8080/f/follow-up',
            label: 'Follow-up',
        });
    });

    it('refuses everything else, which means stay', () => {
        for (const raw of [
            null,
            'https://health.example.org',
            { url: 'javascript:alert(1)', label: 'x' },
            { url: 'http://elsewhere.example/next', label: 'x' },
            { url: 'data:text/html,x', label: 'x' },
            { url: 'https://health.example.org', label: '' },
            { url: 42, label: 'x' },
            { label: 'x' },
        ]) {
            expect(parseRedirect(raw, origin), JSON.stringify(raw)).toBeNull();
        }
    });
});

describe('the destination through the real client (M130)', () => {
    function respond(redirect: unknown): typeof fetch {
        return vi.fn(
            async () =>
                new Response(JSON.stringify({ data: { id: 'sub-1', reference: '7K4M-2QXB', status: 'submitted', redirect } }), {
                    status: 201,
                    headers: { 'Content-Type': 'application/json' },
                }),
        ) as unknown as typeof fetch;
    }

    it('reads the destination off an accepted response', async () => {
        const client = createApiClient({ token: 'tok', slug: 'intake', fetch: respond(NEXT) });

        const result = await client.submit({ answers: {}, clientSubmissionUuid: '0192f1a2-b3c4-7d5e-8f90-0000000000d1', locale: 'en' });

        expect(result.redirect).toEqual(NEXT);
    });

    it('hands on nothing the page may not follow, whatever the response says', async () => {
        const client = createApiClient({ token: 'tok', slug: 'intake', fetch: respond({ url: 'javascript:alert(1)', label: 'x' }) });

        const result = await client.submit({ answers: {}, clientSubmissionUuid: '0192f1a2-b3c4-7d5e-8f90-0000000000d2', locale: 'en' });

        // Absent, which the type reads as null: nowhere to go.
        expect(result.redirect ?? null).toBeNull();
    });
});
