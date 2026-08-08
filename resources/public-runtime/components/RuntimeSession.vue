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
import PageView from './PageView.vue';
import StepView from './StepView.vue';
import WelcomeBackBanner from './WelcomeBackBanner.vue';
import { createAnnouncer } from '../composables/useAnnouncer';
import { createAutosave } from '../composables/useAutosave';
import { createFormRuntime, type StepResolution } from '../composables/useFormRuntime';
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
import { acceptanceForReasonCode, hasScheduleConstraint } from '../lib/schedule';
import { openDb } from '../lib/db';
import { discardRow, enqueue } from '../lib/outbox';
import { attachToSubmission, collectLocalMediaIds } from '../lib/media-queue';
import { getDeviceId } from '../lib/device';
import { APP_VERSION } from '../lib/app-version';
import type { ApiClient } from '../lib/api-client';
import type { AnswerMap, Bootstrap, ScheduleAcceptance, SchemaResponse } from '../lib/types';

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
    /** Increment H7 — the raw `location.search` to prefill `url`-sourced hidden fields from. App.vue reads
     *  the DOM once and threads it here so the store itself stays DOM-free. */
    search?: string;
    /** Increment H21a — the session clock `today()`/`now()` evaluate against, in PHP's exact ISO-8601 shape.
     *  Threaded from App.vue for the same reason `search` is (Doc #27 §3.4). */
    now?: string | null;
}>();

const emit = defineEmits<{
    // Increment H6b — `confirmation` is the author's message, ALREADY locale-resolved and hole-filled, or
    // null to keep App.vue's hardcoded default. It is rendered here rather than there because App unmounts
    // this session the instant it flips to the confirmation phase (they are mutually exclusive branches),
    // so the store — and with it the locale, the source map and the submitted answers — does not outlive
    // the emit. See {@link authoredConfirmation}.
    submitted: [id: string, confirmation: string | null];
    queued: [clientUuid: string];
    reschema: [payload: { schema: SchemaResponse; answers: AnswerMap }];
    discard: [];
    // Increment H12b — a submit rejected because the form closed / filled mid-fill; App shows the full-screen state.
    unavailable: [acceptance: ScheduleAcceptance];
}>();

const runtime = createFormRuntime(props.schema, {
    // A resumed session restores its saved locale; otherwise the bootstrap/form default (Increment H10).
    initialLocale: props.resume?.locale ?? (props.bootstrap.defaultLocale || props.schema.form.default_locale),
    initialAnswers: props.initialAnswers,
    // A resumed session reuses the draft's uuid so its submit promotes that row (H9a invariant).
    initialClientSubmissionUuid: props.resume?.uuid,
    // Increment H7 — hidden-field prefill. Seeded before `initialAnswers`, so a restored draft wins.
    search: props.search,
    // Increment H21a — the clock, frozen for the life of this session (Doc #27 §3.4).
    now: props.now,
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
            // Increment H21b, Doc #27 §5.3 — this used to be a guarded no-op, so a stored step that no longer
            // resolved dropped the respondent on step 1 in silence. Now it reports HOW it resolved, and the
            // welcome-back banner says so.
            stepResolution.value = runtime.goToStep(props.resume.stepKey);
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

// Increment H21b — where the resume cursor landed, and the step it landed on, for the banner's explanation.
const stepResolution = ref<StepResolution | null>(null);
const resumeStepTitle = computed(() => runtime.currentStep.value?.title ?? null);

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
        if (normalized.kind === 'schedule') {
            // Increment H12b — the form closed / filled while being filled in. The queued row was already
            // discarded above; hand off to App.vue to replace the form with the full-screen unavailable state.
            emit('unavailable', acceptanceForReasonCode(normalized.code) ?? 'closed');
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

    // Increment H12b — fail CLOSED offline for a scheduled/capped form. Its `acceptance` was computed
    // server-side at load and can flip (close / fill up) while offline, and an offline SPA can't re-verify it.
    // Blocking here (before any enqueue) avoids optimistically queuing a response that would 403 on replay.
    // Unscheduled forms are untouched — they keep the G8b offline outbox; a device-local draft can still save.
    if (!online.value && hasScheduleConstraint(props.schema.form.schedule)) {
        notice.value = {
            type: 'error',
            message:
                "This form can't be submitted while you're offline, because it has a limited open period. " +
                'Please reconnect to submit.',
        };
        return 'blocked';
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
        // I10d — discardRow, NOT markSynced. This is the path where the submission went straight out while
        // ONLINE, so the outbox row is only the crash-safe intent record and its job is done: outbox.ts's own
        // docblock for discardRow says exactly that ("an online submit resolved the intent without queuing").
        //
        // Retaining here would be actively wrong, not merely redundant. markSynced() now KEEPS the row so it
        // can appear in the list, and the list derives its reference from the CLIENT uuid — while the
        // confirmation screen this path routes to derives its reference from the SERVER id (App.vue). The
        // respondent would be looking at two different codes for the same submission, on the same screen.
        // Discarding also makes the list mean what UX §7.1 says it means: submissions finalized while OFFLINE.
        await discardRow(db, uuid);
        void sync?.refresh();
        await autosave.clear();
        emit('submitted', result.id, authoredConfirmation());
        return 'success';
    } catch (error) {
        return await handleSubmitError(error, uuid);
    } finally {
        submitting.value = false;
    }
}

/**
 * The author-set confirmation copy (Increment H6b, Doc #26 §6.2), locale-resolved then hole-filled.
 *
 * Computed HERE, at submit time, for a structural reason: `App.vue` renders `ConfirmationScreen` and
 * `RuntimeSession` as mutually exclusive branches, so this component unmounts the moment the phase flips.
 * The answers it renders from are literally the ones just enqueued and POSTed, so the thank-you says what
 * was actually recorded — and matches what `SubmissionInboxPresenter` will later render for that row.
 *
 * Flat scope, no repeat instance: §6.2 validates the message at the maximal position in flat scope, so
 * its holes can only name flat fields.
 *
 * A message that is absent, or that renders to nothing (every hole unanswered), collapses to null so the
 * hardcoded default stands. That is not tidiness — `ConfirmationScreen` renders it into the page's only
 * `<h1>` and focuses it on mount, so an empty render would give a focused heading an empty accessible
 * name: an axe `empty-heading` violation on a page the e2e gate scans.
 */
function authoredConfirmation(): string | null {
    const rendered = runtime.templateOptional(
        props.schema.form.confirmation_message ?? null,
        props.schema.form.confirmation_message_translations ?? null,
    );

    return rendered === null || rendered.trim() === '' ? null : rendered;
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
            <WelcomeBackBanner
                v-if="resume"
                :resolution="stepResolution"
                :step-title="resumeStepTitle"
                :note="resume.note"
            />
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
