import { describe, expect, it } from 'vitest';
import { field, schemaResponse } from '../../../public-runtime/__tests__/fixtures';
import { PREVIEW_LINKED_UNAVAILABLE, PREVIEW_LIST_UNAVAILABLE, applyPreviewChoices, previewChoicesUrl, shownOf, type PreviewChoices } from './preview-choices';

/**
 * M184 (`R-d8c2ce84`) — the builder's Preview shows the choices a form takes from elsewhere: the server's lists are
 * written into a copy of the schema with the runtime's own merges, a long list's question says how much is shown, and a
 * list that cannot be shown says so in an author's words, never a respondent's.
 */

const NONE: PreviewChoices = { linked: {}, cascades: {}, flat: {}, suggestions: {} };

function schema() {
    return schemaResponse({
        versionId: 'draft-1',
        fields: [
            field({ key: 'referred_to', field_type: 'dropdown', config: { options: [], options_source: { form_id: 'src', field_key: 'facility_name' } } }),
            field({ key: 'address', field_type: 'cascading_select', config: { options: [], levels: [{ key: 'region', label: 'Region', list: 'regions' }] } }),
            field({ key: 'facility', field_type: 'single_select', config: { options: [], options_list: 'facilities' }, hint: 'Where you went.' }),
            field({ key: 'visited', field_type: 'short_text', config: { suggestions_source: { kind: 'list', name: 'facilities' } } }),
        ],
    });
}

describe('applyPreviewChoices', () => {
    it('writes every kind of list into a copy of the schema', () => {
        const merged = applyPreviewChoices(schema(), {
            linked: { referred_to: { stamp: 's', available: true, truncated: false, options: [{ value: 'San Jose RHU', label: 'San Jose RHU' }] } },
            cascades: { address: { levels: ['region'], options: [[0, '01', 'Ilocos Region', null]], total: 1 } },
            flat: { facility: { options: [{ value: 'RHU1', label: 'San Jose RHU' }], total: 1 } },
            suggestions: { visited: { suggestions: ['San Jose RHU'], total: 1 } },
        });
        const [referred, address, facility, visited] = merged.version.schema.fields;

        expect(referred.config?.options).toEqual([{ value: 'San Jose RHU', label: 'San Jose RHU' }]);
        expect(address.config?.options).toEqual([{ level: 'region', value: '01', label: 'Ilocos Region', parent: null }]);
        expect(facility.config?.options).toEqual([{ value: 'RHU1', label: 'San Jose RHU' }]);
        expect(facility.hint).toBe('Where you went.');
        expect(visited.config?.suggestions).toEqual(['San Jose RHU']);
        expect(schema().version.schema.fields[2].config?.options).toEqual([]);
    });

    it('says how much of a long list is shown', () => {
        const merged = applyPreviewChoices(schema(), { ...NONE, flat: { facility: { options: [{ value: 'a', label: 'A' }], total: 42010 } } });

        expect(merged.version.schema.fields[2].hint).toBe('Where you went. Showing the first 1 of 42,010 choices here; the form shows them all.');
        expect(shownOf(200, 200)).toBeNull();
    });

    it('says in an author\'s words when a list cannot be shown', () => {
        const [referred, address] = applyPreviewChoices(schema(), NONE).version.schema.fields;

        expect(referred.hint).toContain(PREVIEW_LINKED_UNAVAILABLE);
        expect(address.hint).toContain(PREVIEW_LIST_UNAVAILABLE);
        expect(`${referred.hint} ${address.hint}`).not.toContain('online');
    });
});

describe('previewChoicesUrl', () => {
    it('reads the draft\'s choices, or a published version\'s', () => {
        expect(previewChoicesUrl('form 1')).toBe('/forms/form%201/choices');
        expect(previewChoicesUrl('f', 'v')).toBe('/forms/f/versions/v/choices');
    });
});
