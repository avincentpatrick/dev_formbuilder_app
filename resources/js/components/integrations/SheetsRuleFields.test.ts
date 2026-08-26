import { mount, flushPromises, type DOMWrapper, type VueWrapper } from '@vue/test-utils';
import { describe, expect, it, vi, beforeEach } from 'vitest';

/**
 * Increment M23 — the double-provision guard. First component spec in this directory.
 *
 * ⛔ WHY THIS ONE BUTTON EARNS A SPEC WHEN NO OTHER `:loading` BUTTON DOES. Its click reaches a raw `fetch`
 * with an IRREVERSIBLE EXTERNAL side effect: `createDestination()` provisions a real spreadsheet in the
 * tenant's Google Drive. A duplicate is a file Meridian will never write to and only the tenant can delete
 * — and because both responses assign `destination`, the rule binds to the SECOND sheet and the FIRST is
 * the orphan. Everything else in the tree is either an Inertia submit or a GET.
 *
 * ⚠️ THE PASSTHROUGH STUB IS LOAD-BEARING, AND THAT IS THE WHOLE REASON THERE ARE TWO SPECS FOR ONE CLICK.
 * This increment guards it in two places: here (a `busy` early-return) and inside `MdsButton`
 * (`stopImmediatePropagation`). Mounted against the REAL button, the design-system guard alone satisfies
 * the assertion below — so reverting the local guard would leave this file GREEN and the mutation signal
 * would be measuring the other fix entirely. The stub takes the design-system guard out of the picture so
 * this file tests the guard it is named for; `Button.test.ts` tests the other one against the same click.
 */

const mocks = vi.hoisted(() => ({
    createDestination: vi.fn(),
    fetchMappableColumns: vi.fn(),
    inspectDestination: vi.fn(),
}));

vi.mock('./integrationsClient', () => mocks);

const SheetsRuleFields = (await import('./SheetsRuleFields.vue')).default;

/** A bare button carrying the consumer's listeners — i.e. MdsButton with its own guard removed. */
const ButtonPassthrough = {
    name: 'Button',
    inheritAttrs: false,
    template: '<button v-bind="$attrs"><slot /></button>',
};

/**
 * ⛔ THE STUB KEY IS `Button`, NOT `MdsButton`, AND GETTING IT WRONG FAILS SILENTLY. Vue Test Utils matches
 * a stub against the component's own inferred name, which for a `<script setup>` SFC is its FILENAME —
 * `Button.vue` gives `Button`. `MdsButton` is only the barrel's export alias, so keying on it matches
 * nothing at all: the real button renders, its guard absorbs the extra clicks, and the spec passes with the
 * local guard reverted. MEASURED rather than deduced — the same three-click case reports **1** call under
 * the `MdsButton` key and **2** under `Button`. This assertion is what stops that returning unnoticed.
 */
function expectStubApplied(button: DOMWrapper<Element>): void {
    expect(
        button.classes(),
        'the passthrough stub must be in place, or this file is measuring MdsButton’s guard instead',
    ).not.toContain('mds-button');
}

function render(connectionId: string | null = 'conn-1'): VueWrapper {
    return mount(SheetsRuleFields, {
        props: { connectionId, formId: '', formTitle: 'Q3 Intake', rule: null, errors: {} },
        global: { stubs: { Button: ButtonPassthrough } },
    });
}

/** Located by label rather than position — this editor renders more than one button. */
function createButton(wrapper: VueWrapper): DOMWrapper<Element> {
    const button = wrapper.findAll('button').find((candidate) => candidate.text().includes('Create'));
    expect(button, 'the Create button should be rendered in create mode').toBeTruthy();
    expectStubApplied(button!);

    return button!;
}

beforeEach(() => {
    vi.clearAllMocks();
    // The immediate watcher calls this on mount; without a resolved payload the component never settles.
    mocks.fetchMappableColumns.mockResolvedValue({ columns: [], scoped: false, error: null });
});

describe('SheetsRuleFields — creating a spreadsheet is not repeatable by double-click', () => {
    it('provisions exactly one sheet however many times Create is pressed mid-request', async () => {
        // ⭐ THE CASE. Mutation: drop `|| busy.value` from create()'s early return and this reddens with 2.
        // The `:disabled` expression cannot cover it — that reads `destination !== null`, and `destination`
        // is not assigned until the response lands, so it is false for the entire flight.
        let land: (value: unknown) => void = () => {};
        mocks.createDestination.mockReturnValue(
            new Promise((resolve) => {
                land = resolve;
            }),
        );

        const wrapper = render();
        await flushPromises();

        const button = createButton(wrapper);
        await button.trigger('click');
        await button.trigger('click');
        await button.trigger('click');

        expect(mocks.createDestination).toHaveBeenCalledTimes(1);

        land({ destination: null, error: null });
        await flushPromises();
        wrapper.unmount();
    });

    it('releases the guard when the response lands, rather than wedging the editor busy forever', async () => {
        // The control for the case above: a guard that never clears is indistinguishable from a working one
        // in that test and catastrophic in production. Here the request completes on the ERROR arm — the one
        // easiest to leave `busy` set on — and Create has to work again afterwards.
        mocks.createDestination.mockResolvedValueOnce({ destination: null, error: 'Google said no.' });

        const wrapper = render();
        await flushPromises();

        await createButton(wrapper).trigger('click');
        await flushPromises();

        expect(mocks.createDestination).toHaveBeenCalledTimes(1);
        expect(wrapper.text()).toContain('Google said no.');

        mocks.createDestination.mockResolvedValueOnce({ destination: null, error: null });
        await createButton(wrapper).trigger('click');
        await flushPromises();

        expect(mocks.createDestination).toHaveBeenCalledTimes(2);
        wrapper.unmount();
    });

    it('still does nothing at all without a connection, which is the guard that was already there', async () => {
        // Pinned so the added `busy` clause cannot be written in a way that drops the original condition.
        const wrapper = render(null);
        await flushPromises();

        await createButton(wrapper).trigger('click');
        await flushPromises();

        expect(mocks.createDestination).not.toHaveBeenCalled();
        wrapper.unmount();
    });
});
