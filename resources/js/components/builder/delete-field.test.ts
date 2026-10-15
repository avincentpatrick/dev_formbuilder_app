/**
 * M172 (`R-a44638b6`, `D133`, and the delete half of `R-9ea69e29`) — deleting a question responses have answered.
 *
 * The testers asked for it (2026-10-15): delete when nothing was submitted; otherwise say so, keep the answers with a note,
 * keep the history. The delete works in the draft either way; with responses holding answers the author is told how many
 * first. And a rule of another question comparing with the deleted one goes with it on the server, so it leaves the screen
 * too — the undo entry names it and brings it back.
 */
import { flushPromises, mount } from '@vue/test-utils';
import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import BuilderCanvas from './BuilderCanvas.vue';
import { fetchMock, jsonResponse, pageProps, requestLog, serverField } from './builder-store-fixtures';
import { useBuilderStore, type BuilderStore } from './useBuilderStore';
import type { BuilderPageProps, ServerField } from './types';

const AGE = serverField({ id: 'f-age', key: 'age', label: 'Age', form_section_id: null, sequence: 1 });
const CONSENT = serverField({
    id: 'f-consent',
    key: 'consent',
    label: 'Consent',
    form_section_id: null,
    sequence: 2,
    validations: [
        {
            rule_type: 'required_if', operator: 'eq', rule_value: '18', expression: null, error_message: null,
            related_field_key: 'age', logic_group: null, logic_operator: null, sequence: 0,
        },
    ] as ServerField['validations'],
});

let mock: ReturnType<typeof vi.fn>;

function published(overrides: Partial<BuilderPageProps> = {}): BuilderPageProps {
    const props = pageProps({ fields: [AGE, CONSENT], sections: [], ...overrides });

    return { ...props, share: { ...props.share, is_published: true }, draft: { id: 'v-2', version_number: 2 } };
}

function canvasOn(store: BuilderStore) {
    return mount(BuilderCanvas, { props: { store, fieldTypeLabels: { short_text: 'Text' } }, attachTo: document.body });
}

function trashOf(wrapper: ReturnType<typeof canvasOn>, index: number) {
    return wrapper.findAll('button[aria-label="Delete field"]')[index];
}

function dialogButton(name: string): HTMLButtonElement | undefined {
    return [...document.body.querySelectorAll<HTMLButtonElement>('[role="dialog"] button')].find((button) => button.textContent?.trim() === name);
}

beforeEach(() => {
    sessionStorage.clear();
    mock = fetchMock();
});

afterEach(() => {
    vi.unstubAllGlobals();
    sessionStorage.clear();
    document.body.innerHTML = '';
});

describe('a question responses have answered', () => {
    it('is deleted only after the author is told how many responses hold answers, and that they keep them', async () => {
        mock.mockResolvedValueOnce(jsonResponse(200, { responses: 3 })); // the count
        mock.mockResolvedValue(jsonResponse(200, {})); // the delete
        const store = useBuilderStore(published());
        const wrapper = canvasOn(store);

        await trashOf(wrapper, 0).trigger('click');
        await flushPromises();

        const text = document.body.querySelector('[role="dialog"]')?.textContent ?? '';
        expect(text).toContain('Delete “Age”?');
        expect(text).toContain('3 responses hold answers to this question.');
        expect(text).toContain('They keep them, marked as removed from the form.');
        expect(requestLog(mock).map((request) => request.method)).toEqual(['GET']); // nothing deleted yet

        dialogButton('Delete question')!.click();
        await flushPromises();
        expect(requestLog(mock).map((request) => [request.method, request.url])).toEqual([
            ['GET', '/forms/form-1/fields/f-age/answers'],
            ['DELETE', '/forms/form-1/fields/f-age'],
        ]);
        expect(store.fields.value.map((field) => field.key)).toEqual(['consent']);
        wrapper.unmount();
    });

    it('is kept when the author cancels', async () => {
        mock.mockResolvedValueOnce(jsonResponse(200, { responses: 1 }));
        const store = useBuilderStore(published());
        const wrapper = canvasOn(store);

        await trashOf(wrapper, 0).trigger('click');
        await flushPromises();
        expect(document.body.querySelector('[role="dialog"]')?.textContent).toContain('1 response holds an answer to this question.');

        dialogButton('Cancel')!.click();
        await flushPromises();
        expect(store.fields.value.map((field) => field.key)).toEqual(['age', 'consent']);
        expect(requestLog(mock).map((request) => request.method)).toEqual(['GET']);
        wrapper.unmount();
    });
});

describe('a question nobody has answered', () => {
    it('is deleted at once, as before — and on a form never published, without even asking the server', async () => {
        mock.mockResolvedValue(jsonResponse(200, {}));
        const store = useBuilderStore(pageProps({ fields: [AGE, CONSENT], sections: [] })); // draft version 1, never published
        const wrapper = canvasOn(store);

        await trashOf(wrapper, 0).trigger('click');
        await flushPromises();

        expect(document.body.querySelector('[role="dialog"]')).toBeNull();
        expect(requestLog(mock).map((request) => request.method)).toEqual(['DELETE']);
        wrapper.unmount();
    });
});

describe('a rule of another question comparing with the deleted one (R-9ea69e29)', () => {
    it('leaves the screen with it, and comes back with the undo', async () => {
        mock.mockImplementation((url: string, init?: RequestInit) => {
            const body = typeof init?.body === 'string' ? (JSON.parse(init.body) as Record<string, unknown>) : {};
            if (init?.method === 'POST' && url.endsWith('/fields')) return Promise.resolve(jsonResponse(200, { ...AGE, id: 'f-age-2', key: 'text_1' }));

            return Promise.resolve(jsonResponse(200, { ...serverField(), ...body, id: url.split('/').pop(), version: 'v9' }));
        });
        const store = useBuilderStore(pageProps({ fields: [AGE, CONSENT], sections: [] }));

        await store.deleteField(store.fields.value[0].uid);

        const consent = store.fields.value.find((field) => field.key === 'consent')!;
        expect(consent.validations).toEqual([]); // not re-sent comparing with nothing on its next save
        expect(store.canUndo.value).toBe(true);

        await store.undo();
        await flushPromises();

        expect(store.fields.value.map((field) => field.key).sort()).toEqual(['age', 'consent']);
        expect(consent.validations.map((rule) => rule.related_field_key)).toEqual(['age']);
        const consentSave = requestLog(mock).filter((request) => request.method === 'PATCH' && request.url.endsWith('/f-consent'));
        expect(consentSave).toHaveLength(1); // the rule is saved back, naming the restored question's key
    });
});
