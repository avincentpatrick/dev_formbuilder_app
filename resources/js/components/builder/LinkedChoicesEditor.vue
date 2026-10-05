<script setup lang="ts">
/**
 * Where a single-choice or dropdown question's choices come from (M133, `R-5da4a30f` — Connect project v1): typed
 * here, or another form's answers to one of its questions.
 *
 * The link is one config key, `options_source = {form_id, field_key}`. The forms offered are the ones that share
 * their answers (their Data sharing settings) and whose responses this author can read — the server sends them as
 * `linkable_sources`, so nothing here decides who may see what. Whether the link can SERVE (the source still
 * shares the question, this form's owner can read it) is publish's to say, in words, because both keys can change
 * after the link is made.
 *
 * ⚠️ TYPED CHOICES AND A LINK NEVER LIVE TOGETHER. Both engines check an answer against the typed list whenever it
 * is non-empty, so a linked answer would be refused. Choosing "Another form's answers" over typed choices asks
 * first, and the choices go in the same change as the link — one undo entry, through the panel.
 *
 * ⚠️ IT TAKES THE QUESTION'S CONFIG AND EMITS A WHOLE NEW ONE, AND NO STORE — the sub-editor house contract
 * (`types.ts`, `ConditionCatalogue`). `ConfigPanel` writes what it emits in one assignment and one touch, which is
 * what makes "remove the typed choices and link" a single undo entry.
 *
 * ⚠️ A RADIO PAIR, NOT A SEGMENTED CONTROL. The Options tab already sits under the builder's one tablist, and a
 * two-way choice with a sentence of consequence under it reads better as a group with a legend.
 */
import { computed, ref, useId } from 'vue';
import { MdsButton, MdsFormField, MdsRadio, MdsSelect } from '@meridian/design-system';
import type { LinkableSource, OptionsSource } from './types';

const props = defineProps<{
    /** The question's whole config: its `options_source` link, if any, and its typed `options`. */
    config: Record<string, unknown>;
    sources: LinkableSource[];
}>();

const emit = defineEmits<{ 'update:config': [value: Record<string, unknown>] }>();

/** The question's link, or null when its choices are typed here (a missing or null key alike). */
const source = computed<OptionsSource | null>(() => {
    const link = props.config.options_source;
    if (link === null || typeof link !== 'object') return null;
    const { form_id: formId, field_key: fieldKey } = link as Record<string, unknown>;

    return { form_id: typeof formId === 'string' ? formId : null, field_key: typeof fieldKey === 'string' ? fieldKey : null };
});

/** How many choices are typed here — what choosing a link would remove. */
const typedCount = computed(() => (Array.isArray(props.config.options) ? props.config.options.length : 0));

/** One new config: the link set (and typed choices cleared, never kept beside it), or the link removed. */
function emitSource(next: OptionsSource | null): void {
    emit('update:config', next === null ? { ...props.config, options_source: null } : { ...props.config, options: [], options_source: next });
}

const mode = computed<'here' | 'form'>(() => (source.value === null ? 'here' : 'form'));
const confirming = ref(false);
const modeName = useId();

const chosenSource = computed<LinkableSource | null>(() => props.sources.find((s) => s.id === source.value?.form_id) ?? null);

const sourceOptions = computed(() => props.sources.map((s) => ({ value: s.id, label: s.title })));
const questionOptions = computed(() => (chosenSource.value?.questions ?? []).map((q) => ({ value: q.key, label: q.label })));

/** A link this author can no longer pick from: the source stopped sharing, or they cannot read it. */
const unavailable = computed(() => source.value?.form_id != null && chosenSource.value === null);

function startLink(): void {
    confirming.value = false;
    emitSource({ form_id: null, field_key: null });
}

function onMode(value: string): void {
    if (value === 'here') {
        confirming.value = false;
        if (source.value !== null) emitSource(null);

        return;
    }
    if (source.value !== null) return;
    if (typedCount.value > 0) {
        confirming.value = true;

        return;
    }
    startLink();
}

