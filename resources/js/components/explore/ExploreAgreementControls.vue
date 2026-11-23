<script setup lang="ts">
// What an agreement list counts (M250, `agreement-report`, `D234`): the questions on it, the answers that are not a
// view, the answers that count together as agreeing, the threshold and the minimum number of views. It edits one
// `AgreementSpec` and emits the next one; the builder owns the declaration it sits in.
//
// A question table is ticked once, as a column, and every row of it goes on the list — 35 indicators are one tick.
import { computed } from 'vue';
import { MdsButton, MdsCheckbox, MdsFormField, MdsNumberInput } from '@meridian/design-system';
import { answerChoices, itemCount, pickGroups, pickLabel } from './agreement-view';
import type { AgreementPick, AgreementSpec, BuilderOptions } from './types';

const props = defineProps<{
    picks: AgreementPick[];
    spec: AgreementSpec;
    limits: NonNullable<BuilderOptions['agreement']>;
}>();

const emit = defineEmits<{ change: [spec: AgreementSpec] }>();

const groups = computed(() => pickGroups(props.picks));
const chosen = computed(() => new Set(props.spec.items));
const items = computed(() => itemCount(props.picks, props.spec.items));
const full = computed(() => props.spec.items.length >= props.limits.max_picks);
const leaveOutChoices = computed(() => answerChoices(props.picks, props.spec.items, props.spec.leave_out));
const agreeingChoices = computed(() =>
    answerChoices(props.picks, props.spec.items, props.spec.agreeing).filter((answer) => !props.spec.leave_out.includes(answer.value)),
);

function change(next: Partial<AgreementSpec>): void {
    emit('change', { ...props.spec, ...next });
}

/** Ticked picks stay in the form's order, whatever order they were ticked in. */
function setItems(values: Set<string>): void {
    change({ items: props.picks.map((pick) => pick.value).filter((value) => values.has(value)).slice(0, props.limits.max_picks) });
}

function togglePick(value: string, on: boolean): void {
    const next = new Set(props.spec.items);

    if (on) next.add(value);
    else next.delete(value);

    setItems(next);
}

function selectGroup(groupPicks: AgreementPick[], on: boolean): void {
    const next = new Set(props.spec.items);

    for (const pick of groupPicks) {
        if (on) next.add(pick.value);
        else next.delete(pick.value);
    }

    setItems(next);
}

/** An answer is either left out or counted as agreeing, never both — ticking one side unticks the other. */
function toggleLeaveOut(value: string, on: boolean): void {
    change({
        leave_out: on ? [...props.spec.leave_out, value] : props.spec.leave_out.filter((v) => v !== value),
        agreeing: on ? props.spec.agreeing.filter((v) => v !== value) : props.spec.agreeing,
    });
}

function toggleAgreeing(value: string, on: boolean): void {
    change({ agreeing: on ? [...props.spec.agreeing, value] : props.spec.agreeing.filter((v) => v !== value) });
}

function whole(value: number | null, min: number, max: number, fallback: number): number {
    return value === null || Number.isNaN(value) ? fallback : Math.min(Math.max(Math.round(value), min), max);
}
</script>

