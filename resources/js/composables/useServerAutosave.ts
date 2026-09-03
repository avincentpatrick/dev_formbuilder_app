import { getCurrentInstance, onBeforeUnmount, ref, watch, type Ref } from 'vue';

/**
 * Debounced server-side autosave for the manual-encode page (Increment I9b).
 *
 * ── WHY NOT `useAutosave.ts` ─────────────────────────────────────────────────────────────────────────────
 * The guest runtime already has an autosave composable and it is not reusable here, for four independent
 * reasons, each sufficient on its own:
 *   1. it writes to Dexie/IndexedDB, which the tenant app does not mount and must not start mounting;
 *   2. its primary key is `[form_version_id, slug]`, where `slug` is a public SHARE slug the encode channel
 *      has no equivalent of;
 *   3. it has no network layer at all — no in-flight coalescing, no failure state, no HTTP;
 *   4. its contract is "best-effort, silently swallow", which is right for an IndexedDB put and wrong for a
 *      server round trip a keyer is trusting with an hour of transcription.
 *
 * ── WHY PLAIN `fetch` AND NOT `router.post` ──────────────────────────────────────────────────────────────
 * Inertia CANCELS IN-FLIGHT VISITS BY DEFAULT. An autosave tick firing while the keyer clicks Submit would
 * cancel one of the two, and which one is a race. Using `fetch` keeps the two channels independent. CSRF
 * rides the `XSRF-TOKEN` cookie as the `X-XSRF-TOKEN` header, which Laravel's middleware decrypts, so no
 * Blade change is needed.
 */

export type AutosaveState = 'idle' | 'saving' | 'saved' | 'error' | 'stopped';

export interface ServerAutosaveOptions {
    /** The draft endpoint (`props.draft_url`). */
    url: string;
    /** The runtime store's uuid — stable for the page's lifetime, and the idempotency key. */
    clientSubmissionUuid: string;
    /** The reactive answer map. Watched deeply. */
    answers: Record<string, unknown>;
    /** The store's current step key, sent as the resume cursor and flushed on change. */
    currentStepKey: Ref<string>;
    /**
     * Reactive arming switch. MUST start false, and the caller is responsible for flipping it on the first
     * REAL edit (in `Encode.vue`: `armAutosave()`, called from `setFlatValue`, `setInstanceValue`,
     * `addInstance` and `removeInstance`).
     *
     * The reason is that page's min-instance seeding loop, which calls `addInstance()` during setup and so
     * MUTATES the answer map before any human has typed. Armed at construction, the deep watcher below would
     * fire on every page view of any form with a repeatable section and create an empty `status = draft` row
     * carrying a 30-day TTL.
     */
    enabled: Ref<boolean>;
    /**
     * Increment P3a — the lost-update baseline this tab opened on: `props.draft?.baseline`, i.e. the
     * `answers_content_checksum` the page was RENDERED from. Undefined on the blank keying form, which has
     * read nothing and so claims nothing. The composable advances it itself after every successful save; the
     * caller only supplies the starting value.
     */
    baseContentChecksum?: string | null;
    debounceMs?: number;
    backstopMs?: number;
    /** Injected for tests; defaults to the real `fetch` wrapper below. */
    post?: (body: unknown) => Promise<Response>;
}

export interface ServerAutosave {
    state: Ref<AutosaveState>;
    savedAt: Ref<string | null>;
    completeness: Ref<number | null>;
    expiresAt: Ref<string | null>;
    message: Ref<string | null>;
    /**
     * Increment P3a — the CURRENT lost-update baseline, advanced by every successful tick. Exposed because
     * **Submit** must send it: that endpoint also saves-then-promotes, and the page's render-time
     * `props.draft.baseline` is stale the moment this loop ticks once.
     */
    baseline: Ref<string | null>;
    /** Force an immediate save if dirty. Awaits the in-flight request. */
    flush: () => Promise<void>;
    dispose: () => void;
}

