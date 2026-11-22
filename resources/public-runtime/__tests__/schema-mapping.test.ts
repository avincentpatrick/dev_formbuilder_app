import { describe, expect, it } from 'vitest';
import {
    buildEngineSchema,
    buildRenderModel,
    controlOption,
    resolveMatrix,
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
                // M187: Duration, retired by `D140`, is the type no channel answers (Signature has its pad now).
                field({ key: 'took', field_type: 'duration' }),
            ],
        });
        const model = buildRenderModel(schema);
        const gender = model.fields.find((f) => f.key === 'gender')!;
        expect(gender.options).toEqual([
            { value: 'f', label: 'Female', labelTranslations: { es: 'Femenino' } },
            { value: 'm', label: 'm', labelTranslations: null },
        ]);
        expect(model.fields.find((f) => f.key === 'took')!.supported).toBe(false);
    });

    it('marks a signature supported, with a media config the pad uploads through (M187)', () => {
        const schema = schemaResponse({ fields: [field({ key: 'sig', field_type: 'signature', config: { max_count: 1 } })] });
        const sig = buildRenderModel(schema).fields.find((f) => f.key === 'sig')!;

        expect(sig.supported).toBe(true);
        expect(sig.media).toEqual({ acceptedTypes: [], maxFileSizeBytes: null, maxCount: 1, minCount: null, captureSource: null });
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
        expect(loc.supported).toBe(true);
        expect(loc.geo).toEqual({
            captureAltitude: true,
            accuracyThreshold: 25,
            defaultCenter: { lat: 14.6, lon: 121 },
            defaultZoom: 12,
        });

        // A geo field with no author config still resolves to a complete, defaulted RenderGeo.
        const route = model.fields.find((f) => f.key === 'route')!;
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

    it('M247: carries a section’s wide page, and reads an absent or unknown one as the reading width', () => {
        const schema = schemaResponse({
            sections: [
                section({ key: 'wide', sequence: 0, page_width: 'wide' }),
                section({ key: 'plain', sequence: 1 }),
                section({ key: 'odd', sequence: 2, page_width: 'huge' as unknown as 'wide' }),
            ],
            fields: [],
        });
        expect(buildRenderModel(schema).sections.map((s) => [s.key, s.pageWidth])).toEqual([
            ['wide', 'wide'],
            ['plain', 'normal'],
            ['odd', 'normal'],
        ]);
    });

    it('carries the choice layout, and a note’s content blocks while no other question gets any (M130)', () => {
        const blocks = [{ type: 'paragraph', spans: [{ text: 'Bring your card.' }] }];
        const schema = schemaResponse({
            fields: [
                field({ key: 'clinic', field_type: 'single_select', appearance: 'columns', config: { options: [{ value: 'a', label: 'A' }] } }),
                field({ key: 'intro', field_type: 'note', config: { content: blocks } }),
                field({ key: 'plain', field_type: 'note' }),
                field({ key: 'name', field_type: 'short_text', config: { content: blocks } }),
            ],
        });
        const byKey = Object.fromEntries(buildRenderModel(schema).fields.map((f) => [f.key, f]));

        expect(byKey.clinic.appearance).toBe('columns');
        expect(byKey.intro.appearance).toBeNull();
        expect(byKey.intro.content).toEqual(blocks);
        expect(byKey.plain.content).toBeNull();
        // Only a note draws content, so a stray `content` on any other type is not carried at all.
        expect(byKey.name.content).toBeNull();
    });
});

