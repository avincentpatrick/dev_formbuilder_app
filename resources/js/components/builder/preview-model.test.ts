/**
 * The builder preview's model layer (Increment M118, `B7` / `R-14ce6e05`).
 *
 * The highest-value case in this file is the pair that proves the TWO CHANNELS are actually independent:
 * `shape` must not move when only presentation changes, and the render model must move for exactly the same
 * edit. One without the other is the defect — a preview that never updates its wording, or one that tears the
 * engine down on every keystroke. `draft-snapshot.test.ts` already pins the first half at the digest level;
 * this pins that the RENDERER sees the change the digest deliberately ignores.
 */

import { describe as group, expect, it } from 'vitest';

import {
    PREVIEW_REBUILD_DEBOUNCE_MS,
    buildPreviewModel,
    engineKnows,
    isCaptureField,
    previewFieldsFor,
    previewLimitations,
    previewPendingFields,
    previewRenderedSteps,
    previewSectionFor,
    previewStepLabels,
    LEAD_STEP_LABEL,
    PREVIEW_STRIP_MAX_SEGMENTS,
    UNTITLED_SECTION_LABEL,
} from './preview-model';
import type { DraftProjectionInput } from './draft-snapshot';
import type { LocalField, LocalSection } from './types';
import type { FormRuntime, RuntimeStep } from '../../../public-runtime/composables/useFormRuntime';
import type { RenderField } from '../../../public-runtime/lib/types';

function field(overrides: Partial<LocalField> & { uid: string }): LocalField {
    return {
        id: `id-${overrides.uid}`,
        form_section_id: null,
        key: 'q1',
        field_type: 'short_text',
        label: 'Question one',
        hint: null,
        placeholder: null,
        is_required: 'optional',
        relevant_expression: null,
        appearance: null,
        config: {},
        default_value: null,
        is_pii: false,
        is_sensitive: false,
        is_queryable: false,
        indexed_data_type: null,
        sequence: 0,
        section_sequence: null,
        version: null,
        validations: [],
        ...overrides,
    };
}

function section(overrides: Partial<LocalSection> & { uid: string }): LocalSection {
    return {
        id: `sid-${overrides.uid}`,
        key: 's1',
        label: 'Section one',
        description: null,
        is_repeatable: false,
        min_instances: null,
        max_instances: null,
        relevant_expression: null,
        sequence: 0,
        version: null,
        ...overrides,
    };
}

function input(fields: LocalField[], sections: LocalSection[] = []): DraftProjectionInput {
    return {
        form: {
            id: 'form-1',
            title: 'Survey',
            description: null,
            default_locale: 'en',
            supported_locales: ['en'],
        },
        version: { id: 'ver-1', version_number: 1 },
        sections,
        fields,
    };
}

function step(overrides: Partial<RuntimeStep> = {}): RuntimeStep {
    return {
        key: 's1',
        sectionKey: 's1',
        title: 'Section one',
        fieldKeys: ['q1'],
        isRepeat: false,
        continuation: false,
        ...overrides,
    } as RuntimeStep;
}

group('the two channels are independent, which is the whole design', () => {
    // ⛔ THE PAIR THAT MATTERS. If the first assertion fails the engine remounts on every keystroke, which
    // `draft-snapshot.test.ts` calls the cost that makes this feature unaffordable. If the second fails the
    // preview shows stale wording for ever, because `shapeOf()` excludes label text BY CONSTRUCTION and
    // nothing else would carry it. Both directions, or neither means anything.
    it('leaves `shape` untouched but moves the render model when only a label changes', () => {
        const before = buildPreviewModel(input([field({ uid: 'u1', label: 'Before' })]));
        const after = buildPreviewModel(input([field({ uid: 'u1', label: 'After' })]));

        expect(after.projection.shape).toBe(before.projection.shape);
        expect(before.renderModel.fields[0].label).toBe('Before');
        expect(after.renderModel.fields[0].label).toBe('After');
    });

    it('moves the render model for an OPTION LABEL, which `shape` also excludes', () => {
        const options = (label: string) => ({
            options: [{ value: 'yes', label, label_translations: null }],
        });

        const before = buildPreviewModel(
            input([field({ uid: 'u1', field_type: 'single_select', config: options('Yes') })]),
        );
        const after = buildPreviewModel(
            input([field({ uid: 'u1', field_type: 'single_select', config: options('Absolutely') })]),
        );

        expect(after.projection.shape).toBe(before.projection.shape);
        expect(before.renderModel.fields[0].options[0].label).toBe('Yes');
        expect(after.renderModel.fields[0].options[0].label).toBe('Absolutely');
    });

    it('MOVES `shape` when the structure changes, so the engine is rebuilt exactly then', () => {
        const before = buildPreviewModel(input([field({ uid: 'u1', key: 'q1' })]));
        const after = buildPreviewModel(input([field({ uid: 'u1', key: 'renamed' })]));

        expect(after.projection.shape).not.toBe(before.projection.shape);
    });
});