/**
 * The sentence a keyer reads when a `409` stops the autosave loop, keyed by the envelope's `error.code`
 * (Increment M67).
 *
 * ⛔ THE OBLIGATION WAS ALREADY WRITTEN DOWN AND NOTHING GATED IT. `SubmissionDraftController::store()`
 * carries the comment *"THREE CAUSES SINCE M11, AND THE COMPOSABLE MUST NOT TREAT THEM ALIKE"* and names
 * all three. This file split the status two ways — `draft_conflict` versus everything else — so
 * `submission_uuid_claimed` was told *"already been submitted"*, which is false and whose remedy is the
 * opposite one. A deferral written into a comment outlives the premise that justified it, silently.
 *
 * ⚠️ THE ROW THAT FILED THIS NAMED THE WRONG CAUSE, and it changes what the fix is. It said the ENTITLEMENT
 * and CONTENT causes both got the finalized sentence. `submission_conflict` (the content cause) cannot reach
 * this endpoint at all — it is raised only by `SubmissionPipeline` and is deliberately suspended for drafts —
 * so it is absent here rather than mapped to something plausible. Adding it would document a refusal this
 * channel cannot produce.
 *
 * ⚠️ NOT REUSED FROM `public-runtime/lib/conflict-notice.ts`, THOUGH ITS DECISION IS. That module addresses
 * a RESPONDENT re-mounting a fill session, and its remedy — review and submit again — is not available to a
 * keyer whose background save has stopped: there is nothing to re-mount, and the answers are still on screen.
 * What carries over is the rule that copy is keyed off `error.code` in exactly one place.
 */
const CONFLICT_COPY: Record<string, string> = {
    // Another tab or device saved in between, so this tab's base is stale. Still terminal for the AUTOSAVE
    // loop — retrying sends the same stale base forever, and silently re-sending would be the lost update
    // the guard exists to stop — but the remedy is reloading, not submission.
    draft_conflict:
        'This draft was changed somewhere else, so saving has stopped to avoid overwriting it. Reload the page to pick up the newer answers.',
    // The row really was promoted between two ticks. The pre-M67 sentence, which was correct for this cause
    // and only this one.
    draft_already_finalized: 'This response has already been submitted, so it is no longer being saved as a draft.',
    // ENTITLEMENT, not timing: the identifier is spent on a row outside this caller's scope. Nothing the
    // keyer typed is lost or wrong, and telling them it was submitted sends them looking for a submission
    // that is not theirs — so the sentence names the identifier and the one act that helps.
    submission_uuid_claimed:
        'This response could not be matched to the draft it belongs to, so saving has stopped. Your answers are still on screen — reload the page to start a fresh draft, and copy them across before leaving.',
};

/**
 * The fallback for an unrecognised or unreadable code.
 *
 * ⚠️ IT STAYS THE FINALIZED SENTENCE DELIBERATELY, AND `conflictCode()` RETURNING NULL IS A REAL INPUT
 * (an unparseable body, or a cause added server-side before this build knew about it). Of the outcomes
 * available it is the one that is safe when wrong: it tells the keyer the loop has stopped and that their
 * work is no longer being saved, which is true of EVERY cause. The alternative — a reassuring sentence —
 * is the failure mode the whole map exists to end.
 */
const FINALIZED_COPY = CONFLICT_COPY.draft_already_finalized;

/** 1500ms, not the guest's 800: each tick is a network round trip plus a `lockForUpdate` transaction. */
const DEFAULT_DEBOUNCE_MS = 1_500;
/** The periodic backstop, per the UX spec's "every 30 seconds while the tab is active". Dirty-gated. */
const DEFAULT_BACKSTOP_MS = 30_000;

function readCookie(name: string): string | null {
    const match = document.cookie.match(new RegExp('(^|; )' + name + '=([^;]*)'));

    return match === null ? null : decodeURIComponent(match[2]);
}

