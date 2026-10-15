/**
 * M172 (`R-733eff09`, `D132`) — a key clash is avoided, then adjusted, never refused.
 *
 * The testers met "The key has already been taken. … has a change that was not saved. Select it to see why": the refusal
 * showed only under Field key on the Advanced tab, selecting the question opened Basics, and the alert named no reason.
 * The user (2026-10-15): "if we can already help the user avoid the errors, notify them or the app auto adjust the key
 * naming to avoid conflicts". So: the panel warns AS a key in use is typed and offers the free one; a clash that still
 * reaches the server is saved under the next free number, and the panel says so; every rule comparing with the old key
 * follows; and a refusal of any other kind is listed with its reason, opening the tab that shows it.
 */
import { flushPromises, mount, type VueWrapper } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import ConfigPanel from './ConfigPanel.vue';
import { freeKeyFor, tabForSavePath } from './field-save-errors';
import { fetchMock, jsonResponse, pageProps, requestLog, serverField } from './builder-store-fixtures';
import { useBuilderStore, type BuilderStore } from './useBuilderStore';
import type { ServerField } from './types';

vi.mock('@/composables/useEntitlements', () => ({
    useEntitlements: () => ({ feature: () => true }),
}));

const AGE = serverField({ id: 'f-age', key: 'age', label: 'Age (years)', sequence: 1 });
const HEIGHT = serverField({ id: 'f-height', key: 'height', label: 'Height', sequence: 2 });
const WEIGHT = serverField({
    id: 'f-weight',
    key: 'weight',
    label: 'Weight',
    sequence: 3,
    validations: [
        {
            rule_type: 'required_if', operator: 'eq', rule_value: '1', expression: null, error_message: null,
            related_field_key: 'height', logic_group: null, logic_operator: null, sequence: 0,
        },
    ] as ServerField['validations'],
});

let mock: ReturnType<typeof vi.fn>;

/** The server's own D132 rule: a key another question holds is kept as the next free number. */
function serverSuffixesClashes(): void {
    mock.mockImplementation((url: string, init?: RequestInit) => {
        const body = typeof init?.body === 'string' ? (JSON.parse(init.body) as Record<string, unknown>) : {};
        const id = url.split('/').pop();
        const key = body.key === 'age' && id !== 'f-age' ? 'age_2' : body.key;

        return Promise.resolve(jsonResponse(200, { ...serverField(), ...body, key, id, version: `v-${Math.random()}` }));
    });
}

function makeStore(fields: ServerField[] = [AGE, HEIGHT, WEIGHT]): BuilderStore {
    return useBuilderStore(pageProps({ fields, sections: [] }));
}

async function mountOn(store: BuilderStore, uid: string): Promise<VueWrapper> {
    store.select({ kind: 'field', uid });
    const wrapper = mount(ConfigPanel, { props: { store }, attachTo: document.body });
    await flushPromises();

    return wrapper;
}

async function openTab(wrapper: VueWrapper, label: string): Promise<void> {
    const tab = wrapper.findAll('[role="tab"]').find((candidate) => candidate.text() === label);
    expect(tab, `the ${label} tab`).toBeDefined();
    await tab!.trigger('click');
}

function fieldKeyInput(wrapper: VueWrapper) {
    const label = wrapper.findAll('label').find((candidate) => candidate.text().trim() === 'Field key');
    expect(label, 'the Field key label').toBeDefined();

    return wrapper.find(`#${label!.attributes('for')}`);
}

async function settle(store: BuilderStore): Promise<void> {
    await vi.advanceTimersByTimeAsync(600);
    await store.whenIdle();
    await flushPromises();
}

beforeEach(() => {
    vi.useFakeTimers();
    sessionStorage.clear();
    mock = fetchMock();
    serverSuffixesClashes();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    sessionStorage.clear();
    document.body.innerHTML = '';
});

