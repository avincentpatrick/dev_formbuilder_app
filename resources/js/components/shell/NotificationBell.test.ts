import { mount, type VueWrapper } from '@vue/test-utils';
import { ref } from 'vue';
import { beforeEach, describe, expect, it, vi } from 'vitest';
import type { NotificationRow } from '@/components/notifications/types';

/**
 * The bell's MARKUP and ACCESSIBILITY (Increment I4) — the feed engine has its own suite, so this one
 * mocks the composable and never touches a timer.
 *
 * What needs proving here is the set of things a screenshot cannot show and axe cannot infer:
 *  · the unread count reaches a screen reader, not only the eye (WCAG 1.4.1 — a visual-only badge is the
 *    same defect as a colour-only status), and it is the REAL number even when the bubble caps at 9+;
 *  · a row the server could not resolve a destination for renders as text, not as `<a href="">` — which
 *    looks interactive, does nothing, and is exactly what a fixture of only-linkable rows would hide;
 *  · unread is announced as WORDS, because the dot is aria-hidden and colour alone is not a channel;
 *  · opening focuses the dialog rather than "Mark all as read", so the first thing a keyboard user can
 *    press is not the bulk action.
 */
// Only the spies are hoisted. `vi.hoisted` runs BEFORE the `vue` import, so a `ref()` in there throws
// "Cannot access '__vi_import_1__' before initialization" — the feed object is assembled below and read
// lazily by the factory, which is not called until the SFC is imported at the bottom of this block.
const mocks = vi.hoisted(() => ({
    feed: null as unknown as Record<string, unknown>,
    wake: vi.fn(),
    markRead: vi.fn(),
    markAllRead: vi.fn(),
}));

vi.mock('@/composables/useNotificationFeed', () => ({ useNotificationFeed: () => mocks.feed }));

vi.mock('@inertiajs/vue3', () => ({
    Link: { name: 'Link', props: ['href'], template: '<a :href="href"><slot /></a>' },
}));

const items = ref<NotificationRow[]>([]);
const unreadCount = ref(0);
const hasLoaded = ref(true);
const stale = ref(false);

mocks.feed = {
    items,
    unreadCount,
    hasLoaded,
    stale,
    wake: mocks.wake,
    markRead: mocks.markRead,
    markAllRead: mocks.markAllRead,
};

// Imported AFTER the mocks so the component resolves them.
const NotificationBell = (await import('./NotificationBell.vue')).default;

function row(overrides: Partial<NotificationRow> = {}): NotificationRow {
    return {
        id: 'n1',
        type: 'submission_received',
        title: 'New submission',
        description: 'A new response arrived on Clinic Intake.',
        url: '/submissions/s1',
        action_label: 'View submission',
        read_at: null,
        created_at: '2026-08-06T11:00:00Z',
        ...overrides,
    };
}

function render(): VueWrapper {
    return mount(NotificationBell);
}

async function open(wrapper: VueWrapper): Promise<void> {
    await wrapper.get('.bell__trigger').trigger('click');
}

beforeEach(() => {
    items.value = [];
    unreadCount.value = 0;
    hasLoaded.value = true;
    stale.value = false;
    mocks.wake.mockClear();
    mocks.markRead.mockClear();
    mocks.markAllRead.mockClear();
});

describe('the trigger', () => {
    it('hides the bubble at zero and names itself plainly', () => {
        const wrapper = render();

        expect(wrapper.find('.bell__count').exists()).toBe(false);
        expect(wrapper.get('.bell__trigger').attributes('aria-label')).toBe('Notifications');
        wrapper.unmount();
    });

    it('carries the unread count in the ACCESSIBLE NAME, not only in the bubble', () => {
        unreadCount.value = 3;
        const wrapper = render();

        expect(wrapper.get('.bell__count').text()).toBe('3');
        expect(wrapper.get('.bell__trigger').attributes('aria-label')).toBe('Notifications, 3 unread');
        wrapper.unmount();
    });

    it('caps the bubble at 9+ while the accessible name keeps the real number', () => {
        unreadCount.value = 214;
        const wrapper = render();

        // Three glyphs would push the bubble wider than the 40px trigger and, under the extra-large text
        // scale, into the account menu beside it. The exact figure is not lost — it is in the name.
        expect(wrapper.get('.bell__count').text()).toBe('9+');
        expect(wrapper.get('.bell__trigger').attributes('aria-label')).toBe('Notifications, 214 unread');
        wrapper.unmount();
    });

    it('hides the bubble from assistive tech, so the count is not announced twice', () => {
        unreadCount.value = 3;
        const wrapper = render();

        expect(wrapper.get('.bell__count').attributes('aria-hidden')).toBe('true');
        wrapper.unmount();
    });
});

