<script setup lang="ts">
/**
 * The getting-started checklist (DSR §3.13, Increment J5b) — a short, finite list of things a workspace
 * has or has not done yet, each one either ticked or offering the one link that would tick it.
 *
 * ── IT IS DELIBERATELY THE SAME GRAMMAR AS `MdsPasswordStrength`, AND THAT IS REUSE, NOT COINCIDENCE ────
 * Both are "a list of conditions, each met or not, with a running count" — so both use the identical
 * construction: the `check` glyph for a met row, a CSS ring for a pending one, and a visually-hidden state
 * word so the state is never carried by colour or glyph alone (WCAG 1.4.1). A reader who has seen one
 * recognises the other, and a future author changing the met-mark has one visual idea to keep consistent
 * rather than two that merely resemble each other.
 *
 * The two differ in exactly one way that matters, and it is why this is a second component rather than a
 * prop on the first: **a checklist row can be actionable.** A password rule is something the user is
 * already doing; a checklist row is somewhere they have not been yet.
 *
 * ── THE PROGRESS BAR IS `MdsProgress`, NOT A HAND-ROLLED METER ──────────────────────────────────────────
 * DSR §3.9's governing rule — a measurable operation never gets an invented presentation — and its
 * "always paired with a numeric label" clause, which that component enforces by having no way to hide the
 * label. `valueText` carries the domain-native reading ("2 of 4"), because `aria-valuenow="2"` against
 * `aria-valuemax="4"` is announced as "50 percent", which is not what anybody is counting.
 *
 * ⚠️ **AND THIS IS NOT §3.9's STEP-COUNT VARIANT, WHICH IS STILL UNBUILT AND STILL SHOULD BE.** That
 * variant is "Step X of N" for multi-step form NAVIGATION, with a visited-set rule (a step is done because
 * the respondent saw it, never because its index is lower). A checklist has no current position, no
 * ordering to be ahead or behind of, and no navigation — reaching for it here would have generalised a
 * component against a shape it does not have. See `docs/feature-backlog.md`.
 *
 * ── THE COMPONENT NEVER HIDES ITSELF ────────────────────────────────────────────────────────────────────
 * `dismissible` renders the control and `dismiss` is emitted; the `v-if` stays at the call site. Whether a
 * dismissal survives a reload is a page decision every time — `MdsAlert` and `MdsToast` both draw this
 * line, and here the page's answer is a column on the membership row.
 *
 * ── `linkComponent`, FOR THE REASON `MdsBreadcrumb` HAS IT ──────────────────────────────────────────────
 * Defaults to a plain anchor so the package imports nothing from the application's router; an Inertia app
 * passes its own `Link` and a row becomes a client visit rather than a document load.
 *
 * ⚠️ **AN ITEM WITH NO `href` RENDERS AS TEXT, AND BOTH ROUTES TO THAT STATE ARE INTENDED.** A done row has
 * nothing left to do, and a row whose destination the reader is refused must not offer a link that bounces.
 * The label keeps its place either way: dropping the row would make the same workspace show a different
 * NUMBER of steps per role, which is the hierarchy loss `MdsBreadcrumb` refuses for the same reason.
 */
import { computed, useId } from 'vue';
import type { Component } from 'vue';
import Icon from '../Icon/Icon.vue';
import Progress from '../Progress/Progress.vue';

export interface ChecklistItem {
    /** Stable identity for the `v-for` key — never the label, which is copy and may be reworded. */
    key: string;
    label: string;
    /** One line saying what the step actually involves. Omit for a step whose label is self-evident. */
    description?: string;
    done: boolean;
    /**
     * Where the step is carried out. Omit it for a done step (nothing to do) and for a destination this
     * reader may not reach — the component renders text in both cases and does not distinguish them,
     * because the *caller* is the only thing that knows which applies.
     */
    href?: string;
}

