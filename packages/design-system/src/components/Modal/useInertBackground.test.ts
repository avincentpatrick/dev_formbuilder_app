import { flushPromises, mount } from '@vue/test-utils';
import { defineComponent, h, ref, type Ref } from 'vue';
import { afterEach, describe, expect, it } from 'vitest';
import { useInertBackground } from './useInertBackground';
import { openModalCount, popModalRoot, pushModalRoot } from './inert-stack';

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

describe('useInertBackground — a root that changes identity while active (J6)', () => {
    /** Two candidate roots, swapped by a flag, which is the `v-if` shape a real consumer would reach for. */
    function swappable() {
        const active = ref(false);
        const second = ref(false);

        const Host = defineComponent({
            setup() {
                const root = ref<HTMLElement | null>(null);
                useInertBackground({ active, root, initialFocus: '.first' });

                return () =>
                    h('div', { class: 'shell' }, [
                        h('main', { class: 'background' }, [h('button', { class: 'behind' }, 'Behind')]),
                        second.value
                            ? h('div', { ref: root, key: 'b', class: 'surface surface-b', tabindex: '-1' }, [
                                h('a', { class: 'first', href: '#b' }, 'B'),
                            ])
                            : h('div', { ref: root, key: 'a', class: 'surface surface-a', tabindex: '-1' }, [
                                h('a', { class: 'first', href: '#a' }, 'A'),
                            ]),
                    ]);
            },
        });

        return { active, second, wrapper: mount(Host, { attachTo: document.body }) };
    }

    it('hands the page to the new root rather than holding the stale one', async () => {
        // ⭐ THE FINDING. Watching only `active` left the stack pointing at the element that had been
        // replaced, so the inert walk was computed from a detached node — and the LIVE surface, an off-path
        // sibling of the stale one, went inert. Asserting the new surface is reachable is the half that
        // catches it; asserting the background is still inert is what stops the fix from being "release and
        // never re-take".
        const { active, second, wrapper } = swappable();

        active.value = true;
        await flushPromises();
        expect(document.querySelector('.surface-a')?.closest('[inert]')).toBeNull();

        second.value = true;
        await flushPromises();

        const live = document.querySelector('.surface-b') as HTMLElement;
        expect(live).not.toBeNull();
        expect(live.closest('[inert]')).toBeNull();
        expect((document.querySelector('.background') as HTMLElement).hasAttribute('inert')).toBe(true);

        active.value = false;
        await flushPromises();
        expect((document.querySelector('.background') as HTMLElement).hasAttribute('inert')).toBe(false);

        wrapper.unmount();
    });

    it('moves focus into the new root, since the old one is no longer on the page', async () => {
        const { active, second, wrapper } = swappable();

        active.value = true;
        await flushPromises();
        expect(document.activeElement).toBe(document.querySelector('.surface-a .first'));

        second.value = true;
        await flushPromises();
        expect(document.activeElement).toBe(document.querySelector('.surface-b .first'));

        active.value = false;
        await flushPromises();
        wrapper.unmount();
    });

    it('keeps the ORIGINAL opener across the hand-over, not whatever the old root had focused', async () => {
        // ⭐ Why the capture is `??=` rather than `=`. Re-capturing on the second take would make the opener
        // an element INSIDE the previous root, so closing the surface would return the user to the surface.
        const opener = document.createElement('button');
        document.body.appendChild(opener);
        opener.focus();

        const { active, second, wrapper } = swappable();

        active.value = true;
        await flushPromises();

        second.value = true;
        await flushPromises();

        active.value = false;
        await flushPromises();

        expect(document.activeElement).toBe(opener);

        wrapper.unmount();
        opener.remove();
    });

    it('does not re-take, or steal focus, when a re-render keeps the SAME element', async () => {
        // ⭐ The adversarial case for the fix itself: `[active, root]` fires more often than `active` alone,
        // and a naive implementation would re-run initial focus on every patch — yanking the caret out of
        // whatever the user had focused inside the surface.
        const { active, wrapper } = harness();

        active.value = true;
        await flushPromises();

        (document.querySelector('.second') as HTMLElement).focus();
        await wrapper.vm.$forceUpdate();
        await flushPromises();

        expect(document.activeElement).toBe(document.querySelector('.second'));
        expect(openModalCount()).toBe(0);

        active.value = false;
        await flushPromises();
        wrapper.unmount();
    });

    it('does not re-capture an opener on a hand-over just because the first capture found nothing', async () => {
        // ⭐ THE TWO GUARDS J6 ADDED INTERACT, AND THE ADVERSARIAL PASS FOUND IT BY READING THEM TOGETHER.
        // `opener === null` means BOTH "not captured yet" AND "captured, and the answer was legitimately
        // nothing" — the second being exactly what happens now that the body element is refused. So a
        // hand-over re-ran the capture and took whatever was focused INSIDE THE OUTGOING ROOT, and closing
        // the surface would have returned focus to a detached element in a root that no longer exists.
        // That is the drift the single-capture rule exists to stop, reintroduced by the guard beside it.
        const { active, second, wrapper } = swappable();

        // Nothing focused, so the capture legitimately yields nothing.
        (document.activeElement as HTMLElement | null)?.blur?.();
        active.value = true;
        await flushPromises();

        // Focus is now inside root A, which the hand-over is about to remove.
        const inA = document.querySelector('.surface-a .first');
        expect(document.activeElement).toBe(inA);

        second.value = true;
        await flushPromises();

        active.value = false;
        await flushPromises();

        // The detached element from root A must NOT have been adopted as the opener.
        expect(document.activeElement).not.toBe(inA);
        expect(inA?.isConnected).toBe(false);

        wrapper.unmount();
    });

    it('treats a root that goes null while held as a close, and returns focus', async () => {
        const opener = document.createElement('button');
        document.body.appendChild(opener);
        opener.focus();

        const active = ref(true);
        const present = ref(true);
        const Host = defineComponent({
            setup() {
                const root = ref<HTMLElement | null>(null);
                useInertBackground({ active: active as Ref<boolean>, root });

                return () =>
                    h('div', {}, [
                        h('main', { class: 'background' }, 'Behind'),
                        present.value
                            ? h('div', { ref: root, class: 'surface', tabindex: '-1' }, [h('a', { href: '#one' }, 'One')])
                            : null,
                    ]);
            },
        });

        const wrapper = mount(Host, { attachTo: document.body });
        await flushPromises();
        expect((document.querySelector('.background') as HTMLElement).hasAttribute('inert')).toBe(true);

        present.value = false;
        await flushPromises();

        // Nothing left to move focus into, so this is a close rather than a hand-over — the page comes back
        // and the opener gets focus, instead of the document sitting inert around a surface that is gone.
        expect((document.querySelector('.background') as HTMLElement).hasAttribute('inert')).toBe(false);
        expect(document.activeElement).toBe(opener);

        wrapper.unmount();
        opener.remove();
    });
});

