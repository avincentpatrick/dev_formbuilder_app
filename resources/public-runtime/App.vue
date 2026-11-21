<script setup lang="ts">
/**
 * Root of the public-runtime SPA (Increment F6b). A small state machine — loading → ready → confirmation, with
 * an error terminal — around one long-lived, stateful `ApiClient`. It fetches the schema once, hosts the fill
 * session (re-keyed on a version-drift `reschema` so the store rebuilds cleanly), and shows the confirmation.
 */
import { computed, onMounted, provide, ref, shallowRef, watch } from 'vue';
import { MdsEmptyState, MdsSpinner } from '@meridian/design-system';
import { ContentImageUrlKey } from '@/components/submissions/note-content';
import ConfirmationScreen from './components/ConfirmationScreen.vue';
import OfflineIndicator from './components/OfflineIndicator.vue';
import RuntimeSession from './components/RuntimeSession.vue';
import SyncStatus from './components/SyncStatus.vue';
import {
    ConflictReviewKey,
    DbKey,
    OfflineMediaKey,
    RespondentSessionKey,
    SyncOutboxKey,
    UploadUrlKey,
} from './composables/context';
import { useOnline } from './composables/useOnline';
import { createSyncOutbox } from './composables/useSyncOutbox';
import { createApiClient, readAmend, resumeDraft } from './lib/api-client';
import { contentImageUrl, warmContentImages } from './lib/content-images';
import { conflictCopy } from './lib/conflict-notice';
import { adoptDraft, draftBelongsToVisit, earlierVisitDraft, openDb, type DraftRow } from './lib/db';
import { localMediaRefId, stash } from './lib/media-queue';
import { reconcileDraft, type LocalDraft } from './lib/reconcile';
import { respondentSession, rotateRespondentSession } from './lib/respondent-session';
import { uuidv7 } from './lib/uuid';
import { ApiError } from './lib/error-normalizer';
import { deriveReference } from './lib/reference-number';
import { formatInstantInZone, scheduleStateCopy, type ScheduleStateCopy } from './lib/schedule';
import { isoClock } from './lib/schema-mapping';
import type { AmendLink, AnswerMap, Bootstrap, PaperFieldMeta, PaperPage, PaperReview, ScheduleAcceptance, ScheduleBlock, SchemaResponse, SubmitRedirect } from './lib/types';
// M166 — below the other imports on purpose: `App.vue:29-31` is cited by line, and an import above would move it.
import PaperReading from './components/PaperReading.vue';
// M248 (`D118`, `D232`) — the same reason.
import AlreadyAnswered from './components/AlreadyAnswered.vue';
import ContinueOrStartOver from './components/ContinueOrStartOver.vue';
import { readAnswered, rememberAnswered, type AnsweredRecord } from './lib/answered';

const props = defineProps<{ bootstrap: Bootstrap }>();

// 'unavailable' = a scheduled form the runtime refuses to render for filling (opens-soon / closed / full),
// computed from the server-side `acceptance` label (Increment H12b). It REPLACES the form.
// 'reading' = a sent paper copy being read, before its sender checks it (M166, `D116`). It REPLACES the form too.
// 'continue' = this device holds answers an earlier visit left; ask before showing them (M248, `D118`). REPLACES the form.
// 'answered' = this device already sent a response to a one-response form (M248, `D232`). REPLACES the form.
type Phase = 'loading' | 'ready' | 'reading' | 'confirmation' | 'error' | 'unavailable' | 'continue' | 'answered';

const CONFIRM_MESSAGE = 'Thanks — your response has been recorded.';
const QUEUED_MESSAGE = "Saved on this device — we'll submit it automatically when you're back online.";
const RESOLVED_MESSAGE = 'Thanks — your reviewed response has been submitted.';
// M165 (`D106`) — the user's own sentence for a paper copy sent from the public link.
const PAPER_RECEIVED_MESSAGE = 'Your paper form was received. Staff will check it and enter your answers.';

// Increment G8c — the resolve-mode banner, keyed by which 409 parked the row. ⚠️ INCREMENT M14 MOVED THE
// COPY ITSELF OUT TO `lib/conflict-notice.ts` AND LEFT THE LOOKUP HERE, because `outbox-status.ts` held a
// second, disagreeing copy of the same decision: the list called a content conflict "the form changed after
// this was saved" while this banner called it something else, for one row. One module now answers both.
const DRIFT_RESOLVE_NOTICE = conflictCopy('form_updated').resolveNotice;

