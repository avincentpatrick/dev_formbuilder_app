import { afterEach, beforeEach, describe, expect, it, vi } from 'vitest';
import { nextTick, ref, reactive } from 'vue';

import { createServerAutosave, type ServerAutosaveOptions } from '../useServerAutosave';

/**
 * Increment I9b — the manual-encode autosave.
 *
 * Every case here is a failure mode rather than a happy path, because the happy path ("it eventually POSTs")
 * is satisfied by almost any implementation. What is worth pinning is the behaviour under a burst of typing,
 * under an in-flight request, on a dead session, and on a page view where nobody has typed anything at all.
 */

function jsonResponse(status: number, data: Record<string, unknown> = {}): Response {
    return {
        ok: status >= 200 && status < 300,
        status,
        json: async () => ({ data }),
    } as unknown as Response;
}

/**
 * Every composable this file constructs, so `afterEach` can take its `beforeunload` listener off the
 * shared window.
 *
 * ⛔ ADDED IN M71 BECAUSE THE FIRST DISPATCH-BASED CASE WOULD OTHERWISE HAVE MEASURED ITS NEIGHBOURS.
 * happy-dom gives ONE `window` per test FILE, and until now nothing here disposed anything — so by the
 * time a late case runs, every earlier harness still has an armed `beforeunload` handler attached, and
 * several of them were deliberately left dirty (the 5xx and network-throw retry cases). A case that
 * asserts "the page does NOT warn on close" by dispatching a cancelable `beforeunload` and reading
 * `defaultPrevented` would therefore have been red no matter what the code under test did — a false
 * failure that looks exactly like a real one. This is the isolation those cases need, and it is written
 * as a teardown of the harnesses rather than a `window` reset so that it cannot mask a leak instead.
 *
 * `standDown()` and not `dispose()`: both detach the listener, and only `dispose()` writes.
 */
const liveHarnesses: Array<{ standDown: () => void }> = [];

function harness(overrides: Partial<ServerAutosaveOptions> = {}) {
    const post = vi.fn(async () => jsonResponse(200, { last_saved_at: '2026-08-08T00:00:00+00:00', completeness_percent: 50 }));
    const answers = reactive<Record<string, unknown>>({});
    const enabled = ref(true);
    const currentStepKey = ref('step-1');

    const autosave = createServerAutosave({
        url: '/forms/f1/submissions/draft',
        clientSubmissionUuid: 'uuid-1',
        answers,
        currentStepKey,
        enabled,
        debounceMs: 100,
        backstopMs: 1_000,
        post,
        ...overrides,
    });

    liveHarnesses.push(autosave);

    return { autosave, post, answers, enabled, currentStepKey };
}

/** Dispatch a real, cancelable `beforeunload` and report whether anything asked to stop the navigation. */
function unloadIsWarned(): boolean {
    const event = new Event('beforeunload', { cancelable: true });
    window.dispatchEvent(event);

    return event.defaultPrevented;
}

beforeEach(() => {
    vi.useFakeTimers();
});

afterEach(() => {
    liveHarnesses.forEach((autosave) => autosave.standDown());
    liveHarnesses.length = 0;
});

describe('useServerAutosave — batching', () => {
    it('collapses a burst of typing into ONE request', async () => {
        const { post, answers } = harness();

        answers.a = '1';
        await nextTick();
        answers.a = '12';
        await nextTick();
        answers.a = '123';
        await nextTick();

        expect(post).not.toHaveBeenCalled(); // still inside the debounce window

        await vi.advanceTimersByTimeAsync(150);

        expect(post).toHaveBeenCalledTimes(1);
        // And it sends the LAST value, not the first.
        expect((post.mock.calls[0][0] as { answers: Record<string, unknown> }).answers).toEqual({ a: '123' });
    });

    it('sends the uuid and the step cursor with every save', async () => {
        const { post, answers } = harness();

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        const body = post.mock.calls[0][0] as Record<string, unknown>;
        expect(body.client_submission_uuid).toBe('uuid-1');
        expect(body.draft_current_step).toBe('step-1');
    });
});