group('issues are indexed for the row that owns them', () => {
    it('indexes by field key so a row renders its own chips without scanning', () => {
        const model = buildPreviewModel(
            input([field({ uid: 'u1', key: 'pick', field_type: 'single_select', config: { options: [] } })]),
        );

        expect(model.issuesByKey.pick?.map((i) => i.code)).toContain('empty_option_list');
    });

    it('gives a field with nothing wrong no entry at all, rather than an empty array', () => {
        const model = buildPreviewModel(input([field({ uid: 'u1', key: 'q1' })]));

        expect(model.issuesByKey.q1).toBeUndefined();
    });
});

group('step resolution reads the LIVE model and tolerates the debounce window', () => {
    it('resolves a section heading from the live model', () => {
        const model = buildPreviewModel(
            input([field({ uid: 'u1', form_section_id: 'sid-s1' })], [section({ uid: 's1', label: 'Consent' })]),
        );

        expect(previewSectionFor(step(), model.renderModel)?.label).toBe('Consent');
    });

    it('returns null for the lead block rather than hunting for a section', () => {
        const model = buildPreviewModel(input([field({ uid: 'u1' })]));

        expect(previewSectionFor(step({ sectionKey: null }), model.renderModel)).toBeNull();
    });

    // ⚠️ The engine is up to PREVIEW_REBUILD_DEBOUNCE_MS behind, so it can name a section key the author has
    // just renamed. Degrading to "no heading" is correct; throwing inside a render function is not.
    it('degrades to null when the engine names a section the live model no longer has', () => {
        const model = buildPreviewModel(input([field({ uid: 'u1' })]));

        expect(previewSectionFor(step({ sectionKey: 'deleted-section' }), model.renderModel)).toBeNull();
    });

    it('drops a step key the live model no longer has, instead of rendering undefined', () => {
        const model = buildPreviewModel(input([field({ uid: 'u1', key: 'q1' })]));

        expect(previewFieldsFor(step({ fieldKeys: ['q1', 'deleted'] }), model.renderModel).map((f) => f.key))
            .toEqual(['q1']);
    });

    it('keeps the step order, not the model order', () => {
        const model = buildPreviewModel(
            input([field({ uid: 'u1', key: 'a', sequence: 0 }), field({ uid: 'u2', key: 'b', sequence: 1 })]),
        );

        expect(previewFieldsFor(step({ fieldKeys: ['b', 'a'] }), model.renderModel).map((f) => f.key))
            .toEqual(['b', 'a']);
    });
});

group('a field the engine has not placed yet is shown, never swallowed', () => {
    // ⛔ WITHOUT THIS THE AUTHOR ADDS A QUESTION AND NOTHING HAPPENS FOR 300ms, WHICH IS INDISTINGUISHABLE
    // FROM A BUG. The field is in the render model immediately and in no step until the rebuild lands.
    it('reports a field that no current step mentions', () => {
        const model = buildPreviewModel(
            input([field({ uid: 'u1', key: 'q1' }), field({ uid: 'u2', key: 'brand_new', sequence: 1 })]),
        );

        expect(previewPendingFields([step({ fieldKeys: ['q1'] })], model.renderModel).map((f) => f.key))
            .toEqual(['brand_new']);
    });

    it('reports nothing once every field is placed', () => {
        const model = buildPreviewModel(input([field({ uid: 'u1', key: 'q1' })]));

        expect(previewPendingFields([step({ fieldKeys: ['q1'] })], model.renderModel)).toEqual([]);
    });

    // The engine never places these, so they are absent on purpose rather than pending. Asserted through the
    // three real type names because `rendersNothing` is the engine's own predicate and this is what it means.
    it.each(['hidden', 'calculated', 'page_break'])('never reports `%s`, which renders nothing', (type) => {
        const model = buildPreviewModel(input([field({ uid: 'u1', key: 'x', field_type: type })]));

        expect(previewPendingFields([], model.renderModel)).toEqual([]);
    });
});

