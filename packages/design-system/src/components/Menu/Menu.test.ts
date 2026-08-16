import { flushPromises, mount } from '@vue/test-utils';
import { readFileSync } from 'node:fs';
import { join } from 'node:path';
import { h, markRaw } from 'vue';
import { afterEach, describe, expect, it, vi } from 'vitest';
import Menu, { type MenuItem } from './Menu.vue';

/**
 * MdsMenu (DSR §3.4b, J4b).
 *
 * The centre of gravity here is the ARIA fix. The implementation this extracted put non-menuitem content
 * inside `role="menu"`, which renders identically, passes every visual check, and quietly makes that
 * content unreachable to a screen reader in application mode. Nothing in the application tree could see it:
 * an app-tree component gets no Storybook story and therefore no accessibility scan, and no end-to-end spec
 * ever opened the menu. The case below is the first thing that has ever looked.
 */

const ITEMS: MenuItem[] = [
    { id: 'settings', label: 'Settings', icon: 'settings', href: '/settings' },
    { id: 'logout', label: 'Log out', icon: 'logout' },
];

function mountMenu(props: Record<string, unknown> = {}, slots: Record<string, string> = {}) {
    return mount(Menu, {
        attachTo: document.body,
        props: { items: ITEMS, triggerLabel: 'Account menu', label: 'Account', ...props },
        slots: { trigger: '<span>AV</span>', ...slots },
    });
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('MdsMenu — the disclosure contract', () => {
    it('advertises the popup and tracks its state in both directions', async () => {
        const wrapper = mountMenu();
        const trigger = wrapper.get('button.mds-menu__trigger');

        expect(trigger.attributes('aria-haspopup')).toBe('menu');
        expect(trigger.attributes('aria-expanded')).toBe('false');
        expect(trigger.attributes('aria-label')).toBe('Account menu');

        await trigger.trigger('click');
        expect(trigger.attributes('aria-expanded')).toBe('true');

        await trigger.trigger('click');
        expect(trigger.attributes('aria-expanded')).toBe('false');
    });

    it('renders nothing at all until it is opened', () => {
        const wrapper = mountMenu();
        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
        expect(wrapper.find('.mds-menu__panel').exists()).toBe(false);
    });

    it('moves focus to the first item on open', async () => {
        const wrapper = mountMenu();
        await wrapper.get('button.mds-menu__trigger').trigger('click');
        await flushPromises();

        expect(document.activeElement).toBe(wrapper.findAll('[role="menuitem"]')[0]?.element);
    });
});

describe('MdsMenu — a menu owns only menuitems', () => {
    it('keeps the header OUTSIDE role="menu", and describes the menu with it', async () => {
        // ⭐ THE J4b ARIA FIX, AND THE MUTATION IS THE CODE THIS REPLACED. Moving the header back inside
        // `role="menu"` renders pixel-identically and passes every other case in this file. It is wrong
        // because a menu's children must all be menuitem/-radio/-checkbox or group: a screen reader in
        // application mode walks the items and never announces a stray div, so the signed-in identity was
        // unreachable to exactly the readers who most need it. `aria-describedby` announces it on entry.
        const wrapper = mountMenu({}, { header: '<p>ada@example.test</p>' });
        await wrapper.get('button.mds-menu__trigger').trigger('click');

        const menu = wrapper.get('[role="menu"]').element;
        expect(Array.from(menu.children).every((c) => c.getAttribute('role') === 'menuitem')).toBe(true);

        const header = wrapper.get('.mds-menu__header').element;
        expect(menu.contains(header)).toBe(false);
        expect(wrapper.get('.mds-menu__panel').element.contains(header)).toBe(true);
        expect(wrapper.get('[role="menu"]').attributes('aria-describedby')).toBe(header.id);
        expect(header.id).toBeTruthy();
    });

    it('carries no dangling describedby when there is no header', async () => {
        const wrapper = mountMenu();
        await wrapper.get('button.mds-menu__trigger').trigger('click');

        expect(wrapper.get('[role="menu"]').attributes('aria-describedby')).toBeUndefined();
        expect(wrapper.find('.mds-menu__header').exists()).toBe(false);
    });

    it('names the menu, and makes every item a roving stop rather than a tab stop', async () => {
        const wrapper = mountMenu();
        await wrapper.get('button.mds-menu__trigger').trigger('click');

        expect(wrapper.get('[role="menu"]').attributes('aria-label')).toBe('Account');
        for (const item of wrapper.findAll('[role="menuitem"]')) {
            expect(item.attributes('tabindex')).toBe('-1');
        }
    });
});

describe('MdsMenu — keyboard, per the APG menu pattern', () => {
    async function opened() {
        const wrapper = mountMenu();
        await wrapper.get('button.mds-menu__trigger').trigger('click');
        await flushPromises();
        return wrapper;
    }

    it('moves with the arrows and wraps at both ends', async () => {
        const wrapper = await opened();
        const menu = wrapper.get('[role="menu"]');
        const items = wrapper.findAll('[role="menuitem"]');

        await menu.trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement).toBe(items[1]?.element);

        await menu.trigger('keydown', { key: 'ArrowDown' });
        expect(document.activeElement).toBe(items[0]?.element);

        await menu.trigger('keydown', { key: 'ArrowUp' });
        expect(document.activeElement).toBe(items[1]?.element);
    });

    it('jumps to the ends with Home and End', async () => {
        const wrapper = await opened();
        const menu = wrapper.get('[role="menu"]');
        const items = wrapper.findAll('[role="menuitem"]');

        await menu.trigger('keydown', { key: 'End' });
        expect(document.activeElement).toBe(items[1]?.element);

        await menu.trigger('keydown', { key: 'Home' });
        expect(document.activeElement).toBe(items[0]?.element);
    });

    it('closes on Escape, returns focus to the trigger, and never reaches an ANCESTOR handler', async () => {
        // ⭐ THE ORDERING CASE, AND ONLY AN ANCESTOR SPY CAN TELL THE TWO IMPLEMENTATIONS APART. MdsModal
        // binds Escape on its own panel, which wraps any menu opened inside a dialog. A document-level
        // bubble-phase handler — which is what the account menu and the application's dismissal composable
        // both use — is the LAST thing to see the event, so the dialog would close first and the
        // `stopPropagation` written to prevent exactly that would never run: the user loses the whole task
        // instead of a two-item popup. Binding on the menu's own root is what puts this handler ahead of
        // the wrapper. A document-level spy passes against BOTH versions and proves nothing.
        const ancestor = vi.fn();
        const host = document.createElement('div');
        host.addEventListener('keydown', ancestor);
        document.body.appendChild(host);

        const wrapper = mount(Menu, {
            attachTo: host,
            props: { items: ITEMS, triggerLabel: 'Account menu', label: 'Account' },
            slots: { trigger: '<span>AV</span>' },
        });
        await wrapper.get('button.mds-menu__trigger').trigger('click');
        await flushPromises();

        await wrapper.findAll('[role="menuitem"]')[0]?.trigger('keydown', { key: 'Escape' });

        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
        expect(document.activeElement).toBe(wrapper.get('button.mds-menu__trigger').element);
        expect(ancestor).not.toHaveBeenCalled();

        wrapper.unmount();
        host.remove();
    });

    it('leaves Escape alone while closed, so a dialog behind it still receives one', async () => {
        // The mirror of the case above. Consuming Escape unconditionally would make a closed menu anywhere
        // on the page swallow the key the dialog around it needs.
        const ancestor = vi.fn();
        const host = document.createElement('div');
        host.addEventListener('keydown', ancestor);
        document.body.appendChild(host);

        const wrapper = mount(Menu, {
            attachTo: host,
            props: { items: ITEMS, triggerLabel: 'Account menu', label: 'Account' },
            slots: { trigger: '<span>AV</span>' },
        });
        await wrapper.get('button.mds-menu__trigger').trigger('keydown', { key: 'Escape' });

        expect(ancestor).toHaveBeenCalledTimes(1);

        wrapper.unmount();
        host.remove();
    });

    it('closes on Tab WITHOUT preventing it, so the sequence continues into the page', async () => {
        const wrapper = await opened();
        const event = new KeyboardEvent('keydown', { key: 'Tab', bubbles: true, cancelable: true });
        wrapper.get('[role="menu"]').element.dispatchEvent(event);
        await flushPromises();

        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
        expect(event.defaultPrevented).toBe(false);
    });

    it('closes on an outside pointerdown WITHOUT stealing focus', async () => {
        // The click should land where the user aimed it. Returning focus to the trigger here would yank it
        // back from whatever they were reaching for.
        const outside = document.createElement('button');
        document.body.appendChild(outside);

        const wrapper = await opened();
        outside.focus();
        document.dispatchEvent(new Event('pointerdown', { bubbles: true }));
        await flushPromises();

        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
        expect(document.activeElement).toBe(outside);
        outside.remove();
    });

    it('removes its document listener on unmount', async () => {
        // A leaked capture-phase pointerdown listener in the shared happy-dom document closes menus that
        // belong to the NEXT spec file, and the failure surfaces somewhere unrelated.
        const remove = vi.spyOn(document, 'removeEventListener');
        const wrapper = await opened();
        wrapper.unmount();

        expect(remove).toHaveBeenCalledWith('pointerdown', expect.any(Function), true);
        remove.mockRestore();
    });
});

