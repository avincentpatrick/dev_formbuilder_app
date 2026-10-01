<script setup lang="ts">
/**
 * Change a question's type (Increment M123, `B5b`) — the dialog. Presentational on purpose, like `ConflictDialog`:
 * props in, events out, no store. `FieldTypeControl` owns the state machine and the requests.
 *
 * ⛔ IT SHOWS THE CHOSEN PLAN BEFORE THE PRIMARY CAN APPLY IT, EVERY TIME. What is lost, what is added, what the
 * author should check, and — the part nothing else in the builder can tell them — which OTHER questions still point
 * at this one but will mean something different afterwards. A conversion keeps the key, so every reference survives
 * syntactically; the census is what says when its meaning does not.
 *
 * ⚠️ NOTHING HERE IS NATIVELY DISABLED. A disabled control that holds focus drops it to `<body>`, where the modal's
 * Escape handler and Tab trap are unreachable — so the busy primary uses `:loading` (focusable, `aria-disabled`)
 * and a refused click is answered with a message rather than a dead control.
 *
 * ⚠️ NO TAB ROLE ANYWHERE, NOT EVEN IN A COMMENT. The builder page holds exactly one tablist and end-to-end specs
 * click every match; Vue renders template comments into the DOM in development builds.
 */
import { computed, nextTick, ref, watch } from 'vue';
import { MdsAlert, MdsButton, MdsFormField, MdsModal, MdsSelect } from '@meridian/design-system';
import type { ConsequenceView, ConvertPhase, TargetGroup } from './field-conversion';

const props = defineProps<{
    open: boolean;
    phase: ConvertPhase;
    /** The question's label, or its key when it has none. */
    questionLabel: string;
    /** The current type's palette label. */
    currentLabel: string;
    groups: TargetGroup[];
    selected: string;
    /** The selected target's palette label, or '' when nothing is chosen. */
    targetLabel: string;
    view: ConsequenceView | null;
    message: string | null;
}>();

const emit = defineEmits<{ 'update:selected': [to: string]; confirm: []; retry: []; close: [] }>();

const HELP = 'It keeps its key, so conditions, calculations and text that mention it still point to it. What changes is listed below.';

const bodyEl = ref<HTMLElement | null>(null);
const staleEl = ref<HTMLElement | null>(null);
const choiceError = ref('');
let focusedChoice = false;

const busy = computed(() => props.phase === 'loading' || props.phase === 'applying');
const showChoice = computed(() => props.phase !== 'loading' && props.phase !== 'load-failed');
const showPrimary = computed(() => !showChoice.value || props.groups.length > 0);
const primaryLabel = computed(() => {
    if (props.phase === 'load-failed') return 'Try again';
    return props.targetLabel !== '' ? `Change to ${props.targetLabel}` : 'Change type';
});
const status = computed(() => {
    if (props.phase === 'loading') return 'Checking which types it can change to…';
    if (props.phase === 'applying') return `Changing it to ${props.targetLabel}…`;
    return '';
});
const hasQuestionItems = computed(
    () =>
        props.view !== null &&
        (props.view.question.length > 0 || props.view.kept !== null || props.view.removed.length > 0 || props.view.added.length > 0),
);

function selectEl(): HTMLSelectElement | null {
    return bodyEl.value?.querySelector<HTMLSelectElement>('[data-convert-target]') ?? null;
}

watch(
    () => props.open,
    (isOpen) => {
        if (isOpen) {
            focusedChoice = false;
            choiceError.value = '';
        }
    },
);

watch(
    () => props.phase,
    async (phase) => {
        await nextTick();
        if (phase === 'stale') {
            // A reflexive second Enter must not confirm a plan the author has not read yet.
            staleEl.value?.focus();
            return;
        }
        if (phase === 'choosing' && !focusedChoice && props.groups.length > 0) {
            focusedChoice = true;
            const active = document.activeElement;
            // Only from where the modal put focus itself; never pull it away from a control the author chose.
            if (active === null || active === document.body || active.classList.contains('mds-modal__close')) {
                selectEl()?.focus();
            }
        }
    },
);

function onSelect(value: string): void {
    choiceError.value = '';
    emit('update:selected', value);
}

function onPrimary(): void {
    if (busy.value) return;
    if (props.phase === 'load-failed') {
        emit('retry');
        return;
    }
    if (props.selected === '') {
        choiceError.value = 'Choose a type first.';
        void nextTick(() => selectEl()?.focus());
        return;
    }
    emit('confirm');
}

function onClose(): void {
    // The write's outcome is reported in this dialog, so it stays open until the request answers.
    if (props.phase !== 'applying') emit('close');
}
</script>

