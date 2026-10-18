import { beforeEach, describe, expect, it, vi } from 'vitest';
import { flushPromises, mount } from '@vue/test-utils';

import RuntimeSession from '../components/RuntimeSession.vue';
import { fieldRendersNothing } from '../engine/field-roles';
import type { ApiClient } from '../lib/api-client';
import { buildEngineSchema, buildRenderModel } from '../lib/schema-mapping';
import type { Bootstrap } from '../lib/types';
import { field, schemaResponse, section } from './fixtures';

/**
 * M177 (`R-f44e9d00`) — "Show to respondents: Always · Never · Only when…". `config.visibility` is `hidden` (Never) or
 * `shown` (Always, which only a calculation needs: it is Never by default); absent keeps every type's own rule. A
 * Never question is still computed, stored and validated against nothing the respondent cannot fix; an Always
 * calculation shows its value, read-only.
 */
const bootstrap: Bootstrap = { shareToken: 't1', expiresAt: '', formId: 'f', formTitle: 'T', slug: 's', defaultLocale: 'en', resumeToken: '' };

function fakeClient(): ApiClient {
    return {
        fetchSchema: vi.fn(async () => {
            throw new Error('unused');
        }),
        submit: vi.fn(async () => ({ id: 'sub-1', reference: 'R-1', status: 'submitted', created: true })),
        saveDraft: vi.fn(),
        remint: vi.fn(),
        token: () => 't1',
    } as unknown as ApiClient;
}

async function settle(): Promise<void> {
    for (let i = 0; i < 5; i += 1) {
        await flushPromises();
        await new Promise((resolve) => setTimeout(resolve, 0));
    }
}

beforeEach(() => {
    window.localStorage.clear();
});

describe('fieldRendersNothing (M177)', () => {
    it('keeps each type’s own rule when no visibility is set', () => {
        expect(fieldRendersNothing({ field_type: 'short_text' })).toBe(false);
        expect(fieldRendersNothing({ field_type: 'calculated' })).toBe(true);
        expect(fieldRendersNothing({ field_type: 'hidden' })).toBe(true);
        expect(fieldRendersNothing({ field_type: 'page_break' })).toBe(true);
    });

    it('hides any question set to Never, and shows a calculation set to Always', () => {
        expect(fieldRendersNothing({ field_type: 'short_text', visibility: 'hidden' })).toBe(true);
        expect(fieldRendersNothing({ field_type: 'calculated', visibility: 'shown' })).toBe(false);
    });

    it('never shows the Hidden type or a page break, whatever is stored', () => {
        expect(fieldRendersNothing({ field_type: 'hidden', visibility: 'shown' })).toBe(true);
        expect(fieldRendersNothing({ field_type: 'page_break', visibility: 'shown' })).toBe(true);
    });
});

describe('schema mapping carries visibility to both models (M177)', () => {
    const schema = schemaResponse({
        fields: [
            field({ key: 'ref', config: { visibility: 'hidden' } }),
            field({ key: 'total', field_type: 'calculated', config: { calculated_formula: '1 + 1', visibility: 'shown' } }),
            field({ key: 'plain' }),
        ],
    });

    it('into the render model', () => {
        expect(buildRenderModel(schema).fields.map((f) => [f.key, f.visibility])).toEqual([
            ['ref', 'hidden'],
            ['total', 'shown'],
            ['plain', null],
        ]);
    });

    it('into the engine model', () => {
        expect(buildEngineSchema(schema).fields.map((f) => [f.key, f.visibility])).toEqual([
            ['ref', 'hidden'],
            ['total', 'shown'],
            ['plain', null],
        ]);
    });
});

describe('RuntimeSession — Always and Never (M177)', () => {
    it('shows no question set to Never, and its required rule never blocks the submit', async () => {
        const schema = schemaResponse({
            fields: [
                field({ key: 'name', label: 'Full name' }),
                field({ key: 'ref', label: 'Reference code', is_required: 'required', config: { visibility: 'hidden' } }),
            ],
        });
        const client = fakeClient();
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client } });
        await settle();

        expect(wrapper.text()).toContain('Full name');
        expect(wrapper.text()).not.toContain('Reference code');

        await wrapper.find('input').setValue('Ana');
        await wrapper.find('form').trigger('submit');
        await settle();

        expect(client.submit).toHaveBeenCalledWith(expect.objectContaining({ answers: { name: 'Ana' } }));
        wrapper.unmount();
    });

    it('shows a calculation set to Always with its value, read-only, and keeps it current', async () => {
        const schema = schemaResponse({
            fields: [
                field({ key: 'a', label: 'Apples', field_type: 'integer' }),
                field({ key: 'total', label: 'Total fruit', field_type: 'calculated', config: { calculated_formula: '${a} + 2', visibility: 'shown' } }),
            ],
        });
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client: fakeClient() } });
        await settle();

        const row = () => wrapper.get('[data-computed]');
        expect(row().text()).toContain('Total fruit');
        expect(row().find('input').exists()).toBe(false);

        await wrapper.find('input').setValue('3');
        await settle();
        expect(row().text()).toContain('5');
        wrapper.unmount();
    });

    it('still shows nothing for a calculation left at its default', async () => {
        const schema = schemaResponse({
            sections: [section({ key: 's1', label: 'Totals' })],
            fields: [
                field({ key: 'a', label: 'Apples', field_type: 'integer' }),
                field({ key: 'total', label: 'Total fruit', field_type: 'calculated', section_key: 's1', config: { calculated_formula: '${a} + 2' } }),
            ],
        });
        const wrapper = mount(RuntimeSession, { props: { schema, bootstrap, client: fakeClient() } });
        await settle();

        expect(wrapper.text()).not.toContain('Total fruit');
        expect(wrapper.find('[data-computed]').exists()).toBe(false);
        wrapper.unmount();
    });
});