const props = withDefaults(
    defineProps<{
        items: ChecklistItem[];
        /** The card's own title, and the region's accessible name. */
        label?: string;
        /**
         * Names the meter. Deliberately NOT the same string as `label`: `MdsProgress` always renders its
         * label, so passing the title twice would print it twice.
         */
        progressLabel?: string;
        /** Renders a dismiss control. The page decides what dismissal means and how long it lasts. */
        dismissible?: boolean;
        dismissLabel?: string;
        /** What an actionable row renders as. An Inertia app passes `Link`; see the docblock. */
        linkComponent?: Component | string;
    }>(),
    {
        label: 'Getting started',
        progressLabel: 'Steps completed',
        dismissible: false,
        dismissLabel: 'Hide the getting-started checklist',
        linkComponent: 'a',
    },
);

defineEmits<{ dismiss: [] }>();

const titleId = useId();

const doneCount = computed(() => props.items.filter((item) => item.done).length);

/**
 * The reading a person actually counts in. Not a percentage: four steps do not divide into a percentage
 * anybody thinks in, and "50%" of a to-do list is a stranger sentence than "2 of 4".
 */
const valueText = computed(() => `${doneCount.value} of ${props.items.length}`);

/** The per-row state word — the same information the glyph carries, for a reader who gets no glyph. */
function stateLabel(done: boolean): string {
    return done ? 'Done:' : 'To do:';
}
</script>

<template>
    <section v-if="items.length > 0" class="mds-checklist" :aria-labelledby="titleId">
        <div class="mds-checklist__head">
            <p :id="titleId" class="mds-checklist__title">{{ label }}</p>
            <button
                v-if="dismissible"
                type="button"
                class="mds-checklist__dismiss"
                :aria-label="dismissLabel"
                @click="$emit('dismiss')"
            >
                <Icon name="close" size="sm" />
            </button>
        </div>

        <Progress
            :label="progressLabel"
            :value="doneCount"
            :max="items.length"
            :value-text="valueText"
            size="sm"
        />

        <!-- `role="list"` is NOT redundant beside `list-style: none`, which strips list semantics in
             WebKit — so without it VoiceOver reads four steps with no sense of how many there are, on the
             one surface whose entire point is how many there are. `MdsBreadcrumb` carries the same
             attribute for the same reason. -->
        <ul class="mds-checklist__list" role="list">
            <li
                v-for="item in items"
                :key="item.key"
                class="mds-checklist__row"
                :class="{ 'mds-checklist__row--done': item.done }"
            >
                <!-- The GLYPH is the signifier, never the colour alone (WCAG 1.4.1). The pending mark is
                     drawn in CSS rather than added to the icon registry: a glyph there must be
                     distinguishable from every other at sidebar-rail size, which a bare ring is not.
                     `MdsPasswordStrength` makes the identical trade. -->
                <Icon
                    v-if="item.done"
                    name="check"
                    size="sm"
                    class="mds-checklist__mark"
                    aria-hidden="true"
                />
                <span v-else class="mds-checklist__mark mds-checklist__mark--pending" aria-hidden="true" />

                <span class="mds-checklist__text">
                    <span class="mds-checklist__sr">{{ stateLabel(item.done) }}</span>
                    <component
                        :is="linkComponent"
                        v-if="item.href"
                        class="mds-checklist__link"
                        :href="item.href"
                        >{{ item.label }}</component
                    >
                    <span v-else class="mds-checklist__label">{{ item.label }}</span>
                    <span v-if="item.description" class="mds-checklist__desc">{{ item.description }}</span>
                </span>
            </li>
        </ul>
    </section>
</template>

<style scoped>
/* ⚠️ `position: relative` IS LOAD-BEARING, NOT DECORATIVE — the fourth instance of a defect this
   repository cannot see with any gate it runs. `.mds-checklist__sr` below is the absolutely-positioned
   clip-rect visually-hidden pattern, and absolute positioning resolves against the nearest POSITIONED
   ancestor: without this line, four 1px hidden nodes are parked against whatever is positioned further up
   and can extend the DOCUMENT's scrollable box. G11 found it horizontally on `MdsDataTable`, JR5
   vertically on `MdsSegmentedControl` (73px of real scroll), and `MdsPasswordStrength` carries the same
   line with the same warning. happy-dom computes no layout, the e2e overflow assertion reads a box
   `.app-shell { overflow-x: clip }` pins flat, and axe has no rule for it — so the guard is a source-text
   assertion in `Checklist.test.ts`. */
.mds-checklist {
    position: relative;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
}

.mds-checklist__head {
    display: flex;
    align-items: flex-start;
    justify-content: space-between;
    gap: var(--mds-space-2);
}

