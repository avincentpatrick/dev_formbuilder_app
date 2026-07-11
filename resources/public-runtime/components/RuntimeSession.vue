<script setup lang="ts">
/**
 * Owns one fill session for a fixed schema version: creates + PROVIDES the store, announcer, and submit flow;
 * wires client-only draft autosave (restore on a fresh session, discard-on-version-drift via checksum); and
 * orchestrates submit — the client-side gate, the network call, and the error branches (field errors → banner;
 * version drift → re-mint + re-fetch → `reschema` up to App; rate-limit / terminal → a session notice).
 *
 * App re-mounts this component (a new `:key`) on a version-drift `reschema`, so a superseding republish gets a
 * clean store with a fresh `client_submission_uuid` while the retained answers are carried in by key.
 */
import { computed, onBeforeUnmount, provide, ref } from 'vue';
import RuntimeShell from './RuntimeShell.vue';
import PageView from './PageView.vue';
import StepView from './StepView.vue';
import { createAnnouncer } from '../composables/useAnnouncer';
import { createAutosave } from '../composables/useAutosave';
import { createFormRuntime } from '../composables/useFormRuntime';
import {
    AnnouncerKey,
    RuntimeKey,
    SubmitFlowKey,
    type SubmitFlow,
    type SubmitOutcome,
} from '../composables/context';
import { ApiError } from '../lib/error-normalizer';
import type { ApiClient } from '../lib/api-client';
import type { AnswerMap, Bootstrap, SchemaResponse } from '../lib/types';

const props = defineProps<{
    schema: SchemaResponse;
    bootstrap: Bootstrap;
    client: ApiClient;
    initialAnswers?: AnswerMap;
    notice?: string | null;
}>();

const emit = defineEmits<{
    submitted: [id: string];
    reschema: [payload: { schema: SchemaResponse; answers: AnswerMap }];
}>();

const runtime = createFormRuntime(props.schema, {
    initialLocale: props.bootstrap.defaultLocale || props.schema.form.default_locale,
    initialAnswers: props.initialAnswers,
});
const announcer = createAnnouncer();

provide(RuntimeKey, runtime);
provide(AnnouncerKey, announcer);

const autosave = createAutosave({
    formId: props.bootstrap.formId,
    slug: props.bootstrap.slug,
    checksum: runtime.schemaChecksum,
    answers: runtime.answers,
    locale: runtime.locale,
    currentStepKey: runtime.currentStepKey,
});

// Restore a same-browser draft — but only on a FRESH session. A version-drift remount already carries the
// retained answers in (`initialAnswers`), and its old-checksum draft would be discarded by the guard anyway.
if (props.initialAnswers === undefined) {
    const draft = autosave.restore();
    if (draft !== null) {
        runtime.restoreAnswers(draft.answers);
        runtime.locale.value = draft.locale;
        runtime.goToStep(draft.currentStepKey);
    }
}

onBeforeUnmount(() => autosave.dispose());

type Notice = { type: 'info' | 'error' | 'rate-limited'; message: string } | null;
const notice = ref<Notice>(props.notice ? { type: 'info', message: props.notice } : null);
const submitting = ref(false);

async function handleDrift(): Promise<void> {
    try {
        await props.client.remint();
        const next = await props.client.fetchSchema();
        emit('reschema', { schema: next, answers: { ...runtime.answers } });
    } catch {
        notice.value = { type: 'error', message: 'This form is no longer available.' };
    }
}

function handleError(error: unknown): SubmitOutcome {
    if (error instanceof ApiError) {
        const normalized = error.normalized;
        if (normalized.kind === 'field') {
            runtime.setServerErrors(normalized.fieldErrors);
            runtime.markSubmitAttempted();
            return 'field-errors';
        }
        if (normalized.kind === 'refresh') {
            void handleDrift();
            return 'blocked';
        }
        if (normalized.kind === 'rate_limited') {
            notice.value = { type: 'rate-limited', message: normalized.message };
            return 'blocked';
        }
        notice.value = { type: 'error', message: normalized.message };
        return 'blocked';
    }
    notice.value = { type: 'error', message: 'Something went wrong. Please try again.' };
    return 'blocked';
}

async function submit(): Promise<SubmitOutcome> {
    runtime.markSubmitAttempted();
    if (!runtime.passed.value) {
        return 'field-errors';
    }
    submitting.value = true;
    notice.value = null;
    try {
        const result = await props.client.submit({
            answers: runtime.effectiveAnswers.value,
            clientSubmissionUuid: runtime.clientSubmissionUuid,
            locale: runtime.locale.value,
        });
        autosave.clear();
        emit('submitted', result.id);
        return 'success';
    } catch (error) {
        return handleError(error);
    } finally {
        submitting.value = false;
    }
}

const flow: SubmitFlow = { submitting, submit };
provide(SubmitFlowKey, flow);

const title = computed(() => runtime.renderModel.form.title);
const description = computed(() => runtime.renderModel.form.description);
</script>

<template>
    <RuntimeShell
        :title="title"
        :description="description"
        :saving="autosave.saving.value"
        :saved-at="autosave.savedAt.value"
    >
        <template #notice>
            <div
                v-if="notice"
                class="session-notice"
                :class="`session-notice--${notice.type}`"
                role="status"
            >
                {{ notice.message }}
            </div>
        </template>
        <PageView v-if="runtime.singlePageMode" />
        <StepView v-else />
    </RuntimeShell>
</template>

<style scoped>
.session-notice {
    padding: var(--mds-space-3) var(--mds-space-4);
    border-radius: var(--mds-radius-md);
    border: 1px solid var(--mds-color-border-default);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-md-font-size);
    line-height: var(--mds-type-body-md-line-height);
    color: var(--mds-color-text-body);
    background-color: var(--mds-color-bg-surface);
}

.session-notice--info {
    border-left: 4px solid var(--mds-color-action-primary-bg);
}

.session-notice--rate-limited {
    border-left: 4px solid var(--mds-color-warning-text, var(--mds-color-border-strong));
}

.session-notice--error {
    border-left: 4px solid var(--mds-color-action-danger-bg);
    color: var(--mds-color-danger-text);
}
</style>