// M183 (`R-5033e87e`, `R-1c1d7266`): a matrix column's own type, choices and required flag, and a grid's layout,
// survive both projections — the engine's (it validates the typed cells) and the render model's (it draws them).
describe('typed grid columns', () => {
    const typedGrid = () =>
        schemaResponse({
            fields: [
                field({
                    key: 'g',
                    field_type: 'matrix',
                    config: {
                        rows: [{ value: 'a', label: 'Clinic A' }],
                        columns: [
                            { value: 'staff', label: 'Staff', type: 'integer', required: true },
                            { value: 'svc', label: 'Services', type: 'multi_select', options: [{ value: 'lab', label: 'Lab', label_translations: { fil: 'Laboratoryo' } }] },
                            { value: 'rate', label: 'Rating' },
                        ],
                        cells: [{ value: 'good', label: 'Good' }],
                        layout: 'stacked',
                    },
                }),
                field({ key: 'sat', field_type: 'likert_matrix', config: { rows: [{ value: 'r', label: 'R' }], columns: [{ value: '1', label: 'Low' }] } }),
            ],
        });

    it('hands the engine each typed column’s rule, keyed by its value', () => {
        const grids = Object.fromEntries(buildEngineSchema(typedGrid()).fields.map((f) => [f.key, f.grid]));

        expect(grids.g?.column_specs).toEqual({
            staff: { type: 'integer', options: [], required: true },
            svc: { type: 'multi_select', options: ['lab'], required: false },
        });
        // A grid with no typed column is exactly the pre-M183 projection.
        expect(grids.sat?.column_specs).toBeUndefined();
    });

    it('draws each matrix column with its type, its choices (its own, else the shared cells) and its required flag', () => {
        const byKey = Object.fromEntries(buildRenderModel(typedGrid()).fields.map((f) => [f.key, f]));

        expect(byKey.g.matrix?.layout).toBe('stacked');
        expect(byKey.g.matrix?.columns).toEqual([
            { value: 'staff', label: 'Staff', labelTranslations: null, type: 'integer', options: [], required: true },
            { value: 'svc', label: 'Services', labelTranslations: null, type: 'multi_select', options: [{ value: 'lab', label: 'Lab', labelTranslations: { fil: 'Laboratoryo' } }], required: false },
            { value: 'rate', label: 'Rating', labelTranslations: null, type: 'single_select', options: [{ value: 'good', label: 'Good', labelTranslations: null }], required: false },
        ]);
        expect(byKey.sat.matrix?.layout).toBe('table');
        expect(byKey.sat.matrix?.columns).toEqual([{ value: '1', label: 'Low', labelTranslations: null }]);
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

/**
 * M249 (`D233`): a section's chapter and progress label, and a choice's short label, reach the render model — blank
 * as none, absent as before — and a control draws a short label only where the locale may show it.
 */
describe('M249 — chapters, progress labels and short labels in the render model', () => {
    it('carries a section\'s chapter, its translations and its progress label, blank and absent as none', () => {
        const schema = schemaResponse({
            sections: [
                section({ key: 'a', sequence: 0, chapter: ' Welcome ', chapter_translations: { fil: 'Maligayang pagdating' }, progress_label: 'Hello' }),
                section({ key: 'b', sequence: 1, chapter: '  ', progress_label: '' }),
                section({ key: 'c', sequence: 2 }),
            ],
            fields: [],
        });

        expect(buildRenderModel(schema).sections.map((s) => [s.key, s.chapter, s.chapterTranslations, s.progressLabel])).toEqual([
            ['a', 'Welcome', { fil: 'Maligayang pagdating' }, 'Hello'],
            ['b', null, null, null],
            ['c', null, null, null],
        ]);
    });

    it('keeps a choice\'s short label only when it has one, and draws it only where the locale may show it', () => {
        const schema = schemaResponse({
            fields: [
                field({
                    key: 'use',
                    field_type: 'dropdown',
                    config: {
                        options: [
                            { value: 'include', label: 'Include. As it stands.', label_translations: { fil: 'Isama. Gaya ng dati.' }, short_label: 'Include' },
                            { value: 'change', label: 'Change it. Measure it another way.', label_translations: { fil: 'Baguhin.' }, short_label: 'Change', short_label_translations: { fil: 'Baguhin' } },
                            { value: 'drop', label: 'Drop it', short_label: '  ' },
                        ],
                    },
                }),
            ],
        });
        const options = buildRenderModel(schema).fields[0].options;

        expect(options.map((o) => o.shortLabel)).toEqual(['Include', 'Change', undefined]);
        expect(options[2]).not.toHaveProperty('shortLabel');
        expect(options.map((o) => controlOption(o, 'en'))).toEqual([
            { value: 'include', label: 'Include. As it stands.', shortLabel: 'Include' },
            { value: 'change', label: 'Change it. Measure it another way.', shortLabel: 'Change' },
            { value: 'drop', label: 'Drop it' },
        ]);
        // In Filipino, "Include" would sit beside a translated list: the closed box shows the translated full label.
        expect(options.map((o) => controlOption(o, 'fil'))).toEqual([
            { value: 'include', label: 'Isama. Gaya ng dati.' },
            { value: 'change', label: 'Baguhin.', shortLabel: 'Baguhin' },
            { value: 'drop', label: 'Drop it' },
        ]);
    });

    it('carries a grid cell choice\'s short label to the resolved grid', () => {
        const schema = schemaResponse({
            fields: [
                field({
                    key: 'grid',
                    field_type: 'matrix',
                    config: {
                        rows: [{ value: 'a01', label: 'A01' }],
                        columns: [{ value: 'use', label: 'Use', options: [{ value: 'include', label: 'Include. As it stands.', short_label: 'Include' }] }],
                        cells: [{ value: 'yes', label: 'Yes', short_label: 'Y' }],
                    },
                }),
            ],
        });
        const grid = resolveMatrix(buildRenderModel(schema).fields[0].matrix, 'en');

        expect(grid?.columns[0].options).toEqual([{ value: 'include', label: 'Include. As it stands.', shortLabel: 'Include' }]);
        expect(grid?.cells).toEqual([{ value: 'yes', label: 'Yes', shortLabel: 'Y' }]);
    });
});
