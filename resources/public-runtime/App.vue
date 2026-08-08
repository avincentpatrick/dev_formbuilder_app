<script setup lang="ts">
/**
 * Root of the public-runtime SPA (Increment F6b). A small state machine — loading → ready → confirmation, with
 * an error terminal — around one long-lived, stateful `ApiClient`. It fetches the schema once, hosts the fill
 * session (re-keyed on a version-drift `reschema` so the store rebuilds cleanly), and shows the confirmation.
 */
import { onMounted, provide, ref, shallowRef } from 'vue';
import { MdsEmptyState, MdsSpinner } from '@meridian/design-system';
import ConfirmationScreen from './components/ConfirmationScreen.vue';
import OfflineIndicator from './components/OfflineIndicator.vue';
import RuntimeSession from './components/RuntimeSession.vue';
import SyncStatus from './components/SyncStatus.vue';
import { ConflictReviewKey, DbKey, OfflineMediaKey, SyncOutboxKey, UploadUrlKey } from './composables/context';
import { useOnline } from './composables/useOnline';
import { createSyncOutbox } from './composables/useSyncOutbox';
import { createApiClient, resumeDraft } from './lib/api-client';
import { openDb } from './lib/db';
import { localMediaRefId, stash } from './lib/media-queue';
import { reconcileDraft, type LocalDraft } from './lib/reconcile';
import { uuidv7 } from './lib/uuid';
import { ApiError } from './lib/error-normalizer';
import { deriveReference } from './lib/reference-number';
import { formatInstantInZone, scheduleStateCopy, type ScheduleStateCopy } from './lib/schedule';
import { isoClock } from './lib/schema-mapping';
import type { AnswerMap, Bootstrap, ScheduleAcceptance, ScheduleBlock, SchemaResponse } from './lib/types';

const props = defineProps<{ bootstrap: Bootstrap }>();

// 'unavailable' = a scheduled form the runtime refuses to render for filling (opens-soon / closed / full),
// computed from the server-side `acceptance` label (Increment H12b). It REPLACES the form.
type Phase = 'loading' | 'ready' | 'confirmation' | 'error' | 'unavailable';

const CONFIRM_MESSAGE = 'Thanks — your response has been recorded.';
const QUEUED_MESSAGE = "Saved on this device — we'll submit it automatically when you're back online.";
const RESOLVED_MESSAGE = 'Thanks — your reviewed response has been submitted.';

// Increment G8c — the resolve-mode banner, keyed by which 409 parked the row.
const DRIFT_RESOLVE_NOTICE =
    'This form was updated after this response was saved. Your answers were kept where possible — please review and resubmit.';
const CONTENT_RESOLVE_NOTICE =
    'This response conflicts with a copy already saved. Please review your answers and submit again.';

function resolveNotice(code: string | null): string {
    return code === 'submission_conflict' ? CONTENT_RESOLVE_NOTICE : DRIFT_RESOLVE_NOTICE;
}

const phase = ref<Phase>('loading');
const schema = shallowRef<SchemaResponse | null>(null);
const errorMessage = ref('');
// Increment H12b — the full-screen "unavailable" copy (opens-soon / closed / full) when phase === 'unavailable'.
const unavailableCopy = ref<ScheduleStateCopy | null>(null);
const reference = ref('');
const confirmationMessage = ref(CONFIRM_MESSAGE);
const sessionKey = ref(0);
const retainedAnswers = shallowRef<AnswerMap | undefined>(undefined);
const driftNotice = ref<string | null>(null);
// Increment H10 — the reconciled resume seed for RuntimeSession (null on a normal entry / drift remount).
const resumeSeed = shallowRef<{
    uuid: string;
    locale: string | null;
    stepKey: string | null;
    completeness: number | null;
    note: string | null;
} | null>(null);
const RESUME_UNAVAILABLE_MESSAGE =
    'This saved form is no longer available — it may have already been submitted, or the link may have expired.';
// Increment G8c — while resolving a parked conflict, the uuid of the row being reviewed (discarded on success).
const resolvingUuid = ref<string | null>(null);