describe('useServerAutosave — the empty-draft guard', () => {
    it('POSTs nothing while disabled, however much the answer map changes', async () => {
        // ⚠️ THE ROW-PER-PAGE-VIEW GUARD. `Encode.vue` seeds each repeatable section's min_instances during
        // mount, which MUTATES the answer map before any human has typed. Armed at setup, the composable
        // would create a `status = draft` row with a 30-day TTL on every single page view of any form with a
        // repeat group.
        const { post, answers, enabled } = harness();
        enabled.value = false;

        answers.a = 'seeded-by-mount';
        await nextTick();
        await vi.advanceTimersByTimeAsync(1_000);

        expect(post).not.toHaveBeenCalled();
    });

    it('starts saving once armed by a real edit', async () => {
        // The control: without it, the case above passes against a composable that never saves at all.
        const { post, answers, enabled } = harness();
        enabled.value = false;

        answers.a = 'seeded';
        await nextTick();
        await vi.advanceTimersByTimeAsync(1_000);
        expect(post).not.toHaveBeenCalled();

        enabled.value = true;
        answers.a = 'typed';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(post).toHaveBeenCalledTimes(1);
    });
});

describe('useServerAutosave — concurrency', () => {
    it('never runs two requests at once, and schedules exactly one follow-up', async () => {
        // Two concurrent POSTs would serialize on the server's row lock, so the risk is not corruption but an
        // OLDER payload landing last.
        let resolveFirst: ((r: Response) => void) | null = null;
        const post = vi.fn(
            () =>
                new Promise<Response>((resolve) => {
                    if (resolveFirst === null) {
                        resolveFirst = resolve;

                        return;
                    }
                    resolve(jsonResponse(200));
                }),
        );

        const { answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);
        expect(post).toHaveBeenCalledTimes(1); // in flight, unresolved

        // Three more edits land while it is in flight.
        answers.a = '2';
        await nextTick();
        answers.a = '3';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(post).toHaveBeenCalledTimes(1); // still only one — the rest coalesced

        resolveFirst!(jsonResponse(200));
        await vi.advanceTimersByTimeAsync(150);

        expect(post).toHaveBeenCalledTimes(2); // exactly ONE follow-up, not three
    });
});

describe('useServerAutosave — the backstop', () => {
    it('is dirty-gated: a quiet tab issues no periodic requests', async () => {
        const { post } = harness();

        await vi.advanceTimersByTimeAsync(5_000);

        expect(post).not.toHaveBeenCalled();
    });

    it('does fire when there is unsaved work', async () => {
        // The control for the case above. A save that failed leaves the work dirty, and the backstop is what
        // eventually retries it without the keyer having to type again.
        const post = vi.fn(async () => jsonResponse(500));
        const { answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);
        expect(post).toHaveBeenCalledTimes(1); // the failed attempt

        await vi.advanceTimersByTimeAsync(1_100); // one backstop interval

        expect(post.mock.calls.length).toBeGreaterThan(1);
    });
});

describe('useServerAutosave — terminal failures', () => {
    it('stops permanently on a 409 and names what happened', async () => {
        // The draft was submitted from another tab. Retrying can never succeed.
        const post = vi.fn(async () => jsonResponse(409));
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('stopped');
        expect(autosave.message.value).toContain('already been submitted');

        const callsAtStop = post.mock.calls.length;
        answers.a = '2';
        await nextTick();
        await vi.advanceTimersByTimeAsync(5_000);

        expect(post).toHaveBeenCalledTimes(callsAtStop); // never tries again
    });

    it('stops permanently on a 419, because a dead session loses work silently otherwise', async () => {
        const post = vi.fn(async () => jsonResponse(419));
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('stopped');
        expect(autosave.message.value).toContain('expired');
    });

    it('keeps retrying after a 5xx rather than giving up', async () => {
        const post = vi.fn(async () => jsonResponse(503));
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('error');

        answers.a = '2';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(post.mock.calls.length).toBeGreaterThan(1);
    });

    it('keeps retrying after a network throw', async () => {
        const post = vi.fn(async () => {
            throw new Error('offline');
        });
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('error');
        expect(autosave.message.value).toContain('keep trying');
    });
});