describe('the popover', () => {
    it('opens as a dialog named by its own visible heading', async () => {
        const wrapper = render();
        await open(wrapper);

        const dialog = wrapper.get('[role="dialog"]');
        expect(dialog.attributes('aria-labelledby')).toBe('notification-popover-title');
        expect(wrapper.get('#notification-popover-title').text()).toBe('Notifications');
        wrapper.unmount();
    });

    it('refetches on open, because opening the bell is a sign of a human', async () => {
        const wrapper = render();
        await open(wrapper);

        expect(mocks.wake).toHaveBeenCalled();
        wrapper.unmount();
    });

    it('shows a spinner before the first load settles, not an empty state', async () => {
        hasLoaded.value = false;
        const wrapper = render();
        await open(wrapper);

        expect(wrapper.find('.bell__loading').exists()).toBe(true);
        expect(wrapper.find('.bell__list').exists()).toBe(false);
        wrapper.unmount();
    });

    it('shows the empty state once the feed is known to be empty', async () => {
        const wrapper = render();
        await open(wrapper);

        expect(wrapper.find('.bell__empty').exists()).toBe(true);
        wrapper.unmount();
    });

    it('keeps the rows on screen and says so when a refresh failed', async () => {
        items.value = [row()];
        stale.value = true;
        const wrapper = render();
        await open(wrapper);

        expect(wrapper.findAll('.bell__row')).toHaveLength(1);
        expect(wrapper.get('.bell__stale').attributes('role')).toBe('status');
        wrapper.unmount();
    });

    it('makes the scroll container focusable, for a feed with no linkable row', async () => {
        // axe `scrollable-region-focusable` (WCAG 2.1.1): an all-read, all-unlinkable feed has no focusable
        // descendant, so without this the scroller is unreachable by keyboard.
        items.value = [row({ url: null, read_at: '2026-08-06T11:05:00Z' })];
        const wrapper = render();
        await open(wrapper);

        expect(wrapper.get('.bell__list').attributes('tabindex')).toBe('0');
        expect(wrapper.get('.bell__list').attributes('role')).toBe('list');
        wrapper.unmount();
    });

    it('offers Mark all as read only when something is unread', async () => {
        const wrapper = render();
        await open(wrapper);
        expect(wrapper.get('.bell__head button').attributes('disabled')).toBeDefined();
        wrapper.unmount();

        unreadCount.value = 2;
        const withUnread = render();
        await open(withUnread);
        expect(withUnread.get('.bell__head button').attributes('disabled')).toBeUndefined();
        withUnread.unmount();
    });

    it('links to the preferences card, which is the only way to find it from here', async () => {
        const wrapper = render();
        await open(wrapper);

        expect(wrapper.get('.bell__prefs').attributes('href')).toBe('/settings');
        wrapper.unmount();
    });
});

describe('a row', () => {
    it('links a row whose url is set, and that href starts with a slash', async () => {
        items.value = [row()];
        const wrapper = render();
        await open(wrapper);

        // The server joins the slash, once. Without it `<Link href="submissions/s1">` resolves RELATIVE to
        // the current URL — right on /dashboard, wrong on /forms/{id}/builder, identical in a screenshot.
        const href = wrapper.get('.bell__link').attributes('href');
        expect(href).toBe('/submissions/s1');
        expect(href?.startsWith('/')).toBe(true);
        wrapper.unmount();
    });

    it('renders a null-url row as a non-link, printing neither null nor undefined', async () => {
        items.value = [row({ url: null })];
        const wrapper = render();
        await open(wrapper);

        expect(wrapper.find('.bell__link').exists()).toBe(false);
        expect(wrapper.get('.bell__plain').text()).toContain('New submission');
        expect(wrapper.text()).not.toContain('null');
        expect(wrapper.text()).not.toContain('undefined');
        wrapper.unmount();
    });

    it('suppresses the action label on a row with nowhere to go', async () => {
        items.value = [row({ url: null })];
        const wrapper = render();
        await open(wrapper);

        expect(wrapper.text()).not.toContain('View submission');
        wrapper.unmount();
    });

    it('announces unread as TEXT, not only as a dot', async () => {
        items.value = [row()];
        const wrapper = render();
        await open(wrapper);

        expect(wrapper.get('.bell__sr').text()).toBe('Unread.');
        expect(wrapper.get('.bell__dot').attributes('aria-hidden')).toBe('true');
        wrapper.unmount();
    });

    it('drops the unread text and the mark-read control once a row is read', async () => {
        items.value = [row({ read_at: '2026-08-06T11:05:00Z' })];
        const wrapper = render();
        await open(wrapper);

        expect(wrapper.find('.bell__sr').exists()).toBe(false);
        expect(wrapper.find('.mds-icon-button').exists()).toBe(false);
        wrapper.unmount();
    });

    it('gives the mark-read control a name that says WHICH row it clears', async () => {
        items.value = [row()];
        const wrapper = render();
        await open(wrapper);

        expect(wrapper.get('.mds-icon-button').attributes('aria-label')).toBe('Mark as read: New submission');
        wrapper.unmount();
    });

    it('marks one row read on the check button, and STAYS OPEN so several can be cleared', async () => {
        items.value = [row()];
        const wrapper = render();
        await open(wrapper);

        await wrapper.get('.mds-icon-button').trigger('click');

        expect(mocks.markRead).toHaveBeenCalledWith('n1');
        expect(wrapper.find('[role="dialog"]').exists()).toBe(true);
        wrapper.unmount();
    });

    it('marks a row read on following its link, and CLOSES', async () => {
        // Leaving the popover open would park it over the very page the user just asked to see.
        items.value = [row()];
        const wrapper = render();
        await open(wrapper);

        await wrapper.get('.bell__link').trigger('click');

        expect(mocks.markRead).toHaveBeenCalledWith('n1');
        expect(wrapper.find('[role="dialog"]').exists()).toBe(false);
        wrapper.unmount();
    });

    it('renders the server’s words, never re-deriving them', async () => {
        items.value = [row({ title: 'Export failed', description: 'Storage quota is full.' })];
        const wrapper = render();
        await open(wrapper);

        expect(wrapper.get('.bell__row-title').text()).toBe('Export failed');
        expect(wrapper.get('.bell__desc').text()).toBe('Storage quota is full.');
        wrapper.unmount();
    });

    it('falls back to a neutral glyph for a type the client has never heard of', async () => {
        items.value = [row({ type: 'submission_teleported' })];
        const wrapper = render();
        await open(wrapper);

        expect(wrapper.get('.bell__chip').classes()).toContain('bell__chip--neutral');
        wrapper.unmount();
    });
});