describe('MdsMenu — heterogeneous items', () => {
    it('hands a link item its href as a real PROP, through the injected component', async () => {
        // ⭐ Bound as a fall-through ATTRIBUTE the anchor looks perfect and silently stops being a client
        // visit — every page in the application would start doing full document loads from this menu, and
        // nothing would look broken. MdsBreadcrumb's suite pins the same direction for the same reason.
        const seen: Record<string, unknown>[] = [];
        const Stub = markRaw({
            props: { href: { type: String, required: true } },
            setup(props: { href: string }) {
                seen.push(props);
                return () => null;
            },
        });

        const wrapper = mountMenu({ linkComponent: Stub });
        await wrapper.get('button.mds-menu__trigger').trigger('click');

        expect(seen).toHaveLength(1);
        expect(seen[0]?.href).toBe('/settings');
    });

    it('defaults the link component to a plain anchor, so a router-less Storybook renders', async () => {
        const wrapper = mountMenu();
        await wrapper.get('button.mds-menu__trigger').trigger('click');

        const link = wrapper.findAll('[role="menuitem"]')[0];
        expect(link?.element.tagName).toBe('A');
        expect(link?.attributes('href')).toBe('/settings');
    });

    it('renders a non-link item as a real button and emits its id once', async () => {
        const wrapper = mountMenu();
        await wrapper.get('button.mds-menu__trigger').trigger('click');

        const action = wrapper.findAll('[role="menuitem"]')[1];
        expect(action?.element.tagName).toBe('BUTTON');
        expect(action?.attributes('type')).toBe('button');

        await action?.trigger('click');
        expect(wrapper.emitted('select')).toEqual([['logout']]);
    });

    it('closes and returns focus when any item is activated', async () => {
        const wrapper = mountMenu();
        await wrapper.get('button.mds-menu__trigger').trigger('click');
        await wrapper.findAll('[role="menuitem"]')[1]?.trigger('click');

        expect(wrapper.find('[role="menu"]').exists()).toBe(false);
        expect(document.activeElement).toBe(wrapper.get('button.mds-menu__trigger').element);
    });

    it('marks a disabled item aria-disabled, keeps it in the ring, and refuses its activation', async () => {
        // ⭐ The native attribute would remove it from the arrow-key ring and from the accessibility tree,
        // taking any explanation of WHY it is disabled with it — the rule DSR §3.4a states in full. But
        // aria-disabled is advisory, so the click still arrives and the handler must refuse it; an
        // implementation that only sets the attribute emits `select` and looks completely correct.
        const wrapper = mountMenu({
            items: [ITEMS[0], { id: 'delete', label: 'Delete account', disabled: true, tone: 'danger' }],
        });
        await wrapper.get('button.mds-menu__trigger').trigger('click');

        const item = wrapper.findAll('[role="menuitem"]')[1];
        expect(item?.attributes('aria-disabled')).toBe('true');
        expect(item?.attributes('disabled')).toBeUndefined();
        expect(wrapper.findAll('[role="menuitem"]')).toHaveLength(2);

        await item?.trigger('click');
        expect(wrapper.emitted('select')).toBeUndefined();
        expect(wrapper.find('[role="menu"]').exists()).toBe(true);
    });
});

