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
    RespondentSessionKey,
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
import { touchRespondentSession } from '../lib/respondent-session';
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
        /** Increment P3a — the SERVER draft's lost-update baseline, which is deliberately not affected by
         *  which tier reconcileDraft chose: it describes the state on the server that the next save will
         *  write over, not the answers being shown. Same rule reconcile.ts already records for the uuid. */
        contentChecksum: string | null;
    /** Increment M14 — false when the seed came from a mid-session draft RE-READ rather than from opening a
     *  resume link, which suppresses the welcome-back banner. The respondent never left, so "Welcome back —
     *  we've restored your saved answers" would be addressed to a journey they did not take; the redraft
     *  banner says what actually happened. Absent means true, so every pre-M14 caller is unchanged. */
    greet?: boolean;
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
    // Increment J2e — the SERVER-issued reference rides along, so App.vue prints the code the tenant can
    // find rather than one derived on the device from the id.
    submitted: [id: string, reference: string, confirmation: string | null];
    queued: [clientUuid: string];
    // Increment M14 — `conflictCode` names WHICH 409 caused the remount (null = the ordinary republish
    // drift), so App.vue can pick a true sentence instead of asserting a republish for all five causes.
    reschema: [payload: { schema: SchemaResponse; answers: AnswerMap; conflictCode: string | null }];
    // Increment M14 — a 409 `draft_conflict`: re-read the server draft and re-mount on it, KEEPING the uuid.
    // Deliberately not a variant of `reschema`: that event means "start a new submission against a new
    // version", and this one means the exact opposite on both counts.
    redraft: [payload: { resumeToken: string }];
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
// Increment M15 — provided by App.vue (see `RespondentSessionKey` for why it is injected, not imported).
// Null only in a test that mounts this component bare; a row stamped null reads as an earlier visit, which
// is the safe direction.
const respondentSessionId = inject(RespondentSessionKey, null);

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
    // Increment M21 — the visit read at `:124`, so an abandoned draft is never restored into the next
    // respondent's form. `undefined` (a bare test mount, where the inject falls back to null) keeps the
    // pre-M21 unscoped behaviour, which is M15's convention for this argument throughout the runtime.
    sessionId: respondentSessionId ?? undefined,
    // Increment M21 — the respondent typing is what keeps their visit alive. Without this the window
    // measured elapsed-since-boot rather than idle, so a refresh part-way through a long fill issued a
    // FRESH visit and this session's own draft became unrestorable. See `lib/respondent-session.ts`.
    onActivity: touchRespondentSession,
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

/**
 * Re-mint, re-fetch the schema, and hand both up so `App.vue` can re-mount the session against them.
 *
 * Increment M14 — `conflictCode` is the 409 envelope code that sent us here, or `null` for the ordinary
 * republish drift this function was written for. It is passed through rather than resolved here because the
 * banner belongs to `App.vue`, which owns every other piece of resolve/drift copy; all this function knows is
 * which refusal it is recovering from.
 */
async function handleDrift(conflictCode: string | null = null): Promise<void> {
    try {
        await props.client.remint();
        const next = await props.client.fetchSchema();
        emit('reschema', { schema: next, answers: { ...runtime.answers }, conflictCode });
    } catch (error) {
        // ⚠️ INCREMENT M66 — THE ERROR IS BOUND NOW, AND THE SENTENCE BELOW USED TO BE THE ONLY ONE. This
        // `catch` took no argument, so a dropped connection during `remint()` or `fetchSchema()` read as
        // "This form is no longer available." — a terminal claim about the FORM, made about the NETWORK.
        //
        // The split is the one this file already makes 76 lines down (`handleSubmitError`'s tail) and the
        // one `App.vue:236-242` and `lib/replay.ts` make around this very call pair. `ApiError` means the
        // server answered; anything else is a raw fetch rejection. Keeping the original sentence on the
        // `terminal` arm is deliberate — that is the one case it was always true for, and the phrase is
        // bound to a real 404 everywhere else in the codebase (`GuestDraftResumeController`, `App.vue`).
        notice.value = {
            type: 'error',
            message:
                error instanceof ApiError && error.normalized.kind === 'terminal'
                    ? 'This form is no longer available.'
                    : error instanceof ApiError
                      ? error.normalized.message
                      : unreachableRecoveryMessage(),
        };
    }
}

/**
 * The network arm's copy — and the reason it branches is a data question, not a wording one.
 *
 * ⛔ "YOUR ANSWERS ARE SAVED ON THIS DEVICE" IS FALSE IN A CONFLICT REVIEW, WHICH IS THE ONE PLACE IT WOULD
 * MATTER MOST. `handleSubmitError` discards the durable outbox row BEFORE it calls `handleDrift`, and a
 * resolving session has autosave disabled by construction (`enabled: !props.resolving` above) because the
 * parked row WAS its durable copy. So when this arm is reached while resolving, the reviewed answers exist
 * only in memory: reassuring the respondent would be the same class of error as the sentence this
 * increment removed, pointing the other way.
 *
 * The underlying lifecycle defect — that a failed recovery can strand the only copy — is filed rather than
 * fixed here; it reaches the outbox contract `lib/replay.ts` and the background driver share.
 */
function unreachableRecoveryMessage(): string {
    return props.resolving
        ? 'We could not reach the server to reload this form. Please keep this page open and try again once you are back online.'
        : 'We could not reach the server to reload this form. Your answers are saved on this device — please check your connection and try again.';
}

