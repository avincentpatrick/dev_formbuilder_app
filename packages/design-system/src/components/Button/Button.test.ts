import { describe, expect, it, vi } from 'vitest';
import { mount } from '@vue/test-utils';
import Button from './Button.vue';

/**
 * Increment M23 — the first spec this component has ever had, added with the in-flight click guard.
 *
 * ⛔ WHAT IS UNDER TEST IS A VUE MERGE ORDER, AND IT IS THE REASON THE FIX IS POSSIBLE AT ALL. A consumer
 * writes `<MdsButton :loading="busy" @click="create">`. That `@click` is a FALLTHROUGH ATTR: Vue merges it
 * onto the same root element as this component's own `@click="onClick"`, as one array. Two facts decide
 * whether a guard here can work, and neither is obvious enough to assume:
 *
 *   1. WHOSE HANDLER RUNS FIRST. `mergeProps` concatenates existing-first and `cloneVNode` passes the
 *      component's own props first, so the array is [ours, theirs] and ours can stop what follows. Had the
 *      order been reversed no component-level fix would have existed.
 *   2. THAT `stopImmediatePropagation` REACHES ACROSS THAT ARRAY. A plain DOM listener array would not
 *      honour it — Vue's invoker patches the event specifically so it does, and only when the handler
 *      value IS an array, which is exactly this case.
 *
 * Both were measured before the fix was written, with a standalone probe: `stopPropagation()` left the
 * consumer's handler running (order came back ["inner","consumer"], consumer called once) and
 * `stopImmediatePropagation()` stopped it (consumer called zero times). What shipped before this increment
 * was `stopPropagation()`, which only stops ANCESTORS — so for four increments this guard read as working
 * and blocked nothing on any button that is not a native `type="submit"`.
 *
 * ⚠️ THE CONSUMER HANDLER MUST BE PASSED THROUGH `attrs`, NOT `props`, OR EVERY CASE BELOW IS VACUOUS.
 * `props` does not create the merged array, so the test would pass against the broken code. This is the
 * one thing in the file that must not be "tidied".
 */
describe('MdsButton — the in-flight click guard', () => {
    it('does not let the consumer’s @click through while loading', async () => {
        // ⭐ THE CASE. Mutation: change `event.stopImmediatePropagation()` back to `event.stopPropagation()`
        // in Button.vue and this reddens — the consumer's handler is a sibling in the merged array, not an
        // ancestor, so plain propagation was never what stood between it and a second irreversible request.
        const click = vi.fn();
        const wrapper = mount(Button, { props: { loading: true }, attrs: { onClick: click } });

        await wrapper.get('button').trigger('click');

        expect(click).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('lets it through normally, which is the zero-blast-radius claim', async () => {
        // Button.vue is rendered by most of the product. "The default did not move" needs a test that can
        // fail rather than a sentence in a PR body.
        const click = vi.fn();
        const wrapper = mount(Button, { props: { loading: false }, attrs: { onClick: click } });

        await wrapper.get('button').trigger('click');

        expect(click).toHaveBeenCalledTimes(1);
        wrapper.unmount();
    });

    it('blocks it while disabled, and carries the native attribute that also blocks it', async () => {
        // Two independent guards on the same state, asserted together because the native one is what makes
        // the JS one look unnecessary. happy-dom DOES suppress a dispatched click on a disabled <button>,
        // so this case is real rather than vacuous — worth stating, because "happy-dom cannot see this" is
        // the usual assumption in this folder and would get the case deleted.
        const click = vi.fn();
        const wrapper = mount(Button, { props: { disabled: true }, attrs: { onClick: click } });

        expect(wrapper.get('button').attributes('disabled')).toBeDefined();
        await wrapper.get('button').trigger('click');

        expect(click).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('blocks it on an anchor, where there is no native disabled to fall back on', async () => {
        // ⚠️ THE CASE THAT CANNOT BE FIXED ANY OTHER WAY. An <a> ignores the disabled attribute entirely,
        // and `.mds-button--disabled`'s pointer-events:none is keyed on `isLink && disabled` — never on
        // loading. So for a loading anchor this handler is the ONLY guard that exists. No call site
        // combines as="a" with :loading today, which makes this latent rather than live, and is exactly
        // why it needs a test rather than a reader's attention.
        const click = vi.fn();
        const wrapper = mount(Button, {
            props: { as: 'a', href: '/forms', loading: true },
            attrs: { onClick: click },
        });

        expect(wrapper.get('a').attributes('href')).toBeUndefined();
        await wrapper.get('a').trigger('click');

        expect(click).not.toHaveBeenCalled();
        wrapper.unmount();
    });

    it('keeps the button focusable while loading, which is why the guard has to exist in script at all', () => {
        // The alternative fix — binding native `disabled` to `loading` — would move focus to <body> on the
        // very click that starts the request. aria-disabled + a script guard is the deliberate trade, and
        // this pins it so a future "simplification" has to argue with a failing test.
        const wrapper = mount(Button, { props: { loading: true } });
        const button = wrapper.get('button');

        expect(button.attributes('disabled')).toBeUndefined();
        expect(button.attributes('aria-disabled')).toBe('true');
        expect(button.attributes('aria-busy')).toBe('true');

        wrapper.unmount();
    });
});