// Increment M14 — the LIVE drift banner. Unchanged in wording; it simply stopped being spelled inline in
// `onReschema` once there were three notices to tell apart rather than one.
const LIVE_DRIFT_NOTICE = 'This form was updated. Your answers were kept where possible — please review and resubmit.';

// Increment M14 — the banner for a live draft RE-READ (`ErrorKind` `draft_stale`), and it deliberately does
// NOT live in `conflict-notice.ts`. That module describes a row sitting PARKED in the outbox, which nothing
// has re-read; this describes the one path where the newer answers really were fetched a moment ago and are
// on screen. Reusing the parked wording here would claim a fetch that never happened, which is the same class
// of untrue sentence M14 exists to remove.
const REDRAFT_NOTICE =
    'This response was also filled in on another device. We have loaded that newer copy — please check it over and submit again.';

const phase = ref<Phase>('loading');
const schema = shallowRef<SchemaResponse | null>(null);
const errorMessage = ref('');
// Increment H12b — the full-screen "unavailable" copy (opens-soon / closed / full) when phase === 'unavailable'.
const unavailableCopy = ref<ScheduleStateCopy | null>(null);
// Increment J2e — exactly one of these is ever set. `reference` is the server-issued handle (the response
// reached the server); `queueTag` is a device-local label for one that has not. See ConfirmationScreen.
const reference = ref<string | null>(null);
const queueTag = ref<string | null>(null);
// M130 (`R-db169c29`, `D76`) — where the respondent goes after the thank-you screen, from the submit response.
const redirectTarget = ref<SubmitRedirect | null>(null);
// Whether this page is inside a frame. Comparing the two windows is allowed across origins; reading anything
// else on `top` is not, so an exception means framed by somebody else.
const framed = ((): boolean => {
    try {
        return window.self !== window.top;
    } catch {
        return true;
    }
})();
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
    /** Increment P3a — the server draft's lost-update baseline (see loadResume for why it is the server's). */
    contentChecksum: string | null;
    /** Increment M14 — false when the seed came from a mid-session draft RE-READ rather than from opening a
     *  resume link, which suppresses the welcome-back banner. The respondent never left, so "Welcome back —
     *  we've restored your saved answers" would be addressed to a journey they did not take; the redraft
     *  banner says what actually happened. Absent means true, so every pre-M14 caller is unchanged. */
    greet?: boolean;
} | null>(null);
const RESUME_UNAVAILABLE_MESSAGE =
    'This saved form is no longer available — it may have already been submitted, or the link may have expired.';
// Increment G8c — while resolving a parked conflict, the uuid of the row being reviewed (discarded on success).
const resolvingUuid = ref<string | null>(null);

// I10d — the app-level OfflineIndicator needs its own reading. RuntimeSession keeps its own useOnline()
// for the H12b schedule guard; this one only decides whether the pill shows.
// DESTRUCTURED — `useOnline()` returns `{ online, dispose }`, not a bare ref. Writing `const online =
// useOnline()` binds the OBJECT, which is always truthy, so `v-if="!online"` never fires and the offline
// pill silently never renders. vue-tsc cannot catch it (`!someObject` is valid TS) and the component
// tests mount OfflineIndicator directly, so only the e2e saw it.
const { online } = useOnline();
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
// M180 (`D153`): the instant is read here, once; it is STAMPED in the form's zone once the schema is in, so `today()` is
// the form's calendar day — and a remount still keeps the instant the respondent started under.
const sessionInstant = new Date();
// M248 (`D232`) — a session changing a SENT response is checked against the day it was sent, as the server checks it
// (`SubmissionAnswerEditService`'s `submitted_at` clock); otherwise a date rule could pass on the server and fail here.
const sessionNow = computed(() =>
    isoClock(amendClock.value ?? sessionInstant, schema.value?.form.schedule?.timezone ?? 'UTC'),
);

// Increment G8b — the offline database + replay driver are created once here and shared with every session
// (and, via the same DB name, with the service worker's Background-Sync replay). The slug scopes G8c conflict
// resolution to rows this form's share-token client can resubmit.
const db = openDb();