/**
 * Increment M14 — a 409 `draft_conflict`: another device wrote the server draft this session is editing.
 *
 * ⚠️ THE REMEDY IS THE OPPOSITE OF `handleDrift`'s AND THAT IS THE WHOLE POINT OF THE INCREMENT. Nothing was
 * republished, so re-fetching the schema answers a question nobody asked; and re-mounting mints a fresh
 * `client_submission_uuid` with a null baseline, which abandons the server draft mid-edit and lets the next
 * save mint a SECOND draft with a second emailed resume link. Re-reading the draft keeps the uuid, picks up
 * the other device's answers, and re-bases on the checksum the server actually holds.
 *
 * ⚠️ THE FALLBACK IS DEGRADED, NEVER SILENT. A re-read needs a resume token, and although one is in hand
 * wherever this refusal is reachable — the server only raises it against an EXISTING draft row for this uuid
 * (`GuestSubmissionController::existingDraft()`), which this device either created (every save returns a
 * token) or resumed into (the token is in the URL) — that argument is about the server's reachability, not
 * about this component's state. If the token is missing anyway, the drift remount still runs and the
 * respondent still gets a true sentence; they lose the re-read, not the refusal.
 */
function handleRedraft(conflictCode: string): void {
    const token = draftResumeToken.value;
    if (token === null) {
        void handleDrift(conflictCode);

        return;
    }
    emit('redraft', { resumeToken: token });
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
        // Increment M14 — the three 409s that are NOT a republish. Until M14 all of them arrived as `refresh`
        // and took the branch above, so the respondent read "This form was updated" for a form nobody had
        // touched. `draft_stale` re-reads the draft and keeps the uuid; the other two keep the remount, whose
        // fresh uuid is the correct remedy for `uuid_claimed` and harmless for a content conflict — only the
        // sentence was wrong for them, and the code now carries it.
        //
        // `finalized` deliberately has no branch: it falls to the generic tail below, which shows the server's
        // own sentence ("This draft has already been submitted…"). That IS the right answer, and a branch that
        // reproduced it would be a second place to keep it correct.
        if (normalized.kind === 'draft_stale') {
            handleRedraft(normalized.code);
            return 'blocked';
        }
        if (normalized.kind === 'conflict' || normalized.kind === 'uuid_claimed') {
            void handleDrift(normalized.code);
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
        // Increment M15 — WHOSE visit this row belongs to, stamped at the one place a row is ever created.
        // `device_id` above answers "which machine collected this" and deliberately outlives everybody;
        // this answers "who was standing here", which is what the outbox surface needs and never had.
        respondent_session_id: respondentSessionId,
        // Increment P3a — freeze the baseline INTO the queued row, so a replay hours from now makes the same
        // claim this submit would have made live. Null on an ordinary fill that never created a server draft.
        base_content_checksum: draftBaseline.value,
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
            // Increment P3a — the same claim the queued row above carries. A submit against an existing
            // server draft saves before it promotes, so without this a stale device finalizes over another
            // device's answers.
            baseContentChecksum: draftBaseline.value,
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
        emit('submitted', result.id, result.reference, authoredConfirmation());
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

// Increment P3a — this device's lost-update baseline, seeded from the resume read and advanced by every
// successful save. A fresh (non-resumed) session starts null, which is the honest claim: it has read nothing,
// so its first save creates the draft rather than overwriting one.
const draftBaseline = ref<string | null>(props.resume?.contentChecksum ?? null);
// Increment M14 — the token a `draft_conflict` re-reads through. Seeded from the resume link this session was
// opened with (empty on a normal `/f/{slug}` entry) and advanced by every successful save, because
// `GuestDraftController` mints a fresh one on each. The save result carried it all along and this component
// threw it away, which is why the recovery had nothing to read the draft with.
const draftResumeToken = ref<string | null>(props.bootstrap.resumeToken === '' ? null : props.bootstrap.resumeToken);

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
            baseContentChecksum: draftBaseline.value,
        });
        draftCompleteness.value = result.completenessPercent;
        // Advance the baseline to what the server just wrote, so the NEXT save from this device is based on
        // it. Skipping this would make every save after the first look like a second device.
        draftBaseline.value = result.contentChecksum;
        draftResumeToken.value = result.resumeToken;
        const email = (options.email ?? '').trim();
        return { resumeUrl: result.resumeUrl, emailed: options.finishLater && email !== '' };
    } catch (error) {
        if (error instanceof ApiError) {
            const { kind, code } = error.normalized;
            // A republish between mint and save surfaces as a refresh drift — route it through the same reschema
            // remount as submit (the caller sees null and closes; the session re-mounts against the new version).
            if (kind === 'refresh') {
                void handleDrift();

                return null;
            }
            // Increment M14 — the same three-way split the submit path takes. This branch used to be `refresh`
            // ALONE, and its comment named a republish as the only thing that reached it, which was true when
            // it was written and stopped being true at P3a: `GuestDraftController` sets `checkBaseline: true`
            // unconditionally, so a `draft_conflict` is reachable on EVERY save and was landing here silently.
            if (kind === 'draft_stale') {
                handleRedraft(code);

                return null;
            }
            if (kind === 'conflict' || kind === 'uuid_claimed') {
                void handleDrift(code);

                return null;
            }
        }
        // ⚠️ INCREMENT M14 — EVERYTHING ELSE IS RE-THROWN SO THE CALLER CAN SPEAK, AND THAT IS THE FIX FOR THE
        // SECOND BACKLOG ROW. `null` means "the session is remounting, so say nothing" — it is a correct answer
        // only for the branches above, which all remount. A `finalized` refusal remounts nothing, so returning
        // null for it produced the reported symptom exactly: "Save and finish later" with no save, no resume
        // link and no error. `SaveForLater` now renders the server's own sentence for these.
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
                v-if="resume && resume.greet !== false"
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