describe('useServerAutosave — lifecycle', () => {
    it('flushes pending work on demand', async () => {
        const { autosave, post, answers } = harness();

        answers.a = '1';
        await nextTick();

        expect(post).not.toHaveBeenCalled(); // debounce not yet elapsed
        await autosave.flush();

        expect(post).toHaveBeenCalledTimes(1);
    });

    it('SAVES pending work on dispose(), because Inertia navigation fires no unload event', async () => {
        // ⚠️ THE MOST COMMON WAY TO LEAVE THIS PAGE IS AN INERTIA <Link> (Cancel, the breadcrumb, any sidebar
        // item), which unmounts the component client-side and fires no `beforeunload` at all. An earlier
        // draft of dispose() cleared the timers WITHOUT saving, so up to a full debounce window of typing
        // vanished on every click-away — no error, no prompt, nothing to notice.
        const { autosave, post, answers } = harness();

        answers.a = '1';
        await nextTick();
        expect(post).not.toHaveBeenCalled(); // still inside the debounce window

        autosave.dispose();

        expect(post).toHaveBeenCalledTimes(1);
        expect((post.mock.calls[0][0] as { answers: Record<string, unknown> }).answers).toEqual({ a: '1' });
    });

    it('stops the backstop on dispose(), so a torn-down page issues nothing further', async () => {
        const { autosave, post, answers } = harness();

        answers.a = '1';
        await nextTick();
        autosave.dispose();
        const afterDispose = post.mock.calls.length;

        await vi.advanceTimersByTimeAsync(10_000);

        expect(post).toHaveBeenCalledTimes(afterDispose);
    });

    it('saves nothing on dispose() when there is nothing pending', async () => {
        // The control: dispose() must not manufacture a write on a page nobody touched.
        const { autosave, post } = harness();

        autosave.dispose();

        expect(post).not.toHaveBeenCalled();
    });

    it('still writes on dispose() when the work is dirty but NO timer is armed', async () => {
        // ⚠️ FOUND BY MUTATION, NOT BY READING (M62). `dispose()`'s gate is `dirty || debounceTimer !== null`,
        // and narrowing that `||` to `&&` left the whole of this file green — every other dispose case here
        // happens to have both true at once, so the `||` arm was load-bearing and pinned by nothing.
        //
        // This is the state that separates them: a 5xx sets `dirty = true` and deliberately does NOT
        // re-schedule (the next keystroke is what retries), so a keyer who hits a server error and then
        // clicks away has unsaved work and no armed timer. Under `&&` that work is dropped in silence —
        // the same class of loss as the race above, reached from the other side.
        const post = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse(500))
            .mockResolvedValue(jsonResponse(200, { content_checksum: 'sum-2' }));
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        // Non-vacuity: the case is only about the dirty-without-timer state, so prove we are in it.
        expect(post).toHaveBeenCalledTimes(1);
        expect(autosave.state.value).toBe('error');

        autosave.dispose();

        expect(post).toHaveBeenCalledTimes(2);
        expect((post.mock.calls[1][0] as { answers: Record<string, unknown> }).answers).toEqual({ a: '1' });
    });
});

/**
 * Increment P3a — the lost-update baseline on the encode channel.
 *
 * Two tabs on one draft is the reachable case here (bootstrap/app.php names it), and before P3a the second
 * tab's tick silently replaced the first tab's answers: the write is a whole-document replace.
 */