// Increment M15 — WHICH VISIT IS AT THIS DEVICE, read once here for the same reason `initialSearch` and
// `sessionNow` are read here: this is the composition root, and the modules below stay pure. The outbox
// surface mounts above the phase machine on an unauthenticated page, so without this the next respondent
// at a shared kiosk saw the previous one's queue tags, server references and Discard buttons.
//
// Module scope, so a version-drift remount or a conflict review keeps the visit the respondent started
// under — the same reasoning `sessionNow` gives for not re-reading the clock per session.
const respondentSessionId = respondentSession();

const syncOutbox = createSyncOutbox(db, { slug: props.bootstrap.slug, sessionId: respondentSessionId });
provide(RespondentSessionKey, respondentSessionId);
provide(DbKey, db);
provide(SyncOutboxKey, syncOutbox);
provide(ConflictReviewKey, beginConflictReview);

// Media uploads (Increment G6) POST to the same token-scoped guest surface, resolved live so a re-minted token
// is picked up. The manual-encode channel instead gets its form-scoped URL from EncodeFormPresenter.
provide(UploadUrlKey, () => `/api/v1/public/f/${encodeURIComponent(client.token())}/attachments`);

// M130 (`R-c9f50df2`) — a note's content images, read through the guest route with the CURRENT token (resolved
// per image, so a re-minted one is used), and asked for once whenever a schema arrives online so a step reached
// offline still shows them: the service worker caches each one keyed WITHOUT the token. Watched rather than
// called, because four paths below set a schema (load, resume, conflict review, version drift).
const contentImageUrlFor = (attachmentId: string): string => contentImageUrl(client.token(), attachmentId);
provide(ContentImageUrlKey, contentImageUrlFor);
watch(schema, (loaded) => {
    if (loaded === null || typeof window === 'undefined' || !navigator.onLine) return;
    void warmContentImages(loaded, contentImageUrlFor, {
        origin: window.location.origin,
        fetch: (url) => fetch(url, { credentials: 'same-origin' }),
        caches: typeof caches === 'undefined' ? null : caches,
    });
});

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
        // M248 (`D232`) — opened from a respondent's link to change the response they sent.
        // Truthy rather than `!== ''`: a bootstrap built before M248 carries no `amendToken` at all.
        if (props.bootstrap.amendToken) {
            await loadAmend(props.bootstrap.amendToken);
            return;
        }
        if (props.bootstrap.resumeToken !== '') {
            await loadResume(props.bootstrap.resumeToken);
            return;
        }
        schema.value = await client.fetchSchema();
        // M248 (`D232`) — BEFORE the schedule gate below, so a FULL form still offers its respondent "Change my answers".
        if (await offerAlreadyAnswered(schema.value)) {
            return;
        }
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
        // M248 (`D118` A) — answers an earlier visit left here are offered back, behind a choice, before any appears.
        if (await offerContinue(schema.value)) {
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

// Increment H10 — open from a resume link. The resume-read gives the saved answers + a server "last saved"
// to reconcile against any same-device local draft, and the draft's uuid so the eventual submit PROMOTES
// that row. A promoted/reaped/expired draft → a resume-specific terminal message.
//
// ⛔ THE SENTENCE THAT USED TO OPEN THIS COMMENT WAS THE DEFECT, AND IT READ AS REASSURANCE: *"The web shell
// already embedded a fresh pinned-version SHARE token (data-share-token), so the existing `client` is
// ready."* On the one path that matters it is not fresh. A resume boot is exactly the boot most likely to be
// served from the service worker's cached shell, and that HTML carries whatever token was minted when it was
// cached — up to seven days old against a 24h share token. `fetchSchema()` below then 401s,
// `withFreshToken()` transparently re-mints through `GET /f/{slug}`, and THAT route pins
// `current_published_version_id` rather than the draft's version. The page renders the new schema while
// `server.formVersionId` names the old one, and `reconcileDraft` silently drops the respondent's newer local
// tier on the mismatch: a v2 form under a "welcome back" greeting holding v1 answers, whose first autosave
// 409s them onto a fresh uuid and abandons the server draft and the emailed resume link with it.
// ⚠️ The fix is not a new endpoint — `resumeDraft()` has always returned a correctly-pinned share token and
// nobody read it. See `client.adoptToken()` below.
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

    // ⛔ BEFORE `fetchSchema()`, NEVER AFTER — the whole point is which version the schema is fetched AT.
    // `resumeDraft()` minted this token against the draft's own `form_version_id`, so adopting it makes the
    // schema below and `server.formVersionId` two names for one version BY CONSTRUCTION, on every path:
    // the pre-mount boot, `onRedraft()`'s mid-session re-read, and a client that has already been re-minted
    // by an earlier expiry. It also removes the 401-and-retry round trip that used to hide the divergence.
    client.adoptToken(server.shareToken);

    schema.value = await client.fetchSchema();

    const localRow = await db.draft_answers.get([server.formVersionId, props.bootstrap.slug]);
    // Increment M21 — the SAME visit gate `useAutosave.restore()` applies, on the other of this table's two
    // readers. Without it `reconcileDraft`'s newest-wins rule hands an ABANDONED stranger's draft to this
    // respondent whenever it is the more recent of the two — and the damage is worse here than on the plain
    // entry path, because the seed pins the SERVER's uuid (`:267`) and the SERVER's baseline (`:276`) while
    // taking the LOCAL tier's answers. Their next "Save and finish later" therefore writes a stranger's
    // answers into THEIR server draft, past P3a's lost-update guard, which cannot fire because the baseline
    // is genuinely theirs; a submit then promotes that row.
    //
    // A foreign row degrades to `undefined`, which is `reconcile.ts:70`'s first branch: the server draft
    // wins, silently and without a note. That closes the sibling `minor` row without touching
    // `LOCAL_WINS_NOTE` — the sentence is correct English for every case that can now reach it.
    const local: LocalDraft | undefined =
        localRow === undefined || !draftBelongsToVisit(localRow, respondentSessionId)
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
        // Increment P3a — always the SERVER's checksum, never derived from `reconciled`. The baseline answers
        // "what does the server currently hold", which is what the next save overwrites; which tier won the
        // ANSWERS is a separate question. Taking it from the local tier when local wins would hand the server
        // a base it never issued and turn every local-wins resume into a false conflict.
        contentChecksum: server.contentChecksum,
    };
    phase.value = 'ready';
}

