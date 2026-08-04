<script setup lang="ts">
// The answer-value question picker (ADR-0011 §D3, Increment H24b2).
//
// ── It ENCODES the refusals; it never discovers them ────────────────────────────────────────────────────
// §D3 names three properties this picker must state rather than let the user find out by trying: the
// projected set is `is_queryable` AND a top-level scalar; an author can flag a field that can NEVER
// project and still publish successfully; and the projector drops rows silently within a flagged field.
// The server already resolves all of that into `reportable` / `refusal` / `refusal_label`, so this
// component's whole job is to put the sentence on screen beside the question — as TEXT, not a tooltip,
// and not as a colour or a disabled state alone (WCAG 1.4.1).
//
// Sorting is done here rather than in the presenter: which group a question belongs to is a presentation
// decision, and the presenter is a deliberate pass-through of the aggregator's catalogue.
import { computed } from 'vue';
import { MdsButton, MdsEmptyState } from '@meridian/design-system';
import type { QuestionRow } from './types';

const props = defineProps<{ questions: QuestionRow[]; selected: string | null }>();

const emit = defineEmits<{ select: [string | null] }>();

const reportable = computed(() => props.questions.filter((q) => q.reportable));
const refused = computed(() => props.questions.filter((q) => !q.reportable));
</script>

<template>
    <div class="analytics__picker">
        <MdsEmptyState
            v-if="questions.length === 0"
            headline="No questions to report on"
            description="Questions appear here once a form in this report has been published."
        />

        <template v-else>
            <fieldset class="analytics__picker-group">
                <legend class="analytics__picker-legend">Available</legend>

                <p v-if="reportable.length === 0" class="analytics__picker-note">
                    None of the questions in these forms are marked “Indexed for reporting”, so there are no
                    answer values to summarise yet.
                </p>

                <div v-for="question in reportable" :key="question.key" class="analytics__picker-row">
                    <label class="analytics__picker-label">
                        <input
                            type="radio"
                            name="analytics-question"
                            :value="question.key"
                            :checked="selected === question.key"
                            @change="emit('select', question.key)"
                        />
                        <span>{{ question.label }}</span>
                    </label>
                    <p
                        v-if="question.first_indexed_version !== null"
                        class="analytics__picker-note analytics__picker-note--indent"
                    >
                        Reportable from version {{ question.first_indexed_version }} onward.
                    </p>
                </div>
            </fieldset>

            <fieldset v-if="refused.length > 0" class="analytics__picker-group">
                <legend class="analytics__picker-legend">Not available for reporting</legend>

                <div v-for="question in refused" :key="question.key" class="analytics__picker-row">
                    <p class="analytics__picker-refused">{{ question.label }}</p>
                    <p class="analytics__picker-note analytics__picker-note--indent">
                        {{ question.refusal_label }}
                    </p>
                </div>
            </fieldset>

            <div v-if="selected !== null" class="analytics__picker-clear">
                <MdsButton variant="tertiary" size="sm" icon-left="close" @click="emit('select', null)">
                    Clear question
                </MdsButton>
            </div>
        </template>
    </div>
</template>

<style scoped>
.analytics__picker {
    display: grid;
    gap: var(--mds-space-3);
}

.analytics__picker-group {
    display: grid;
    gap: var(--mds-space-2);
    margin: 0;
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
}

.analytics__picker-legend {
    padding: 0 var(--mds-space-1);
    font-size: var(--mds-type-body-sm-font-size);
    font-weight: var(--mds-type-heading-4-font-weight);
    color: var(--mds-color-text-heading);
}

.analytics__picker-label {
    display: flex;
    align-items: center;
    gap: var(--mds-space-2);
    cursor: pointer;
    color: var(--mds-color-text-body);
}

.analytics__picker-refused {
    margin: 0;
    color: var(--mds-color-text-body);
}

.analytics__picker-note {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.analytics__picker-note--indent {
    margin-top: var(--mds-space-0-5);
}

.analytics__picker-clear {
    justify-self: start;
}
</style>