// I10d — the app-level OfflineIndicator needs its own reading. RuntimeSession keeps its own useOnline()
// for the H12b schedule guard; this one only decides whether the pill shows.
const online = useOnline();
const resolveMode = ref(false);

const client = createApiClient({ token: props.bootstrap.shareToken, slug: props.bootstrap.slug });

// Increment H7 — the query string this form was opened with, read ONCE here (the one DOM touch) and handed
// to every session below so `url`-sourced hidden fields prefill. Captured at module scope rather than per
// session because a version-drift remount or a conflict review re-mounts RuntimeSession against the SAME
// navigation, and the respondent's original link is still what those sessions belong to.
//
// Known narrowing, recorded rather than papered over: an INSTALLED PWA launches at the manifest's
// `start_url` (`/f/{slug}`, no query), so a prefilled link opened from the home-screen icon prefills
// nothing. That is inherent to how the manifest scopes a form and is not worth widening the manifest for.
const initialSearch = typeof window === 'undefined' ? '' : window.location.search;

// Increment H21a (Doc #27 §3.4) — the session clock. Read HERE, once, for the same reason `initialSearch`
// is: the runtime store is deliberately DOM-free and pure, so a `new Date()` inside it would break its own
// unit-testability invariant. Before this, `toSemanticInput()` passed no clock at all, so `today()` and
// `now()` both evaluated to ABSENT in the SPA while PHP always stamps one — a live relevance divergence
// invisible to the R3 gate, because the golden runner supplies `now` from each vector.
//
// Module scope, not per-session, so a version-drift remount or a conflict review keeps the clock the
// respondent started under rather than silently jumping it mid-session.
const sessionNow = isoClock(new Date());

// Increment G8b — the offline database + replay driver are created once here and shared with every session
// (and, via the same DB name, with the service worker's Background-Sync replay). The slug scopes G8c conflict
// resolution to rows this form's share-token client can resubmit.
const db = openDb();
const syncOutbox = createSyncOutbox(db, { slug: props.bootstrap.slug });
provide(DbKey, db);
provide(SyncOutboxKey, syncOutbox);
provide(ConflictReviewKey, beginConflictReview);

// Media uploads (Increment G6) POST to the same token-scoped guest surface, resolved live so a re-minted token
// is picked up. The manual-encode channel instead gets its form-scoped URL from EncodeFormPresenter.
provide(UploadUrlKey, () => `/api/v1/public/f/${encodeURIComponent(client.token())}/attachments`);

// Offline media staging (Increment G8b): when a pick can't upload, keep the blob in the Dexie media queue and
// hand back a `local:` placeholder ref that the outbox replay swaps for a real attachment id on reconnect.
provide(OfflineMediaKey, async (file, fieldKey) => {
    const localId = uuidv7();
    await stash(db, {
        attachment_local_id: localId,
        field_key: fieldKey,
        blob: file,
        name: file.name,
        mime: file.type,
        size: file.size,
    });
    return { id: localMediaRefId(localId), name: file.name, mime: file.type };
});

onMounted(load);

async function load(): Promise<void> {
    phase.value = 'loading';
    try {
        if (props.bootstrap.resumeToken !== '') {
            await loadResume(props.bootstrap.resumeToken);
            return;
        }
        schema.value = await client.fetchSchema();
        // Increment H12b — a scheduled form that isn't accepting fresh responses (opens-soon / closed / full)
        // shows a full-screen state INSTEAD of the fill session. This gate is on the FRESH-load path only: a
        // resume link (loadResume) is never blocked here, so H12a's grace window (a draft started before close
        // may still be promoted) is honoured — write-path enforcement is authoritative for that case.
        const state = scheduleState(schema.value.form.schedule);
        if (state !== null) {
            unavailableCopy.value = state;
            phase.value = 'unavailable';
            return;
        }
        phase.value = 'ready';
    } catch (error) {
        errorMessage.value =
            error instanceof ApiError ? error.normalized.message : 'We could not load this form. Please try again.';
        phase.value = 'error';
    }
}