describe('the key the server will keep', () => {
    it('is the typed key when nobody holds it, and the next free number when somebody does', () => {
        expect(freeKeyFor('age', ['height'])).toBe('age');
        expect(freeKeyFor('age', ['age'])).toBe('age_2');
        expect(freeKeyFor('age', ['age', 'age_2', 'age_3'])).toBe('age_4');
        expect(freeKeyFor('a'.repeat(150), ['a'.repeat(150)])).toBe(`${'a'.repeat(148)}_2`);
    });

    it('is named on the tab that holds the control a refusal names', () => {
        expect(tabForSavePath('key')).toBe('advanced');
        expect(tabForSavePath('label')).toBe('basics');
        expect(tabForSavePath('validations.0.rule_value')).toBe('validation');
        expect(tabForSavePath('config.options.2.label')).toBe('options');
        expect(tabForSavePath('nothing_known')).toBeNull();
    });
});

describe('a key another question holds (D132)', () => {
    it('is said as it is typed, with the key the save will keep, and one press takes that key', async () => {
        const store = makeStore();
        const wrapper = await mountOn(store, store.fields.value[1].uid);
        await openTab(wrapper, 'Advanced');

        await fieldKeyInput(wrapper).setValue('age');

        const warning = wrapper.find('[data-key-clash]');
        expect(warning.exists(), 'the warning shows while typing').toBe(true);
        expect(warning.text()).toContain('Used by “Age (years)”. It will be saved as age_2.');

        await warning.findAll('button').find((button) => button.text() === 'Use age_2')!.trigger('click');
        expect(store.fields.value[1].key).toBe('age_2');
        expect(wrapper.find('[data-key-clash]').exists()).toBe(false);
    });

    it('is saved under the kept key, said under Field key, and the rule comparing with the old key follows', async () => {
        const store = makeStore();
        const wrapper = await mountOn(store, store.fields.value[1].uid);
        await openTab(wrapper, 'Advanced');

        await fieldKeyInput(wrapper).setValue('age');
        await settle(store);

        const height = store.fields.value[1];
        expect(height.key).toBe('age_2');
        expect(store.keyNotices.value[height.uid]).toEqual({ wanted: 'age', kept: 'age_2' });
        expect(wrapper.find('[data-key-notice]').text()).toContain('“age” is used by another question, so this one was saved as “age_2”.');

        // Weight's rule compared with "height": it now names the key the server kept — the server stores it by id.
        expect(store.fields.value[2].validations[0].related_field_key).toBe('age_2');
        // Nothing is owed: the kept key is what the server holds, and Weight was not sent again.
        expect(store.saveState.value).toBe('saved');
        expect(requestLog(mock).map((request) => request.url)).toEqual(['/forms/form-1/fields/f-height']);
    });
});

describe('a refusal of any other kind', () => {
    it('lists another refused question with its reason, and opening it shows the tab that holds the error', async () => {
        mock.mockResolvedValueOnce(jsonResponse(422, { message: 'The given data was invalid.', errors: { key: ['The key format is invalid.'] } }));
        const store = makeStore();
        const wrapper = await mountOn(store, store.fields.value[0].uid);
        await openTab(wrapper, 'Advanced');
        await fieldKeyInput(wrapper).setValue('agE');
        await settle(store);

        store.select({ kind: 'field', uid: store.fields.value[1].uid });
        await flushPromises();

        const listed = wrapper.find('[data-other-refusal]');
        expect(listed.text().replace(/\s+/g, ' ')).toContain('“Age (years)” was not saved. Field key: The key format is invalid.');
        expect(wrapper.text()).not.toContain('Select it to see why');

        await listed.find('button').trigger('click');
        await flushPromises();
        const selected = wrapper.find('[role="tab"][aria-selected="true"]');
        expect(selected.text()).toBe('Advanced');
        expect(selected.find('[data-mds-tab-alert]').exists(), 'the tab holding the error carries the dot').toBe(true);
    });

    it('announces an identical refusal again, as a new alert', async () => {
        mock.mockResolvedValue(jsonResponse(422, { message: 'The given data was invalid.', errors: { label: ['The label is too long.'] } }));
        const store = makeStore();
        const wrapper = await mountOn(store, store.fields.value[0].uid);

        store.fields.value[0].label = 'x'.repeat(10);
        store.touch(store.fields.value[0].uid, 'field');
        await settle(store);
        const first = wrapper.find('[role="alert"]').element;

        store.fields.value[0].label = 'y'.repeat(10);
        store.touch(store.fields.value[0].uid, 'field');
        await settle(store);
        const second = wrapper.find('[role="alert"]').element;

        expect(second).not.toBe(first);
    });
});