function onSubmitted(
    id: string,
    submittedReference: string,
    authored: string | null = null,
    redirect: SubmitRedirect | null = null,
    amend: AmendLink | null = null,
): void {
    // M248 (`D232`) — a one-response form: the device remembers it answered, and in `amend` mode how to change it.
    confirmationAmendLink.value = amend;
    if (afterSubmit.value !== 'another') {
        void rememberAnswered(db, props.bootstrap.slug, {
            reference: submittedReference,
            at: new Date().toISOString(),
            amendToken: amend?.token ?? null,
        });
    }
    // M166 — a checked paper copy is now a response like any other; nothing of the review outlives it.
    paperReview.value = null;
    // Increment G8c — a resolved conflict: drop the parked row now that its reviewed answers are recorded.
    const resolved = resolvingUuid.value !== null;
    if (resolved) {
        void syncOutbox.discardSubmission(resolvingUuid.value as string);
        clearResolveState();
    }
    // The SERVER's handle, passed straight through. Deriving one from the id is exactly what J2e removed:
    // those characters are a uuidv7 timestamp prefix, so the code was not a lookup for anybody.
    reference.value = submittedReference;
    queueTag.value = null;
    // M130 (`D76`) — never after a resolved conflict: that respondent came back to review a parked answer, and the
    // destination was chosen for a fresh fill.
    redirectTarget.value = resolved ? null : redirect;
    // Increment H6b — the author's message (already locale-resolved and hole-filled by RuntimeSession,
    // which still had the store when it emitted) replaces the hardcoded copy on BOTH terminal success
    // states. Null — no message, or one whose every hole was unanswered — keeps the default.
    confirmationMessage.value = authored ?? (resolved ? RESOLVED_MESSAGE : CONFIRM_MESSAGE);
    phase.value = 'confirmation';
}

// M165 (`R-7173c02c`, `D106`) — a filled paper copy was sent instead of an answer. The user's words for what the
// respondent is told, with the server's reference and nowhere to go next: the response does not exist until staff save it.
function onPaperLeave(): void {
    reference.value = paperReference.value;
    queueTag.value = null;
    redirectTarget.value = null;
    confirmationMessage.value = PAPER_RECEIVED_MESSAGE;
    paperReview.value = null;
    phase.value = 'confirmation';
}

// M166 (`D116`) — the copy was sent: wait for its reading, then reopen the form filled in with what was read, for its sender
// to check and submit. Leaving it for staff (`M165`'s whole flow, above) stays one button away throughout.
const paperReference = ref<string | null>(null);
const paperToken = ref<string | null>(null);
const paperReview = shallowRef<PaperReview | null>(null);

