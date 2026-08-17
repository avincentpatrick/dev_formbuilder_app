import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h, ref, type Ref } from 'vue';
import { afterEach, describe, expect, it } from 'vitest';
import { useInertBackground } from './useInertBackground';
import { openModalCount } from './inert-stack';

/**
 * `useInertBackground` (J4b) — the sequence `MdsModal` has owned since I10a, extracted for surfaces that
 * need the same guarantee without being dialogs.
 *
 * Everything here fails silently in production. A page left inert has no error, no warning and no visual
 * symptom until a keyboard user discovers they cannot reach anything; focus restored before the release
 * is a no-op that strands the user on `<body>`. So the cases are about ORDER and CLEANUP rather than about
 * rendering.
 */

function harness(initialFocus?: string) {
    const active = ref(false);

    const Host = defineComponent({
        setup() {
            const root = ref<HTMLElement | null>(null);
            useInertBackground({ active, root, initialFocus });
            return () =>
                h('div', { class: 'shell' }, [
                    h('main', { class: 'background' }, [h('button', { class: 'behind' }, 'Behind')]),
                    h('div', { ref: root, class: 'surface', tabindex: '-1' }, [
                        h('a', { class: 'first', href: '#one' }, 'One'),
                        h('a', { class: 'second', href: '#two' }, 'Two'),
                    ]),
                ]);
        },
    });

    const wrapper = mount(Host, { attachTo: document.body });
    return { active, wrapper };
}

afterEach(() => {
    document.body.innerHTML = '';
});

describe('useInertBackground — taking and releasing the page', () => {
    it('marks the background inert while active and releases every mark on the way out', async () => {
        const { active, wrapper } = harness();
        const background = document.querySelector('.background') as HTMLElement;

        expect(background.hasAttribute('inert')).toBe(false);

        active.value = true;
        await flushPromises();
        // ⭐ THESE TWO LINES ARE ONE ASSERTION, AND THE PAIR IS THE POINT (J6). The `inert` mark proves the
        // root IS on the stack; the zero proves it is not counted as a DIALOG. Either alone is vacuous —
        // `toBe(0)` would also hold if nothing had been pushed at all, which is the version of this test
        // that would have let the regression back in. Until J6 this read `toBe(1)`, and that number is what
        // made ⌘K a dead key whenever the drawer was open.
        expect(background.hasAttribute('inert')).toBe(true);
        expect(openModalCount()).toBe(0);

        active.value = false;
        await flushPromises();
        expect(background.hasAttribute('inert')).toBe(false);
        expect(openModalCount()).toBe(0);

        wrapper.unmount();
    });

    it('never marks the surface’s own descendants', async () => {
        // ⭐ THE ASSERTION THAT REFUTES THE FILED BLOCKER. The backlog row for the mobile drawer claimed
        // this stack could not be reused because the walk "would mark its own siblings within the shell".
        // It would not: the walk visits ancestor SIBLINGS, and everything inside the pushed root is on the
        // path, so a scrim rendered as the root's child is never touched and keeps its pointer events.
        const { active, wrapper } = harness();

        active.value = true;
        await flushPromises();

        const surface = document.querySelector('.surface') as HTMLElement;
        expect(surface.hasAttribute('inert')).toBe(false);
        expect(surface.closest('[inert]')).toBeNull();
        expect(surface.querySelector('[inert]')).toBeNull();

        active.value = false;
        await flushPromises();
        wrapper.unmount();
    });

    it('takes the page when it mounts already active', async () => {
        // The immediate watch. Two call sites in this repo open on mount, and without it they would render
        // over a fully reachable page.
        const active = ref(true);
        const Host = defineComponent({
            setup() {
                const root = ref<HTMLElement | null>(null);
                useInertBackground({ active: active as Ref<boolean>, root });
                return () =>
                    h('div', {}, [
                        h('main', { class: 'background' }, 'Behind'),
                        h('div', { ref: root, class: 'surface', tabindex: '-1' }, [
                            h('a', { href: '#one' }, 'One'),
                        ]),
                    ]);
            },
        });

        const wrapper = mount(Host, { attachTo: document.body });
        await flushPromises();

        expect((document.querySelector('.background') as HTMLElement).hasAttribute('inert')).toBe(true);
        wrapper.unmount();
    });

    it('leaves the page alone when active flips true then false inside one tick', async () => {
        // ⭐ Without re-reading `active` inside the nextTick, this pushes a root nothing wants back and the
        // page stays inert forever — with no error and nothing on screen to explain it.
        const { active, wrapper } = harness();

        active.value = true;
        active.value = false;
        await flushPromises();

        expect((document.querySelector('.background') as HTMLElement).hasAttribute('inert')).toBe(false);
        expect(openModalCount()).toBe(0);

        wrapper.unmount();
    });
});

describe('useInertBackground — focus management, which is not a trap', () => {
    it('moves focus inside on take and restores the opener on release, in that order', async () => {
        // ⭐ THE ORDER IS THE ASSERTION. Restoring focus before `popModalRoot` targets an element that is
        // still inert, where `.focus()` is a silent no-op — so the reader is left on `<body>` with the
        // surface gone. Checking the opener holds focus AFTER release is what pins the sequence.
        const opener = document.createElement('button');
        document.body.appendChild(opener);
        opener.focus();

        const { active, wrapper } = harness();

        active.value = true;
        await flushPromises();
        expect(document.activeElement).toBe(document.querySelector('.first'));

        active.value = false;
        await flushPromises();
        expect(document.activeElement).toBe(opener);

        wrapper.unmount();
        opener.remove();
    });

    it('honours a designated target inside the surface', async () => {
        const { active, wrapper } = harness('.second');

        active.value = true;
        await flushPromises();

        expect(document.activeElement).toBe(document.querySelector('.second'));

        active.value = false;
        await flushPromises();
        wrapper.unmount();
    });

    it('falls back rather than throwing on an invalid selector', async () => {
        // ⭐ An invalid selector raises a DOMException instead of returning null, and this runs inside
        // nextTick — which does not route through Vue's error handler. Unguarded, the throw escapes as an
        // unhandled rejection and focus never moves at all, on a page that is ALREADY inert.
        const { active, wrapper } = harness('[');

        active.value = true;
        await flushPromises();

        expect(document.activeElement).toBe(document.querySelector('.first'));

        active.value = false;
        await flushPromises();
        wrapper.unmount();
    });

    it('never lets a selector outside the surface pull focus away', async () => {
        const { active, wrapper } = harness('.behind');

        active.value = true;
        await flushPromises();

        // `.behind` lives in the background, so the query — scoped to the root — finds nothing and the
        // fallback keeps focus inside.
        expect(document.activeElement).toBe(document.querySelector('.first'));

        active.value = false;
        await flushPromises();
        wrapper.unmount();
    });
});

describe('useInertBackground — cleanup', () => {
    it('releases the page when the component unmounts while active', async () => {
        // A leaked `inert` in the shared test document blanks the NEXT spec file's assertions, and in the
        // application it strands a page whose drawer was unmounted mid-navigation.
        const { active, wrapper } = harness();
        const background = document.querySelector('.background') as HTMLElement;

        active.value = true;
        await flushPromises();
        expect(background.hasAttribute('inert')).toBe(true);

        wrapper.unmount();
        expect(background.hasAttribute('inert')).toBe(false);
        expect(openModalCount()).toBe(0);
    });
});