<template>
    <MdsModal :open="open" title="Change question type" :return-focus="['[data-convert-opener]']" @close="onClose">
        <div ref="bodyEl" class="convert">
            <p class="convert__lead">
                Current type of “{{ questionLabel }}”: <strong>{{ currentLabel }}</strong>
            </p>
            <p class="convert__status" role="status">{{ status }}</p>

            <MdsAlert
                v-if="phase === 'load-failed'"
                tone="danger"
                assertive
                title="Couldn’t check which types it can change to"
                :message="message ?? undefined"
            />
            <MdsAlert
                v-if="phase === 'apply-failed'"
                tone="danger"
                assertive
                title="The type wasn’t changed"
                :message="message ?? undefined"
            />
            <div v-if="phase === 'stale'" ref="staleEl" tabindex="-1" class="convert__stale" data-convert-stale>
                <MdsAlert tone="warning" title="This question changed while this was open" :message="message ?? undefined" />
            </div>

            <template v-if="showChoice">
                <MdsFormField
                    v-if="groups.length > 0"
                    label="Change to"
                    :help="HELP"
                    :error="choiceError || undefined"
                    v-slot="{ id, describedby, invalid }"
                >
                    <MdsSelect
                        :id="id"
                        :describedby="describedby"
                        :invalid="invalid"
                        data-convert-target
                        :model-value="selected"
                        :groups="groups"
                        placeholder="Choose a type"
                        @update:model-value="onSelect"
                    />
                </MdsFormField>
                <p v-else class="convert__empty">This question can’t be changed to another type.</p>

                <section v-if="view" class="convert__effects" aria-label="What changes" data-convert-effects>
                    <p class="convert__summary">{{ view.summary }}</p>

                    <template v-if="hasQuestionItems">
                        <h3 class="convert__heading">This question</h3>
                        <ul class="convert__list">
                            <li v-for="line in view.question" :key="line">{{ line }}</li>
                            <li v-if="view.kept">{{ view.kept }}</li>
                            <li v-for="(rule, i) in view.removed" :key="`removed-${i}`">
                                Removes the rule {{ rule.label }}<template v-if="rule.value">
                                    <code class="convert__code">{{ rule.value }}</code></template
                                ><template v-if="rule.shows"> (shows “{{ rule.shows }}”)</template>.
                                <span class="convert__reason">{{ rule.reason }}</span>
                            </li>
                            <li v-for="(rule, i) in view.added" :key="`added-${i}`">
                                Adds the rule {{ rule.label }}<template v-if="rule.value">
                                    <code class="convert__code">{{ rule.value }}</code></template
                                ><template v-if="rule.shows"> (shows “{{ rule.shows }}”)</template>.
                            </li>
                        </ul>
                    </template>

                    <template v-if="view.warnings.length > 0">
                        <h3 class="convert__heading">Things to check</h3>
                        <ul class="convert__list">
                            <li v-for="warning in view.warnings" :key="warning">{{ warning }}</li>
                        </ul>
                    </template>

                    <template v-if="view.elsewhere.length > 0">
                        <h3 class="convert__heading">Elsewhere in the form</h3>
                        <p class="convert__intro">These still point to this question, but what they check changes:</p>
                        <ul class="convert__list">
                            <li v-for="(item, i) in view.elsewhere" :key="`elsewhere-${i}`">
                                <strong>{{ item.owner }}</strong> — {{ item.message }}
                            </li>
                        </ul>
                    </template>
                </section>
            </template>
        </div>

        <template #actions>
            <MdsButton variant="tertiary" data-convert-cancel @click="onClose">Cancel</MdsButton>
            <MdsButton v-if="showPrimary" variant="primary" :loading="busy" data-convert-primary @click="onPrimary">
                {{ primaryLabel }}
            </MdsButton>
        </template>
    </MdsModal>
</template>

<style scoped>
.convert {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-3);
    min-width: 0;
}

.convert__lead,
.convert__summary,
.convert__intro,
.convert__empty {
    margin: 0;
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    overflow-wrap: anywhere;
}

.convert__status {
    margin: 0;
    color: var(--mds-color-text-secondary);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
}

.convert__status:empty {
    display: none;
}

.convert__stale:focus-visible {
    outline: 2px solid var(--mds-color-focus-ring);
    outline-offset: 2px;
    border-radius: var(--mds-radius-md);
}

.convert__effects {
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
    padding: var(--mds-space-3);
    border: 1px solid var(--mds-color-border-default);
    border-radius: var(--mds-radius-md);
    background-color: var(--mds-color-bg-surface);
}

.convert__heading {
    margin: var(--mds-space-2) 0 0;
    font-size: var(--mds-type-label-font-size);
    font-weight: var(--mds-font-weight-semibold);
    color: var(--mds-color-text-secondary);
}

.convert__list {
    margin: 0;
    padding-left: var(--mds-space-5);
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-1);
    color: var(--mds-color-text-body);
    font-size: var(--mds-type-body-sm-font-size);
    line-height: var(--mds-type-body-sm-line-height);
    overflow-wrap: anywhere;
}

.convert__code {
    margin-left: var(--mds-space-1);
    font-family: var(--mds-font-family-mono);
    font-size: var(--mds-type-body-sm-font-size);
    overflow-wrap: anywhere;
}

.convert__reason {
    display: block;
    color: var(--mds-color-text-secondary);
}
</style>
