<script setup lang="ts">
/**
 * M243 (`R-1da063f4`) — a question's answer limits, at the top of its Validation tab: "Words" on a Short or Long text,
 * "Choices" on a multi-select. Controlled and stateless, the {@link MediaEditor} "How many" shape: the parent writes each
 * change back through `setConfig` (one debounced history entry).
 *
 * The keys — `min_words`/`max_words`, `min_choices`/`max_choices` — are read by both engines
 * (`SemanticValidator::collectAnswerLimitErrors()` and its twin) and by the respondent's screen, which shows a live word
 * count and stops one tick too many. Which types offer which pair is the server's (`word_limit_types`,
 * `choice_limit_types`). A minimum above its maximum is refused at publish, not here, so a half-typed pair never errors.
 */
import { computed } from 'vue';
import { MdsFormField, MdsNumberInput } from '@meridian/design-system';

const props = defineProps<{ kind: 'words' | 'choices'; min: number | null; max: number | null }>();
const emit = defineEmits<{ 'update:min': [value: number | null]; 'update:max': [value: number | null] }>();

const heading = computed<string>(() => (props.kind === 'words' ? 'Words' : 'Choices'));
const note = computed<string>(() =>
    props.kind === 'words'
        ? 'People see a count of their words as they type. Leave a box empty for no limit.'
        : 'Once the most are ticked, the other boxes cannot be ticked. Leave a box empty for no limit.',
);
const noun = computed<string>(() => (props.kind === 'words' ? 'words' : 'choices'));

/** A cleared box, or anything below 1, is no limit. */
function limit(value: number | null): number | null {
    return value === null || !Number.isFinite(value) || value < 1 ? null : Math.floor(value);
}
</script>

<template>
    <section class="answer-limits" :data-answer-limits="kind">
        <h3 class="answer-limits__heading">{{ heading }}</h3>
        <p class="answer-limits__note">{{ note }}</p>
        <div class="answer-limits__pair">
            <MdsFormField label="At least" :help="`Fewest ${noun}.`" v-slot="{ id, describedby }">
                <MdsNumberInput
                    :id="id"
                    :describedby="describedby"
                    :model-value="min"
                    :min="1"
                    :step="1"
                    placeholder="No minimum"
                    data-answer-limit="min"
                    @update:model-value="emit('update:min', limit($event))"
                />
            </MdsFormField>
            <MdsFormField label="At most" :help="`Most ${noun}.`" v-slot="{ id, describedby }">
                <MdsNumberInput
                    :id="id"
                    :describedby="describedby"
                    :model-value="max"
                    :min="1"
                    :step="1"
                    placeholder="No maximum"
                    data-answer-limit="max"
                    @update:model-value="emit('update:max', limit($event))"
                />
            </MdsFormField>
        </div>
    </section>
</template>

<style scoped>
.answer-limits {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
    margin-bottom: var(--mds-space-5);
}

.answer-limits__heading {
    margin: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-body);
}

.answer-limits__note {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.answer-limits__pair {
    display: grid;
    grid-template-columns: 1fr 1fr;
    gap: var(--mds-space-3);
    min-width: 0;
}
</style>
