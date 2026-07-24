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
import { computed, inject, onBeforeUnmount, onMounted, provide, ref } from 'vue';
import RuntimeShell from './RuntimeShell.vue';
import OfflineIndicator from './OfflineIndicator.vue';
import SyncStatus from './SyncStatus.vue';
import PageView from './PageView.vue';
import StepView from './StepView.vue';
import WelcomeBackBanner from './WelcomeBackBanner.vue';
import { createAnnouncer } from '../composables/useAnnouncer';
import { createAutosave } from '../composables/useAutosave';
import { createFormRuntime } from '../composables/useFormRuntime';
import { useOnline } from '../composables/useOnline';
import {
    AnnouncerKey,
    DbKey,
    DraftFlowKey,
    RuntimeKey,
    SubmitFlowKey,
    SyncOutboxKey,
    type DraftSaveResult,
    type SubmitFlow,
    type SubmitOutcome,
} from '../composables/context';
import { ApiError } from '../lib/error-normalizer';
import { openDb } from '../lib/db';
import { discardRow, enqueue, markSynced } from '../lib/outbox';
import { attachToSubmission, collectLocalMediaIds } from '../lib/media-queue';
import { getDeviceId } from '../lib/device';
import { APP_VERSION } from '../lib/app-version';
import type { ApiClient } from '../lib/api-client';
import type { AnswerMap, Bootstrap, SchemaResponse } from '../lib/types';

const props = defineProps<{
    schema: SchemaResponse;
    bootstrap: Bootstrap;
    client: ApiClient;
    initialAnswers?: AnswerMap;
    notice?: string | null;
    /** Increment G8c — this session is resolving a parked conflict (review & resubmit): suppress autosave and
     *  the sync banner, and offer a "discard this response" escape hatch. */
    resolving?: boolean;
    /** Increment H10 — the reconciled resume seed when the session was opened from a resume link: the server
     *  draft's uuid (so the finalize promotes it), the restored locale/step, and the welcome-back banner data.
     *  Null on a normal fresh entry and on a version-drift remount (the banner is a one-time restore signal). */
    resume?: {
        uuid: string;
        locale: string | null;
        stepKey: string | null;
        completeness: number | null;
        note: string | null;
    } | null;
}>();

const emit = defineEmits<{
    submitted: [id: string];
    queued: [clientUuid: string];
    reschema: [payload: { schema: SchemaResponse; answers: AnswerMap }];
    discard: [];
}>();

const runtime = createFormRuntime(props.schema, {
    // A resumed session restores its saved locale; otherwise the bootstrap/form default (Increment H10).
    initialLocale: props.resume?.locale ?? (props.bootstrap.defaultLocale || props.schema.form.default_locale),
    initialAnswers: props.initialAnswers,
    // A resumed session reuses the draft's uuid so its submit promotes that row (H9a invariant).
    initialClientSubmissionUuid: props.resume?.uuid,
});
const announcer = createAnnouncer();

provide(RuntimeKey, runtime);
provide(AnnouncerKey, announcer);

// The offline DB + replay driver are provided by App.vue (shared with the service worker); fall back to a
// fresh handle if a test mounts this component in isolation.
const db = inject(DbKey) ?? openDb();
const sync = inject(SyncOutboxKey, null);
const deviceId = getDeviceId();

const autosave = createAutosave({
    db,
    formId: props.bootstrap.formId,
    formVersionId: props.schema.version.id,
    slug: props.bootstrap.slug,
    checksum: runtime.schemaChecksum,
    answers: runtime.answers,
    locale: runtime.locale,
    currentStepKey: runtime.currentStepKey,
    // A conflict-review session must not autosave — the durable copy is the parked outbox row (Increment G8c).
    enabled: !props.resolving,
});

// Restore a same-browser draft — but only on a FRESH session. A version-drift remount already carries the
// retained answers in (`initialAnswers`), and its old-checksum draft would be discarded by the guard anyway.
// The Dexie-backed restore is async (Increment G8b), so it runs after mount; App.vue's loading phase covers it.
onMounted(async () => {
    // Increment H10 — a resume session already had its answers/locale reconciled and seeded via props; here we
    // only restore the saved step (the server draft's, or the local draft's when it won the reconciliation).
    if (props.resume) {
        if (props.resume.stepKey) {
            runtime.goToStep(props.resume.stepKey);
        }
        return;
    }
    if (props.initialAnswers === undefined) {
        const draft = await autosave.restore();
        if (draft !== null) {
            runtime.restoreAnswers(draft.answers);
            runtime.locale.value = draft.locale;
            runtime.goToStep(draft.currentStepKey);
        }
    }
});

onBeforeUnmount(() => autosave.dispose());

const { online } = useOnline();

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

/** Map an inline (online) submit failure. Client-resolvable outcomes discard the queued row; a genuine network
 *  failure leaves it pending for the background driver (→ 'queued'). */