/** Render an ISO instant in the given zone using the SPA's default locale (Increment H12b). */
function formatInstant(iso: string, timeZone: string): string {
    return formatInstantInZone(iso, timeZone, props.bootstrap.defaultLocale || 'en');
}

/** The unavailable-state copy for a schedule block, or null when the form is open / unscheduled (H12b). */
function scheduleState(schedule: ScheduleBlock | undefined): ScheduleStateCopy | null {
    if (schedule === undefined || schedule.acceptance === 'open') {
        return null;
    }
    return scheduleStateCopy(schedule, formatInstant);
}

/**
 * Increment H12b — a submit that 403s with a schedule reason (the form closed / filled while being filled in
 * online) transitions the whole SPA to the unavailable state, overriding the load-time acceptance with the
 * one the server just reported.
 */
function onUnavailable(acceptance: ScheduleAcceptance): void {
    const base: ScheduleBlock = schema.value?.form.schedule ?? {
        opens_at: null,
        closes_at: null,
        timezone: 'UTC',
        max_responses: null,
        acceptance,
        remaining: null,
    };
    unavailableCopy.value = scheduleStateCopy({ ...base, acceptance }, formatInstant);
    phase.value = 'unavailable';
}

// Increment H10 — open from a resume link. The web shell already embedded a fresh pinned-version SHARE token
// (data-share-token), so the existing `client` is ready; the resume-read gives the saved answers + a server
// "last saved" to reconcile against any same-device local draft, and the draft's uuid so the eventual submit
// PROMOTES that row. A promoted/reaped/expired draft → a resume-specific terminal message.
async function loadResume(resumeToken: string): Promise<void> {
    let server;
    try {
        server = await resumeDraft(resumeToken);
    } catch (error) {
        // A 404 (draft_not_found) / invalid token is terminal — the saved response is gone. Show a
        // resume-specific message rather than the generic normalized one.
        errorMessage.value =
            error instanceof ApiError && error.normalized.kind === 'terminal'
                ? RESUME_UNAVAILABLE_MESSAGE
                : error instanceof ApiError
                  ? error.normalized.message
                  : 'We could not restore your saved form. Please try again.';
        phase.value = 'error';
        return;
    }

    schema.value = await client.fetchSchema();

    const localRow = await db.draft_answers.get([server.formVersionId, props.bootstrap.slug]);
    const local: LocalDraft | undefined =
        localRow === undefined
            ? undefined
            : {
                  checksum: localRow.checksum,
                  locale: localRow.locale,
                  currentStepKey: localRow.current_step_key,
                  answers: localRow.answers,
                  updatedAt: localRow.updated_at,
              };

    const reconciled = reconcileDraft(server, schema.value.version.checksum, local);

    retainedAnswers.value = reconciled.answers;
    resumeSeed.value = {
        // Guest drafts always carry the SPA's uuid; the fallback only guards the impossible OCR-staged case
        // (where a fresh uuid means the submit creates a new row rather than promoting — acceptable degradation).
        uuid: server.clientSubmissionUuid ?? uuidv7(),
        locale: reconciled.locale,
        stepKey: reconciled.currentStepKey,
        completeness: server.completenessPercent,
        note: reconciled.note,
    };
    phase.value = 'ready';
}

function onSubmitted(id: string, authored: string | null = null): void {
    // Increment G8c — a resolved conflict: drop the parked row now that its reviewed answers are recorded.
    const resolved = resolvingUuid.value !== null;
    if (resolved) {
        void syncOutbox.discardSubmission(resolvingUuid.value as string);
        clearResolveState();
    }
    reference.value = deriveReference(id);
    // Increment H6b — the author's message (already locale-resolved and hole-filled by RuntimeSession,
    // which still had the store when it emitted) replaces the hardcoded copy on BOTH terminal success
    // states. Null — no message, or one whose every hole was unanswered — keeps the default.
    confirmationMessage.value = authored ?? (resolved ? RESOLVED_MESSAGE : CONFIRM_MESSAGE);
    phase.value = 'confirmation';
}

