<script setup lang="ts">
/**
 * The Logic view (Increment H21d1, Doc #27 §8) — the form's branching, read-derived and drawn as a vertical
 * rail. It WRITES NOTHING: selecting a node hands the existing config panel the same selection the Structure
 * view would, and every edit still happens there. H21d2 is the increment that owns a write path.
 *
 * ── A RAIL WITH CONDITIONS, NOT A FLOW CHART, AND IT SAYS SO ────────────────────────────────────────
 * §2's step graph is a total order filtered by relevance: no edge table, no successor column, no
 * `next_step_id`. A "branch" is a predicate on a node, so there is no arrow to drag from section 3 to
 * section 7 and a skip over four sections is four conditions. The standing explainer at the top of the rail
 * is not decoration — an author who expects to draw arrows must be told, rather than left to infer it from
 * the absence of a control.
 *
 * ── LAYOUT IS VERTICAL BY CONSTRUCTION ──────────────────────────────────────────────────────────────
 * The responsive-overflow lesson has reddened the a11y gate three times (H12b, H14, H15b) on a non-wrapping
 * flex row or an `overflow` container in a shared primitive, and Doc #27 §9 applies it to this row
 * pre-emptively as "the largest such surface yet built". A vertical stack cannot overflow horizontally, and
 * the one genuinely wide thing here — an author's raw expression — WRAPS rather than scrolling.
 */
import { computed } from 'vue';
import { MdsBadge, MdsButton, MdsEmptyState, MdsIcon, statusVariant } from '@meridian/design-system';
import { buildRail, formLevelNotices, railHasLogic, type GraphNotice, type RailNode } from './logic-rail';
import type { ConditionReading } from './condition-describer';
import { useGraphNotices } from './useGraphNotices';
import type { BuilderStore } from './useBuilderStore';

const props = defineProps<{ store: BuilderStore; formId: string; active: boolean }>();

const store = props.store;
const graph = useGraphNotices(
    props.formId,
    store,
    computed(() => props.active),
);

const rail = computed<RailNode[]>(() => buildRail({ groups: store.groups.value, notices: graph.notices.value }));
const formNotices = computed<GraphNotice[]>(() => formLevelNotices(graph.notices.value));
const hasLogic = computed<boolean>(() => railHasLogic(rail.value));

function isSelected(node: RailNode): boolean {
    return node.uid !== null && store.selection.value?.kind === 'section' && store.selection.value.uid === node.uid;
}

function isFieldSelected(uid: string): boolean {
    return store.selection.value?.kind === 'field' && store.selection.value.uid === uid;
}

/** The one-line summary under a node's name — never the raw expression, which is rendered separately. */
function summary(reading: ConditionReading): string {
    switch (reading.status) {
        case 'blank':
            return 'Always shown';
        case 'described':
            return reading.prose;
        case 'opaque':
            return 'Shown when this condition holds:';
        case 'invalid':
            return 'This condition can’t be read, so publishing will refuse it:';
    }
}
</script>