async function handleSubmitError(error: unknown, uuid: string): Promise<SubmitOutcome> {
    if (error instanceof ApiError) {
        await discardRow(db, uuid);
        void sync?.refresh();
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
    // Not an ApiError → the connection dropped mid-submit. Keep the row queued; the driver replays it.
    await autosave.clear();
    sync?.registerBackgroundSync();
    void sync?.refresh();
    emit('queued', uuid);
    return 'queued';
}

async function submit(): Promise<SubmitOutcome> {
    runtime.markSubmitAttempted();
    if (!runtime.passed.value) {
        return 'field-errors';
    }

    // Increment G8b — enqueue the finalized submission to the outbox FIRST (a durable, crash-safe record of
    // intent), linking any offline-picked media blobs to it. Then replay inline if online, or defer if not.
    const uuid = runtime.clientSubmissionUuid;
    const answers = runtime.effectiveAnswers.value;
    const localMediaIds = collectLocalMediaIds(answers);
    if (localMediaIds.length > 0) {
        await attachToSubmission(db, localMediaIds, uuid);
    }
    await enqueue(db, {
        client_submission_uuid: uuid,
        slug: props.bootstrap.slug,
        form_version_id: props.schema.version.id,
        checksum: runtime.schemaChecksum,
        answers,
        locale: runtime.locale.value,
        device_id: deviceId,
        app_version: APP_VERSION,
    });
    void sync?.refresh();

    if (!online.value) {
        await autosave.clear();
        sync?.registerBackgroundSync();
        emit('queued', uuid);
        return 'queued';
    }

    submitting.value = true;
    notice.value = null;
    try {
        const result = await props.client.submit({
            answers,
            clientSubmissionUuid: uuid,
            locale: runtime.locale.value,
            deviceId,
            appVersion: APP_VERSION,
        });
        await markSynced(db, uuid);
        void sync?.refresh();
        await autosave.clear();
        emit('submitted', result.id);
        return 'success';
    } catch (error) {
        return await handleSubmitError(error, uuid);
    } finally {
        submitting.value = false;
    }
}

const flow: SubmitFlow = { submitting, submit };
provide(SubmitFlowKey, flow);

// Increment H10 — the "Save and finish later" flow. Sends the FULL retained answer set (not the pruned submit
// set): a draft must preserve incomplete/currently-irrelevant values. Provided only when the form offers
// save-and-resume and this is not a conflict-review session; SaveForLater self-hides when it's absent.
const draftSaving = ref(false);
const draftCompleteness = ref<number | null>(props.resume?.completeness ?? null);

async function saveDraftAction(options: { email?: string | null; finishLater: boolean }): Promise<DraftSaveResult | null> {
    draftSaving.value = true;
    try {
        const result = await props.client.saveDraft({
            answers: { ...runtime.answers },
            clientSubmissionUuid: runtime.clientSubmissionUuid,
            locale: runtime.locale.value,
            draftCurrentStep: runtime.currentStepKey.value,
            guestContactEmail: options.email ?? null,
            deviceId,
            appVersion: APP_VERSION,
            finishLater: options.finishLater,
        });
        draftCompleteness.value = result.completenessPercent;
        const email = (options.email ?? '').trim();
        return { resumeUrl: result.resumeUrl, emailed: options.finishLater && email !== '' };
    } catch (error) {
        // A republish between mint and save surfaces as a refresh drift — route it through the same reschema
        // remount as submit (the caller sees null and closes; the session re-mounts against the new version).
        if (error instanceof ApiError && error.normalized.kind === 'refresh') {
            void handleDrift();
            return null;
        }
        throw error;
    } finally {
        draftSaving.value = false;
    }
}

if (props.schema.form.save_and_resume && !props.resolving) {
    provide(DraftFlowKey, { saving: draftSaving, completeness: draftCompleteness, saveDraft: saveDraftAction });
}

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
            <WelcomeBackBanner v-if="resume" :completeness="resume.completeness" :note="resume.note" />
            <OfflineIndicator v-if="!online" />
            <SyncStatus v-if="!resolving" />
            <div
                v-if="notice"
                class="session-notice"
                :class="`session-notice--${notice.type}`"
                role="status"
            >
                {{ notice.message }}
            </div>
            <button
                v-if="resolving"
                type="button"
                class="session-notice__discard"
                @click="emit('discard')"
            >
                Discard this response instead
            </button>
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
    border-left: 4px solid var(--mds-color-border-strong);
}

.session-notice--error {
    border-left: 4px solid var(--mds-color-action-danger-bg);
    color: var(--mds-color-danger-text);
}

.session-notice__discard {
    align-self: flex-start;
    padding: var(--mds-space-1) var(--mds-space-2);
    background: transparent;
    border: none;
    color: var(--mds-color-text-secondary);
    font-family: var(--mds-font-family-body);
    font-size: var(--mds-type-body-sm-font-size, var(--mds-type-caption-font-size));
    text-decoration: underline;
    cursor: pointer;
}

.session-notice__discard:hover {
    color: var(--mds-color-danger-text, var(--mds-color-text-body));
}
</style>
