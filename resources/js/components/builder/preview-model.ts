/**
 * The builder preview's model layer (Increment M118, `B7` / `R-14ce6e05`) — pure, DOM-free and testable,
 * on the `logic-rail.ts` precedent beside it.
 *
 * ── WHY THERE ARE TWO CHANNELS AND NOT ONE, WHICH IS THE WHOLE DESIGN ──────────────────────────────────
 * `createFormRuntime(schema)` takes a FROZEN snapshot: `buildRenderModel()`, `buildEngineSchema()` and
 * `buildTemplateSources()` all run once inside the factory, and `templateSources` carries a comment saying
 * it is "deliberately never wrapped in reactive()". A schema change therefore means a NEW runtime, and
 * `createFormRuntime` registers a `watch` on the ambient effect scope with no disposer — so a rebuild has to
 * be a keyed REMOUNT, exactly as the guest does on a version-drift reschema, never a reassignment.
 *
 * ⛔ AND `shape` IS NARROWER THAN A RENDERER NEEDS, WHICH IS THE TRAP THE PLAN FOR THIS ROW WALKED INTO.
 * `shapeOf()` excludes labels, hints, placeholders, section descriptions, every `*_translations` map,
 * `appearance`, `error_message` AND the whole of `config` except option *values*. That is exactly right for
 * the engine — those are the columns relevance reads — and exactly wrong for a renderer. Rebuild on `shape`
 * and read presentation from the runtime, and typing a label changes nothing on screen until some unrelated
 * structural edit happens, while a grid's rows, a cascade's levels and every option LABEL stay stale
 * indefinitely. A preview that lies is worse than no preview.
 *
 * So: the RENDER model is rebuilt on every store change (pure, cheap, no remount) and drives every visible
 * string; the ENGINE is rebuilt only when `shape` moves, and is asked only for relevance, the required
 * marker and step membership — all keyed on field KEY, which `shape` includes and a label edit cannot move.
 * `FieldRow` and `FieldControl` are prop-driven (`props.field`), and `runtime.labelFor(field)` reads
 * `field.label` off the field it is PASSED, so handing them a live `RenderField` yields live text still piped
 * through the frozen template sources. Nothing is mirrored and no runtime component is edited.
 *
 * ⚠️ ONE BOUNDED EXCEPTION, MEASURED AND ASSERTED RATHER THAN DISCOVERED LATER: inside a REPEATABLE section
 * the member list comes from `runtime.membersOf()`, which reads the frozen model, so member label text there
 * refreshes on the next `shape` change instead of immediately. Reusing `RepeatGroup` verbatim buys real
 * add/remove-instance behaviour and edits no respondent-facing component; faking a single instance to get
 * live labels would trade a bounded staleness for a permanent fiction. `previewLimitations()` states it and
 * a test pins it.
 */
import { projectDraft, type DraftProjection, type DraftProjectionInput, type ProjectionIssue } from './draft-snapshot';
import { buildRenderModel, rendersNothing } from '../../../public-runtime/lib/schema-mapping';
import type { RenderField, RenderModel, RenderSection } from '../../../public-runtime/lib/types';
import type { FormRuntime, RuntimeStep } from '../../../public-runtime/composables/useFormRuntime';

/**
 * How long the engine rebuild waits after a structural edit settles.
 *
 * A named constant rather than a literal at the call site because it is the one number that trades typing
 * latency against remount churn, and a test asserts the wiring uses it — a CSS-guess-style magic number is
 * how the section-strip threshold went wrong in a sibling row.
 */
export const PREVIEW_REBUILD_DEBOUNCE_MS = 300;