describe('MdsMenu — a disabled item cannot navigate', () => {
    it('renders a disabled item with an href as a BUTTON, never through the link component', async () => {
        // ⭐ FOUND BY THE ADVERSARIAL PASS. `preventDefault` in the click handler is not enough on its own:
        // the injected link component brings its OWN click handler, and Vue merges a fall-through handler
        // after the component's — so an Inertia visit is already issued by the time this component's guard
        // runs. The row would navigate while announcing itself unavailable, and the menu would stay open
        // because the guard returns before `close`. Rendering a plain button removes the navigation rather
        // than trying to out-run it.
        const visited: string[] = [];
        const Stub = markRaw({
            props: { href: { type: String, required: true } },
            setup(props: { href: string }) {
                return () => h('a', { href: props.href, onClick: () => visited.push(props.href) }, 'x');
            },
        });

        const wrapper = mountMenu({
            linkComponent: Stub,
            items: [{ id: 'transfer', label: 'Transfer ownership', href: '/transfer', disabled: true }],
        });
        await wrapper.get('button.mds-menu__trigger').trigger('click');

        const item = wrapper.findAll('[role="menuitem"]')[0];
        expect(item?.element.tagName).toBe('BUTTON');
        expect(item?.attributes('href')).toBeUndefined();
        expect(item?.attributes('aria-disabled')).toBe('true');

        await item?.trigger('click');
        expect(visited).toEqual([]);
        expect(wrapper.emitted('select')).toBeUndefined();
    });

    it('still routes an ENABLED item through the link component', async () => {
        // The other direction, so the guard cannot be "fixed" by never using linkComponent at all.
        const wrapper = mountMenu();
        await wrapper.get('button.mds-menu__trigger').trigger('click');

        const link = wrapper.findAll('[role="menuitem"]')[0];
        expect(link?.element.tagName).toBe('A');
        expect(link?.attributes('href')).toBe('/settings');
    });
});