export function createServerAutosave(options: ServerAutosaveOptions): ServerAutosave {
    const debounceMs = options.debounceMs ?? DEFAULT_DEBOUNCE_MS;
    const backstopMs = options.backstopMs ?? DEFAULT_BACKSTOP_MS;

    const state = ref<AutosaveState>('idle');
    const savedAt = ref<string | null>(null);
    const completeness = ref<number | null>(null);
    const expiresAt = ref<string | null>(null);
    const message = ref<string | null>(null);

    let dirty = false;
    /** Anything typed AFTER a terminal stop. Kept separately because `stop()` clears `dirty`. */
    let unsavedSinceStop = false;
    let inFlight: Promise<void> | null = null;
    let pendingWhileInFlight = false;
    let debounceTimer: ReturnType<typeof setTimeout> | null = null;
    let backstopTimer: ReturnType<typeof setInterval> | null = null;

    const post =
        options.post ??
        ((body: unknown): Promise<Response> => {
            const token = readCookie('XSRF-TOKEN');

            return fetch(options.url, {
                method: 'POST',
                credentials: 'same-origin',
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(token === null ? {} : { 'X-XSRF-TOKEN': token }),
                },
                body: JSON.stringify(body),
            });
        });

    // Increment P3a — the lost-update baseline, advanced on every successful save. Held here rather than
    // passed per call because the whole point is continuity ACROSS requests: the value proves this tab has
    // seen everything the server holds.
    //
    // ⚠️ A REF, AND EXPOSED, BECAUSE **SUBMIT** NEEDS IT TOO. The encode page's Submit posts to a different
    // endpoint that also saves-then-promotes, and it must send the CURRENT base — the page's render-time
    // `props.draft.baseline` goes stale the moment this loop ticks once, so submitting that value would
    // false-409 every response the keyer actually edited.
    const baseline = ref<string | null>(options.baseContentChecksum ?? null);

    function body(): Record<string, unknown> {
        return {
            answers: JSON.parse(JSON.stringify(options.answers)),
            client_submission_uuid: options.clientSubmissionUuid,
            draft_current_step: options.currentStepKey.value === '' ? null : options.currentStepKey.value,
            // Sent unconditionally, null included: null is the claim a first save makes, and the server
            // cannot distinguish an absent key from a client that forgot to send one.
            base_content_checksum: baseline.value,
        };
    }

    /**
     * The `error.code` from a 409 envelope, or null if the body is unreadable (Increment P3a).
     *
     * Null deliberately falls through to the pre-P3a message rather than the new one: an unparseable body is
     * not evidence of a lost update, and the older message is the one that was correct for the only cause
     * that existed before. Never throws — a JSON parse failure inside error handling must not replace a
     * typed refusal with an unhandled rejection.
     */
    async function conflictCode(response: Response): Promise<string | null> {
        try {
            const payload = (await response.json()) as { error?: { code?: unknown } };
            const code = payload.error?.code;

            return typeof code === 'string' ? code : null;
        } catch {
            return null;
        }
    }

    /** Terminal: stop trying, permanently, and say why. */
    function stop(reason: string): void {
        state.value = 'stopped';
        message.value = reason;
        dirty = false;
        pendingWhileInFlight = false;
        clearTimers();
    }

    async function send(): Promise<void> {
        if (state.value === 'stopped') {
            return;
        }

        dirty = false;
        state.value = 'saving';

        try {
            const response = await post(body());

            if (response.ok) {
                const payload = (await response.json()) as { data?: Record<string, unknown> };
                const data = payload.data ?? {};
                savedAt.value = typeof data.last_saved_at === 'string' ? data.last_saved_at : new Date().toISOString();
                completeness.value = typeof data.completeness_percent === 'number' ? data.completeness_percent : null;
                expiresAt.value = typeof data.expires_at === 'string' ? data.expires_at : null;
                // Increment P3a — carry the server's new checksum forward. Without this every save after the
                // first would present a stale base and read as a second tab.
                baseline.value = typeof data.content_checksum === 'string' ? data.content_checksum : null;
                state.value = 'saved';
                message.value = null;

                return;
            }

            // ⚠️ 409 HAS THREE CAUSES ON THIS CHANNEL AND THEY NEED DIFFERENT ANSWERS — reading them alike
            // told a keyer whose colleague had merely saved that their work was "already submitted", which is
            // both false and unrecoverable-sounding.
            if (response.status === 409) {
                stop(CONFLICT_COPY[(await conflictCode(response)) ?? ''] ?? FINALIZED_COPY);

                return;
            }

            // 419 — the session or CSRF token expired. THE ONE FAILURE THAT MUST BE LOUD: a keyer typing into
            // a dead session loses everything at the end, silently, unless told now.
            if (response.status === 419) {
                stop('Your session has expired. Reload the page before continuing — recent changes are not being saved.');

                return;
            }

            // 401 / 403 / 404 are PERMANENT, and lumping them in with 5xx was wrong: a revoked grant, a
            // signed-out session (with `Accept: application/json` the auth middleware answers 401 rather
            // than redirecting) or an unpublished form will never start succeeding, so "we'll keep trying"
            // is both false and a 30-second request loop for the rest of the tab's life.
            if (response.status === 401 || response.status === 403 || response.status === 404) {
                stop('Your draft can no longer be saved — you may have signed out, or your access to this form changed.');

                return;
            }

            // 422 (a structural fault) and everything else: recoverable. The next edit may fix it.
            dirty = true;
            state.value = 'error';
            message.value =
                response.status === 422
                    ? 'Some answers could not be saved. They will be retried as you keep typing.'
                    : "Couldn't save your draft — we'll keep trying.";
        } catch {
            // Network failure. Same posture as a 5xx: keep the work, keep trying.
            dirty = true;
            state.value = 'error';
            message.value = "Couldn't save your draft — we'll keep trying.";
        }
    }

    /**
     * One request at a time per uuid. Two concurrent POSTs would serialize on the server's `lockForUpdate`
     * anyway, so the risk is not corruption but OUT-OF-ORDER writes — an older payload landing last. On
     * resolution, if anything changed meanwhile, exactly one follow-up is scheduled.
     */
    function run(): Promise<void> {
        if (inFlight !== null) {
            pendingWhileInFlight = true;

            return inFlight;
        }

        inFlight = send().finally(() => {
            inFlight = null;
            if (pendingWhileInFlight && state.value !== 'stopped') {
                pendingWhileInFlight = false;
                void run();
            }
        });

        return inFlight;
    }

    function schedule(): void {
        if (debounceTimer !== null) {
            clearTimeout(debounceTimer);
        }
        debounceTimer = setTimeout(() => {
            debounceTimer = null;
            void run();
        }, debounceMs);
    }

    function clearTimers(): void {
        if (debounceTimer !== null) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }
        if (backstopTimer !== null) {
            clearInterval(backstopTimer);
            backstopTimer = null;
        }
    }

    async function flush(): Promise<void> {
        if (debounceTimer !== null) {
            clearTimeout(debounceTimer);
            debounceTimer = null;
        }
        if (!dirty && inFlight === null) {
            return;
        }
        await run();
    }

    // The answer watcher. Deep, because the map is nested for repeat groups.
    watch(
        () => options.answers,
        () => {
            if (!options.enabled.value) {
                return;
            }
            if (state.value === 'stopped') {
                // Still recorded, so the unload prompt knows there is unsaved work even though nothing is
                // being written any more.
                unsavedSinceStop = true;

                return;
            }
            dirty = true;
            schedule();
        },
        { deep: true },
    );

    // Step navigation flushes immediately — the UX spec asks for a save "on step navigation in multi-step
    // mode", and it is also the moment the resume cursor changes.
    watch(options.currentStepKey, () => {
        if (!options.enabled.value || state.value === 'stopped') {
            return;
        }
        dirty = true;
        void flush();
    });

    // DIRTY-GATED backstop. `useAutosave.ts` fires its interval unconditionally, which is harmless for an
    // IndexedDB put and would be a pointless request every 30 seconds forever over HTTP.
    backstopTimer = setInterval(() => {
        if (dirty && options.enabled.value && state.value !== 'stopped') {
            void run();
        }
    }, backstopMs);

    /**
     * The last-chance write, shared by `beforeunload` (a real browser navigation) and `dispose()` (an Inertia
     * client-side visit, which fires no unload event at all).
     *
     * `fetch` with `keepalive`, NOT `navigator.sendBeacon`: sendBeacon cannot set the CSRF header and forces
     * a content type this endpoint does not accept. The honest limit — `keepalive` bodies are capped at
     * 64 KiB — is exactly why `beforeunload` ALSO raises the confirm prompt rather than relying on this alone.
     * Fire-and-forget with a swallowed rejection: the page is going away, so there is nobody to tell, and an
     * unhandled rejection during teardown is noise in the console rather than information.
     */
    function postKeepalive(): void {
        try {
            // Routes through the injected `post` when there is one, so a test can observe the last-chance
            // write instead of it escaping to the real network.
            //
            // ⛔ THIS COMMENT USED TO CLAIM THE INJECTION ALSO KEPT "the single-flight bookkeeping … not
            // bypassed in the one place that would be hardest to notice." THAT WAS FALSE AND IT IS WHY THE
            // RACE BELOW SURVIVED REVIEW. Neither branch of this function touches `inFlight` or
            // `pendingWhileInFlight` — it is a deliberate bypass of the coalescer, which is exactly why
            // `dispose()` has to consult `inFlight` itself before calling it. A comment asserting the
            // property whose absence is the bug is worse than no comment, so it is corrected in place rather
            // than deleted.
            if (options.post !== undefined) {
                void Promise.resolve(options.post(body())).catch(() => {
                    // the page is going away
                });

                return;
            }

            const token = readCookie('XSRF-TOKEN');
            void fetch(options.url, {
                method: 'POST',
                credentials: 'same-origin',
                keepalive: true,
                headers: {
                    'Content-Type': 'application/json',
                    Accept: 'application/json',
                    ...(token === null ? {} : { 'X-XSRF-TOKEN': token }),
                },
                body: JSON.stringify(body()),
            }).catch(() => {
                // nothing useful to do while the page is going away
            });
        } catch {
            // same
        }
    }

    /**
     * Last-chance save on unload, plus the browser's native leave prompt.
     *
     * `fetch` with `keepalive`, NOT `navigator.sendBeacon`: sendBeacon cannot set the CSRF header and forces
     * a content type the endpoint does not accept. Being honest about the limit — `keepalive` bodies are
     * capped at 64 KiB — is exactly why the confirm prompt is raised as well rather than relied on instead.
     *
     * ⚠️ A SECOND HONEST LIMIT, AND THE ASYMMETRY WITH `dispose()` IS DELIBERATE RATHER THAN AN OVERSIGHT.
     * When a save is in flight this fires the same stale-base keepalive `dispose()` now refuses to fire, and
     * the server will refuse it as `draft_conflict`. It is NOT chained the way `dispose()` chains, because a
     * real browser navigation is tearing the JS context down — a continuation on `inFlight` would never run,
     * so chaining here would trade a request that fails for a request that is never made. The protection on
     * this path is `event.preventDefault()`, the native leave prompt, on exactly the argument the 64 KiB cap
     * makes above: the last-chance POST is the courtesy, and the prompt is the guarantee. The refused POST
     * destroys nothing — the server rejects it and keeps the newer document.
     */
    function onBeforeUnload(event: BeforeUnloadEvent): void {
        // ⚠️ THE `stopped` STATE MUST STILL PROMPT, and an earlier draft of this got it exactly backwards.
        // `stop()` sets `dirty = false`, so testing `dirty` alone suppressed the browser's leave prompt in
        // precisely the state where NOTHING is being saved and the prompt is the only protection left. What
        // is unsaved in that state is everything typed since the stop, so `unsavedSinceStop` tracks it.
        if (state.value === 'stopped') {
            if (unsavedSinceStop) {
                event.preventDefault();
            }

            return; // no last-chance POST: the endpoint has already told us it will never accept another
        }

        if (!dirty && inFlight === null) {
            return;
        }

        event.preventDefault();
        postKeepalive();
    }

    if (typeof window !== 'undefined') {
        window.addEventListener('beforeunload', onBeforeUnload);
    }

    /**
     * ⚠️ FLUSHES BEFORE TEARING DOWN, and the first draft of this did not — which was a silent data-loss
     * bug on the MOST COMMON way to leave this page. `beforeunload` only fires on a real browser navigation;
     * an Inertia `<Link>` (Cancel, the breadcrumb, any sidebar item) is a client-side visit that unmounts the
     * component and fires nothing. Without this, up to one full debounce window of typing vanished whenever a
     * keyer clicked away — no error, no prompt, no trace.
     *
     * `keepalive` so the request survives the teardown, and deliberately fire-and-forget: there is no one
     * left to tell, and awaiting it would block the navigation the keyer asked for.
     */
    function dispose(): void {
        if (state.value !== 'stopped' && (dirty || debounceTimer !== null)) {
            if (inFlight === null) {
                void postKeepalive();
            } else {
                // ⚠️ CHAINED, NOT FIRED. `send()` clears `dirty` at its top and advances `baseline` only on a
                // 200, so anything typed DURING a save leaves this composable dirty while `baseline` still
                // holds the PRE-save checksum. Firing now would present that stale base, the server would
                // serialize the two on `lockForUpdate`, and the keepalive would arrive after the in-flight
                // save had moved the checksum — refused `draft_conflict`, swallowed by the fire-and-forget
                // `catch`, and the edits made during that request lost with no error, no prompt and no trace.
                //
                // Chaining is possible HERE and nowhere else: an Inertia visit unmounts the component but
                // keeps the JS context alive, so this continuation still runs. `onBeforeUnload` cannot do
                // this and does not try — see its own note.
                void inFlight.then(() => {
                    // The in-flight save may itself have ended the loop (409/419/permanent). Posting after
                    // that is exactly what `stop()` exists to prevent, and `stop()` runs before this resolves.
                    if (state.value !== 'stopped') {
                        postKeepalive();
                    }
                });
            }
        }
        clearTimers();
        if (typeof window !== 'undefined') {
            window.removeEventListener('beforeunload', onBeforeUnload);
        }
    }

    // Guarded, because this composable is also driven directly from Vitest with no component around it, and
    // an unguarded lifecycle hook warns there. Inside `Encode.vue` the instance exists and unmount cleans up.
    if (getCurrentInstance() !== null) {
        onBeforeUnmount(dispose);
    }

    return { state, savedAt, completeness, expiresAt, message, baseline, flush, dispose };
}
