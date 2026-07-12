import { describe, expect, it } from 'vitest';
import {
    buildEngineSchema,
    buildRenderModel,
    controlFor,
    resolveOptional,
    resolveText,
} from '../lib/schema-mapping';
import { field, schemaResponse, section, validation } from './fixtures';

describe('buildEngineSchema', () => {
    it('maps the id-free snapshot into engine rows (id = key, FK by key, logic_group → string)', () => {
        const schema = schemaResponse({
            sections: [section({ key: 's1', relevant_expression: "${a} = '1'" })],
            fields: [
                field({ key: 'a', section_key: 's1', sequence: 0 }),
                field({
                    key: 'b',
                    section_key: 's1',
                    sequence: 1,
                    validations: [
                        validation({
                            rule_type: 'greater_than_field',
                            related_field_key: 'a',
                            rule_value: '5',
                            logic_group_ordinal: 2,
                            logic_operator: 'and',
                            sequence: 0,
                        }),
                    ],
                }),
            ],
        });

        const engine = buildEngineSchema(schema);

        expect(engine.fields.map((f) => ({ id: f.id, key: f.key, section: f.form_section_id }))).toEqual([
            { id: 'a', key: 'a', section: 's1' },
            { id: 'b', key: 'b', section: 's1' },
        ]);
        expect(engine.sections[0]).toMatchObject({ id: 's1', key: 's1', relevant_expression: "${a} = '1'" });

        const row = engine.validations[0];
        expect(row).toMatchObject({
            id: 'b:0',
            form_field_id: 'b',
            related_form_field_id: 'a',
            rule_value: '5',
            logic_group: '2', // numeric ordinal → string
            logic_operator: 'and',
        });
    });
});

describe('controlFor', () => {
    it('derives the control kind for each supported type (mirrors FieldInput)', () => {
        expect(controlFor('short_text', true)).toBe('text');
        expect(controlFor('email', true)).toBe('text');
        expect(controlFor('long_text', true)).toBe('textarea');
        expect(controlFor('integer', true)).toBe('number');
        expect(controlFor('single_select', true)).toBe('select');
        expect(controlFor('multi_select', true)).toBe('checkboxes');
        expect(controlFor('yes_no', true)).toBe('yesno');
        expect(controlFor('likert_matrix', true)).toBe('likert_matrix');
        expect(controlFor('matrix', true)).toBe('matrix');
        // Increment G5b2: all three geo types share the one 'geo' control kind.
        expect(controlFor('geopoint', true)).toBe('geo');
        expect(controlFor('geotrace', true)).toBe('geo');
        expect(controlFor('geoshape', true)).toBe('geo');
        expect(controlFor('note', false)).toBe('note');
        expect(controlFor('signature', false)).toBe('unsupported');
    });
});

describe('buildRenderModel', () => {
    it('normalizes options and marks advanced types unsupported', () => {
        const schema = schemaResponse({
            fields: [
                field({
                    key: 'gender',
                    field_type: 'single_select',
                    config: {
                        options: [
                            { value: 'f', label: 'Female', label_translations: { es: 'Femenino' } },
                            { value: 'm' }, // label falls back to value
                        ],
                    },
                }),
                field({ key: 'sig', field_type: 'signature' }),
            ],
        });
        const model = buildRenderModel(schema);
        const gender = model.fields.find((f) => f.key === 'gender')!;
        expect(gender.control).toBe('select');
        expect(gender.options).toEqual([
            { value: 'f', label: 'Female', labelTranslations: { es: 'Femenino' } },
            { value: 'm', label: 'm', labelTranslations: null },
        ]);
        expect(model.fields.find((f) => f.key === 'sig')!.supported).toBe(false);
    });

    it('marks geo types supported and normalizes their config snake_case → camelCase (G5b2)', () => {
        const schema = schemaResponse({
            fields: [
                field({
                    key: 'loc',
                    field_type: 'geopoint',
                    config: {
                        capture_altitude: true,
                        accuracy_threshold: 25,
                        default_center: { lat: 14.6, lon: 121 },
                        default_zoom: 12,
                    },
                }),
                field({ key: 'route', field_type: 'geotrace' }),
            ],
        });
        const model = buildRenderModel(schema);

        const loc = model.fields.find((f) => f.key === 'loc')!;
        expect(loc.control).toBe('geo');
        expect(loc.supported).toBe(true);
        expect(loc.geo).toEqual({
            captureAltitude: true,
            accuracyThreshold: 25,
            defaultCenter: { lat: 14.6, lon: 121 },
            defaultZoom: 12,
        });

        // A geo field with no author config still resolves to a complete, defaulted RenderGeo.
        const route = model.fields.find((f) => f.key === 'route')!;
        expect(route.control).toBe('geo');
        expect(route.geo).toEqual({
            captureAltitude: false,
            accuracyThreshold: null,
            defaultCenter: null,
            defaultZoom: null,
        });
    });

    it('sorts sections and fields by sequence', () => {
        const schema = schemaResponse({
            sections: [section({ key: 'b', sequence: 1 }), section({ key: 'a', sequence: 0 })],
            fields: [field({ key: 'y', sequence: 2 }), field({ key: 'x', sequence: 1 })],
        });
        const model = buildRenderModel(schema);
        expect(model.sections.map((s) => s.key)).toEqual(['a', 'b']);
        expect(model.fields.map((f) => f.key)).toEqual(['x', 'y']);
    });
});

describe('translation resolver', () => {
    it('prefers the locale translation and falls back to the base (never blank)', () => {
        expect(resolveText('Detail', { es: 'Detalle' }, 'es')).toBe('Detalle');
        expect(resolveText('Detail', { es: 'Detalle' }, 'en')).toBe('Detail');
        expect(resolveText('Detail', null, 'es')).toBe('Detail');
        expect(resolveText('Detail', { es: '' }, 'es')).toBe('Detail'); // empty translation ignored
        expect(resolveOptional(null, { es: 'Pista' }, 'es')).toBe('Pista');
        expect(resolveOptional(null, null, 'es')).toBeNull();
    });
});