describe('useInertBackground — the body element is never an opener (J6 adversarial pass)', () => {
    it('does not yank focus out of a dialog when a surface with no opener releases underneath it', async () => {
        // ⭐ FOUND BY THE ADVERSARIAL PASS, AND J6's OWN FIRST FIX IS WHAT MADE IT REACHABLE. Focusing the
        // body element is not a no-op — the body is the document's default focus target, so the call
        // SUCCEEDS. A surface opened with nothing focused (a tap, or a programmatic toggle) captured it as
        // its opener, and `release()` then moved focus TO the body. Harmless while nothing else held the
        // page. Not harmless now: the drawer is a `surface`, so as of J6 a dialog can be open on top of it,
        // and the drawer releasing underneath — which a resize past 480px does — would take focus off the
        // dialog the reader is using.
        const { active, wrapper } = harness();

        // Nothing focused when the surface takes the page, so the captured opener would have been the body.
        (document.activeElement as HTMLElement | null)?.blur?.();
        active.value = true;
        await flushPromises();

        // Something else takes the page on top and holds focus, standing in for the ⌘K palette.
        const dialog = document.createElement('div');
        dialog.innerHTML = '<button id="in-dialog">In dialog</button>';
        document.body.appendChild(dialog);
        pushModalRoot(dialog);
        const inDialog = document.querySelector('#in-dialog') as HTMLElement;
        inDialog.focus();
        expect(document.activeElement).toBe(inDialog);

        // The surface releases from underneath.
        active.value = false;
        await flushPromises();

        expect(document.activeElement).toBe(inDialog);

        popModalRoot(dialog);
        dialog.remove();
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