/**
 * How many sections the strip renders as segments before it degrades to a select.
 *
 * ⛔ A NAMED CONSTANT WITH A TEST ON BOTH SIDES OF IT, WHICH IS WHAT THE ROW ASKED FOR AND WHY.
 * `MdsSegmentedControl` is an `inline-flex` that never shrinks a segment below its longest word, so a
 * twenty-section form would render a bar no width can hold. The threshold is a legibility decision rather
 * than a breakpoint, so it is stated once here instead of guessed at in CSS — the same reasoning as
 * {@link PREVIEW_REBUILD_DEBOUNCE_MS} above, and the row that asked for this strip named a CSS-guess
 * threshold as the thing that went wrong the last time.
 */
export const PREVIEW_STRIP_MAX_SEGMENTS = 7;

/** The strip's label for the lead block — the fields an author left above every section. */
export const LEAD_STEP_LABEL = 'First questions';

/** The strip's label for a section whose own title is still empty. Mirrors `UNTITLED_LABEL` for fields. */
export const UNTITLED_SECTION_LABEL = 'Untitled section';

export interface PreviewModel {
    /** The frozen snapshot an engine rebuild consumes. Changes identity on every store change; only `shape` gates the rebuild. */
    projection: DraftProjection;
    /** The LIVE render model every visible string comes from. */
    renderModel: RenderModel;
    /** Issues indexed by field key, so a row can render its own chips without scanning the list. */
    issuesByKey: Record<string, ProjectionIssue[]>;
}

export function buildPreviewModel(input: DraftProjectionInput): PreviewModel {
    const projection = projectDraft(input);

    const issuesByKey: Record<string, ProjectionIssue[]> = {};
    for (const issue of projection.issues) {
        (issuesByKey[issue.key] ??= []).push(issue);
    }

    return {
        projection,
        renderModel: buildRenderModel(projection.schema),
        issuesByKey,
    };
}

/**
 * The section a step belongs to, resolved in the LIVE model — `null` for the lead (section-less) block.
 *
 * Tolerant by design: a step named by an engine built up to {@link PREVIEW_REBUILD_DEBOUNCE_MS} ago can
 * reference a section the author has since renamed the key of, and a missing section must degrade to "no
 * heading" rather than throw inside a render function.
 */
export function previewSectionFor(step: RuntimeStep, model: RenderModel): RenderSection | null {
    if (step.sectionKey === null) {
        return null;
    }

    return model.sections.find((s) => s.key === step.sectionKey) ?? null;
}

/** One strip entry: the step it selects, and the text an author reads. */
export interface PreviewStripOption {
    value: string;
    label: string;
}

/**
 * The strip's entries, index-prefixed, resolved in the LIVE model.
 *
 * ⛔ IT MUST NOT READ `RuntimeStep.title`, AND THAT IS NOT A STYLE PREFERENCE. That field is resolved once
 * inside `createFormRuntime` off the FROZEN snapshot, so a section renamed a moment ago would still read its
 * old name in the strip while its own heading, a centimetre below it, read the new one. Resolving through
 * {@link previewSectionFor} and the caller's `titleFor` keeps the strip on the same live channel as every
 * other visible string in the preview.
 *
 * ⚠️ THE INDEX PREFIX IS LOAD-BEARING AND IS NOT DECORATION. A section may legitimately be named "Form",
 * "Logic" or "Preview", and the builder's pane switcher and centre control are both located by EXACT text.
 * Prefixing with the position means no section name can ever be an exact match for one of those controls,
 * which is cheaper and far more durable than asking every future locator to scope itself.
 */
export function previewStepLabels(
    steps: readonly RuntimeStep[],
    model: RenderModel,
    titleFor: (section: RenderSection) => string,
): PreviewStripOption[] {
    return steps.map((step, index) => {
        const section = previewSectionFor(step, model);
        const title = section === null ? LEAD_STEP_LABEL : titleFor(section).trim() || UNTITLED_SECTION_LABEL;

        return { value: step.key, label: `${index + 1}. ${title}` };
    });
}

/**
 * The step's fields, resolved in the LIVE model and in the step's own order.
 *
 * ⚠️ THE FILTER IS THE HALF OF THE DEBOUNCE CONTRACT THAT POINTS THIS WAY. The engine's step lists a key the
 * author has just renamed or deleted; it is simply absent here until the rebuild lands. Dropping it silently
 * is correct — the alternative is a row rendering `undefined` as a label.
 */
