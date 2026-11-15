/**
 * M242 (`R-ade0b598`, `D226`) — a renamed key carries every reference that names it, as the builder store takes it.
 *
 * The SERVER rewrites every row whose text named the old key and replies with each one under `carried`, token and all.
 * Through the REAL store with `fetch` mocked by a server that carries like the real one: the store takes each row in
 * place and sends nothing more; the next save of a carried row goes at the token the carry gave it, never a conflict; a
 * row the author has typed into since its last save keeps what they typed and takes the rest; the renamed row's own
 * carried text is what the server holds; the undo entry says so, and its undo carries everything back; and the panel
 * says what followed, what could not be read, and what happened to the thank-you message.
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';

import ConfigPanel from './ConfigPanel.vue';
import { carrySentences } from './carry-notice';
import { fetchMock, jsonResponse, pageProps, requestLog, serverField } from './builder-store-fixtures';
import type { ServerField } from './types';
import { useBuilderStore, type BuilderStore, type CarryNotice } from './useBuilderStore';

vi.mock('@/composables/useEntitlements', () => ({
    useEntitlements: () => ({ feature: () => true }),
}));

const AGE = serverField({ id: 'f-age', key: 'age', label: 'Age', field_type: 'integer', sequence: 0, version: 'age-1' });
const WEIGHT = serverField({
    id: 'f-weight', key: 'weight', label: 'Weight at ${age}', field_type: 'integer', sequence: 1, version: 'weight-1',
    relevant_expression: '${age} > 1',
});
const NOTE = serverField({ id: 'f-note', key: 'note', label: 'Note', sequence: 2, version: 'note-1' });

type Extra = { unread?: unknown[]; confirmation?: 'carried' | 'left' | null };

/**
 * A server that carries like the real one: a save whose key differs from the stored one rewrites `${old}` in every
 * row's condition, label, placeholder and custom rules (the renamed row's own included), gives each rewritten row a new
 * token, and replies with them under `carried`. Its own simple replace stands in for the server's token-aware one.
 */
function carryingServer(initial: ServerField[], extra: Extra = {}) {
    const rows = new Map(initial.map((row) => [row.id, JSON.parse(JSON.stringify(row)) as ServerField]));
    let tick = 0;

    return fetchMock().mockImplementation((url: string, init?: RequestInit) => {
        const body = typeof init?.body === 'string' ? (JSON.parse(init.body) as Partial<ServerField>) : {};
        const id = url.split('/').pop()!;
        const stored = rows.get(id)!;
        const from = stored.key;
        const to = String(body.key ?? from);
        const swap = (text: string | null | undefined) => (typeof text === 'string' ? text.split('${' + from + '}').join('${' + to + '}') : text ?? null);
        const carry = (row: ServerField): ServerField => ({
            ...row,
            label: swap(row.label) ?? '',
            placeholder: swap(row.placeholder),
            relevant_expression: swap(row.relevant_expression),
            validations: row.validations.map((rule) => ({ ...rule, expression: swap(rule.expression) })),
        });

        const sent = { ...stored, ...body, version: `v${++tick}` } as ServerField;
        if (from === to) {
            rows.set(id, sent);
            return Promise.resolve(jsonResponse(200, sent));
        }
        const saved = { ...carry(sent), version: sent.version };
        rows.set(id, saved);
        const changed: ServerField[] = JSON.stringify(saved) === JSON.stringify(sent) ? [] : [saved];
        for (const [otherId, row] of rows) {
            if (otherId === id) continue;
            const next = carry(row);
            if (JSON.stringify(next) === JSON.stringify(row)) continue;
            const stamped = { ...next, version: `v${++tick}` };
            rows.set(otherId, stamped);
            changed.push(stamped);
        }

        return Promise.resolve(
            jsonResponse(200, { ...saved, carried: { from, to, fields: changed, sections: [], unread: extra.unread ?? [], confirmation: extra.confirmation ?? null } }),
        );
    });
}

async function settle(store: BuilderStore): Promise<void> {
    await vi.advanceTimersByTimeAsync(600);
    await store.whenIdle();
    await flushPromises();
    await store.whenIdle();
}