<template>
    <div class="rail">
        <p class="rail__explainer">
            <MdsIcon name="info" size="sm" />
            <span>
                Questions and sections appear in the order below. A condition decides
                <strong>whether</strong> a step is shown, not <strong>where</strong> it goes — so to skip
                ahead, condition each section you want to pass over. Edit a condition by selecting it here
                and using the panel on the right.
            </span>
        </p>

        <!-- Server-derived analysis: the three whole-graph notices, plus the honest state of the check. -->
        <div class="rail__status" role="status" aria-live="polite">
            <span v-if="graph.error.value" class="rail__status-error">
                <MdsIcon name="alert" size="sm" />
                {{ graph.error.value }}
                <MdsButton variant="tertiary" @click="graph.refresh()">Try again</MdsButton>
            </span>
            <span v-else-if="graph.loading.value">
                {{ graph.stale.value ? 'Rechecking conditions…' : 'Checking conditions…' }}
            </span>
        </div>

        <ul v-if="formNotices.length > 0" class="rail__form-notices">
            <li v-for="(notice, i) in formNotices" :key="i" class="rail__notice">
                <MdsIcon name="alert" size="sm" />
                <span>{{ notice.message }}</span>
            </li>
        </ul>

        <MdsEmptyState
            v-if="rail.length === 0"
            headline="Nothing to map yet"
            description="Add a section or a question in the Structure view, then come back to see how they connect."
        />

        <template v-else>
            <p v-if="!hasLogic" class="rail__none">
                No conditions yet — every question below is shown to everyone. Select a section or a question
                and add a <em>Relevant (display) expression</em> in the panel on the right to branch.
            </p>

            <ol class="rail__nodes">
                <li v-for="(node, index) in rail" :key="node.uid ?? '__lead__'" class="rail__node">
                    <div class="rail__marker" aria-hidden="true">
                        <span class="rail__dot">{{ index + 1 }}</span>
                        <span v-if="index < rail.length - 1" class="rail__line" />
                    </div>

                    <div class="rail__card" :class="{ 'is-selected': isSelected(node) }">
                        <!-- The ungrouped bucket is a synthetic step with no row behind it (`__lead__`,
                             Doc #27 §5.3), so it is a heading rather than a control: there is nothing for a
                             config panel to open, and a button that selects nothing is a keyboard stop that
                             does nothing. -->
                        <button
                            v-if="node.uid !== null"
                            type="button"
                            class="rail__head"
                            :aria-pressed="isSelected(node)"
                            @click="store.select({ kind: 'section', uid: node.uid })"
                        >
                            <MdsIcon name="layout" size="sm" />
                            <span class="rail__name">{{ node.label }}</span>
                            <MdsBadge v-if="node.isRepeatable" v-bind="statusVariant('draft')" label="Repeatable" />
                            <span class="rail__count">
                                {{ node.fieldCount }} {{ node.fieldCount === 1 ? 'question' : 'questions' }}
                            </span>
                        </button>
                        <div v-else class="rail__head rail__head--static">
                            <MdsIcon name="layout" size="sm" />
                            <span class="rail__name">{{ node.label }}</span>
                            <span class="rail__count">
                                {{ node.fieldCount }} {{ node.fieldCount === 1 ? 'question' : 'questions' }}
                            </span>
                        </div>

                        <p class="rail__summary" :class="`rail__summary--${node.reading.status}`">
                            {{ summary(node.reading) }}
                        </p>
                        <code v-if="node.reading.status !== 'blank'" class="rail__expression">{{
                            node.expression
                        }}</code>

                        <p v-if="node.neverAStep" class="rail__aside">
                            <MdsIcon name="info" size="sm" />
                            <span>
                                Nobody filling the form sees this section — every question in it is hidden,
                                calculated or a page break. It holds reference values, not questions.
                            </span>
                        </p>

                        <ul v-if="node.notices.length > 0" class="rail__notices">
                            <li v-for="(notice, i) in node.notices" :key="i" class="rail__notice">
                                <MdsIcon name="alert" size="sm" />
                                <span>{{ notice.message }}</span>
                            </li>
                        </ul>

                        <ul v-if="node.conditioned.length > 0" class="rail__fields">
                            <li v-for="field in node.conditioned" :key="field.uid" class="rail__field">
                                <button
                                    type="button"
                                    class="rail__field-head"
                                    :class="{ 'is-selected': isFieldSelected(field.uid) }"
                                    :aria-pressed="isFieldSelected(field.uid)"
                                    @click="store.select({ kind: 'field', uid: field.uid })"
                                >
                                    <MdsIcon name="filter" size="sm" />
                                    <span class="rail__name">{{ field.label }}</span>
                                </button>
                                <p class="rail__summary" :class="`rail__summary--${field.reading.status}`">
                                    {{ summary(field.reading) }}
                                </p>
                                <code class="rail__expression">{{ field.expression }}</code>
                                <ul v-if="field.notices.length > 0" class="rail__notices">
                                    <li v-for="(notice, i) in field.notices" :key="i" class="rail__notice">
                                        <MdsIcon name="alert" size="sm" />
                                        <span>{{ notice.message }}</span>
                                    </li>
                                </ul>
                            </li>
                        </ul>
                    </div>
                </li>
            </ol>
        </template>
    </div>
</template>

<style scoped>
.rail {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    padding: var(--mds-space-4);
    height: 100%;
    overflow-y: auto;
}

.rail__explainer,
.rail__aside,
.rail__notice {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-2);
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.rail__explainer {
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.rail__status {
    min-height: var(--mds-space-5);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

/* `--mds-color-status-danger-fg`, NOT `--mds-color-danger-text`. The latter is `danger-300` in dark, which
   is 4.22:1 on `bg-surface` at 13px — a real `color-contrast` violation the dark builder-axe scan caught
   here. The status-* family is the on-surface pair (8.43:1 light, 6.39:1 dark) and is what this scale is
   for; `danger-text` is sized for the places the design system uses it, not for 13px body copy. */
.rail__status-error {
    display: inline-flex;
    align-items: center;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    color: var(--mds-color-status-danger-fg);
}

.rail__none {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
}

.rail__form-notices,
.rail__notices,
.rail__fields,
.rail__nodes {
    margin: 0;
    padding: 0;
    list-style: none;
}

.rail__form-notices,
.rail__notices {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

/* A notice is text + icon, never colour alone (WCAG 1.4.1). */
.rail__notice {
    color: var(--mds-color-status-warning-fg);
}

.rail__nodes {
    display: flex;
    flex-direction: column;
}

.rail__node {
    display: grid;
    grid-template-columns: auto minmax(0, 1fr);
    gap: var(--mds-space-3);
}

/* The rail itself: a numbered dot per node and a connector down to the next one. Purely presentational —
   the ORDER is carried by the <ol>, which is what a screen reader announces. */
.rail__marker {
    display: flex;
    flex-direction: column;
    align-items: center;
    gap: var(--mds-space-1);
}

.rail__dot {
    display: grid;
    place-items: center;
    width: 28px;
    height: 28px;
    flex-shrink: 0;
    border-radius: var(--mds-radius-full);
    /* ⛔ M23 — SEMANTIC IS NOT THE SAME THING AS VISIBLE, AND THIS DOT IS THE PROOF. It read
       --mds-color-bg-sunken, which the primitive-ban gate added this increment PERMITS, and it was
       still exactly as invisible as the achievements medallion: this dot nearest painting ancestor is
       .builder (Builder.vue:643) = --mds-color-bg-canvas, and in dark theme-overrides.css re-points
       bg-sunken to neutral-50 while bg-canvas is ALREADY neutral-50 — so the disc was 1.000:1 against
       its own ground. status-neutral-bg is neutral-200 in dark (1.55:1 here) and unchanged in light. */
    background-color: var(--mds-color-status-neutral-bg);
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-caption-font-size);
    font-variant-numeric: tabular-nums;
}

.rail__line {
    flex: 1;
    width: 2px;
    min-height: var(--mds-space-4);
    background-color: var(--mds-color-border-default);
}

.rail__card {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    margin-bottom: var(--mds-space-4);
    padding: var(--mds-space-3);
    min-width: 0;
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.rail__card.is-selected {
    border-color: var(--mds-color-border-strong);
}

.rail__head,
.rail__field-head {
    display: flex;
    align-items: center;
    /* Wraps rather than overflowing: a long section name beside a badge and a count is the exact shape the
       responsive gate has caught three times. */
    flex-wrap: wrap;
    gap: var(--mds-space-2);
    width: 100%;
    padding: 0;
    border: 0;
    background: transparent;
    color: var(--mds-color-text-heading);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    font-weight: var(--mds-font-weight-medium);
    text-align: left;
    cursor: pointer;
}

.rail__head:focus-visible,
.rail__field-head:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

/* The ungrouped bucket renders as a plain div — there is no row to select. */
.rail__head--static {
    cursor: default;
}

.rail__name {
    min-width: 0;
    overflow-wrap: anywhere;
}

.rail__count {
    margin-left: auto;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-caption-font-size);
    font-weight: var(--mds-font-weight-regular);
    white-space: nowrap;
}

.rail__summary {
    margin: 0;
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-sm-font-size);
}

.rail__summary--blank {
    color: var(--mds-color-text-secondary);
}

.rail__summary--invalid {
    color: var(--mds-color-status-danger-fg);
}

/* An author's own text, verbatim. It WRAPS — never a scroll container, which would put a horizontal
   scrollbar inside a pane at 375px and hide the end of the very thing being explained. */
.rail__expression {
    display: block;
    padding: var(--mds-space-2);
    border-radius: var(--mds-radius-sm);
    background-color: var(--mds-color-bg-sunken);
    color: var(--mds-color-text-body);
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-caption-font-size);
    white-space: pre-wrap;
    overflow-wrap: anywhere;
}

.rail__fields {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    margin-top: var(--mds-space-1);
    padding-left: var(--mds-space-3);
    border-left: 2px solid var(--mds-color-border-default);
}

.rail__field {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    min-width: 0;
}

.rail__field-head {
    font-size: var(--mds-type-body-sm-font-size);
}
</style>