group('the unknown-key guard', () => {
    const runtimeWith = (relevance: Record<string, boolean>) =>
        ({ fieldRelevance: { value: relevance } }) as unknown as FormRuntime;

    it('knows a key the engine carries, even when that key is currently IRRELEVANT', () => {
        // The distinction the guard exists for: `false` means "hidden by a condition", absent means "the
        // engine has not caught up". Reading them the same way renders a brand new question as hidden.
        expect(engineKnows(runtimeWith({ q1: false }), 'q1')).toBe(true);
    });

    it('does not know a key the engine has never seen', () => {
        expect(engineKnows(runtimeWith({ q1: true }), 'brand_new')).toBe(false);
    });
});

group('capture fields are identified from config, never from a type-name list', () => {
    const renderField = (over: Partial<RenderField>) => ({ media: null, geo: null, ...over }) as RenderField;

    it('treats a field carrying media config as capture', () => {
        expect(isCaptureField(renderField({ media: {} as never }))).toBe(true);
    });

    it('treats a field carrying geo config as capture', () => {
        expect(isCaptureField(renderField({ geo: {} as never }))).toBe(true);
    });

    it('treats an ordinary field as not capture', () => {
        expect(isCaptureField(renderField({}))).toBe(false);
    });

    // ⛔ THE ANTI-MIRROR ASSERTION. The four media types and three geo types must be recognised through the
    // PROJECTION rather than through a literal here, so a capture type added to the enum later is covered the
    // day it ships. If this ever fails because a type stopped carrying config, the fix is in the projection,
    // not a list in `preview-model.ts`.
    it.each(['file_upload', 'image_capture', 'audio_capture', 'video_capture', 'geopoint', 'geotrace', 'geoshape'])(
        'recognises `%s` through its projected config',
        (type) => {
            const model = buildPreviewModel(input([field({ uid: 'u1', key: 'cap', field_type: type })]));

            expect(isCaptureField(model.renderModel.fields[0])).toBe(true);
        },
    );

    it.each(['short_text', 'long_text', 'integer', 'single_select', 'yes_no'])(
        'does not mistake `%s` for capture',
        (type) => {
            const model = buildPreviewModel(input([field({ uid: 'u1', key: 'q', field_type: type })]));

            expect(isCaptureField(model.renderModel.fields[0])).toBe(false);
        },
    );
});

group('the debounce and the limitation list are stated, not implied', () => {
    it('names the rebuild delay as a constant a test can read', () => {
        expect(PREVIEW_REBUILD_DEBOUNCE_MS).toBe(300);
    });

    // Each limitation is owned by another row or a recorded decision. Pinning the COUNT as a floor stops one
    // being dropped silently when its owning row ships; pinning the subjects stops the list drifting into
    // vagueness.
    it('lists every limitation the preview actually has', () => {
        const limits = previewLimitations().join(' ');

        // Three since `M124`: page breaks left the list when the preview started paginating (`R-8c517fb6`).
        expect(previewLimitations().length).toBeGreaterThanOrEqual(3);
        expect(limits).toContain('not interactive');
        expect(limits).toContain('default language');
        expect(limits).toContain('repeatable section');
    });

    /**
     * ⛔ EVERY ASSERTION IN THE CASE ABOVE SURVIVES `M120`'s EDIT, WHICH IS EXACTLY WHY THIS ONE IS NOT
     * OPTIONAL. `R-f1332829` deleted the SECOND CLAUSE of a compound entry and left the entry in place, so
     * the count floor held at four and all four subject substrings — including `Page breaks` — still matched
     * over a list that had materially changed. A gate that cannot see the change it guards is decoration.
     */
    it('no longer claims the preview is always stepped, because it is not', () => {
        const limits = previewLimitations().join(' ');

        expect(limits).not.toContain('always stepped');
        expect(limits).not.toContain('sections are always');
    });

    // `M124` (`R-8c517fb6`): a stepped preview paginates at page breaks exactly as the respondent's form does,
    // and neither paginates a repeatable section — so no page-break wording belongs on a list of what the
    // preview does NOT do. A substring check, because a narrowed entry ("…inside a repeatable section") would
    // still be the false claim this case exists to refuse.
    it('says choices taken from another form show on the live form only (M133)', () => {
        expect(previewLimitations().join(' ')).toContain('Choices taken from another form appear on the live form');
    });

    it('no longer claims page breaks are missing, because the preview shows them', () => {
        expect(previewLimitations().join(' ').toLowerCase()).not.toContain('page break');
    });
});