describe('MdsMenu — the containment contract, held in source text', () => {
    const source = readFileSync(
        join(process.cwd(), 'packages/design-system/src/components/Menu/Menu.vue'),
        'utf8',
    );

    // ⚠️ Scoped to the stylesheet. The docblock above discusses `overflow`, `clip` and the very token
    // families asserted here, so a whole-file negative would match the prose written to justify them.
    const stylesheet = source.slice(source.indexOf('<style'));

    const block = (selector: string): string =>
        stylesheet.match(new RegExp(`${selector.replace(/\./g, '\\.')}\\s*\\{([^}]*)\\}`))?.[1] ?? '';

    it('clamps the panel at both ends, because an over-wide one is clipped rather than caught', () => {
        // ⭐ THE GATE-FREE FAILURE. The app shell sets `overflow-x: clip`, which pins the document width
        // flat, so the end-to-end overflow assertion is structurally blind to a panel that runs off the
        // right edge. Content can widen this one — the header holds an email, and the text-size axis scales
        // it by up to a quarter. The clamp IS the control; there is nothing behind it.
        const panel = block('.mds-menu__panel');
        expect(panel).toMatch(/min-width:\s*220px/);
        expect(panel).toMatch(/max-width:\s*calc\(100vw\s*-\s*var\(--mds-space-6\)\)/);
        expect(block('.mds-menu__header')).toMatch(/overflow-wrap:\s*anywhere/);
    });

    it('takes its layer from the scale and its elevation from the popover tier', () => {
        expect(block('.mds-menu__panel')).toMatch(/z-index:\s*var\(--mds-z-index-menu,\s*30\)/);
        expect(block('.mds-menu__panel')).toMatch(/box-shadow:\s*var\(--mds-shadow-3\)/);
        expect(block('.mds-menu__panel')).toMatch(/border-radius:\s*var\(--mds-radius-md\)/);
    });

    it('paints every colour from a token, including the danger tone', () => {
        expect(block('.mds-menu__item--danger')).toMatch(/color:\s*var\(--mds-color-status-danger-fg\)/);
        expect(stylesheet).not.toMatch(/#[0-9a-fA-F]{3,8}\b/);
        expect(stylesheet).not.toMatch(/\brgb\(/);
    });

    it('keeps a visible focus ring on the trigger and on every item', () => {
        expect(stylesheet).toMatch(/\.mds-menu__trigger:focus-visible\s*\{[^}]*outline:\s*2px solid var\(--mds-color-focus-ring\)/);
        expect(stylesheet).toMatch(/\.mds-menu__item:focus-visible\s*\{[^}]*outline:\s*2px solid var\(--mds-color-focus-ring\)/);
    });

    it('hides nothing with the clip idiom, so it needs no containing-block guard', () => {
        expect(stylesheet).not.toContain('clip: rect(0 0 0 0)');
    });
});