<template>
    <div class="agreement-controls">
        <section class="agreement-controls__block" aria-labelledby="agreement-questions">
            <h3 id="agreement-questions" class="agreement-controls__heading">Questions on the list</h3>
            <p class="agreement-controls__hint" aria-live="polite">
                {{ spec.items.length }} chosen · {{ items }} {{ items === 1 ? 'item' : 'items' }} (at most {{ limits.max_items }}).
                A table counts each of its rows.
            </p>

            <p v-if="picks.length === 0" class="agreement-controls__hint">This form has no choice question to report on.</p>

            <div v-else class="agreement-controls__picks" role="group" aria-labelledby="agreement-questions" tabindex="0">
                <fieldset v-for="(group, groupIndex) in groups" :key="`${groupIndex}-${group.section}`" class="agreement-controls__group">
                    <legend class="agreement-controls__legend">{{ group.section }}</legend>

                    <div class="agreement-controls__group-actions">
                        <MdsButton variant="tertiary" size="sm" :aria-label="`Choose every question in ${group.section}`" @click="selectGroup(group.picks, true)">
                            Select all
                        </MdsButton>
                        <MdsButton variant="tertiary" size="sm" :aria-label="`Clear the questions in ${group.section}`" @click="selectGroup(group.picks, false)">
                            Clear
                        </MdsButton>
                    </div>

                    <MdsCheckbox
                        v-for="pick in group.picks"
                        :id="`agreement-pick-${pick.value.replace(/[^A-Za-z0-9_-]/g, '-')}`"
                        :key="pick.value"
                        :label="pickLabel(pick)"
                        :model-value="chosen.has(pick.value)"
                        :disabled="full && !chosen.has(pick.value)"
                        @update:model-value="(on: boolean) => togglePick(pick.value, on)"
                    />
                </fieldset>
            </div>
        </section>

        <fieldset v-if="leaveOutChoices.length > 0" class="agreement-controls__group">
            <legend class="agreement-controls__legend">Leave out these answers</legend>
            <p class="agreement-controls__hint">Someone who gave only these gave no view, as with “No view or opinion”.</p>
            <MdsCheckbox
                v-for="(answer, index) in leaveOutChoices"
                :id="`agreement-leave-${index}`"
                :key="answer.value"
                :label="answer.label"
                :model-value="spec.leave_out.includes(answer.value)"
                @update:model-value="(on: boolean) => toggleLeaveOut(answer.value, on)"
            />
        </fieldset>

        <fieldset v-if="agreeingChoices.length > 0" class="agreement-controls__group">
            <legend class="agreement-controls__legend">Count these answers as agreeing (optional)</legend>
            <p class="agreement-controls__hint">
                Leave empty to use each question’s most common answer. A question that offers none of these uses its most common answer.
            </p>
            <MdsCheckbox
                v-for="(answer, index) in agreeingChoices"
                :id="`agreement-agreeing-${index}`"
                :key="answer.value"
                :label="answer.label"
                :model-value="spec.agreeing.includes(answer.value)"
                @update:model-value="(on: boolean) => toggleAgreeing(answer.value, on)"
            />
        </fieldset>

        <div class="agreement-controls__pair">
            <MdsFormField label="Short list at (%)" input-id="agreement-threshold" :help="`From ${limits.min_threshold} to ${limits.max_threshold}.`">
                <MdsNumberInput
                    id="agreement-threshold"
                    :model-value="spec.threshold"
                    :min="limits.min_threshold"
                    :max="limits.max_threshold"
                    @update:model-value="(v: number | null) => change({ threshold: whole(v, limits.min_threshold, limits.max_threshold, limits.default_threshold) })"
                />
            </MdsFormField>
            <MdsFormField label="With at least (views)" input-id="agreement-min-views" help="Fewer, and an item has too few views.">
                <MdsNumberInput
                    id="agreement-min-views"
                    :model-value="spec.min_views"
                    :min="1"
                    :max="limits.max_min_views"
                    @update:model-value="(v: number | null) => change({ min_views: whole(v, 1, limits.max_min_views, limits.default_min_views) })"
                />
            </MdsFormField>
        </div>
    </div>
</template>

<style scoped>
.agreement-controls {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-4);
    min-width: 0;
}

.agreement-controls__block {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
}

.agreement-controls__heading,
.agreement-controls__legend {
    margin: 0;
    padding: 0;
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: 600;
    color: var(--mds-color-text-heading);
}

.agreement-controls__hint {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

/* A long form offers many questions: the list scrolls in its own box, which takes focus so a keyboard can scroll it. */
.agreement-controls__picks {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    max-height: 24rem;
    overflow-y: auto;
    padding-inline-end: var(--mds-space-2);
    border-radius: var(--mds-radius-sm);
}

.agreement-controls__picks:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
}

.agreement-controls__group {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    min-width: 0;
    margin: 0;
    padding: 0;
    border: 0;
}

.agreement-controls__group-actions {
    display: flex;
    flex-wrap: wrap;
    gap: var(--mds-space-2);
}

.agreement-controls__pair {
    display: grid;
    grid-template-columns: repeat(2, minmax(0, 1fr));
    gap: var(--mds-space-3);
    align-items: start;
}
</style>