group('the strip labels a step from the live model, never from the frozen one', () => {
    const titleFor = (s: { label: string }) => s.label;

    it('prefixes the position, so no section name can collide with a builder control', () => {
        const model = buildPreviewModel(
            input([field({ uid: 'f1', key: 'q1', form_section_id: 'sec-a' })], [section({ uid: 's1', key: 'a', label: 'Logic' })]),
        ).renderModel;

        const labels = previewStepLabels([step({ key: 'a', sectionKey: 'a' })], model, titleFor as never);

        // ⛔ "Logic" IS A REAL SECTION NAME AND ALSO THE NAME OF A CENTRE-PANE CONTROL located by exact text.
        // The prefix is what makes the two un-confusable without asking every locator to scope itself.
        expect(labels).toEqual([{ value: 'a', label: '1. Logic' }]);
        expect(labels[0].label).not.toBe('Logic');
    });

    it('names the lead block rather than leaving it blank', () => {
        const model = buildPreviewModel(input([field({ uid: 'f1', key: 'q1' })])).renderModel;

        const labels = previewStepLabels([step({ key: '__lead__', sectionKey: null })], model, titleFor as never);

        expect(labels).toEqual([{ value: '__lead__', label: `1. ${LEAD_STEP_LABEL}` }]);
    });

    // An author creates a section before naming it, and a strip entry reading "3. " is unreachable by name
    // for a screen reader and unclickable-by-intent for everyone else.
    it('falls back for a section whose title is still empty', () => {
        const model = buildPreviewModel(
            input([field({ uid: 'f1', key: 'q1', form_section_id: 'sec-a' })], [section({ uid: 's1', key: 'a', label: '  ' })]),
        ).renderModel;

        const labels = previewStepLabels([step({ key: 'a', sectionKey: 'a' })], model, titleFor as never);

        expect(labels[0].label).toBe(`1. ${UNTITLED_SECTION_LABEL}`);
    });

    // `M124` — two pages of one section are two strip entries, and they must not read the same.
    it('marks a later page of a paginated section as continuing', () => {
        const model = buildPreviewModel(
            input([field({ uid: 'f1', key: 'q1', form_section_id: 'sec-a' })], [section({ uid: 's1', key: 'a', label: 'Household' })]),
        ).renderModel;

        const labels = previewStepLabels(
            [step({ key: 'a', sectionKey: 'a' }), step({ key: 'a#pb1', sectionKey: 'a', continuation: true })],
            model,
            titleFor as never,
        );

        expect(labels).toEqual([
            { value: 'a', label: '1. Household' },
            { value: 'a#pb1', label: '2. Household (continued)' },
        ]);
    });

    // ⛔ THE THRESHOLD IS A PRODUCT DECISION AND THIS IS WHAT KEEPS IT ONE. `PreviewStepStrip.test.ts`
    // asserts the render on both sides of it; this asserts the number itself has not drifted into a value
    // no control can hold, which is how the CSS guess this row replaced went wrong.
    it('states a segment ceiling a segmented control can actually render', () => {
        expect(PREVIEW_STRIP_MAX_SEGMENTS).toBeGreaterThanOrEqual(3);
        expect(PREVIEW_STRIP_MAX_SEGMENTS).toBeLessThanOrEqual(9);
    });
});

/**
 * Which steps the preview renders (`M120`, `R-f1332829`, `D35`).
 *
 * The decision is one ternary, and it lives here rather than in the component for the reason
 * `PREVIEW_STRIP_MAX_SEGMENTS` does: it is provable without mounting, and the argument for where the flag
 * must come from needs somewhere to live that a computed in an SFC does not give it.
 */
group('previewRenderedSteps', () => {
    const s1 = step({ key: 's1' });
    const s2 = step({ key: 's2' });

    it('renders only the current step when the form is stepped', () => {
        expect(previewRenderedSteps([s1, s2], s2, false).map((s) => s.key)).toEqual(['s2']);
    });

    it('renders every visible step in one-page mode, in order', () => {
        expect(previewRenderedSteps([s1, s2], s1, true).map((s) => s.key)).toEqual(['s1', 's2']);
    });

    it('renders the whole list in one-page mode even with no current step', () => {
        // The engine seeds a current step on every build, so this is defence rather than a live path — but it
        // is the branch that would otherwise render nothing at all for a one-page form.
        expect(previewRenderedSteps([s1, s2], null, true).map((s) => s.key)).toEqual(['s1', 's2']);
    });

    it('renders nothing when stepped with no current step, rather than falling back to the first', () => {
        // ⛔ NOT `[steps[0]]`. `currentStep` already degrades to the first visible step itself, so a second
        // fallback here would put a heading over nothing in the one state — zero visible steps — that the
        // pane's empty-state copy exists for.
        expect(previewRenderedSteps([s1, s2], null, false)).toEqual([]);
    });

    it('returns a copy, so a caller cannot reorder the engine\'s own step list', () => {
        const steps = [s1, s2];

        expect(previewRenderedSteps(steps, s1, true)).not.toBe(steps);
    });
});