/* A paragraph, never a heading — this component is dropped into a page that already owns its heading
   order, and a fixed level here would break §4.1's heading-order rule wherever the level was wrong.
   `MdsAlert`'s title makes the same argument. The region's accessible name comes from `aria-labelledby`,
   which does not care what element supplies it. */
.mds-checklist__title {
    margin: 0;
    font-size: var(--mds-type-body-lg-font-size);
    line-height: var(--mds-type-body-lg-line-height);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

.mds-checklist__dismiss {
    position: relative;
    flex-shrink: 0;
    display: inline-flex;
    align-items: center;
    justify-content: center;
    width: 24px;
    height: 24px;
    padding: 0;
    border: 0;
    border-radius: var(--mds-radius-sm);
    background: transparent;
    color: var(--mds-color-text-secondary);
    cursor: pointer;
}

/* WCAG 2.5.8 — the visual box is 24px, so the HIT AREA is expanded rather than the glyph inflated (§4.4).
   `MdsAlert`'s and `MdsToast`'s dismiss controls use the identical construction. */
.mds-checklist__dismiss::before {
    content: '';
    position: absolute;
    top: 50%;
    left: 50%;
    min-width: 44px;
    min-height: 44px;
    width: 100%;
    height: 100%;
    transform: translate(-50%, -50%);
}

.mds-checklist__dismiss:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.mds-checklist__list {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    margin: 0;
    padding: 0;
    list-style: none;
}

.mds-checklist__row {
    display: flex;
    align-items: flex-start;
    gap: var(--mds-space-2);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    color: var(--mds-color-text-body);
}

/* `-fg`, never `-bg`: this is ink on the page ground, and the `-bg` half of a status pair is a FILL that
   guarantees contrast only for text printed on it — the J2a/J4a/J4c1 finding, three times paid for. */
.mds-checklist__row--done {
    color: var(--mds-color-status-success-fg);
}

.mds-checklist__mark {
    flex-shrink: 0;
    /* Aligned to the first line's cap height rather than to the row box, so a label that wraps keeps its
       mark beside the FIRST line. */
    margin-top: 0.15em;
}

/* ⚠️ 16px, NOT `1em`. `MdsIcon`'s `sm` is a fixed 16×16 box while `1em` resolves against `body-sm`, so a
   ring narrower than the check replacing it would shift every label sideways as a row ticks over — and
   these rows tick over while the user is looking at them. Fixed rather than relative for the same reason
   the icon is: both must stay identical at every `[data-font-size]` setting. */
.mds-checklist__mark--pending {
    width: 16px;
    height: 16px;
    border: 1.5px solid currentColor;
    border-radius: var(--mds-radius-full);
    opacity: 0.55;
}

.mds-checklist__text {
    min-width: 0;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-0-5);
    /* A step label is authored copy, but this card sits in a dashboard column that narrows to ~300px at
       the extra_large type scale — the Domains-page lesson, applied before it bites. */
    overflow-wrap: anywhere;
}

.mds-checklist__link,
.mds-checklist__label {
    font-weight: var(--mds-font-weight-medium);
}

.mds-checklist__link {
    color: var(--mds-color-action-primary-fg);
    text-decoration: underline;
    text-underline-offset: 2px;
}

.mds-checklist__link:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-sm);
}

/* Secondary on every row INCLUDING a done one: the row's own colour states the outcome, and a green
   sentence of explanatory prose reads as a message rather than as a caption. */
.mds-checklist__desc {
    color: var(--mds-color-text-secondary);
    font-weight: var(--mds-font-weight-regular);
}

/* Visually hidden, still announced. There is no shared `.sr-only` in this repository — every component
   hand-rolls this exact block (SegmentedControl, DataTable, BarChart, Checkbox, Switch, Spinner,
   PasswordStrength) — and `display: none` is not a substitute: it removes the text from the
   accessibility tree entirely, which is the one thing this span exists to be in. */
.mds-checklist__sr {
    position: absolute;
    width: 1px;
    height: 1px;
    padding: 0;
    margin: -1px;
    overflow: hidden;
    clip: rect(0 0 0 0);
    white-space: nowrap;
    border: 0;
}
</style>
