import { describe, expect, it } from 'vitest';
import { choiceSourceOf } from './choice-source';

// M184 (T3): the same cases as `tests/Unit/Forms/ChoiceSourceTest.php` — the builder and the server read a question's
// source keys the same way.
describe('choiceSourceOf', () => {
    it('reads typed choices when neither source key is declared', () => {
        expect(choiceSourceOf({})).toBe('typed');
        expect(choiceSourceOf({ options: [{ value: 'a', label: 'A' }] })).toBe('typed');
        expect(choiceSourceOf({ options: [], options_source: null, options_list: null })).toBe('typed');
    });

    it('reads a list file once options_list is declared, chosen or not', () => {
        expect(choiceSourceOf({ options: [], options_list: 'facilities' })).toBe('list');
        expect(choiceSourceOf({ options: [], options_list: '' })).toBe('list');
    });

    it('reads another form once options_source is declared, chosen or not, and it wins over a list', () => {
        expect(choiceSourceOf({ options_source: { form_id: 'f', field_key: 'k' } })).toBe('form');
        expect(choiceSourceOf({ options_source: { form_id: null, field_key: null } })).toBe('form');
        expect(choiceSourceOf({ options_source: { form_id: 'f', field_key: 'k' }, options_list: 'x' })).toBe('form');
    });
});