const byId = (store: BuilderStore, id: string) => store.fields.value.find((field) => field.id === id)!;
const patches = (mock: ReturnType<typeof vi.fn>) => requestLog(mock).filter((r) => r.method === 'PATCH');

beforeEach(() => {
    vi.useFakeTimers();
    sessionStorage.clear();
});

afterEach(() => {
    vi.useRealTimers();
    vi.unstubAllGlobals();
    vi.restoreAllMocks();
    sessionStorage.clear();
    document.body.innerHTML = '';
});

describe('a renamed key (R-ade0b598)', () => {
    it('takes every row the server rewrote, token and all, sends nothing more, and the undo entry says so', async () => {
        const mock = carryingServer([AGE, WEIGHT, NOTE]);
        const store = useBuilderStore(pageProps({ fields: [AGE, WEIGHT, NOTE] }));

        byId(store, 'f-age').key = 'age_years';
        store.touch(byId(store, 'f-age').uid, 'field');
        await settle(store);

        const weight = byId(store, 'f-weight');
        expect(weight.relevant_expression).toBe('${age_years} > 1');
        expect(weight.label).toBe('Weight at ${age_years}');
        expect(weight.version).not.toBe('weight-1');
        expect(byId(store, 'f-note').version).toBe('note-1');
        expect(patches(mock).map((r) => r.url)).toEqual(['/forms/form-1/fields/f-age']);
        expect(store.saveState.value).toBe('saved');
        expect(store.undoLabel.value).toBe('Edit Age and 1 that named its key');
        expect(store.carryNotices.value[byId(store, 'f-age').uid]).toMatchObject({ from: 'age', to: 'age_years', fields: 1, sections: 0 });

        // The carried row's next save goes at the token the carry gave it: the server would refuse the old one.
        weight.hint = 'In kilograms';
        store.touch(weight.uid, 'field');
        await settle(store);
        const weightSave = patches(mock).find((r) => r.url.endsWith('/f-weight'));
        expect((weightSave?.body as { version?: string }).version).not.toBe('weight-1');
        expect(weightSave?.body).toMatchObject({ relevant_expression: '${age_years} > 1', hint: 'In kilograms' });
    });

    it('carries everything back when the rename is undone, and forward again on redo', async () => {
        carryingServer([AGE, WEIGHT, NOTE]);
        const store = useBuilderStore(pageProps({ fields: [AGE, WEIGHT, NOTE] }));

        byId(store, 'f-age').key = 'age_years';
        store.touch(byId(store, 'f-age').uid, 'field');
        await settle(store);

        await store.undo();
        await settle(store);
        expect(byId(store, 'f-age').key).toBe('age');
        expect(byId(store, 'f-weight').relevant_expression).toBe('${age} > 1');
        expect(store.saveState.value).toBe('saved');

        await store.redo();
        await settle(store);
        expect(byId(store, 'f-weight').relevant_expression).toBe('${age_years} > 1');
    });

    it('keeps what the author typed into a carried row before its save, and takes the rest', async () => {
        const mock = carryingServer([AGE, WEIGHT, NOTE]);
        const store = useBuilderStore(pageProps({ fields: [AGE, WEIGHT, NOTE] }));
        const weight = byId(store, 'f-weight');

        byId(store, 'f-age').key = 'age_years';
        store.touch(byId(store, 'f-age').uid, 'field');
        // Typed into Weight while the rename waits: touching another row sends the rename first.
        weight.label = 'Body weight at ${age}';
        store.touch(weight.uid, 'field');
        await settle(store);

        expect(weight.label).toBe('Body weight at ${age}'); // the author's text, as typed
        expect(weight.relevant_expression).toBe('${age_years} > 1'); // the carry, where they typed nothing
        const weightSave = patches(mock).find((r) => r.url.endsWith('/f-weight'));
        expect(weightSave?.body).toMatchObject({ label: 'Body weight at ${age}', relevant_expression: '${age_years} > 1' });
        expect((weightSave?.body as { version?: string }).version).not.toBe('weight-1');
        expect(store.saveState.value).toBe('saved');
    });

    it('takes the renamed row’s own carried text as what the server holds', async () => {
        const own = serverField({
            ...AGE,
            validations: [{ rule_type: null, operator: null, rule_value: null, expression: '${age} < 130', error_message: 'Too old.', related_field_key: null, logic_group: null, logic_operator: null, sequence: 0 }] as ServerField['validations'],
        });
        const mock = carryingServer([own, NOTE]);
        const store = useBuilderStore(pageProps({ fields: [own, NOTE] }));
        const age = byId(store, 'f-age');

        age.key = 'age_years';
        store.touch(age.uid, 'field');
        await settle(store);

        expect(age.validations[0].expression).toBe('${age_years} < 130');
        expect(store.undoLabel.value).toBe('Edit Age'); // nothing ELSE named it
        expect(patches(mock)).toHaveLength(1);
        expect(store.saveState.value).toBe('saved');

        // Nothing is owed: an unrelated commit of the row sends exactly what the server holds, the carried text with it.
        age.label = 'Age in years';
        store.touch(age.uid, 'field');
        await settle(store);
        expect(patches(mock)[1].body).toMatchObject({ validations: [expect.objectContaining({ expression: '${age_years} < 130' })] });
    });

    it('says under Field key what followed, what could not be read, and what happened to the thank-you message', async () => {
        carryingServer([AGE, WEIGHT, NOTE], {
            unread: [{ kind: 'field', id: 'f-note', label: 'Note', column: 'relevant_expression' }],
            confirmation: 'left',
        });
        const store = useBuilderStore(pageProps({ fields: [AGE, WEIGHT, NOTE] }));
        store.select({ kind: 'field', uid: byId(store, 'f-age').uid });
        const wrapper = mount(ConfigPanel, { props: { store }, attachTo: document.body });
        await flushPromises();
        await wrapper.findAll('[role="tab"]').find((tab) => tab.text() === 'Advanced')!.trigger('click');

        const label = wrapper.findAll('label').find((candidate) => candidate.text().trim() === 'Field key')!;
        await wrapper.find(`#${label.attributes('for')}`).setValue('age_years');
        await settle(store);

        const notice = wrapper.find('[data-carry-notice]');
        expect(notice.exists()).toBe(true);
        expect(notice.attributes('role')).toBe('status');
        expect(notice.text()).toContain('1 other question that named “age” now names “age_years”.');
        expect(notice.text()).toContain('“Note”’s “Show … only when” condition could not be read, so it may still name “age”.');
        expect(notice.text()).toContain('The thank-you message is shared with the live form, so it still names “age”. Change it just before you publish.');

        // The row's next save, which renames nothing, says nothing more about the rename before it.
        byId(store, 'f-age').label = 'Age in years';
        store.touch(byId(store, 'f-age').uid, 'field');
        await settle(store);
        expect(store.carryNotices.value[byId(store, 'f-age').uid]).toBeUndefined();
        expect(wrapper.find('[data-carry-notice]').exists()).toBe(false);
    });
});

