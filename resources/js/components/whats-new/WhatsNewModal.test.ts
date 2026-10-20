import { mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick } from 'vue';
import type { WhatsNewRelease } from '@/types/inertia';

/**
 * M181 (`R-c7247dbf`, `D126` A) — the What's new prompt. It opens on the first page loaded while the shared `whatsNew`
 * prop carries releases this person has not seen (for someone signing in, the page they land on), lists them newest
 * first, and ANY close — Got it, the X, Escape, a click outside — closes it at once and records the newest version
 * shown, exactly once. Rendered in place (`teleport: false` falls through to the `MdsModal` root), as
 * `ConflictDialog.test.ts` does.
 */

const mocks = vi.hoisted(() => ({
    page: { component: 'Dashboard', url: '/dashboard', props: {} as Record<string, unknown> },
    live: null as null | { component: string; url: string; props: Record<string, unknown> },
    post: vi.fn(),
    visit: vi.fn(),
}));

vi.mock('@inertiajs/vue3', async () => {
    const { reactive } = await import('vue');
    mocks.live = reactive(mocks.page);
    return {
        usePage: () => mocks.live,
        router: { post: mocks.post, visit: mocks.visit },
        Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
    };
});

const WhatsNewModal = (await import('./WhatsNewModal.vue')).default;

function release(version: string, title: string): WhatsNewRelease {
    return {
        version,
        title,
        released_at: '2026-10-20T10:00:00+00:00',
        items: [
            { kind: 'new', text: `Something new in ${version}.` },
            { kind: 'fixed', text: `Something fixed in ${version}.` },
        ],
    };
}

function mountPrompt(entries: WhatsNewRelease[] | null): VueWrapper {
    mocks.live!.props = { whatsNew: entries === null ? null : { entries } };

    return mount(WhatsNewModal, { attrs: { teleport: false }, attachTo: document.body });
}

beforeEach(() => {
    mocks.post.mockReset();
    mocks.visit.mockReset();
});

afterEach(() => {
    document.body.innerHTML = '';
});

describe('WhatsNewModal — when it opens', () => {
    it('opens with the releases this person has not seen, newest first, titled by the newest', () => {
        const wrapper = mountPrompt([release('0.10.0', 'Grids'), release('0.9.0', 'Version numbers')]);

        expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
        expect(wrapper.get('.mds-modal__title').text()).toBe("What's new in Meridian 0.10.0");
        expect(wrapper.findAll('.release__title').map((h) => h.text())).toEqual(['Grids', 'Version numbers']);
        expect(wrapper.text()).toContain('Something new in 0.10.0.');
        expect(wrapper.text()).toContain('Fixed');
    });

    it('stays closed when there is nothing new', () => {
        expect(mountPrompt(null).find('[role="dialog"]').exists()).toBe(false);
    });
});

describe('WhatsNewModal — any close marks it seen (D126 A)', () => {
    async function closesAndRecords(close: (wrapper: VueWrapper) => Promise<void>): Promise<void> {
        const wrapper = mountPrompt([release('0.10.0', 'Grids'), release('0.9.0', 'Version numbers')]);

        await close(wrapper);
        await nextTick();

        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(mocks.post.mock.calls[0][0]).toBe('/whats-new/seen');
        expect(mocks.post.mock.calls[0][1]).toEqual({ version: '0.10.0' });
    }

    it('records the newest version on Got it', async () => {
        await closesAndRecords(async (w) => {
            await w.findAll('button').find((b) => b.text() === 'Got it')!.trigger('click');
        });
    });

    it('records it on the close control', async () => {
        await closesAndRecords(async (w) => {
            await w.get('button[aria-label="Close"]').trigger('click');
        });
    });

    it('records it on Escape', async () => {
        await closesAndRecords(async (w) => {
            await w.get('[role="dialog"]').trigger('keydown', { key: 'Escape' });
        });
    });

    it('records it on a click outside', async () => {
        await closesAndRecords(async (w) => {
            await w.get('.mds-modal__backdrop').trigger('mousedown');
        });
    });

    it('records it once, however many ways it is closed', async () => {
        const wrapper = mountPrompt([release('0.9.0', 'Version numbers')]);

        await wrapper.findAll('button').find((b) => b.text() === 'Got it')!.trigger('click');
        mocks.live!.props = { whatsNew: { entries: [release('0.9.0', 'Version numbers')] } };
        await nextTick();

        expect(mocks.post).toHaveBeenCalledTimes(1);
    });

    it('opens the full list only once the close is recorded, so the visit cannot cancel it', async () => {
        const wrapper = mountPrompt([release('0.9.0', 'Version numbers')]);

        await wrapper.findAll('button').find((b) => b.text() === 'See all changes')!.trigger('click');

        expect(mocks.post).toHaveBeenCalledTimes(1);
        expect(mocks.visit).not.toHaveBeenCalled();
        mocks.post.mock.calls[0][2].onFinish();
        expect(mocks.visit).toHaveBeenCalledWith('/whats-new');
    });
});