describe('useServerAutosave — P3a lost-update baseline', () => {
    /** A 409 carrying a typed error envelope, which the `{ data }` helper above cannot express. */
    function conflict(code: string): Response {
        return {
            ok: false,
            status: 409,
            json: async () => ({ error: { code, message: 'nope' } }),
        } as unknown as Response;
    }

    it('sends the seeded baseline, then advances it from each response', async () => {
        const post = vi
            .fn()
            .mockResolvedValueOnce(jsonResponse(200, { last_saved_at: 't1', completeness_percent: 10, content_checksum: 'sum-2' }))
            .mockResolvedValueOnce(jsonResponse(200, { last_saved_at: 't2', completeness_percent: 20, content_checksum: 'sum-3' }));
        const { answers } = harness({ post, baseContentChecksum: 'sum-1' });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        // The page's rendered baseline goes out first.
        expect((post.mock.calls[0][0] as Record<string, unknown>).base_content_checksum).toBe('sum-1');

        answers.a = '2';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        // ...and the SECOND tick carries what the server just wrote, not the stale page value. Without this
        // every save after the first would present a stale base and read as a second tab.
        expect((post.mock.calls[1][0] as Record<string, unknown>).base_content_checksum).toBe('sum-2');
    });

    it('sends an explicit null baseline on the blank keying form', async () => {
        const post = vi.fn(async () => jsonResponse(200, { content_checksum: 'sum-1' }));
        const { answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        const body = post.mock.calls[0][0] as Record<string, unknown>;
        expect(body).toHaveProperty('base_content_checksum');
        expect(body.base_content_checksum).toBeNull();
    });

    it('tells a lost update apart from an already-submitted draft, and says something different', async () => {
        const post = vi.fn(async () => conflict('draft_conflict'));
        const { autosave, answers } = harness({ post, baseContentChecksum: 'stale' });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('stopped');
        // Reading the two 409 causes alike told a keyer whose colleague had merely SAVED that their work was
        // "already submitted" — false, and it names a remedy that does not exist.
        expect(autosave.message.value).toContain('Reload');
        expect(autosave.message.value).not.toContain('already been submitted');
    });

    it('keeps the pre-P3a message for the promotion race', async () => {
        const post = vi.fn(async () => conflict('draft_already_finalized'));
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('stopped');
        expect(autosave.message.value).toContain('already been submitted');
    });

    it('falls back to the pre-P3a message when a 409 body is unreadable', async () => {
        const post = vi.fn(async () => ({
            ok: false,
            status: 409,
            json: async () => {
                throw new SyntaxError('not json');
            },
        }) as unknown as Response);
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        // An unparseable body is not EVIDENCE of a lost update, so it must not claim one — and it must not
        // turn a typed refusal into an unhandled rejection either.
        expect(autosave.state.value).toBe('stopped');
        expect(autosave.message.value).toContain('already been submitted');
    });

    /*
     * Increment M67 — the THIRD cause, which this channel has been able to return since M11.
     *
     * ⛔ THE ASSERTIONS ARE ON THE MESSAGE, NOT THE STATE, AND THAT IS THE WHOLE POINT. All three causes end
     * in `stopped`, so a case asserting the state passes identically whether the arm exists or not — which
     * is how the missing arm survived two increments of green.
     */
    it('tells an unmatched identifier apart from an already-submitted draft', async () => {
        const post = vi.fn(async () => conflict('submission_uuid_claimed'));
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('stopped');
        // ENTITLEMENT, not timing. Nothing was submitted, and saying so sends the keyer looking for a
        // submission that is not theirs; the sentence must name the identifier and what still helps.
        expect(autosave.message.value).toContain('could not be matched');
        expect(autosave.message.value).not.toContain('already been submitted');
        // ...and it must not borrow the OTHER cause's remedy either: reloading to pick up newer answers is
        // the draft_conflict fix, and there are no newer answers here.
        expect(autosave.message.value).not.toContain('pick up the newer answers');
    });

    it('falls back to the finalized sentence for a cause this build has never heard of', async () => {
        // A server-side cause added ahead of the front end. Of the sentences available, the one that is safe
        // when wrong is the one true of EVERY cause: the loop has stopped and this is no longer being saved.
        const post = vi.fn(async () => conflict('some_future_cause'));
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(autosave.state.value).toBe('stopped');
        expect(autosave.message.value).toContain('already been submitted');
    });

    /*
     * Increment M62 — dispose() must not RACE the save it is disposing.
     *
     * `send()` clears `dirty` at its top and advances `baseline` only on a 200. So everything typed WHILE a
     * save is open leaves the composable dirty against the PRE-save checksum, and the last-chance keepalive
     * dispose() fires on an Inertia navigation used to carry that stale base. The server serializes the two
     * on `lockForUpdate`, the open save moves the checksum, and the keepalive is refused `draft_conflict` —
     * swallowed by the fire-and-forget catch, so the keyer loses those edits with no error and no trace.
     *
     * These two cases need a post that STAYS OPEN, which no other case in this file has: every helper above
     * resolves immediately, so `inFlight` is always null by the time the assertion runs and the race is
     * unreachable by construction.
     */

    /** A post whose promise is held open, so the composable really is mid-flight when dispose() lands. */
    function deferredPost() {
        const releases: Array<(r: Response) => void> = [];
        const post = vi.fn(() => new Promise<Response>((resolve) => { releases.push(resolve); }));

        return { post, release: (r: Response) => releases.shift()?.(r) };
    }

    it('does not race an in-flight save on dispose(), and follows it with the ADVANCED baseline', async () => {
        const { post, release } = deferredPost();
        const { autosave, answers } = harness({ post, baseContentChecksum: 'sum-1' });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(post).toHaveBeenCalledTimes(1);
        expect((post.mock.calls[0][0] as Record<string, unknown>).base_content_checksum).toBe('sum-1');

        // The keyer types on while that request is still open, then clicks an Inertia <Link>.
        answers.a = '12';
        await nextTick();
        autosave.dispose();

        // ⛔ THE DEFECT, PINNED: a keepalive fired HERE would carry `sum-1`, which the open save is about to
        // supersede, and be refused. Nothing may go out until that save has landed.
        expect(post).toHaveBeenCalledTimes(1);

        release(jsonResponse(200, { last_saved_at: 't1', content_checksum: 'sum-2' }));
        await vi.advanceTimersByTimeAsync(0);

        // ...and now it goes, carrying what the server just wrote and the text typed during the request.
        expect(post).toHaveBeenCalledTimes(2);
        const body = post.mock.calls[1][0] as { answers: Record<string, unknown>; base_content_checksum: unknown };
        expect(body.base_content_checksum).toBe('sum-2');
        expect(body.answers).toEqual({ a: '12' });
    });

    it('sends nothing after dispose() when the in-flight save has ENDED the loop', async () => {
        // The continuation resolves after `stop()` has run, which is exactly the window `stop()` exists to
        // close. Without the re-check inside it, dispose() would post into a session the server has already
        // refused — the "we'll keep trying" behaviour the terminal cases above deliberately forbid.
        const { post, release } = deferredPost();
        const { autosave, answers } = harness({ post, baseContentChecksum: 'sum-1' });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        answers.a = '12';
        await nextTick();
        autosave.dispose();

        release(conflict('draft_conflict'));
        await vi.advanceTimersByTimeAsync(0);

        expect(autosave.state.value).toBe('stopped');
        expect(post).toHaveBeenCalledTimes(1);
    });
});

/*
 * Increment M68 — Submit must not RACE the draft channel, and `dispose()` made it do exactly that.
 *
 * `Encode.vue`'s `submit()` called `autosave.dispose()` and then immediately posted the promote carrying
 * `autosave.baseline.value`. `dispose()` fires a last-chance keepalive carrying the SAME base, so on a dirty
 * page TWO writers went out against one checksum. They serialize on `updateDraft()`'s `lockForUpdate`, the
 * winner advances it, and the loser is refused `draftConcurrentlyModified()` — which, when the loser is the
 * Submit, tells a keyer their draft "was changed somewhere else" on a page with no somewhere else.
 *
 * ⛔ WHICH ONE WINS IS TIMING AND IS DELIBERATELY NOT TESTED HERE. It does not need settling: `standDown()`
 * removes one writer, so the race is gone in every ordering. A test that pinned an ordering would be pinning
 * the scheduler.
 *
 * ⚠️ AND THE ROW UNDERSTATED IT — THERE WERE THREE WRITERS ON A SUCCESSFUL SUBMIT, NOT TWO. `postKeepalive()`
 * never touches `dirty`, and `dispose()`'s only condition is `dirty || debounceTimer !== null`, so the
 * remount that follows a successful promote ran `onBeforeUnmount(dispose)` and fired the keepalive a SECOND
 * time. The third case below is the one that pins that, and it is why `standDown()` clears `dirty`.
 */
describe('useServerAutosave — standing down for a caller that will write the document itself (M68)', () => {
    /** A post whose promise is held open, so the composable really is mid-flight when the call lands. */
    function deferredPost() {
        const releases: Array<(r: Response) => void> = [];
        const post = vi.fn(() => new Promise<Response>((resolve) => { releases.push(resolve); }));

        return { post, release: (r: Response) => releases.shift()?.(r) };
    }

    it('writes NOTHING on standDown(), where dispose() would have written', async () => {
        const { autosave, post, answers } = harness({ baseContentChecksum: 'sum-1' });

        answers.a = '1';
        await nextTick();

        // Inside the debounce window: dirty, with a timer armed. This is the state a keyer is in when they
        // finish the last field and click Submit, and it is the state `dispose()` writes in.
        expect(post).not.toHaveBeenCalled();

        autosave.standDown();
        await vi.advanceTimersByTimeAsync(500);

        expect(post).not.toHaveBeenCalled();
    });

    it('DOES write on dispose() in that same state — the discriminator', async () => {
        // ⛔ THE CONTROL FOR THE CASE ABOVE. Without it, "no request went out" is equally consistent with
        // standDown() working and with the harness never having been dirty in the first place — the vacuous
        // success this project keeps cataloguing. Same setup, one call changed, opposite outcome.
        const { autosave, post, answers } = harness({ baseContentChecksum: 'sum-1' });

        answers.a = '1';
        await nextTick();

        expect(post).not.toHaveBeenCalled();

        autosave.dispose();
        await vi.advanceTimersByTimeAsync(0);

        expect(post).toHaveBeenCalledTimes(1);
        expect((post.mock.calls[0][0] as Record<string, unknown>).base_content_checksum).toBe('sum-1');
    });

    it('leaves a later dispose() a genuine no-op, so the remount after a successful Submit writes nothing', async () => {
        // ⛔ THE THIRD WRITER, PINNED. `Encode.vue` sets `preserveState` false on success, so the component
        // remounts and `onBeforeUnmount(dispose)` runs. Before M68 `dirty` was still true at that point —
        // the keepalive does not clear it — so this fired a second stale-base write at the exact moment the
        // promote had finalized the row, whose refusal would be `draft_already_finalized`.
        const { autosave, post, answers } = harness({ baseContentChecksum: 'sum-1' });

        answers.a = '1';
        await nextTick();

        autosave.standDown();
        autosave.dispose(); // what the unmount does a moment later

        await vi.advanceTimersByTimeAsync(500);

        expect(post).not.toHaveBeenCalled();
    });

    it('settles an in-flight save so the caller reads the ADVANCED baseline, not the pre-save one', async () => {
        const { post, release } = deferredPost();
        const { autosave, answers } = harness({ post, baseContentChecksum: 'sum-1' });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);

        expect(post).toHaveBeenCalledTimes(1);
        expect(autosave.baseline.value).toBe('sum-1'); // not advanced yet — the request is still open

        const settled = autosave.settle();
        release(jsonResponse(200, { last_saved_at: 't1', content_checksum: 'sum-2' }));
        await settled;

        // ⛔ THIS IS WHAT THE SUBMIT POSTS. Without the settle it would post `sum-1` while the save that
        // just landed had already moved the document to `sum-2` — refused, on the Submit, for a conflict
        // the page itself created.
        expect(autosave.baseline.value).toBe('sum-2');
    });

    it('does not let the coalescer start a FOLLOW-UP save while settling', async () => {
        // ⛔ THE REASON `settle()` CLEARS `pendingWhileInFlight` AND LOOPS. `run()`'s own `finally` starts a
        // follow-up request when work arrived during the one it is completing, and that continuation runs
        // BEFORE an `await inFlight` resolves — so a naive single await returns with a NEW writer in flight,
        // which is the very thing being stood down. Typing during the open save is what arms it.
        const { post, release } = deferredPost();
        const { autosave, answers } = harness({ post, baseContentChecksum: 'sum-1' });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);
        expect(post).toHaveBeenCalledTimes(1);

        // ⛔ TYPING ALONE DOES NOT ARM `pendingWhileInFlight`, AND THE FIRST DRAFT OF THIS CASE ASSUMED IT
        // DID — so the case passed while being blind to the mechanism it names, and only the mutation
        // showed it (dropping `pendingWhileInFlight = false` from `settle()` SURVIVED). The answer watcher
        // calls `schedule()`, which only arms a timer; the flag is set inside `run()`, so the debounce has
        // to actually FIRE while the first request is still open. That is the second advance below.
        answers.a = '12';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);
        expect(post).toHaveBeenCalledTimes(1); // coalesced, not sent — and now pendingWhileInFlight is true

        const settled = autosave.settle();
        release(jsonResponse(200, { last_saved_at: 't1', content_checksum: 'sum-2' }));
        await settled;
        autosave.standDown();
        await vi.advanceTimersByTimeAsync(500);

        expect(post).toHaveBeenCalledTimes(1);
    });

    it('settles to nothing when no save is open, without starting one', async () => {
        // The common path: `inFlight === null`, so `settle()` must be a no-op rather than a flush. A version
        // that called `run()` here would reintroduce the writer this pair exists to remove.
        const { autosave, post, answers } = harness();

        answers.a = '1';
        await nextTick();

        await autosave.settle();

        expect(post).not.toHaveBeenCalled();
    });

    // ── M71 — what standDown() takes away, and what the next keystroke must give back. ──────────────
    //
    // The live branch is the 422. `Encode.vue` keeps this component mounted only when the refusal carries
    // an errors bag, and of the refusals the encode Submit can meet only `SubmissionValidationException`
    // does — the conflict refusals return `back()` with a toast and no errors, so Inertia takes a fresh
    // key, the component remounts, and a brand-new composable arms its own guards. The row that found
    // this said "after a refused Submit", which is broader than the defect.

    it('WARNS on close while dirty — the control, without which every negative below is vacuous', async () => {
        // ⛔ THE DISCRIMINATOR FOR THE WHOLE GROUP. Every case below reads `event.defaultPrevented` after
        // dispatching a cancelable `beforeunload`. If dispatching did not reach the composable at all —
        // wrong event name, no listener attached, happy-dom not honouring `cancelable` — then "not warned"
        // would be true for reasons having nothing to do with the code under test, and the two negatives
        // below would pass while proving nothing.
        const { answers } = harness();

        answers.a = '1';
        await nextTick();

        expect(unloadIsWarned()).toBe(true);
    });

    it('re-arms the leave prompt on the next keystroke after standDown()', async () => {
        // ⛔ THE DEFECT. `onBeforeUnload` was registered exactly once at construction and BOTH teardown
        // paths removed it, with nothing anywhere re-adding it — so a keyer whose Submit came back 422
        // kept typing into a page that still autosaved and would never again warn them on close. The
        // composable's own note calls that prompt "the guarantee" to the last-chance POST's "courtesy".
        const { autosave, answers } = harness();

        answers.a = '1';
        await nextTick();

        autosave.standDown();
        expect(unloadIsWarned()).toBe(false); // stood down, nothing typed since — nothing to warn about

        answers.a = '12'; // the 422 came back and the keyer kept going
        await nextTick();

        expect(unloadIsWarned()).toBe(true);
    });

    it('does NOT re-arm the leave prompt after dispose() — the discriminator', async () => {
        // ⛔ THE OTHER HALF OF THE PAIR, AND WHY `disposed` IS A FLAG RATHER THAN AN ASSUMPTION.
        // `standDown()` is recoverable and `dispose()` is terminal; a re-arm that could not tell them
        // apart would resurrect a listener on a component being unmounted. In a browser the watcher dies
        // with the component so this is unreachable — but Vitest drives the composable with no component
        // around it, which is exactly the shape that would hide the difference.
        const { autosave, answers } = harness();

        answers.a = '1';
        await nextTick();

        autosave.dispose();

        answers.a = '12';
        await nextTick();

        expect(unloadIsWarned()).toBe(false);
    });

    it('re-arms the BACKSTOP on the next keystroke after standDown(), not just the debounce', async () => {
        // ⛔ THE HALF NOBODY NOTICED, AND IT IS INVISIBLE TO THE PROMPT CASES ABOVE. `clearTimers()` clears
        // the backstop interval as well as the debounce timer, and `schedule()` re-creates only the
        // debounce timer — the interval was a single `setInterval` at construction. So the save loop
        // "recovered" on the next keystroke while the periodic retry that rescues a save stuck in `error`
        // was gone for the rest of the page's life.
        //
        // A failing post is what makes it observable: it leaves the work dirty, which is the backstop's
        // own gate, and it is the same setup the backstop's original pair uses.
        const post = vi.fn(async () => jsonResponse(500));
        const { autosave, answers } = harness({ post });

        answers.a = '1';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150);
        expect(post).toHaveBeenCalledTimes(1); // the failed attempt

        autosave.standDown();

        answers.a = '12';
        await nextTick();
        await vi.advanceTimersByTimeAsync(150); // the debounce, which always recovered
        const afterTyping = post.mock.calls.length;

        // ⛔ AND NOW NOBODY TYPES. Anything further can only come from the interval, which is the point:
        // a case that let the debounce fire again here would pass on the unfixed code too.
        await vi.advanceTimersByTimeAsync(1_100);

        expect(post.mock.calls.length).toBeGreaterThan(afterTyping);
    });
});
