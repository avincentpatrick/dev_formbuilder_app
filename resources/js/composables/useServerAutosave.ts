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
    /** Force an immediate save if dirty. Awaits the in-flight request. */
    flush: () => Promise<void>;
    dispose: () => void;
}

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

    function body(): Record<string, unknown> {
        return {
            answers: JSON.parse(JSON.stringify(options.answers)),
            client_submission_uuid: options.clientSubmissionUuid,
            draft_current_step: options.currentStepKey.value === '' ? null : options.currentStepKey.value,
        };
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
                state.value = 'saved';
                message.value = null;

                return;
            }

            // 409 — the draft was submitted from another tab. Retrying can never succeed: the row will never
            // be a draft again. Stopping is the only honest behaviour, and it must be visible.
            if (response.status === 409) {
                stop('This response has already been submitted, so it is no longer being saved as a draft.');

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
            // write instead of it escaping to the real network — and so the single-flight bookkeeping is not
            // bypassed in the one place that would be hardest to notice.
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
            void postKeepalive();
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

    return { state, savedAt, completeness, expiresAt, message, flush, dispose };
}