function onPaperReceived(sentReference: string, sentPaperToken: string): void {
    paperReference.value = sentReference;
    paperToken.value = sentPaperToken;
    phase.value = 'reading';
}

function onPaperRead(result: { answers: AnswerMap; fields: Record<string, PaperFieldMeta>; pages: PaperPage[] }): void {
    retainedAnswers.value = result.answers;
    paperReview.value = { token: paperToken.value ?? '', fields: result.fields, pages: result.pages };
    sessionKey.value += 1;
    phase.value = 'ready';
}

function onPaperFillByHand(): void {
    paperReview.value = null;
    sessionKey.value += 1;
    phase.value = 'ready';
}

// Increment G8b — an offline (or dropped-mid-submit) finalize: the answers are safely queued, so show a
// "saved on this device" confirmation with a local QUEUE TAG derived from the client submission id — not a
// reference, because no server row exists yet and a code the tenant cannot find is worse than none (J2e).
function onQueued(clientUuid: string): void {
    // Increment G8c — a resolve that went offline: the reviewed answers are safely re-queued under the new
    // uuid, so the old parked conflict row can be dropped.
    if (resolvingUuid.value !== null) {
        void syncOutbox.discardSubmission(resolvingUuid.value);
        clearResolveState();
    }
    queueTag.value = deriveReference(clientUuid);
    reference.value = null;
    // M248 (`D232`) — a one-response form remembers a QUEUED response at once; the replay that delivers it adds the
    // reference and, in `amend` mode, the token (`lib/replay.ts`).
    confirmationAmendLink.value = null;
    if (afterSubmit.value !== 'another') {
        void rememberAnswered(db, props.bootstrap.slug, { reference: null, at: new Date().toISOString(), amendToken: null });
    }
    // Increment H6b deliberately does NOT let an author message replace this one. It is not a thank-you —
    // it is the only thing telling the respondent their answers have not been delivered yet, and swapping
    // it for "Thanks, Maria — your response has been recorded." would be a factual lie about delivery
    // state on the one screen where that truth matters most.
    confirmationMessage.value = QUEUED_MESSAGE;
    phase.value = 'confirmation';
}

/**
 * Increment M14 — `conflictCode` says WHICH 409 sent us here, and `null` means the ordinary live drift (a
 * republish, or a recovery whose cause the session could not name). Before M14 every 409 arrived here alike
 * and this function asserted a republish for all of them, so a respondent whose own second device had
 * written the draft was told the form had changed. The remount itself is unchanged — only the sentence, and
 * only for the causes that were being described falsely.
 */
function onReschema(payload: { schema: SchemaResponse; answers: AnswerMap; conflictCode: string | null }): void {
    schema.value = payload.schema;
    retainedAnswers.value = payload.answers;
    // Increment H10 — a drift remount starts fresh against the new version (a new submission, fresh uuid) and
    // is not a resume, so drop the welcome-back seed; the drift notice below explains the carry-over instead.
    resumeSeed.value = null;
    // Increment G8c — a fresh republish DURING a conflict review keeps the resolve context (notice + row), so the
    // loop re-maps against the newer schema until the resubmit succeeds; otherwise it's the normal live-drift copy.
    driftNotice.value = resolveMode.value
        ? DRIFT_RESOLVE_NOTICE
        : payload.conflictCode === null
          ? LIVE_DRIFT_NOTICE
          : conflictCopy(payload.conflictCode).resolveNotice;
    sessionKey.value += 1;
    phase.value = 'ready';
}