export function previewFieldsFor(step: RuntimeStep, model: RenderModel): RenderField[] {
    return step.fieldKeys
        .map((key) => model.fields.find((f) => f.key === key))
        .filter((f): f is RenderField => f !== undefined);
}

/**
 * Fields the LIVE model holds that no current engine step mentions — the other half of the debounce window.
 *
 * ⛔ WITHOUT THIS THE PREVIEW SILENTLY SWALLOWS A NEW QUESTION FOR 300ms AND THE AUTHOR BLAMES THEIR EDIT.
 * A field added, or moved to a new section, exists in the render model immediately and in no step until the
 * engine is rebuilt. It is rendered in a labelled pending block rather than dropped, because "I added a
 * question and nothing happened" is indistinguishable from a bug.
 *
 * Display-only fields are excluded through `rendersNothing()` — the engine's OWN predicate, re-exported by
 * `schema-mapping`, rather than a second list. `hidden`, `calculated` and `page_break` carry no answer and
 * the engine never places them in a step, so they are not pending; they are absent on purpose.
 */
export function previewPendingFields(steps: readonly RuntimeStep[], model: RenderModel): RenderField[] {
    const placed = new Set<string>();
    for (const step of steps) {
        for (const key of step.fieldKeys) {
            placed.add(key);
        }
    }

    return model.fields.filter((f) => !placed.has(f.key) && !rendersNothing(f.fieldType));
}

/**
 * Whether the engine knows this key at all.
 *
 * ⛔ THE GUARD THE 300ms WINDOW MAKES NECESSARY, IN ONE PLACE RATHER THAN AS A `?.` AT EVERY CALL SITE.
 * `fieldRelevance` is keyed by the FROZEN schema's keys. A field the author just added is absent from it,
 * and `runtime.fieldRelevance.value[key] === true` is then `false` — so an unguarded read renders a brand
 * new question as HIDDEN, which reads as "relevance is broken" rather than "the engine has not caught up".
 * Unknown means relevant, unmarked and error-free, deliberately: the preview errs toward showing the author
 * what they just typed.
 */
export function engineKnows(runtime: FormRuntime, key: string): boolean {
    return key in runtime.fieldRelevance.value;
}

/**
 * Whether this field captures a photo, a file or a position — and is therefore rendered inert in the preview
 * (a user decision of record, 2026-09-28).
 *
 * ⛔ DERIVED FROM THE FIELD'S OWN CONFIG, NEVER FROM A LIST OF TYPE NAMES. `RenderField.media` and
 * `RenderField.geo` are non-null for exactly the capture types and null for every other, so a capture type
 * added later is covered the day it ships. A literal `['file_upload', …]` here would be the eleventh
 * hand-mirror in this area and the first one nothing gates.
 *
 * Why inert rather than live, both halves measured: omitting `UploadUrlKey` does NOT disable the picker —
 * `MediaInput` renders the whole `<input type="file">` regardless and fails only on pick, so an author would
 * get a control that looks real and errors. And `GeoInput` statically imports Leaflet's CSS plus three
 * marker PNGs and fetches live map tiles, which would enter the builder route's bundle and re-fetch on every
 * rebuild.
 */
export function isCaptureField(field: RenderField): boolean {
    return field.media !== null || field.geo !== null;
}

/**
 * What the preview deliberately does not do, as data rather than prose, so the pane can render it and a test
 * can assert the list is neither empty nor silently shortened.
 *
 * Each entry is owned by another row or by a recorded decision; none is an oversight, and none is fixed here.
 */
export function previewLimitations(): string[] {
    return [
        'Photo, file and location questions are shown but not interactive.',
        'Only the default language is shown.',
        'Page breaks are not shown, and sections are always stepped.',
        'Inside a repeatable section, wording updates on the next structural change.',
    ];
}