// Increment G8b — an offline (or dropped-mid-submit) finalize: the answers are safely queued, so show a
// "saved on this device" confirmation with a local reference derived from the client submission id.
function onQueued(clientUuid: string): void {
    // Increment G8c — a resolve that went offline: the reviewed answers are safely re-queued under the new
    // uuid, so the old parked conflict row can be dropped.
    if (resolvingUuid.value !== null) {
        void syncOutbox.discardSubmission(resolvingUuid.value);
        clearResolveState();
    }
    reference.value = deriveReference(clientUuid);
    // Increment H6b deliberately does NOT let an author message replace this one. It is not a thank-you —
    // it is the only thing telling the respondent their answers have not been delivered yet, and swapping
    // it for "Thanks, Maria — your response has been recorded." would be a factual lie about delivery
    // state on the one screen where that truth matters most.
    confirmationMessage.value = QUEUED_MESSAGE;
    phase.value = 'confirmation';
}

function onReschema(payload: { schema: SchemaResponse; answers: AnswerMap }): void {
    schema.value = payload.schema;
    retainedAnswers.value = payload.answers;
    // Increment H10 — a drift remount starts fresh against the new version (a new submission, fresh uuid) and
    // is not a resume, so drop the welcome-back seed; the drift notice below explains the carry-over instead.
    resumeSeed.value = null;
    // Increment G8c — a fresh republish DURING a conflict review keeps the resolve context (notice + row), so the
    // loop re-maps against the newer schema until the resubmit succeeds; otherwise it's the normal live-drift copy.
    driftNotice.value = resolveMode.value
        ? DRIFT_RESOLVE_NOTICE
        : 'This form was updated. Your answers were kept where possible — please review and resubmit.';
    sessionKey.value += 1;
    phase.value = 'ready';
}

// Increment G8c — open the review UX for the oldest parked conflict on this form: re-mint the token, re-fetch
// the current published schema, and re-mount the fill session seeded with the saved answers (re-mapped onto the
// new schema; a fresh client_submission_uuid is minted by the store). The user reviews and resubmits (or discards).
async function beginConflictReview(uuid?: string): Promise<void> {
    // Named row first (the per-row Review button), else the oldest (the banner's Review).
    const row = uuid === undefined ? await syncOutbox.nextConflict() : await syncOutbox.conflictRow(uuid);
    if (row === null) {
        await syncOutbox.refresh();
        return;
    }
    try {
        await client.remint();
        schema.value = await client.fetchSchema();
        retainedAnswers.value = row.answers;
        resumeSeed.value = null; // a conflict review is its own entry, never a resume (H10)
        resolvingUuid.value = row.client_submission_uuid;
        // I10d — the list hides the row whose review flow is on screen. This replaces RuntimeSession's old
        // blanket `v-if="!resolving"`, which hid the WHOLE surface during a review; the respondent now keeps
        // sight of their other queued submissions while resolving one.
        syncOutbox.reviewingUuid.value = row.client_submission_uuid;
        resolveMode.value = true;
        driftNotice.value = resolveNotice(row.conflict_code);
        sessionKey.value += 1;
        phase.value = 'ready';
    } catch (error) {
        errorMessage.value =
            error instanceof ApiError
                ? error.normalized.message
                : 'We could not load the latest form to resolve this response. Please try again.';
        phase.value = 'error';
    }
}

// Increment G8c — the "discard this response instead" escape hatch: drop the parked row, then move on to the
// next conflict if any, else reload into a fresh fill of the current form.
async function onDiscard(): Promise<void> {
    const uuid = resolvingUuid.value;
    if (uuid === null) {
        return;
    }
    // ⚠️ NARROWING, RECORDED RATHER THAN LEFT AS AN INCONSISTENCY. I10d gave the new per-submission list an
    // INLINE two-step confirm and rejected `window.confirm` for it on specific grounds — it blocks the main
    // thread, renders as unstyled OS chrome inside the branded offline shell, cannot be asserted by the
    // Playwright gate without a dialog handler, and cannot name WHICH response it is about to destroy. Every
    // one of those applies here too. It is left alone in this increment only because this is the G8c
    // resolve-mode path, whose button lives in RuntimeSession's notice slot and whose visibility the e2e
    // already pins; converting it is a change to that flow rather than to this one. Filed to the backlog.
    if (typeof window !== 'undefined' && !window.confirm('Discard this saved response? This cannot be undone.')) {
        return;
    }
    await syncOutbox.discardSubmission(uuid);
    clearResolveState();
    if ((await syncOutbox.nextConflict()) !== null) {
        void beginConflictReview();
        return;
    }
    window.location.reload();
}