function onSource(formId: string): void {
    const picked = props.sources.find((s) => s.id === formId);
    // A form sharing exactly one question needs no second pick.
    const only = picked?.questions.length === 1 ? picked.questions[0].key : null;
    emitSource({ form_id: formId || null, field_key: only });
}

function onQuestion(key: string): void {
    emitSource({ form_id: source.value?.form_id ?? null, field_key: key || null });
}
</script>

<template>
    <fieldset class="linked-choices" data-linked-choices>
        <legend class="linked-choices__legend">Choices come from</legend>
        <div class="linked-choices__modes">
            <MdsRadio
                :model-value="confirming ? 'form' : mode"
                value="here"
                label="Typed here"
                :name="modeName"
                @update:model-value="onMode"
            />
            <MdsRadio
                :model-value="confirming ? 'form' : mode"
                value="form"
                label="Another form's answers"
                :name="modeName"
                @update:model-value="onMode"
            />
        </div>

        <div v-if="confirming" class="linked-choices__confirm" role="group" aria-label="Remove typed choices" data-linked-choices-confirm>
            <p class="linked-choices__text">
                The {{ typedCount }} {{ typedCount === 1 ? 'choice' : 'choices' }} typed here will be removed. A question
                takes its choices from one place.
            </p>
            <div class="linked-choices__actions">
                <MdsButton variant="tertiary" size="sm" @click="confirming = false">Keep typed choices</MdsButton>
                <MdsButton variant="primary" size="sm" @click="startLink">Remove and continue</MdsButton>
            </div>
        </div>

        <template v-if="mode === 'form'">
            <p v-if="sources.length === 0 && !unavailable" class="linked-choices__text" data-linked-choices-empty>
                No form shares its answers with you yet. A form's owner can share them in its Data sharing settings.
            </p>
            <p v-if="unavailable" class="linked-choices__warning" data-linked-choices-unavailable>
                The form this question takes its choices from no longer shares them with you. Choose another, or type
                the choices here.
            </p>
            <template v-if="sources.length > 0">
                <MdsFormField v-slot="{ id, describedby, invalid }" label="Form">
                    <MdsSelect
                        :id="id"
                        :describedby="describedby"
                        :invalid="invalid"
                        :model-value="chosenSource?.id ?? ''"
                        :options="sourceOptions"
                        placeholder="Choose a form"
                        @update:model-value="onSource"
                    />
                </MdsFormField>
                <MdsFormField
                    v-if="chosenSource"
                    v-slot="{ id, describedby, invalid }"
                    label="Question"
                    help="Respondents choose from the answers given to this question, up to 1,000, as they read today."
                >
                    <MdsSelect
                        :id="id"
                        :describedby="describedby"
                        :invalid="invalid"
                        :model-value="source?.field_key ?? ''"
                        :options="questionOptions"
                        placeholder="Choose a question"
                        @update:model-value="onQuestion"
                    />
                </MdsFormField>
            </template>
        </template>
    </fieldset>
</template>

<style scoped>
.linked-choices {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
    margin: 0 0 var(--mds-space-4);
    padding: 0;
    border: 0;
}

.linked-choices__legend {
    padding: 0;
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-label-font-size);
    line-height: var(--mds-type-label-line-height);
    font-weight: var(--mds-font-weight-medium);
    color: var(--mds-color-text-body);
}

.linked-choices__modes {
    display: flex;
    flex-direction: column;
}

.linked-choices__text {
    margin: 0;
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-secondary);
}

.linked-choices__warning {
    margin: 0;
    padding-left: var(--mds-space-3);
    border-left: 4px solid var(--mds-color-status-warning-fg);
    font-size: var(--mds-type-body-sm-font-size);
    color: var(--mds-color-text-body);
}

.linked-choices__confirm {
    padding: var(--mds-space-3);
    border-left: 4px solid var(--mds-color-status-warning-fg);
    border-radius: var(--mds-radius-sm);
    background: var(--mds-color-bg-sunken);
}

.linked-choices__actions {
    display: flex;
    flex-wrap: wrap;
    justify-content: flex-end;
    gap: var(--mds-space-2);
    margin-top: var(--mds-space-2);
}
</style>