describe('the panel’s sentences', () => {
    const notice = (overrides: Partial<CarryNotice>): CarryNotice => ({ from: 'age', to: 'years', fields: 0, sections: 0, unread: [], confirmation: null, ...overrides });

    it('counts questions and sections, in the singular when there is one', () => {
        expect(carrySentences(notice({ fields: 2, sections: 1 }))).toEqual(['2 other questions and 1 section that named “age” now name “years”.']);
        expect(carrySentences(notice({ sections: 1 }))).toEqual(['1 section that named “age” now names “years”.']);
        expect(carrySentences(notice({}))).toEqual([]);
    });

    it('names a text it could not read by its question, or as a section when it has no title', () => {
        expect(carrySentences(notice({ unread: [{ kind: 'section', id: 's', label: '', column: 'relevant_expression' }] }))).toEqual([
            'A section’s “Show … only when” condition could not be read, so it may still name “age”.',
        ]);
        expect(carrySentences(notice({ unread: [{ kind: 'field', id: 'f', label: 'BMI', column: 'calculated_formula' }] }))).toEqual([
            '“BMI”’s formula could not be read, so it may still name “age”.',
        ]);
    });

    it('says the thank-you message followed when no version is live', () => {
        expect(carrySentences(notice({ confirmation: 'carried' }))).toEqual(['The thank-you message now names “years” too.']);
    });
});