/**
 * Increment M14 — a 409 `draft_conflict`: another device wrote this draft, so the answers on screen are based
 * on a copy the server has already moved past.
 *
 * ⚠️ THIS IS THE PATH THE WHOLE INCREMENT EXISTS FOR, AND IT IS NOT A REMOUNT-WITH-A-BETTER-SENTENCE.
 * `onReschema` above re-fetches the SCHEMA and starts a new submission — fresh uuid, `resumeSeed` nulled, so
 * `draftBaseline` reseeds to null and the next write carries no baseline at all. For a republish that is
 * right. For a draft conflict it is precisely wrong: nothing was republished, the schema is unchanged, and
 * discarding the uuid abandons the server draft this device was editing — the second draft and the second
 * emailed resume link the backlog row describes.
 *
 * So it re-reads the DRAFT instead, through `loadResume()`, which already does every part of this correctly
 * and has since H10: it keeps the server draft's `client_submission_uuid`, reconciles the two answer tiers
 * under the precedence locked with the user on 2026-07-23 ("newest-wins, non-destructive"), and always seeds
 * the baseline from the SERVER's checksum rather than from whichever tier won. The only thing it does not do
 * is force a remount, because it normally runs before the session has mounted at all.
 *
 * ⚠️ IN RESOLVE MODE THIS IS UNREACHABLE BY CONSTRUCTION, WHICH IS WORTH STATING RATHER THAN GUARDING.
 * A conflict review re-mounts against a PARKED row's answers under a fresh uuid and performs no draft save,
 * so the session holds no resume token and `RuntimeSession` falls back to `onReschema`. If that ever changes,
 * `loadResume()` would overwrite `resolvingUuid`'s session with the server draft's uuid.
 */