function clearResolveState(): void {
    resolvingUuid.value = null;
    syncOutbox.reviewingUuid.value = null;
    resolveMode.value = false;
    driftNotice.value = null;
    retainedAnswers.value = undefined;
}

function onRestart(): void {
    window.location.reload();
}
</script>

<template>
    <!--
        I10d — the sync surface is mounted HERE, above the phase machine, not inside RuntimeSession.
        `docs/ux/form-filling-ux-flow.md` §7.1 puts the list "inside the installed PWA — visible from the
        form's own completion/home view", and §7.3 asks for a persistent, non-modal banner. Mounted inside the
        fill session this vanished on exactly the screen §7.1 names — the confirmation — as well as when a
        form is unavailable and while one is loading. (The cited section is §7.1/§7.3; an earlier version of
        this comment cited a §7.3.1 that does not exist.)

        The wrapper is a flex COLUMN and the phase panels below became `flex: 1`, replacing the three
        `min-height: 100vh` rules that each assumed they owned the viewport. Without that, putting anything
        above them makes the document taller than the screen and every page gains a scrollbar — which
        `assertClean` would NOT have caught, since it asserts horizontal overflow only.
    -->
    <div class="app-shell">
        <div class="app-shell__banner">
            <OfflineIndicator v-if="!online" />
            <SyncStatus />
        </div>

        <div v-if="phase === 'loading'" class="app-state">
            <MdsSpinner size="lg" label="Loading form" />
        </div>
        <div v-else-if="phase === 'error'" class="app-state">
            <MdsEmptyState illustration="lock" headline="This form isn’t available" :description="errorMessage" />
        </div>
        <div v-else-if="phase === 'unavailable' && unavailableCopy" class="app-state">
            <MdsEmptyState
                :illustration="unavailableCopy.illustration"
                :headline="unavailableCopy.headline"
                :description="unavailableCopy.description"
            />
        </div>
        <ConfirmationScreen
            v-else-if="phase === 'confirmation'"
            :reference="reference"
            :message="confirmationMessage"
            @restart="onRestart"
        />
        <RuntimeSession
            v-else-if="schema"
            :key="sessionKey"
            :schema="schema"
            :bootstrap="bootstrap"
            :client="client"
            :initial-answers="retainedAnswers"
            :notice="driftNotice"
            :resolving="resolveMode"
            :resume="resumeSeed"
            :search="initialSearch"
            :now="sessionNow"
            @submitted="onSubmitted"
            @queued="onQueued"
            @reschema="onReschema"
            @discard="onDiscard"
            @unavailable="onUnavailable"
        />
    </div>
</template>

<style scoped>
/* I10d — the column that lets a persistent surface sit above a full-height phase panel without making the
   document taller than the viewport. The phase panels below use `flex: 1` for the same reason. */
.app-shell {
    display: flex;
    flex-direction: column;
    min-height: 100vh;
}

/*
 * The promoted surface has to line up with the runtime's own column, not span the viewport. Both used to
 * render inside RuntimeShell's notice slot, i.e. inside its centred 44rem column with its padding; hoisted
 * to a bare flex child they became full-bleed and the offline pill sat against the screen edge.
 */
.app-shell__banner {
    width: 100%;
    max-width: 44rem;
    margin: 0 auto;
    padding: var(--mds-space-3) var(--mds-space-4) 0;
    display: flex;
    flex-direction: column;
    gap: var(--mds-space-2);
}

.app-state {
    flex: 1;
    display: flex;
    align-items: center;
    justify-content: center;
    padding: var(--mds-space-6);
    background-color: var(--mds-color-bg-canvas);
}
</style>