async function onRedraft(payload: { resumeToken: string }): Promise<void> {
    try {
        await loadResume(payload.resumeToken);
    } catch {
        // `loadResume` catches its OWN resume failure and sets the error phase; this catches the second
        // `fetchSchema()` it deliberately leaves uncaught, which on the pre-mount path would surface as a
        // rejected `onMounted` and here would be a silent dead end.
        errorMessage.value = 'We could not reload your saved answers. Please try again.';
        phase.value = 'error';

        return;
    }
    if (phase.value !== 'ready') {
        // The resume token was refused (a promoted or reaped draft) — `loadResume` has already said so.
        return;
    }
    // ⚠️ Increment M14 — the seed is the resume machinery's, but this is NOT a resume, and the banner it
    // drives says so in as many words: "Welcome back — we've restored your saved answers." The respondent
    // never went anywhere; they pressed Save and were refused. Reusing `loadResume` for the re-read is right
    // and inheriting its greeting is not, so the one part that assumes a return journey is switched off.
    resumeSeed.value = resumeSeed.value === null ? null : { ...resumeSeed.value, greet: false };
    driftNotice.value = REDRAFT_NOTICE;
    sessionKey.value += 1;
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
        driftNotice.value = conflictCopy(row.conflict_code).resolveNotice;
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

/**
 * "Submit another response" — and after Increment M15 this is also the deliberate kiosk hand-over.
 *
 * ⚠️ THE ROTATION HAPPENS BEFORE THE RELOAD, NOT AFTER IT, AND THE ORDER IS THE WHOLE POINT. The reload
 * re-boots the SPA, which reads the visit id straight back out of `sessionStorage`; rotating afterwards
 * would run in a context that is already being torn down. `rotateRespondentSession()` removes the marker
 * rather than minting a replacement, so a reload that never happens cannot leave a fresh valid session
 * sitting there for whoever picks the device up.
 *
 * This alone is NOT the containment — it depends on the respondent pressing something before they walk
 * away, and walking away is the dominant kiosk path. The idle window in `lib/respondent-session.ts` is what
 * covers that; this is the fast path for the person who does press it.
 */
function onRestart(): void {
    rotateRespondentSession();
    window.location.reload();
}

// ── M248 (`D118`, `D232`) — the respondent's own answers ────────────────────────────────────────────────────────────

/** The form's "After submitting" setting; a manifest cached before M248 has none, which is `another`. */
const afterSubmit = computed<'another' | 'amend' | 'once'>(() => schema.value?.form.after_submit ?? 'another');
/** The respondent's own zone and language, for the times the new screens show. */
const viewerZone = (() => {
    try {
        return Intl.DateTimeFormat().resolvedOptions().timeZone || 'UTC';
    } catch {
        return 'UTC';
    }
})();
const viewerLocale = props.bootstrap.defaultLocale || 'en';
const errorHeadline = ref('This form isn’t available');

/** The earlier visit's draft `ContinueOrStartOver` asks about, while it asks. */
const earlierDraft = shallowRef<DraftRow | null>(null);
/** What this device remembers sending, while `AlreadyAnswered` shows it. */
const answered = shallowRef<(AnsweredRecord & { canChange: boolean; closedNote: string | null; until: string | null }) | null>(null);
/** The response being changed, while a session changes it; its clock is the day it was sent. */
const amendSession = shallowRef<{ token: string; baseline: string | null } | null>(null);
const amendClock = ref<Date | null>(null);
/** The link the thank-you page offers, after a submit or a change in `amend` mode. */
const confirmationAmendLink = shallowRef<AmendLink | null>(null);

/**
 * Show "You've already answered this form" when this device remembers sending a response and the form takes one per
 * device. The answers stay off screen until "Change my answers".
 */
async function offerAlreadyAnswered(loaded: SchemaResponse): Promise<boolean> {
    const mode = loaded.form.after_submit ?? 'another';
    if (mode === 'another') {
        return false;
    }
    const record = await readAnswered(db, props.bootstrap.slug);
    if (record === null) {
        return false;
    }
    const acceptance = loaded.form.schedule?.acceptance ?? 'open';
    const closed = acceptance === 'closed' || acceptance === 'opens_soon';
    const canChange = mode === 'amend' && record.amendToken !== null && !closed;
    answered.value = {
        ...record,
        canChange,
        until: loaded.form.schedule?.closes_at ?? null,
        closedNote:
            mode !== 'amend' || canChange
                ? null
                : closed
                  ? 'This form has closed, so your answers can no longer be changed.'
                  : 'Your response is still waiting to be sent from this device. Once it is sent, you can change it here.',
    };
    phase.value = 'answered';
    return true;
}

/** Ask "Continue where you left off" or "Start over" when an earlier visit left answers on this form. */
async function offerContinue(loaded: SchemaResponse): Promise<boolean> {
    const row = await earlierVisitDraft(
        db,
        { formVersionId: loaded.version.id, slug: props.bootstrap.slug, checksum: loaded.version.checksum },
        respondentSessionId,
    );
    if (row === null) {
        return false;
    }
    earlierDraft.value = row;
    phase.value = 'continue';
    return true;
}

/**
 * "Continue where you left off": the row becomes this visit's. When it shadows a server draft ("Save and finish later"),
 * the session carries on THAT draft through the resume read — same uuid, the server's baseline, newest answers win
 * (`D232` answer 4); otherwise the session's own restore picks the row up. A server draft that is gone (sent, or reaped)
 * falls back to the answers on this device.
 */
async function onContinue(): Promise<void> {
    const row = earlierDraft.value;
    earlierDraft.value = null;
    if (row === null) {
        phase.value = 'ready';
        return;
    }
    try {
        await adoptDraft(db, { formVersionId: row.form_version_id, slug: props.bootstrap.slug }, respondentSessionId);
    } catch {
        // A refused write leaves the row foreign; the form then starts blank, which is today's behaviour.
    }
    if (row.resume_token) {
        try {
            await loadResume(row.resume_token);
        } catch {
            phase.value = 'error';
        }
        if (phase.value === 'ready') {
            return;
        }
        // The server draft is gone: fall back to the device's own copy, under a fresh session.
        errorMessage.value = '';
        resumeSeed.value = null;
        retainedAnswers.value = undefined;
        try {
            schema.value = await client.fetchSchema();
        } catch {
            errorMessage.value = 'We could not load this form. Please try again.';
            phase.value = 'error';
            return;
        }
    }
    sessionKey.value += 1;
    phase.value = 'ready';
}

/** "Start over": a blank form. The earlier row is left for the first save to overwrite, as before M248. */
function onStartOver(): void {
    earlierDraft.value = null;
    phase.value = 'ready';
}

/** "Change my answers" from the already-answered screen or the thank-you page: open the response through its token. */
async function onChangeAnswers(): Promise<void> {
    const token = answered.value?.amendToken ?? confirmationAmendLink.value?.token ?? null;
    if (token === null) {
        return;
    }
    phase.value = 'loading';
    await loadAmend(token);
}

/**
 * M248 (`D232`) — open the response a respondent SENT, to change it. Its version is the one it was sent under: the read
 * returns a share token minted for that version, adopted BEFORE the schema is fetched, as `loadResume()` does. No
 * schedule gate here — the read already refused a closed form — and no "full" gate, because a change adds no response.
 */
async function loadAmend(amendToken: string): Promise<void> {
    let read;
    try {
        read = await readAmend(amendToken);
    } catch (error) {
        errorHeadline.value = 'Your answers can’t be changed';
        errorMessage.value = amendUnavailableMessage(error);
        phase.value = 'error';
        return;
    }

    client.adoptToken(read.shareToken);
    try {
        schema.value = await client.fetchSchema();
    } catch (error) {
        errorMessage.value = error instanceof ApiError ? error.normalized.message : 'We could not load this form. Please try again.';
        phase.value = 'error';
        return;
    }

    amendClock.value = read.submittedAt === null ? null : new Date(read.submittedAt);
    amendSession.value = { token: amendToken, baseline: read.contentChecksum };
    confirmationAmendLink.value = { token: amendToken, url: window.location.origin + '/f/amend/' + encodeURIComponent(amendToken), until: read.until };
    retainedAnswers.value = read.answers;
    resumeSeed.value = null;
    driftNotice.value = null;
    sessionKey.value += 1;
    phase.value = 'ready';
}

function amendUnavailableMessage(error: unknown): string {
    if (!(error instanceof ApiError)) {
        return 'We could not reach the server. Check your connection and try again.';
    }
    switch (error.normalized.code) {
        case 'form_closed':
            return 'This form has closed, so your answers can no longer be changed.';
        case 'form_not_open':
            return 'This form is not open yet.';
        case 'response_under_review':
        case 'amend_not_allowed':
            return error.normalized.message;
        default:
            return error.normalized.kind === 'terminal'
                ? 'This link to change your answers is no longer valid.'
                : error.normalized.message;
    }
}

/** A change to a sent response was saved. */
function onAmended(amendedReference: string): void {
    // This device now knows the response, wherever the link was opened.
    if (amendSession.value !== null) {
        void rememberAnswered(db, props.bootstrap.slug, {
            reference: amendedReference,
            at: new Date().toISOString(),
            amendToken: amendSession.value.token,
        });
    }
    reference.value = amendedReference;
    queueTag.value = null;
    redirectTarget.value = null;
    confirmationMessage.value = 'Your changes were saved.';
    phase.value = 'confirmation';
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
        <div class="app-shell__banner" :class="{ 'app-shell__banner--wide': phase === 'ready' && paperReview !== null && paperReview.pages.length > 0 }">
            <OfflineIndicator v-if="!online" />
            <SyncStatus />
        </div>

        <div v-if="phase === 'loading'" class="app-state">
            <MdsSpinner size="lg" label="Loading form" />
        </div>
        <div v-else-if="phase === 'error'" class="app-state">
            <MdsEmptyState illustration="lock" :headline="errorHeadline" :description="errorMessage" />
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
            :queue-tag="queueTag"
            :message="confirmationMessage"
            :redirect="redirectTarget"
            :unsent="syncOutbox.pending.value"
            :framed="framed"
            :after-submit="afterSubmit"
            :amend-link="confirmationAmendLink"
            :time-zone="viewerZone"
            :locale="viewerLocale"
            @leave="rotateRespondentSession"
            @restart="onRestart"
            @change="onChangeAnswers"
        />
        <ContinueOrStartOver
            v-else-if="phase === 'continue' && earlierDraft"
            :title="schema?.form.title ?? bootstrap.formTitle"
            :saved-at="earlierDraft.updated_at"
            :time-zone="viewerZone"
            :locale="viewerLocale"
            @continue="onContinue"
            @start-over="onStartOver"
        />
        <AlreadyAnswered
            v-else-if="phase === 'answered' && answered"
            :title="schema?.form.title ?? bootstrap.formTitle"
            :reference="answered.reference"
            :sent-at="answered.at"
            :can-change="answered.canChange"
            :closed-note="answered.closedNote"
            :until="answered.until"
            :time-zone="viewerZone"
            :locale="viewerLocale"
            @change="onChangeAnswers"
        />
        <PaperReading
            v-else-if="phase === 'reading' && paperToken"
            :client="client"
            :paper-token="paperToken"
            @read="onPaperRead"
            @fill-by-hand="onPaperFillByHand"
            @leave="onPaperLeave"
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
            :resolving-uuid="resolvingUuid"
            :resume="resumeSeed"
            :search="amendSession ? '' : initialSearch"
            :now="sessionNow"
            :paper-review="paperReview"
            :amend="amendSession"
            @submitted="onSubmitted"
            @amended="onAmended"
            @queued="onQueued"
            @reschema="onReschema"
            @redraft="onRedraft"
            @discard="onDiscard"
            @unavailable="onUnavailable"
            @paper-received="onPaperReceived"
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

/* M168 — and widens with it while a paper copy is checked beside its scanned pages (`RuntimeShell`'s `wide`). */
@media (min-width: 64rem) {
    .app-shell__banner--wide {
        max-width: 90rem;
    }
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
